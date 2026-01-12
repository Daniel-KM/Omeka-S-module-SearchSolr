<?php declare(strict_types=1);

namespace SearchSolr\Test\Controller;

use Omeka\Mvc\Exception\PermissionDeniedException;
use SearchSolrTest\Controller\SearchSolrControllerTestCase;

class ApiControllerTest extends SearchSolrControllerTestCase
{
    public function testApiSolrCoreIsDeniedToAnonymousUsers(): void
    {
        $this->expectException(PermissionDeniedException::class);
        $this->dispatchUnauthenticated('/api/solr_cores');
    }

    public function testApiSolrMapsIsDeniedToAnonymousUsers(): void
    {
        $this->expectException(PermissionDeniedException::class);
        $this->dispatchUnauthenticated('/api/solr_maps');
    }

    public function testApiSolrCoresIsAllowedToAdmin(): void
    {
        $this->dispatch('/api/solr_cores');
        $this->assertResponseStatusCode(200);
    }

    public function testApiSolrMapsIsAllowedToAdmin(): void
    {
        $this->dispatch('/api/solr_maps');
        $this->assertResponseStatusCode(200);
    }
}
