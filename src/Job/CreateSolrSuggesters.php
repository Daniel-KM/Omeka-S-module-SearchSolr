<?php declare(strict_types=1);

namespace SearchSolr\Job;

use Omeka\Job\AbstractJob;

/**
 * Create Solr suggester components and build their dictionaries.
 *
 * This job resolves the Solr fields from the suggester settings, creates one
 * searchComponent per field, updates the /suggest handler, and rebuilds each
 * dictionary.
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

        $options = [
            'lookupImpl' => $settings['solr_lookup_implementation'] ?? 'AnalyzingInfixLookupFactory',
            'buildOnCommit' => empty($settings['solr_skip_build_on_commit']),
            'skipHandler' => true,
        ];

        $timeStart = microtime(true);
        $totalFields = count($solrFields);
        $this->logger->notice(
            'Starting creating {total} suggesters for "{name}".', // @translate
            ['total' => $totalFields, 'name' => $baseSuggesterName]
        );

        // 1. Create all searchComponents.
        $createdNames = [];
        $errors = 0;
        foreach (array_values($solrFields) as $index => $solrField) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'Stopped by user after {count}/{total} components.', // @translate
                    ['count' => count($createdNames), 'total' => $totalFields]
                );
                break;
            }

            $suggesterName = $totalFields === 1
                ? $baseSuggesterName
                : $baseSuggesterName . '_' . preg_replace('/[^a-z0-9_]/i', '_', $solrField);

            $result = $solrCore->createSuggester($suggesterName, $solrField, $options);
            if ($result === true) {
                $createdNames[] = $suggesterName;
            } else {
                ++$errors;
                $this->logger->err(
                    'Failed to create "{name}": {error}', // @translate
                    ['name' => $suggesterName, 'error' => $result]
                );
            }

            if (($index + 1) % 50 === 0) {
                $this->logger->info(
                    '{count}/{total} components created.', // @translate
                    ['count' => $index + 1, 'total' => $totalFields]
                );
            }
        }

        if (empty($createdNames)) {
            $this->logger->err('No suggesters were created.'); // @translate
            return;
        }

        // 2. Update the /suggest handler with all component names.
        $this->logger->info(
            'Updating /suggest handler with {count} components…', // @translate
            ['count' => count($createdNames)]
        );
        $result = $solrCore->updateSuggestHandler($createdNames);
        if ($result !== true) {
            $this->logger->err(
                'Failed to update suggest handler: {error}', // @translate
                ['error' => is_string($result) ? $result : 'unknown']
            );
        }

        // 3. Build each dictionary.
        if ($this->shouldStop()) {
            $this->logger->warn('Stopped before build phase.'); // @translate
            return;
        }

        $built = 0;
        $this->logger->info('Starting building dictionaries.'); // @translate
        foreach ($createdNames as $index => $suggesterName) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'Stopped during build after {count}/{total}.', // @translate
                    ['count' => $built, 'total' => count($createdNames)]
                );
                break;
            }

            if ($solrCore->buildSuggester($suggesterName)) {
                ++$built;
            } else {
                $this->logger->warn(
                    'Failed to build "{name}".', // @translate
                    ['name' => $suggesterName]
                );
            }

            if (($index + 1) % 50 === 0) {
                $this->logger->info(
                    '{count}/{total} dictionaries built.', // @translate
                    ['count' => $index + 1, 'total' => count($createdNames)]
                );
            }
        }

        $timeTotal = (int) (microtime(true) - $timeStart);
        $this->logger->notice(
            'Process ended: {created} created, {built} built, {errors} errors. Duration: {duration}.', // @translate
            [
                'created' => count($createdNames),
                'built' => $built,
                'errors' => $errors,
                'duration' => $timeTotal,
            ]
        );
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
