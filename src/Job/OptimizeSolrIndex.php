<?php declare(strict_types=1);

namespace SearchSolr\Job;

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
            $logger->err(
                'Missing solr_core_id argument.' // @translate
            );
            return;
        }

        try {
            /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
            $solrCore = $api->read('solr_cores', $solrCoreId)->getContent();
        } catch (\Throwable $e) {
            $logger->err(
                'Solr core #{id} not found.', // @translate
                ['id' => $solrCoreId]
            );
            return;
        }

        $client = $solrCore->solariumClient();
        if (!$client) {
            $logger->err(
                'Unable to connect to the Solr core "{name}".', // @translate
                ['name' => $solrCore->name()]
            );
            return;
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
            $logger->err(new PsrMessage(
                'Solr optimization failed: {error}', // @translate
                ['error' => $e->getMessage()]
            ));
        } finally {
            if ($previousTimeout !== null && $adapter instanceof TimeoutAwareInterface) {
                $adapter->setTimeout($previousTimeout);
            }
        }
    }
}
