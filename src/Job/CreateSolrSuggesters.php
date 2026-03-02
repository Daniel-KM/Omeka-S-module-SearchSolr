<?php declare(strict_types=1);

namespace SearchSolr\Job;

use Omeka\Job\AbstractJob;

/**
 * Create Solr suggester components and build their dictionaries.
 *
 * This job handles the heavy async work of creating one Solr searchComponent
 * per field, updating the /suggest handler, and rebuilding each dictionary.
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

        $solrFields = $this->getArg('solr_fields', []);
        $baseSuggesterName = $this->getArg('base_suggester_name', 'omeka_suggester');
        $options = $this->getArg('options', []);

        if (empty($solrFields)) {
            $this->logger->warn('No Solr fields provided.'); // @translate
            return;
        }

        $timeStart = microtime(true);
        $totalFields = count($solrFields);
        $this->logger->notice(
            'Starting creating {total} suggesters for "{name}".', // @translate
            ['total' => $totalFields, 'name' => $baseSuggesterName]
        );

        // 1. Create all searchComponents (skip handler update per field).
        $options['skipHandler'] = true;
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

            // Log progress every 50 fields.
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
            'Process ended. {created} created, {built} built, {errors} errors. Duration: {duration}.', // @translate
            [
                'created' => count($createdNames),
                'built' => $built,
                'errors' => $errors,
                'duration' => $timeTotal,
            ]
        );
    }
}
