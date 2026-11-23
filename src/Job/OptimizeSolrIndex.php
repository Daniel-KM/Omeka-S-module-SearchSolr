<?php declare(strict_types=1);

namespace SearchSolr\Job;

use Common\Job\Exception\RuntimeException;
use Common\Stdlib\PsrMessage;
use Omeka\Job\AbstractJob;
use Solarium\Core\Client\Adapter\TimeoutAwareInterface;

/**
 * Optimize a Solr core (forceMerge).
 *
 * This rewrites every segment in the current Lucene codec, so it is mainly
 * useful to finalize a migration to a new Solr version or to reclaim the space
 * of deleted documents. It is expensive (full rewrite, up to twice the disk
 * space), so it is run on demand as a background job, never on every reindex.
 */
class OptimizeSolrIndex extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId(
            'searchsolr/optimize/job_' . $this->job->getId()
        );
        $logger->addProcessor($referenceIdProcessor);

        $solrCoreId = (int) $this->getArg('solr_core_id');
        if (!$solrCoreId) {
            throw new RuntimeException(
                'Missing solr_core_id argument.' // @translate
            );
        }

        try {
            /** @var \SearchSolr\Stdlib\SolrCore $solrCore */
            $solrCore = new \SearchSolr\Stdlib\SolrCore($api->read('search_engines', $solrCoreId)->getContent(), $this->getServiceLocator());
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Solr core #{id} not found.', // @translate
                ['id' => $solrCoreId]
            );
        }

        $client = $solrCore->solariumClient();
        if (!$client) {
            throw new RuntimeException(
                'Unable to connect to the Solr core "{name}".', // @translate
                ['name' => $solrCore->name()]
            );
        }

        $maxSegments = (int) $this->getArg('max_segments', 1) ?: 1;

        $logger->info(new PsrMessage(
            'Optimizing Solr core "{name}" (maxSegments={max}). This rewrites all segments and may take a while.', // @translate
            ['name' => $solrCore->name(), 'max' => $maxSegments]
        ));

        // Optimize may trigger expensive segment merges, so use a long timeout.
        $adapter = $client->getAdapter();
        $previousTimeout = $adapter instanceof TimeoutAwareInterface
            ? $adapter->getTimeout()
            : null;
        if ($adapter instanceof TimeoutAwareInterface) {
            $adapter->setTimeout(3600);
        }

        try {
            $update = $client->createUpdate();
            $update->addOptimize(false, true, $maxSegments);
            $client->update($update);
            $logger->notice(new PsrMessage(
                'Solr core "{name}" optimized.', // @translate
                ['name' => $solrCore->name()]
            ));
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Solr optimization failed: {error}', // @translate
                ['error' => $e->getMessage()],
                0,
                $e
            );
        } finally {
            if ($previousTimeout !== null && $adapter instanceof TimeoutAwareInterface) {
                $adapter->setTimeout($previousTimeout);
            }
        }
    }
}
