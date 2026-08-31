<?php declare(strict_types=1);

namespace SearchSolr\Job;

use Common\Stdlib\PsrMessage;
use Omeka\Job\AbstractJob;
use SearchSolr\Stdlib\LanguageCodes;

/**
 * Create Solr maps for all used properties.
 *
 * Two modes:
 * - "complete": create _txt, _ss, _s, _link_ss for all used properties
 *   (same as the former synchronous completeAction).
 * - "recommended": same, but skip _s/_ss for properties whose longest
 *   value exceeds 200 characters (except title-like properties).
 */
class CompleteSolrMaps extends AbstractJob
{
    /**
     * Minimum ratio of numeric values for a property to get a numeric index.
     */
    const DATATYPE_RATIO = 0.998;

    /**
     * A property gets no exact index when its number of distinct values is
     * greater than this factor multiplied by the square root of its total.
     */
    const DATATYPE_CARDINALITY_FACTOR = 5;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;


    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        $mode = $this->getArg('mode', 'complete');

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId(
            'searchsolr/complete-maps/' . $mode
            . '/job_' . $this->job->getId()
        );
        $this->logger->addProcessor($referenceIdProcessor);

        $solrCoreId = (int) $this->getArg('solr_core_id');
        if (!$solrCoreId) {
            $this->logger->err(
                'Missing solr_core_id argument.' // @translate
            );
            return;
        }

        $resourceName = $this->getArg('resource_name', 'items');

        try {
            /** @var \SearchSolr\Stdlib\SolrCore $solrCore */
            $solrCore = new \SearchSolr\Stdlib\SolrCore($api->read('search_engines', $solrCoreId)->getContent(), $this->getServiceLocator());
        } catch (\Throwable $e) {
            $this->logger->err(
                'Solr core #{id} not found.', // @translate
                ['id' => $solrCoreId]
            );
            return;
        }

        $this->logger->warn(
            'This job should only be run when the database contains a representative set of items. Results may be inaccurate on an incomplete database.' // @translate
        );

        $this->logger->info(new PsrMessage(
            'Starting map completion in "{mode}" mode.', // @translate
            ['mode' => $mode]
        ));

        // Get existing field names.
        $maps = $solrCore->mapsByResourceName($resourceName);
        $existingFields = array_map(
            fn ($v) => $v->fieldName(), $maps
        );

        $skipTermTexts = include dirname(__DIR__, 2)
            . '/config/metadata_text.php';

        // In recommended mode, also skip _s/_ss for long-value
        // properties (> 200 chars), except title-like ones.
        $longProperties = [];
        if ($mode === 'recommended') {
            $this->logger->info(
                'Identifying properties with long values (> 200 chars).' // @translate
            );
            $longProperties = $this->listLongValueProperties(
                $connection, 200
            );
            $keepLongProperties = [
                'dcterms:title',
                'dcterms:alternative',
                'bibo:shortTitle',
                'dcterms:creator',
                'foaf:name',
            ];
            $longProperties = array_diff(
                $longProperties, $keepLongProperties
            );
            $this->logger->info(new PsrMessage(
                '{count} properties have long values and will be skipped for _s/_ss maps.', // @translate
                ['count' => count($longProperties)]
            ));
        }

        // Prepare language indexes.
        $langsByProperties = $this->listLanguagesByProperty(
            $connection
        );

        if (empty($langsByProperties)) {
            $this->logger->info(
                'No values have a language. Using generic _txt.' // @translate
            );
        }

        // Load properties and filter to used ones.
        $properties = $api->search('properties')->getContent();
        if ($mode === 'datatypes') {
            $usedProperties = $this->analyseUsedPropertyIds(
                $connection, $resourceName
            );
        } else {
            $usedProperties = $this->listUsedPropertyIds(
                $connection, $resourceName
            );
        }

        $newMaps = [];

