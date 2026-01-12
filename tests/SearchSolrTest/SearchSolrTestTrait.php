<?php declare(strict_types=1);

namespace SearchSolrTest;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Entity\Job;

/**
 * Shared test helpers for SearchSolr module tests.
 *
 * This trait is designed to work with CommonTest\AbstractHttpControllerTestCase
 * and relies on its api(), getApplicationServiceLocator(), loginAsAdmin(), and logout() methods.
 */
trait SearchSolrTestTrait
{
    /**
     * @var array List of created resource IDs for cleanup.
     */
    protected $createdResources = [];

    /**
     * @var array List of created Solr core IDs for cleanup.
     */
    protected $createdSolrCores = [];

    /**
     * @var array List of created Solr map IDs for cleanup.
     */
    protected $createdSolrMaps = [];

    /**
     * @var array List of created search engine IDs for cleanup.
     */
    protected $createdSearchEngines = [];

    /**
     * Get the service locator (alias for getApplicationServiceLocator).
     */
    protected function getServiceLocator()
    {
        return $this->getApplicationServiceLocator();
    }

    /**
     * Login as admin using adapter (avoids static caching issues with Doctrine).
     *
     * Use this in setUp() to ensure the User entity is properly managed.
     */
    protected function loginAsAdmin(): void
    {
        $services = $this->getApplicationServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Create a test item.
     *
     * @param array $data Item data with property terms as keys.
     * @return ItemRepresentation
     */
    protected function createItem(array $data): ItemRepresentation
    {
        // Convert property terms to proper format if needed.
        $itemData = [];
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');

        foreach ($data as $term => $values) {
            // Skip non-property fields.
            if (strpos($term, ':') === false) {
                $itemData[$term] = $values;
                continue;
            }

            $propertyId = $easyMeta->propertyId($term);
            if (!$propertyId) {
                continue;
            }

            $itemData[$term] = [];
            foreach ($values as $value) {
                $valueData = [
                    'type' => $value['type'] ?? 'literal',
                    'property_id' => $propertyId,
                ];
                if (isset($value['@value'])) {
                    $valueData['@value'] = $value['@value'];
                }
                if (isset($value['@id'])) {
                    $valueData['@id'] = $value['@id'];
                }
                if (isset($value['o:label'])) {
                    $valueData['o:label'] = $value['o:label'];
                }
                $itemData[$term][] = $valueData;
            }
        }

        $response = $this->api()->create('items', $itemData);
        $item = $response->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        return $item;
    }

    /**
     * Create a Solr core in the database.
     *
     * @param string $name Core name.
     * @param array $settings Core settings.
     * @return \SearchSolr\Api\Representation\SolrCoreRepresentation
     */
    protected function createSolrCore(string $name, array $settings = [])
    {
        $defaultSettings = [
            'client' => [
                'scheme' => 'http',
                'host' => 'localhost',
                'port' => 8983,
                'path' => '/',
                'core' => 'omeka',
            ],
            'resource_languages' => '',
        ];
        $settings = array_merge($defaultSettings, $settings);

        $response = $this->api()->create('solr_cores', [
            'o:name' => $name,
            'o:settings' => $settings,
        ]);
        $core = $response->getContent();
        $this->createdSolrCores[] = $core->id();

        return $core;
    }

    /**
     * Create a Solr map in the database.
     *
     * @param int $coreId Solr core ID.
     * @param string $resourceName Resource name (items, item_sets, etc.).
     * @param string $fieldName Solr field name.
     * @param string $source Source (property term or special field).
     * @param array $settings Map settings.
     * @return \SearchSolr\Api\Representation\SolrMapRepresentation
     */
    protected function createSolrMap(int $coreId, string $resourceName, string $fieldName, string $source, array $settings = [])
    {
        $response = $this->api()->create('solr_maps', [
            'o:solr_core' => ['o:id' => $coreId],
            'o:resource_name' => $resourceName,
            'o:field_name' => $fieldName,
            'o:source' => $source,
            'o:settings' => $settings,
        ]);
        $map = $response->getContent();
        $this->createdSolrMaps[] = $map->id();

        return $map;
    }

    /**
     * Create a search engine with Solr adapter.
     *
     * @param string $name Engine name.
     * @param int $coreId Solr core ID.
     * @param array $settings Engine settings.
     * @return \AdvancedSearch\Api\Representation\SearchEngineRepresentation
     */
    protected function createSolrSearchEngine(string $name, int $coreId, array $settings = [])
    {
        $defaultSettings = [
            'adapter' => 'solarium',
            'solr_core_id' => $coreId,
        ];
        $settings = array_merge($defaultSettings, $settings);

        $response = $this->api()->create('search_engines', [
            'o:name' => $name,
            'o:adapter' => 'solarium',
            'o:settings' => $settings,
        ]);
        $engine = $response->getContent();
        $this->createdSearchEngines[] = $engine->id();

        return $engine;
    }

    /**
     * Get a fixture file content.
     *
     * @param string $name Fixture filename.
     * @return string
     */
    protected function getFixture(string $name): string
    {
        $path = dirname(__DIR__) . '/fixtures/' . $name;
        if (!file_exists($path)) {
            throw new \RuntimeException("Fixture not found: $path");
        }
        return file_get_contents($path);
    }

    /**
     * Get the path to the fixtures directory.
     */
    protected function getFixturesPath(): string
    {
        return dirname(__DIR__) . '/fixtures';
    }

    /**
     * @var \Exception|null Last exception from job execution.
     */
    protected $lastJobException;

    /**
     * Run a job synchronously for testing.
     *
     * @param string $jobClass Job class name.
     * @param array $args Job arguments.
     * @param bool $expectError If true, don't rethrow exceptions (for testing error cases).
     * @return Job
     */
    protected function runJob(string $jobClass, array $args, bool $expectError = false): Job
    {
        $this->lastJobException = null;
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $auth = $services->get('Omeka\AuthenticationService');

        // Create job entity.
        $job = new Job();
        $job->setStatus(Job::STATUS_STARTING);
        $job->setClass($jobClass);
        $job->setArgs($args);
        $job->setOwner($auth->getIdentity());

        $entityManager->persist($job);
        $entityManager->flush();

        // Run job synchronously.
        $jobClass = $job->getClass();
        $jobInstance = new $jobClass($job, $services);
        $job->setStatus(Job::STATUS_IN_PROGRESS);
        $job->setStarted(new \DateTime('now'));
        $entityManager->flush();

        try {
            $jobInstance->perform();
            if ($job->getStatus() === Job::STATUS_IN_PROGRESS) {
                $job->setStatus(Job::STATUS_COMPLETED);
            }
        } catch (\Throwable $e) {
            $this->lastJobException = $e;
            $job->setStatus(Job::STATUS_ERROR);
            if (!$expectError) {
                throw $e;
            }
        }

        $job->setEnded(new \DateTime('now'));
        $entityManager->flush();

        return $job;
    }

    /**
     * Get the last exception from job execution (for debugging).
     */
    protected function getLastJobException(): ?\Exception
    {
        return $this->lastJobException;
    }

    /**
     * Clean up created resources after test.
     */
    protected function cleanupResources(): void
    {
        // Delete created items.
        foreach ($this->createdResources as $resource) {
            try {
                $this->api()->delete($resource['type'], $resource['id']);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdResources = [];

        // Delete created Solr maps.
        foreach ($this->createdSolrMaps as $mapId) {
            try {
                $this->api()->delete('solr_maps', $mapId);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdSolrMaps = [];

        // Delete created search engines.
        foreach ($this->createdSearchEngines as $engineId) {
            try {
                $this->api()->delete('search_engines', $engineId);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdSearchEngines = [];

        // Delete created Solr cores.
        foreach ($this->createdSolrCores as $coreId) {
            try {
                $this->api()->delete('solr_cores', $coreId);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdSolrCores = [];
    }
}
