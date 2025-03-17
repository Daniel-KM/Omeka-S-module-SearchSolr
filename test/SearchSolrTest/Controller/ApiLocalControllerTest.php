<?php declare(strict_types=1);

namespace SearchSolr\Test\Controller;

use Omeka\Mvc\Exception\PermissionDeniedException;
use SearchSolrTest\Controller\SearchSolrControllerTestCase;

class ApiLocalControllerTest extends SearchSolrControllerTestCase
{
    public function testApiLocalSolrCoreIsDeniedToAnonymousUsers(): void
    {
        $this->logout();
        $this->expectException(PermissionDeniedException::class);
        $this->dispatch('/api-local/solr_cores');
    }

    public function testApiLocalSolrMapsIsDeniedToAnonymousUsers(): void
    {
        $this->logout();
        $this->expectException(PermissionDeniedException::class);
        $this->dispatch('/api-local/solr_maps');
    }

    public function testApiLocalSolrCoresIsAllowedToAdmin(): void
    {
        $this->dispatch('/api-local/solr_cores');
        $this->assertResponseStatusCode(200);
    }

    public function testApiLocalSolrMapsIsAllowedToAdmin(): void
    {
        $this->dispatch('/api-local/solr_maps');
        $this->assertResponseStatusCode(200);
    }
}
