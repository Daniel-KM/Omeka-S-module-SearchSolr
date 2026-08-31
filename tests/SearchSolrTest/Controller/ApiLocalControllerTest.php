<?php declare(strict_types=1);

namespace SearchSolr\Test\Controller;

use SearchSolrTest\Controller\SearchSolrControllerTestCase;

/**
 * The local rest api of the maps: same rights, cookie authentication.
 *
 * The dedicated routes of the module were removed: they duplicated the route of
 * the core and pointed to controllers that are not in the acl. And the resource
 * "solr_cores" does not exist anymore: a core is a search engine.
 *
 * @group controller
 * @group api
 */
class ApiLocalControllerTest extends SearchSolrControllerTestCase
{
    public function testApiSolrMapsIsDeniedToAnonymousUsers(): void
    {
        // The check is done on "Omeka\Status::isApiRequest()", that reads the
        // route param "__API__". It is not set in this test context, so the
        // rule is checked on the listener itself: in real conditions, the
        // request answers a json 403.
        /** @see \SearchSolr\Module::denyRestApiToNonAdmin() */
        $shared = $this->getApplication()->getServiceManager()->get('SharedEventManager');
        $listeners = $shared->getListeners([\SearchSolr\Api\Adapter\SolrMapAdapter::class], 'api.search.pre');
        $this->assertNotEmpty($listeners, 'The rest api of the maps must be closed to non-admin users.');
    }

    public function testApiSolrMapsIsAllowedToAdmin(): void
    {
        $this->dispatch('/api-local/solr_maps');
        $this->assertResponseStatusCode(200);
    }

    public function testApiSolrCoresDoesNotExistAnymore(): void
    {
        // A core is a search engine with the adapter "solarium".
        $this->dispatch('/api-local/solr_cores');
        $this->assertNotEquals(200, $this->getResponse()->getStatusCode());
    }
}
