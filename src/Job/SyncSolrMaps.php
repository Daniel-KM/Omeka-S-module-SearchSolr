<?php declare(strict_types=1);

namespace SearchSolr\Job;

use Common\Job\Exception\RuntimeException;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Http\PhpEnvironment\Response;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\Http\RouteMatch;
use Laminas\Stdlib\Parameters;
use Omeka\Job\AbstractJob;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\PsrMessage;

/**
 * Align the maps of a solr core in background, on a big database.
 *
 * The alignment of the controller is run with the options of the form, so the
 * process is the same, and its messages are logged.
 *
 * @see \SearchSolr\Controller\Admin\CoreController::syncMapsAction()
 */
class SyncSolrMaps extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('searchsolr/sync_maps/job_' . $this->job->getId());
        $logger->addProcessor($referenceIdProcessor);

        $solrCoreId = (int) $this->getArg('solr_core_id');
        if (!$solrCoreId) {
            throw new RuntimeException(
                'Missing solr_core_id argument.' // @translate
            );
        }

        $request = new Request();
        $request
            ->setMethod('GET')
            ->setQuery(new Parameters([
                'scope' => 'configs',
                'sources' => array_values((array) $this->getArg('sources', [])),
                'clean' => $this->getArg('clean') ? '1' : '',
                'multilingual' => $this->getArg('multilingual') ? '1' : '',
                'max_cardinality' => (int) $this->getArg('max_cardinality', 100),
                'mode' => $this->getArg('audit') ? 'audit' : 'sync',
            ]));

        $routeMatch = new RouteMatch([
            '__NAMESPACE__' => 'SearchSolr\Controller\Admin',
            '__ADMIN__' => true,
            'controller' => \SearchSolr\Controller\Admin\CoreController::class,
            'action' => 'sync-maps',
            'id' => $solrCoreId,
        ]);
        $routeMatch->setMatchedRouteName('admin/search-manager/solr/core-id');

        $response = new Response();
        $event = new MvcEvent();
        $event
            ->setApplication($services->get('Application'))
            ->setRouter($services->get('Router'))
            ->setRequest($request)
            ->setResponse($response)
            ->setRouteMatch($routeMatch);

        /** @var \SearchSolr\Controller\Admin\CoreController $controller */
        $controller = $services->get('ControllerManager')
            ->get(\SearchSolr\Controller\Admin\CoreController::class);
        $controller->setEvent($event);

        $logger->notice(
            'Start of the alignment of the maps of the solr core #{solr_core_id}.', // @translate
            ['solr_core_id' => $solrCoreId]
        );

        $exception = null;
        try {
            $controller->dispatch($request, $response);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $levels = [
            Messenger::ERROR => 'err',
            Messenger::SUCCESS => 'notice',
            Messenger::WARNING => 'warn',
            Messenger::NOTICE => 'notice',
        ];
        foreach ($messenger->get() as $type => $messages) {
            $level = $levels[$type] ?? 'info';
            foreach ($messages as $message) {
                if ($message instanceof PsrMessage) {
                    $logger->$level(strip_tags($message->getMessage()), $message->getContext());
                } else {
                    $logger->$level(strip_tags((string) $message));
                }
            }
        }
        $messenger->clear();

        if ($exception) {
            throw new RuntimeException(
                'The alignment of the maps failed: {message}', // @translate
                ['message' => $exception->getMessage()],
                0,
                $exception
            );
        }

        $logger->notice('End of the alignment of the maps.'); // @translate
    }
}