        foreach ($properties as $property) {
            if (!array_key_exists($property->id(), $usedProperties)) {
                continue;
            }

            $term = $property->term();
            $label = $property->label();

            $solrFieldType = 's';
            $name = strtr($term, ':', '_') . '_txt';
            $stats = $usedProperties[$property->id()] ?? [];
            // A typed index requires its formatter: a few values may not be
            // numbers, and Solr rejects the whole document when one of them is
            // sent as is, so the formatter drops them.
            $formatter = '';

            if ($mode === 'datatypes') {
                if ($stats['z'] >= self::DATATYPE_RATIO * $stats['used']) {
                    // integer
                    $name = strtr($term, ':', '_') . '_is';
                    $solrFieldType = 'i';
                    $formatter = 'integer';
                } elseif ($stats['r'] >= self::DATATYPE_RATIO * $stats['used']) {
                    // floating point
                    $name = strtr($term, ':', '_') . '_ds';
                    $solrFieldType = 'd';
                    $formatter = 'decimal';
                }
            }

            if ($this->createMap(
                $api, $solrCoreId, $resourceName,
                $name, $term, null, [],
                ['formatter' => $formatter, 'label' => $label],
                $existingFields
            )) {
                $newMaps[] = $name;
            }

            // _txt with language suffix.
            foreach ($langsByProperties[$term] ?? [] as $language) {
                $suffix = LanguageCodes::toSolrSuffix($language);
                if ($suffix === '') {
                    continue;
                }
                $name = strtr($term, ':', '_') . '_txt_' . $suffix;
                if ($this->createMap(
                    $api, $solrCoreId, $resourceName,
                    $name, $term, null,
                    [
                        'filter_languages' => LanguageCodes::codesForSolrSuffix($suffix),
                        // A value without language is language neutral, so it
                        // belongs to each language index.
                        'filter_languages_no_lang' => true,
                    ],
                    ['formatter' => '', 'label' => $label],
                    $existingFields
                )) {
                    $newMaps[] = $name;
                }
            }

            if (in_array($term, $skipTermTexts)) {
                continue;
            }

            // In recommended mode, skip _ss and _s for long-value properties.
            $skipStringFields = $mode === 'recommended'
                && in_array($term, $longProperties);

            // In datatypes mode, skip _ss for fields with too many distinct
            // values: such a facet or filter cannot be browsed.
            $skipManyValues = $mode === 'datatypes'
                && sqrt((int) $stats['used']) * self::DATATYPE_CARDINALITY_FACTOR < $stats['numval'];

            if (!$skipStringFields) {
                // _ss: filters and facets.
                $name = strtr($term, ':', '_') . '_ss';
                if ($this->createMap(
                    $api, $solrCoreId, $resourceName,
                    $name, $term, $term, [],
                    ['formatter' => 'text', 'parts' => ['main'],
                        'label' => $label],
                    $existingFields
                )) {
                    $newMaps[] = $name;
                }

                // _s: sort. A numeric property sorts as a number: a string
                // index would sort 10 before 9.
                $name = strtr($term, ':', '_') . '_' . $solrFieldType;
                if ($this->createMap(
                    $api, $solrCoreId, $resourceName,
                    $name, $term, null, [],
                    ['formatter' => $formatter ?: 'text', 'parts' => ['main'],
                        'label' => $label],
                    $existingFields
                )) {
                    $newMaps[] = $name;
                }
            }

            // _link_ss: bounce links (always created).
            $name = strtr($term, ':', '_') . '_link_ss';
            if ($this->createMap(
                $api, $solrCoreId, $resourceName,
                $name, $term, null, [],
                ['parts' => ['link'],
                    'formatter' => 'text', 'label' => $label],
                $existingFields
            )) {
                $newMaps[] = $name;
            }
        }

        // Update field boosts.
        $solrCore = new \SearchSolr\Stdlib\SolrCore($api->read('search_engines', $solrCoreId)->getContent(), $this->getServiceLocator());
        $this->updateFieldsBoost($solrCore, $api);

