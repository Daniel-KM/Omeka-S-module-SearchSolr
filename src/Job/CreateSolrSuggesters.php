<?php declare(strict_types=1);

namespace SearchSolr\Job;

use Omeka\Job\AbstractJob;

/**
 * Create Solr suggesters and build their dictionaries.
 *
 * This job resolves the Solr fields from the suggester settings, creates a
 * single SuggestComponent with all suggesters, updates the /suggest handler,
 * and rebuilds each dictionary.
 */
class CreateSolrSuggesters extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('searchsolr/suggester/job_' . $this->job->getId());
        $this->logger->addProcessor($referenceIdProcessor);

        $suggesterId = (int) $this->getArg('search_suggester_id');
        if (!$suggesterId) {
            $this->logger->err('Missing search_suggester_id argument.'); // @translate
            return;
        }

        /** @var \AdvancedSearch\Api\Representation\SearchSuggesterRepresentation $suggester */
        try {
            $suggester = $api->read('search_suggesters', $suggesterId)->getContent();
        } catch (\Exception $e) {
            $this->logger->err(
                'Suggester #{id} not found.', // @translate
                ['id' => $suggesterId]
            );
            return;
        }

        $searchEngine = $suggester->searchEngine();
        $engineAdapter = $searchEngine->engineAdapter();
        if (!$engineAdapter instanceof \SearchSolr\EngineAdapter\Solarium) {
            $this->logger->err(
                'Suggester #{id} is not a Solr engine.', // @translate
                ['id' => $suggesterId]
            );
            return;
        }

        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $engineAdapter->getSolrCore();
        if (!$solrCore) {
            $this->logger->err('Solr core not found.'); // @translate
            return;
        }

        // Resolve fields and options from suggester settings.
        $settings = $suggester->settings();

        $solrFields = $settings['solr_fields'] ?? [];
        if (empty($solrFields) && !empty($settings['solr_field'])) {
            $solrFields = [$settings['solr_field']];
        }

        // Auto-create suggest_txt field if selected but missing.
        if (in_array('suggest_txt', $solrFields)) {
            $result = $solrCore->ensureSuggestField();
            if ($result !== true) {
                $this->logger->err(
                    'Cannot create suggest_txt: {error}', // @translate
                    ['error' => is_string($result) ? $result : 'unknown']
                );
                return;
            }
        }

        // Resolve "auto": all stored text and string fields, with dedup.
        if (empty($solrFields) || in_array('auto', $solrFields)) {
            $solrFields = array_keys($this->getSolrFieldsForSuggester($solrCore));
        }

        if (empty($solrFields)) {
            $this->logger->warn('No solr fields provided.'); // @translate
            return;
        }

        $baseSuggesterName = $settings['solr_suggester_name'] ?? '';
        if (empty($baseSuggesterName)) {
            $baseSuggesterName = 'omeka_' . preg_replace('/[^a-z0-9_]/i', '_', strtolower($suggester->name()));
        }

        $lookupImpl = $settings['solr_lookup_implementation'] ?? 'AnalyzingInfixLookupFactory';
        $buildOnCommit = empty($settings['solr_skip_build_on_commit']);

        $timeStart = microtime(true);
        $totalFields = count($solrFields);
        $this->logger->notice(
            'Starting creating {total} suggesters for "{name}".', // @translate
            ['total' => $totalFields, 'name' => $baseSuggesterName]
        );

        // 1. Collect all suggester definitions.
        $suggesterDefs = [];
        foreach (array_values($solrFields) as $solrField) {
            $suggesterName = $totalFields === 1
                ? $baseSuggesterName
                : $baseSuggesterName . '_' . preg_replace('/[^a-z0-9_]/i', '_', $solrField);
            $suggesterDefs[] = [
                'name' => $suggesterName,
                'field' => $solrField,
                'lookupImpl' => $lookupImpl,
                'buildOnCommit' => $buildOnCommit,
            ];
        }

        // The component name groups all suggesters.
        $componentName = $baseSuggesterName . '_suggest';

        // 2. Create/update a single searchComponent with all suggesters.
        $this->logger->info(
            'Sending {count} suggesters to Solr component "{component}".', // @translate
            ['count' => count($suggesterDefs), 'component' => $componentName]
        );
        $result = $solrCore->updateSuggestComponent(
            $suggesterDefs,
            $componentName
        );
        if ($result !== true) {
            $this->logger->err(
                'Failed to create suggest component: {error}', // @translate
                ['error' => is_string($result) ? $result : 'unknown']
            );
            return;
        }

        // 3. Update the /suggest handler to reference this component.
        $result = $solrCore->updateSuggestHandler($componentName);
        if ($result !== true) {
            $this->logger->err(
                'Failed to update suggest handler: {error}', // @translate
                ['error' => is_string($result) ? $result : 'unknown']
            );
            return;
        }

        // 4. Build all dictionaries in a single Solr request.
        // Solr builds them sequentially in one thread, avoiding
        // lock conflicts between suggesters.
        if ($this->shouldStop()) {
            $this->logger->warn('Stopped before build phase.'); // @translate
            return;
        }

        $this->logger->info(
            'Building all {total} dictionaries.', // @translate
            ['total' => count($suggesterDefs)]
        );

        $names = array_column($suggesterDefs, 'name');
        $success = $solrCore->buildSuggester($names);

        $timeTotal = (int) (microtime(true) - $timeStart);
        if ($success) {
            $this->logger->notice(
                'All {total} dictionaries built. Duration: {duration} seconds.', // @translate
                [
                    'total' => count($suggesterDefs),
                    'duration' => $timeTotal,
                ]
            );
        } else {
            $this->logger->err(
                'Failed to build dictionaries. Duration: {duration} seconds.', // @translate
                ['duration' => $timeTotal]
            );
        }
    }

    /**
     * Get stored Solr fields suitable for suggestions, with dedup.
     *
     * Skips _ss/_s when _txt exists for the same property prefix.
     *
     * @return array Field names as keys, labels as values.
     */
    protected function getSolrFieldsForSuggester(
        \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore
    ): array {
        $allowedSuffixes = ['_txt', '_ss', '_s'];
        $schema = $solrCore->schema();

        $allFields = [];
        $txtPrefixes = [];
        foreach ($solrCore->mapsOrderedByStructure() as $map) {
            $fieldName = $map->fieldName();
            foreach ($allowedSuffixes as $suffix) {
                if (substr($fieldName, -strlen($suffix)) === $suffix) {
                    if (!$schema->getField($fieldName)) {
                        break;
                    }
                    $prefix = substr($fieldName, 0, -strlen($suffix));
                    $allFields[] = [
                        'name' => $fieldName,
                        'suffix' => $suffix,
                        'prefix' => $prefix,
                    ];
                    if ($suffix === '_txt') {
                        $txtPrefixes[$prefix] = true;
                    }
                    break;
                }
            }
        }

        $fields = [];
        foreach ($allFields as $field) {
            if ($field['suffix'] !== '_txt'
                && isset($txtPrefixes[$field['prefix']])
            ) {
                continue;
            }
            $fields[$field['name']] = $field['name'];
        }

        return $fields;
    }
}
