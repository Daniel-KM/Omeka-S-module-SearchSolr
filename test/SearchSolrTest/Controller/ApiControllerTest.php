<?php declare(strict_types=1);

namespace SearchSolr\Test\Controller;

use Omeka\Mvc\Exception\PermissionDeniedException;
use SearchSolrTest\Controller\SearchSolrControllerTestCase;

class ApiControllerTest extends SearchSolrControllerTestCase
{
    public function testApiSolrCoreIsDeniedToAnonymousUsers()
    {
        $this->logout();
        $this->expectException(PermissionDeniedException::class);
        $this->dispatch('/api/solr_cores');
    }

    public function testApiSolrMapsIsDeniedToAnonymousUsers()
    {
        $this->logout();
        $this->expectException(PermissionDeniedException::class);
        $this->dispatch('/api/solr_maps');
    }

    public function testApiSolrCoresIsAllowedToAdmin()
    {
        $this->dispatch('/api/solr_cores');
        $this->assertResponseStatusCode(200);
    }

    public function testApiSolrMapsIsAllowedToAdmin()
    {
        $this->dispatch('/api/solr_maps');
        $this->assertResponseStatusCode(200);
    }
}
