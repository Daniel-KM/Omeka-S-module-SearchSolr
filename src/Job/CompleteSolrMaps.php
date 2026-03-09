<?php declare(strict_types=1);

namespace SearchSolr\Job;

use Common\Stdlib\PsrMessage;
use Omeka\Job\AbstractJob;

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
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * Solr language mappings (ISO-639 → Solr suffix).
     */
    protected $solrLangs = [
        'cjk' => 'cjk',
        'zh' => 'cjk',
        'zho' => 'cjk',
        'chi' => 'cjk',
        'en' => 'en',
        'eng' => 'en',
        'ar' => 'ar',
        'ara' => 'ar',
        'bg' => 'bg',
        'bul' => 'bg',
        'ca' => 'ca',
        'cat' => 'ca',
        'cz' => 'cz',
        'ces' => 'cz',
        'cze' => 'cz',
        'da' => 'da',
        'dan' => 'da',
        'de' => 'de',
        'deu' => 'de',
        'ger' => 'de',
        'el' => 'el',
        'ell' => 'el',
        'gre' => 'el',
        'es' => 'es',
        'spa' => 'es',
        'et' => 'et',
        'est' => 'et',
        'eu' => 'eu',
        'eus' => 'eu',
        'bas' => 'eu',
        'fa' => 'fa',
        'fas' => 'fa',
        'per' => 'fa',
        'fi' => 'fi',
        'fin' => 'fi',
        'fr' => 'fr',
        'fra' => 'fr',
        'fre' => 'fr',
        'ga' => 'ga',
        'gle' => 'ga',
        'gl' => 'gl',
        'glg' => 'gl',
        'hi' => 'hi',
        'hin' => 'hi',
        'hu' => 'hu',
        'hun' => 'hu',
        'hy' => 'hy',
        'hye' => 'hy',
        'arm' => 'hy',
        'id' => 'id',
        'ind' => 'id',
        'it' => 'it',
        'ita' => 'it',
        'ja' => 'ja',
        'jpn' => 'ja',
        'ko' => 'ko',
        'kor' => 'ko',
        'lv' => 'lv',
        'lav' => 'lv',
        'nl' => 'nl',
        'nld' => 'nl',
        'dut' => 'nl',
        'no' => 'no',
        'nor' => 'no',
        'pt' => 'pt',
        'por' => 'pt',
        'ro' => 'ro',
        'ron' => 'ro',
        'rum' => 'ro',
        'ru' => 'ru',
        'rus' => 'ru',
        'sv' => 'sv',
        'swe' => 'sv',
        'th' => 'th',
        'tha' => 'th',
        'tr' => 'tr',
        'tur' => 'tr',
    ];

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
            /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
            $solrCore = $api->read('solr_cores', $solrCoreId)
                ->getContent();
        } catch (\Exception $e) {
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
        $usedPropertyIds = $this->listUsedPropertyIds(
            $connection, $resourceName
        );

        $newMaps = [];

        foreach ($properties as $property) {
            if (!in_array($property->id(), $usedPropertyIds)) {
                continue;
            }

            $term = $property->term();
            $label = $property->label();

            // _txt: fulltext search.
            $name = strtr($term, ':', '_') . '_txt';
            if ($this->createMap(
                $api, $solrCoreId, $resourceName,
                $name, $term, null, [],
                ['formatter' => '', 'label' => $label],
                $existingFields
            )) {
                $newMaps[] = $name;
            }

            // _txt with language suffix.
            foreach ($langsByProperties[$term] ?? [] as $language) {
                if (!isset($this->solrLangs[$language])) {
                    continue;
                }
                $suffix = $this->solrLangs[$language];
                $name = strtr($term, ':', '_') . '_txt_' . $suffix;
                if ($this->createMap(
                    $api, $solrCoreId, $resourceName,
                    $name, $term, null,
                    ['filter_languages' => array_keys(
                        $this->solrLangs, $suffix
                    )],
                    ['formatter' => '', 'label' => $label],
                    $existingFields
                )) {
                    $newMaps[] = $name;
                }
            }

            if (in_array($term, $skipTermTexts)) {
                continue;
            }

            // In recommended mode, skip _ss and _s for long-value
            // properties.
            $skipStringFields = $mode === 'recommended'
                && in_array($term, $longProperties);

            if (!$skipStringFields) {
                // _ss: filters and facets.
                $name = strtr($term, ':', '_') . '_ss';
                if ($this->createMap(
                    $api, $solrCoreId, $resourceName,
                    $name, $term, $term, [],
                    ['formatter' => '', 'parts' => ['main'],
                        'label' => $label],
                    $existingFields
                )) {
                    $newMaps[] = $name;
                }

                // _s: sort.
                $name = strtr($term, ':', '_') . '_s';
                if ($this->createMap(
                    $api, $solrCoreId, $resourceName,
                    $name, $term, null, [],
                    ['formatter' => '', 'parts' => ['main'],
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
                ['index_for_link' => true, 'parts' => ['link'],
                    'formatter' => '', 'label' => $label],
                $existingFields
            )) {
                $newMaps[] = $name;
            }
        }

        // Update field boosts.
        $solrCore = $api->read('solr_cores', $solrCoreId)
            ->getContent();
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
            ->executeQuery($qb, $qb->getParameters())
            ->fetchFirstColumn();
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
            ->executeQuery($qb, $qb->getParameters())
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
        $result = $connection->executeQuery($qb)
            ->fetchAllAssociative();
        $langsByProperties = [];
        foreach ($result as $row) {
            $langsByProperties[$row['term']][] = $row['lang'];
        }
        return $langsByProperties;
    }

    protected function updateFieldsBoost(
        \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore,
        \Omeka\Api\Manager $api
    ): void {
        $solrCoreSettings = $solrCore->settings();
        $boosts = [];
        foreach ($solrCore->maps() as $map) {
            $boosts[$map->fieldName()] = $map->setting('boost')
                ?: 1;
        }
        $solrCoreSettings['field_boost'] = $boosts;
        $api->update('solr_cores', $solrCore->id(), [
            'o:settings' => $solrCoreSettings,
        ], [], ['isPartial' => true]);
    }
}
