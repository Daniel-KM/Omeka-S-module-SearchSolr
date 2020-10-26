<?php declare(strict_types=1);

namespace SearchSolrTest\Controller\Admin;

use SolrTest\Controller\SolrControllerTestCase;

class CoreControllerTest extends SolrControllerTestCase
{
    public function testBrowseAction(): void
    {
        $this->dispatch('/admin/search/solr');
        $this->assertResponseStatusCode(200);

        $this->assertXpathQueryContentRegex('//table//td[1]', '/default/');
    }

    public function testAddGetAction(): void
    {
        $this->dispatch('/admin/search/solr/core/add');
        $this->assertResponseStatusCode(200);

        $this->assertQuery('input[name="o:name"]');
        $this->assertQuery('input[name="o:settings[client][host]"]');
        $this->assertQuery('input[name="o:settings[client][port]"]');
        $this->assertQuery('input[name="o:settings[client][core]"]');
        $this->assertQuery('input[name="o:settings[resource_name_field]"]');
    }

    public function testAddPostAction(): void
    {
        $forms = $this->getServiceLocator()->get('FormElementManager');
        $form = $forms->get('Solr\Form\Admin\SolrCoreForm');
        $this->dispatch('/admin/search/solr/core/add', 'POST', [
            'o:name' => 'TestCore2',
            'o:settings' => [
                'client' => [
                    'host' => 'example.com',
                    'port' => '8983',
                    'path' => '/',
                    'core' => 'test_core2',
                ],
                'resource_name_field' => 'resource_name_s',
            ],
            'csrf' => $form->get('csrf')->getValue(),
        ]);
        $this->assertRedirectTo('/admin/search/solr');
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
                    'host' => 'localhost',
                    'port' => '8983',
                    'path' => '/',
                    'core' => 'test_core3',
                ],
                'resource_name_field' => 'resource_name_s',
            ],
        ])->getContent();
        $forms = $this->getServiceLocator()->get('FormElementManager');
        $form = $forms->get(\Omeka\Form\ConfirmForm::class);
        $this->dispatch($solrCore3->adminUrl('delete'), 'POST', [
            'confirmform_csrf' => $form->get('confirmform_csrf')->getValue(),
        ]);
        $this->assertRedirectTo('/admin/search/solr');
    }
}
