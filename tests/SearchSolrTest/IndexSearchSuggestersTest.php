<?php declare(strict_types=1);

namespace SearchSolrTest;

/**
 * The indexing of resources does not rebuild the Solr suggesters, that are
 * built by Solr on commit or by the scheduled task. Only a full reindexation
 * rebuilds them, when they are not built on commit.
 *
 * @see \AdvancedSearch\Job\IndexSearch::reindexSuggesters()
 *
 * @group suggester
 * @group solr
 */
class IndexSearchSuggestersTest extends TestCase
{
    use SearchSolrTestTrait;

    /**
     * @var int[]
     */
    protected $suggesterIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    public function tearDown(): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach ($this->suggesterIds as $suggesterId) {
            try {
                $api->delete('search_suggesters', $suggesterId);
            } catch (\Throwable $e) {
            }
        }
        $this->cleanupResources();
        parent::tearDown();
    }

    public function testIndexingOfResourcesDoesNotRebuildSuggesters(): void
    {
        $engine = $this->createEngineWithSuggesters('Partial');
        $this->assertSame([], $this->reindexSuggesters($engine, [1, 2]));
    }

    public function testFullReindexationRebuildsOnlySuggestersNotBuiltOnCommit(): void
    {
        [$engine, $scheduled] = $this->createEngineWithSuggesters('Full', true);
        $dispatched = $this->reindexSuggesters($engine, []);
        $this->assertSame([[\SearchSolr\Job\CreateSolrSuggesters::class, ['search_suggester_id' => $scheduled]]], $dispatched);
    }

    public function testOldInvertedOptionIsSupported(): void
    {
        $solrCore = $this->createSolrCore('TestCoreOldOption');
        $engine = $this->createSolrSearchEngine('TestEngineOldOption', $solrCore->id());
        $skipped = $this->createSuggester($engine->id(), ['solr_skip_build_on_commit' => true]);
        $this->createSuggester($engine->id(), ['solr_skip_build_on_commit' => false]);
        $dispatched = $this->reindexSuggesters($engine, []);
        $this->assertSame([[\SearchSolr\Job\CreateSolrSuggesters::class, ['search_suggester_id' => $skipped]]], $dispatched);
    }

    /**
     * @return \AdvancedSearch\Api\Representation\SearchEngineRepresentation|array
     */
    protected function createEngineWithSuggesters(string $name, bool $withIds = false)
    {
        $solrCore = $this->createSolrCore('TestCore' . $name);
        $engine = $this->createSolrSearchEngine('TestEngine' . $name, $solrCore->id());
        $scheduled = $this->createSuggester($engine->id(), ['solr_build_on_commit' => false]);
        $this->createSuggester($engine->id(), ['solr_build_on_commit' => true]);
        return $withIds ? [$engine, $scheduled] : $engine;
    }

    protected function createSuggester(int $engineId, array $settings): int
    {
        $suggester = $this->getServiceLocator()->get('Omeka\ApiManager')->create('search_suggesters', [
            'o:name' => 'Test index suggester',
            'o:search_engine' => ['o:id' => $engineId],
            'o:settings' => ['solr_fields' => ['suggest_txt']] + $settings,
        ])->getContent();
        $this->suggesterIds[] = $suggester->id();
        return $suggester->id();
    }

    /**
     * Run the end of the indexing job with a dispatcher that records the jobs.
     */
    protected function reindexSuggesters($engine, array $resourceIds): array
    {
        $services = $this->getServiceLocator();
        $dispatcher = $services->get('Omeka\Job\Dispatcher');
        $recorder = new class extends \Omeka\Job\Dispatcher {
            public $dispatched = [];

            public function __construct()
            {
            }

            public function dispatch($class, $args = null, ?\Omeka\Job\DispatchStrategy\StrategyInterface $strategy = null)
            {
                $this->dispatched[] = [$class, $args];
                return null;
            }
        };
        $services->setAllowOverride(true);
        $services->setService('Omeka\Job\Dispatcher', $recorder);
        try {
            $job = new \AdvancedSearch\Job\IndexSearch(new \Omeka\Entity\Job(), $services);
            foreach ([
                'api' => $services->get('Omeka\ApiManager'),
                'logger' => $services->get('Omeka\Logger'),
                'resourceIds' => $resourceIds,
            ] as $name => $value) {
                $property = new \ReflectionProperty(\AdvancedSearch\Job\IndexSearch::class, $name);
                $property->setAccessible(true);
                $property->setValue($job, $value);
            }
            $method = new \ReflectionMethod(\AdvancedSearch\Job\IndexSearch::class, 'reindexSuggesters');
            $method->setAccessible(true);
            $method->invoke($job, [$engine]);
        } finally {
            $services->setService('Omeka\Job\Dispatcher', $dispatcher);
            $services->setAllowOverride(false);
        }
        return $recorder->dispatched;
    }
}
