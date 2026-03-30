<?php declare(strict_types=1);

namespace SearchSolr\Job;

use Common\Stdlib\PsrMessage;
use Omeka\Job\AbstractJob;

/**
 * Reduce Solr field count to stay within the configured maxFields limit.
 *
 * Steps:
 * 1. Remove maps for unused properties.
 * 2. Remove _s/_ss maps for properties with long values (> 200 chars),
 *    except title-like properties.
 * 3. Remove remaining unused maps by priority (_s, _link_ss, _ss, _txt)
 *    until within limit.
 *
 * Note: removing maps only prevents future indexing of those fields.
 * A reindex is required to actually remove the fields from Solr.
 */
class ReduceSolrFields extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     *
     * List of fields, adapted:
     * @see \SearchSolr\Api\Representation\SolrCoreRepresentation::missingRequiredMaps()
     * @see \SearchSolr\Job\ReduceSolrFields::perform()
     */
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId(
            'searchsolr/reduce/job_' . $this->job->getId()
        );
        $this->logger->addProcessor($referenceIdProcessor);

        $solrCoreId = (int) $this->getArg('solr_core_id');
        if (!$solrCoreId) {
            $this->logger->err(
                'Missing solr_core_id argument.' // @translate
            );
            return;
        }

        try {
            /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
            $solrCore = $api->read('solr_cores', $solrCoreId)
                ->getContent();
        } catch (\Throwable $e) {
            $this->logger->err(
                'Solr core #{id} not found.', // @translate
                ['id' => $solrCoreId]
            );
            return;
        }

        $fieldStatus = $solrCore->fieldLimitStatus();
        if (!$fieldStatus || !$fieldStatus['maxFields']) {
            $this->logger->err(
                'Unable to determine the Solr maxFields limit.' // @translate
            );
            return;
        }

        $this->logger->warn(
            'This job should only be run when the database contains a representative set of items. Results may be inaccurate on an incomplete database.' // @translate
        );

        $maxFields = $fieldStatus['maxFields'];
        $numFields = $fieldStatus['numFields'];
        $toRemove = $numFields - $maxFields;
        $removed = [];

        $this->logger->info(new PsrMessage(
            'Solr core has {numFields} fields (limit: {maxFields}), {toRemove} maps to remove.', // @translate
            [
                'numFields' => $numFields,
                'maxFields' => $maxFields,
                'toRemove' => max(0, $toRemove),
            ]
        ));

        // 1. Remove maps for unused properties.
        $this->logger->info(
            'Step 1: Removing maps for unused properties.' // @translate
        );
        $properties = $api->search('properties')->getContent();
        foreach (['items', 'item_sets', 'media'] as $resourceName) {
            $usedIds = $this->listUsedPropertyIds(
                $connection, $resourceName
            );
            $maps = $solrCore->mapsByResourceName($resourceName);
            foreach ($maps as $map) {
                if ($map->resourceName() !== $resourceName) {
                    continue;
                }
                $source = $map->source();
                foreach ($properties as $property) {
                    if ($property->term() !== $source) {
                        continue;
                    }
                    if (!in_array($property->id(), $usedIds)) {
                        $api->delete('solr_maps', $map->id());
                        $removed[] = $map->fieldName()
                            . ' (' . $source . '/'
                            . $resourceName . ')';
                    }
                }
            }
        }

        $this->logger->info(new PsrMessage(
            'Step 1 done: {count} maps removed.', // @translate
            ['count' => count($removed)]
        ));

        // 2. Remove _s/_ss for properties with long values.
        $this->logger->info(
            'Step 2: Identifying properties with long values.' // @translate
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
            '{count} properties with long values: {list}.', // @translate
            [
                'count' => count($longProperties),
                'list' => implode(', ', $longProperties),
            ]
        ));

        // Reload core to get fresh maps.
        $solrCore = $api->read('solr_cores', $solrCoreId)
            ->getContent();
        $usedFields = $this->collectUsedSolrFields(
            $solrCore, $api
        );

        $removedLong = [];
        $skippedLong = [];
        foreach ($solrCore->maps() as $map) {
            $fieldName = $map->fieldName();
            if (!preg_match('/_ss?$/', $fieldName)
                || str_ends_with($fieldName, '_link_ss')
            ) {
                continue;
            }
            if (!in_array($map->source(), $longProperties)) {
                continue;
            }
            if (in_array($fieldName, $usedFields)) {
                $skippedLong[] = $fieldName;
                continue;
            }
            $api->delete('solr_maps', $map->id());
            $removed[] = $fieldName
                . ' (' . $map->source() . '/long values)';
            $removedLong[] = $fieldName;
        }

        $this->logger->info(new PsrMessage(
            'Step 2 done: {count} long-value maps removed: {list}.', // @translate
            [
                'count' => count($removedLong),
                'list' => implode(', ', $removedLong) ?: '-',
            ]
        ));
        if ($skippedLong) {
            $this->logger->info(new PsrMessage(
                'Step 2: {count} long-value maps skipped (used in search configs): {list}.', // @translate
                [
                    'count' => count($skippedLong),
                    'list' => implode(', ', $skippedLong),
                ]
            ));
        }

        // 3. Check if further reduction is needed.
        // The actual Solr field count does not decrease until reindex,
        // so we estimate based on maps removed.
        $estimatedFields = $numFields - count($removed);

        if ($estimatedFields <= $maxFields) {
            $this->finalize(
                $solrCore, $api, $removed,
                $numFields, $estimatedFields, $maxFields
            );
            return;
        }

        // 4. Remove remaining maps by priority until within limit.
        $this->logger->info(new PsrMessage(
            'Step 3: Need to remove {count} more maps (estimated {estimated} fields, limit {maxFields}).', // @translate
            [
                'count' => $estimatedFields - $maxFields,
                'estimated' => $estimatedFields,
                'maxFields' => $maxFields,
            ]
        ));

        // Default fields that should never be removed.
        $requiredSources = [
            'resource_name',
            'o:id',
            'is_public',
            'o:title',
            'owner/o:id',
            'site/o:id',
            'resource_class/o:term',
            'resource_template/o:label',
            'item_set/o:id',
            'item_set/o:title',
            // TODO Still needed?
            'property_values',
            // To manage multiple indexes (drupal).
            'search_index',
        ];
        $requiredFields = [
            // Solr dynamic fields naming convention.
            'resource_name_s',
            'id_i',
            'is_public_i',
            'name_s',
            'owner_id_i',
            'site_id_is',
            'resource_class_s',
            'resource_template_s',
            'title_s',
            'item_set_id_is',
            'item_set_title_ss',
            'property_values_txt',
            // Drupal naming convention.
            'ss_resource_name',
            'is_id',
            'is_public',
            'ss_name',
            'is_owner_id',
            'im_site_id',
            'ss_resource_class',
            'ss_resource_template',
            'ss_title',
            'im_item_set_id',
            'sm_item_set_title',
            'twm_property_values',
        ];

        // Reload core after step 2.
        $solrCore = $api->read('solr_cores', $solrCoreId)
            ->getContent();
        $usedFields = $this->collectUsedSolrFields(
            $solrCore, $api
        );

        $removable = [];
        foreach ($solrCore->maps() as $map) {
            $fieldName = $map->fieldName();
            if (in_array($fieldName, $usedFields)
                || in_array($map->source(), $requiredSources)
                || in_array($fieldName, $requiredFields)
            ) {
                continue;
            }
            $removable[] = $map;
        }

        usort($removable, function ($a, $b) {
            return $this->fieldRemovalPriority($a->fieldName())
                <=> $this->fieldRemovalPriority($b->fieldName());
        });

        $removedStep3 = [];
        foreach ($removable as $map) {
            if ($estimatedFields <= $maxFields) {
                break;
            }
            $api->delete('solr_maps', $map->id());
            $label = $map->fieldName()
                . ' (' . $map->source() . ')';
            $removed[] = $label;
            $removedStep3[] = $label;
            --$estimatedFields;
        }

        $this->logger->info(new PsrMessage(
            'Step 3 done: {count} maps removed: {list}.', // @translate
            [
                'count' => count($removedStep3),
                'list' => implode(', ', $removedStep3) ?: '-',
            ]
        ));

        $solrCore = $api->read('solr_cores', $solrCoreId)
            ->getContent();
        $this->finalize(
            $solrCore, $api, $removed,
            $numFields, $estimatedFields, $maxFields
        );
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

    protected function collectUsedSolrFields(
        \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore,
        \Omeka\Api\Manager $api
    ): array {
        $usedFields = [];

        foreach ($solrCore->searchConfigs() as $searchConfig) {
            $settings = $searchConfig->settings();
            // Facets.
            foreach ($settings['facet']['facets'] ?? [] as $facet) {
                if (!empty($facet['enabled'])
                    && !empty($facet['field'])
                ) {
                    $usedFields[] = $facet['field'];
                }
            }
            // Sorts.
            $sorts = $settings['results']['sort_list']
                ?? $settings['sort']['sort_list']
                ?? [];
            foreach ($sorts as $sort) {
                if (!empty($sort['enabled'])
                    && !empty($sort['name'])
                ) {
                    $usedFields[] = strtok($sort['name'], ' ');
                }
            }
            // Filters.
            foreach ($settings['form']['filters'] ?? [] as $filter) {
                if (!empty($filter['enabled'])
                    && !empty($filter['field'])
                ) {
                    $usedFields[] = $filter['field'];
                }
            }
            // Generic enabled fields scan.
            foreach ($settings as $value) {
                if (!is_array($value)) {
                    continue;
                }
                foreach ($value as $fieldName => $fieldConf) {
                    if (is_array($fieldConf)
                        && !empty($fieldConf['enabled'])
                    ) {
                        $usedFields[] = preg_replace(
                            '/ (asc|desc)$/', '', $fieldName
                        );
                    }
                }
            }
        }

        // Suggesters.
        foreach (array_keys($solrCore->searchEngines()) as $eid) {
            $suggesters = $api->search('search_suggesters', [
                'engine_id' => $eid,
            ])->getContent();
            foreach ($suggesters as $suggester) {
                $sSettings = $suggester->settings();
                $solrFields = $sSettings['solr_fields'] ?? [];
                if (!empty($sSettings['solr_field'])) {
                    $solrFields[] = $sSettings['solr_field'];
                }
                foreach ($solrFields as $f) {
                    if ($f && $f !== 'auto') {
                        $usedFields[] = $f;
                    }
                }
            }
        }

        return array_unique($usedFields);
    }

    protected function fieldRemovalPriority(string $fieldName): int
    {
        if (preg_match('/_s$/', $fieldName)) {
            return 0;
        }
        if (preg_match('/_link_ss$/', $fieldName)) {
            return 1;
        }
        if (preg_match('/_ss$/', $fieldName)) {
            return 2;
        }
        if (preg_match('/_txt$/', $fieldName)) {
            return 3;
        }
        return 4;
    }

    protected function finalize(
        \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore,
        \Omeka\Api\Manager $api,
        array $removed,
        int $originalFields,
        int $estimatedFields,
        int $maxFields
    ): void {
        // Update field boosts.
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

        $this->logger->notice(new PsrMessage(
            'Reduction complete: {count} maps removed. Estimated fields after reindex: {estimated} (was {original}, limit: {maxFields}).', // @translate
            [
                'count' => count($removed),
                'estimated' => $estimatedFields,
                'original' => $originalFields,
                'maxFields' => $maxFields,
            ]
        ));

        if (count($removed)) {
            $this->logger->notice(new PsrMessage(
                'Removed maps: {list}.', // @translate
                ['list' => implode(', ', $removed)]
            ));
            $this->logger->warn(
                'A reindex is required for the Solr field count to actually decrease.' // @translate
            );
        }

        if ($estimatedFields > $maxFields) {
            $this->logger->warn(new PsrMessage(
                'Estimated {estimated} fields still exceeds the limit of {maxFields}. All remaining maps are used in search configs or suggesters. Reduce the number of configured facets, sorts, or suggesters, or increase "maxFields" in solrconfig.xml.', // @translate
                [
                    'estimated' => $estimatedFields,
                    'maxFields' => $maxFields,
                ]
            ));
        }
    }
}