        if ($newMaps) {
            $this->logger->notice(new PsrMessage(
                '{count} new maps created: {list}.', // @translate
                [
                    'count' => count($newMaps),
                    'list' => implode(', ', $newMaps),
                ]
            ));
        } else {
            $this->logger->notice(
                'No new maps added.' // @translate
            );
        }
    }

    /**
     * Create a single map if the field name does not already exist.
     *
     * @return bool True if the map was created.
     */
    protected function createMap(
        \Omeka\Api\Manager $api,
        int $solrCoreId,
        string $resourceName,
        string $fieldName,
        string $source,
        ?string $alias,
        array $pool,
        array $settings,
        array &$existingFields
    ): bool {
        if (in_array($fieldName, $existingFields)) {
            return false;
        }
        $api->create('solr_maps', [
            'o:solr_core' => ['o:id' => $solrCoreId],
            'o:resource_name' => $resourceName,
            'o:field_name' => $fieldName,
            'o:alias' => $alias,
            'o:source' => $source,
            'o:pool' => $pool,
            'o:settings' => $settings,
        ]);
        $existingFields[] = $fieldName;
        return true;
    }

    protected function listUsedPropertyIds(
        \Doctrine\DBAL\Connection $connection,
        string $resourceName
    ): array {
        $resourceTypes = [
            'items' => \Omeka\Entity\Item::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
            'media' => \Omeka\Entity\Media::class,
        ];
        if (class_exists('DigitalObject\Module', false)) {
            $resourceTypes['digital_objects'] = \DigitalObject\Entity\DigitalObject::class;
        }
        if (class_exists('Thesaurus\Module', false)) {
            $resourceTypes['concepts'] = \Thesaurus\Entity\Concept::class;
        }
        if (!isset($resourceTypes[$resourceName])) {
            return [];
        }
        $qb = $connection->createQueryBuilder()
            ->select('DISTINCT value.property_id')
            ->from('value', 'value')
            ->innerJoin(
                'value', 'resource', 'resource',
                'resource.id = value.resource_id'
            )
            ->where('resource.resource_type = :resource_type')
            ->setParameter(
                'resource_type', $resourceTypes[$resourceName]
            )
            ->orderBy('value.property_id', 'ASC');
        return $connection
            ->executeQuery($qb->getSQL(), $qb->getParameters())
            ->fetchAllAssociativeIndexed();
    }

    protected function analyseUsedPropertyIds(
        \Doctrine\DBAL\Connection $connection,
        string $resourceName
    ): array {
        $resourceTypes = [
            'items' => \Omeka\Entity\Item::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
            'media' => \Omeka\Entity\Media::class,
        ];
        if (class_exists('DigitalObject\Module', false)) {
            $resourceTypes['digital_objects'] = \DigitalObject\Entity\DigitalObject::class;
        }
        if (class_exists('Thesaurus\Module', false)) {
            $resourceTypes['concepts'] = \Thesaurus\Entity\Concept::class;
        }
        if (!isset($resourceTypes[$resourceName])) {
            return [];
        }
        $qb = $connection->createQueryBuilder()
            ->select([
                'value.property_id',
                'COUNT(*) used',
                'COUNT(DISTINCT value.value) numval',
                'SUM(value.value RLIKE \'^-?\\\\d+(\\\\.\\\\d+)?$\') r',
                'SUM(value.value RLIKE \'^-?\\\\d+$\') z'
            ])
            ->from('value', 'value')
            ->innerJoin(
                'value', 'resource', 'resource',
                'resource.id = value.resource_id'
            )
	    ->groupBy('value.property_id')
            ->where('resource.resource_type = :resource_type')
            ->setParameter(
                'resource_type', $resourceTypes[$resourceName]
            )
            ->orderBy('value.property_id', 'ASC');
        return $connection
            ->executeQuery($qb->getSQL(), $qb->getParameters())
            ->fetchAllAssociativeIndexed();
    }

    protected function listLongValueProperties(
        \Doctrine\DBAL\Connection $connection,
        int $maxLength
    ): array {
        $qb = $connection->createQueryBuilder()
            ->select(
                'CONCAT(vocabulary.prefix, ":", property.local_name)'
                    . ' AS term'
            )
            ->from('value', 'value')
            ->innerJoin(
                'value', 'property', 'property',
                'property.id = value.property_id'
            )
            ->innerJoin(
                'property', 'vocabulary', 'vocabulary',
                'vocabulary.id = property.vocabulary_id'
            )
            ->groupBy('value.property_id')
            ->having('MAX(LENGTH(value.value)) > :max_length')
            ->setParameter('max_length', $maxLength);
        return $connection
            ->executeQuery($qb->getSQL(), $qb->getParameters())
            ->fetchFirstColumn();
    }

    /**
     * List languages used per property term.
     *
     * @return array Associative array [term => [lang, ...]].
     */
    protected function listLanguagesByProperty(
        \Doctrine\DBAL\Connection $connection
    ): array {
        $qb = $connection->createQueryBuilder()
            ->select(
                'CONCAT(vocabulary.prefix, ":", property.local_name)'
                    . ' AS term',
                'value.lang AS lang',
                'property.id AS prop'
            )
            ->distinct()
            ->from('value', 'value')
            ->innerJoin(
                'value', 'property', 'property',
                'property.id = value.property_id'
            )
            ->innerJoin(
                'property', 'vocabulary', 'vocabulary',
                'property.vocabulary_id = vocabulary.id'
            )
            ->where('value.lang IS NOT NULL')
            ->andWhere("value.lang != ''")
            ->orderBy('property.id', 'asc')
            ->addOrderBy('value.lang', 'asc');
        $result = $connection->executeQuery($qb->getSQL(), $qb->getParameters())
            ->fetchAllAssociative();
        $langsByProperties = [];
        foreach ($result as $row) {
            $langsByProperties[$row['term']][] = $row['lang'];
        }
        return $langsByProperties;
    }

    protected function updateFieldsBoost(
        \SearchSolr\Stdlib\SolrCore $solrCore,
        \Omeka\Api\Manager $api
    ): void {
        $solrCoreSettings = $solrCore->settings();
        $boosts = [];
        foreach ($solrCore->maps() as $map) {
            $boosts[$map->fieldName()] = $map->setting('boost')
                ?: 1;
        }
        $solrCoreSettings['field_boost'] = $boosts;
        $this->updateEngineSolrSettings($solrCore->id(), $solrCoreSettings);
    }

    /**
     * Save the solr settings of the engine (facet "solr" of its settings).
     */
    protected function updateEngineSolrSettings(int $engineId, array $solrSettings): void
    {
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');
        $engineSettings = json_decode((string) $connection->fetchOne(
            'SELECT `settings` FROM `search_engine` WHERE `id` = ?', [$engineId]
        ), true) ?: [];
        $engineSettings['solr'] = $solrSettings;
        $connection->executeStatement(
            'UPDATE `search_engine` SET `settings` = ?, `modified` = NOW() WHERE `id` = ?;',
            [json_encode($engineSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $engineId]
        );
    }
}
