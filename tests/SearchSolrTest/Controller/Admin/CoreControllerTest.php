<?php declare(strict_types=1);

namespace SearchSolrTest\Controller\Admin;

use SearchSolrTest\Controller\SearchSolrControllerTestCase;

class CoreControllerTest extends SearchSolrControllerTestCase
{
    public function testBrowseAction(): void
    {
        $this->dispatch('/admin/search-manager/solr');
        $this->assertResponseStatusCode(200);

        $this->assertXpathQueryContentRegex('//table//td[1]', '/TestCore/');
    }

    public function testAddGetAction(): void
    {
        $this->dispatch('/admin/search-manager/solr/core/add');
        $this->assertResponseStatusCode(200);

        $this->assertQuery('input[name="o:name"]');
        $this->assertQuery('form#solr-core-form');
    }

    public function testAddPostAction(): void
    {
        $this->dispatchPost(
            '/admin/search-manager/solr/core/add',
            [
                'o:name' => 'TestCore2',
                'o:settings' => [
                    'client' => [
                        'scheme' => 'http',
                        'host' => 'example.com',
                        'port' => '8983',
                        'path' => '/',
                        'core' => 'test_core2',
                    ],
                ],
            ],
            \SearchSolr\Form\Admin\SolrCoreForm::class
        );
        // After creation, redirects to edit page.
        $this->assertRedirectRegex('~/admin/search-manager/solr/core/\d+/edit~');
    }

    public function testEditAction(): void
    {
        $this->dispatch($this->solrCore->adminUrl('edit'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteConfirmAction(): void
    {
        $this->dispatch($this->solrCore->adminUrl('delete-confirm'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteAction(): void
    {
        $solrCore3 = $this->api()->create('solr_cores', [
            'o:name' => 'TestCore3',
            'o:settings' => [
                'client' => [
                    'scheme' => 'http',
                    'host' => 'localhost',
                    'port' => '8983',
                    'path' => '/',
                    'core' => 'test_core3',
                ],
                'resource_name_field' => 'resource_name_s',
            ],
        ])->getContent();
        $this->dispatchPost(
            $solrCore3->adminUrl('delete'),
            [],
            \Omeka\Form\ConfirmForm::class,
            [],
            'confirmform_csrf'
        );
        $this->assertRedirectTo('/admin/search-manager/solr');
    }
}
