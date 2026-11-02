<?php declare(strict_types=1);

namespace SearchSolrTest;

use Laminas\EventManager\Event;

/**
 * Tests for the scheduled rebuild of the solr suggesters.
 *
 * @group suggester
 * @group solr
 */
class CronSuggestersTest extends TestCase
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

    public function testCronTaskRebuildsOnlySuggestersNotBuiltOnCommit(): void
    {
        $solrCore = $this->createSolrCore('TestCoreCron');
        $engine = $this->createSolrSearchEngine('TestEngineCron', $solrCore->id());
        $scheduled = $this->createSuggester($engine->id(), false);
        $building = $this->createSuggester($engine->id(), true);

        $this->getServiceLocator()->get('Omeka\Settings')->set('searchsolr_cron_last', null);
        $dispatched = $this->triggerCronTask('solr_suggesters', $handled);

        $this->assertTrue($handled);
        $this->assertCount(1, $dispatched);
        [$class, $args] = $dispatched[0];
        $this->assertSame(\SearchSolr\Job\CreateSolrSuggesters::class, $class);
        $this->assertContains($scheduled, $args['search_suggester_ids']);
        $this->assertNotContains($building, $args['search_suggester_ids']);
    }

    public function testCronTaskSkipsWhenNoResourceChanged(): void
    {
        $solrCore = $this->createSolrCore('TestCoreCronUnchanged');
        $engine = $this->createSolrSearchEngine('TestEngineCronUnchanged', $solrCore->id());
        $this->createSuggester($engine->id(), false);

        $this->getServiceLocator()->get('Omeka\Settings')->set('searchsolr_cron_last', time() + 60);
        $dispatched = $this->triggerCronTask('solr_suggesters', $handled);

        $this->assertTrue($handled);
        $this->assertSame([], $dispatched);
    }

    public function testCronTaskRebuildsAfterResourceDeleted(): void
    {
        $solrCore = $this->createSolrCore('TestCoreCronDeleted');
        $engine = $this->createSolrSearchEngine('TestEngineCronDeleted', $solrCore->id());
        $scheduled = $this->createSuggester($engine->id(), false);

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $item = $this->createItem([]);
        $settings->set('searchsolr_cron_changed', false);
        $services->get('Omeka\ApiManager')->delete('items', $item->id());
        $this->assertTrue((bool) $settings->get('searchsolr_cron_changed'));

        $settings->set('searchsolr_cron_last', time() + 60);
        $dispatched = $this->triggerCronTask('solr_suggesters', $handled);

        $this->assertCount(1, $dispatched);
        $this->assertContains($scheduled, $dispatched[0][1]['search_suggester_ids']);
        $this->assertFalse((bool) $settings->get('searchsolr_cron_changed'));
    }

    public function testCronIgnoresOtherTasks(): void
    {
        $dispatched = $this->triggerCronTask('search_index', $handled);
        $this->assertFalse($handled);
        $this->assertSame([], $dispatched);
    }

    protected function createSuggester(int $engineId, bool $buildOnCommit): int
    {
        $suggester = $this->getServiceLocator()->get('Omeka\ApiManager')->create('search_suggesters', [
            'o:name' => 'Test cron suggester',
            'o:search_engine' => ['o:id' => $engineId],
            'o:settings' => [
                'solr_fields' => ['suggest_txt'],
                'solr_build_on_commit' => $buildOnCommit,
            ],
        ])->getContent();
        $this->suggesterIds[] = $suggester->id();
        return $suggester->id();
    }

    /**
     * Trigger the cron task with a dispatcher that records the jobs.
     */
    protected function triggerCronTask(string $taskId, ?bool &$handled): array
    {
        $services = $this->getServiceLocator();

        // The jobs dispatched by other tests are never processed in the test
        // database, so they would be seen as running.
        $services->get('Omeka\Connection')->executeStatement(
            'UPDATE `job` SET `status` = ? WHERE `class` = ? AND `status` IN (?, ?)',
            [
                \Omeka\Entity\Job::STATUS_STOPPED,
                \SearchSolr\Job\CreateSolrSuggesters::class,
                \Omeka\Entity\Job::STATUS_STARTING,
                \Omeka\Entity\Job::STATUS_IN_PROGRESS,
            ]
        );

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
            $module = new \SearchSolr\Module();
            $module->setServiceLocator($services);
            $event = new Event('easyadmin.cron.execute', null, ['task_id' => $taskId, 'handled' => false]);
            $module->handleCronExecute($event);
            $handled = (bool) $event->getParam('handled');
        } finally {
            $services->setService('Omeka\Job\Dispatcher', $dispatcher);
            $services->setAllowOverride(false);
        }
        return $recorder->dispatched;
    }
}
