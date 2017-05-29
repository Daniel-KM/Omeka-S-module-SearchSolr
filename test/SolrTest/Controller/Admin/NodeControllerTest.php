<?php

namespace SolrTest\Controller\Admin;

use SolrTest\Controller\SolrControllerTestCase;

class NodeControllerTest extends SolrControllerTestCase
{
    public function testBrowseAction()
    {
        $this->dispatch('/admin/solr');
        $this->assertResponseStatusCode(200);

        $this->assertXpathQueryContentRegex('//table//td[1]', '/default/');
    }

    public function testAddGetAction()
    {
        $this->dispatch('/admin/solr/node/add');
        $this->assertResponseStatusCode(200);

        $this->assertQuery('input[name="o:name"]');
        $this->assertQuery('input[name="o:settings[client][hostname]"]');
        $this->assertQuery('input[name="o:settings[client][port]"]');
        $this->assertQuery('input[name="o:settings[client][path]"]');
        $this->assertQuery('input[name="o:settings[resource_name_field]"]');
    }

    public function testAddPostAction()
    {
        $forms = $this->getServiceLocator()->get('FormElementManager');
        $form = $forms->get('Solr\Form\Admin\SolrNodeForm');
        $this->dispatch('/admin/solr/node/add', 'POST', [
            'o:name' => 'TestNode2',
            'o:settings' => [
                'client' => [
                    'hostname' => 'example.com',
                    'port' => '8983',
                    'path' => 'solr/test_node2',
                ],
                'resource_name_field' => 'resource_name_s',
            ],
            'csrf' => $form->get('csrf')->getValue(),
        ]);
        $this->assertRedirectTo('/admin/solr');
    }

    public function testEditAction()
    {
        $this->dispatch($this->solrNode->adminUrl('edit'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteConfirmAction()
    {
        $this->dispatch($this->solrNode->adminUrl('delete-confirm'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteAction()
    {
        $solrNode3 = $this->api()->create('solr_nodes', [
            'o:name' => 'TestNode3',
            'o:settings' => [
                'client' => [
                    'hostname' => 'localhost',
                    'port' => '8983',
                    'path' => 'solr/test_node3',
                ],
                'resource_name_field' => 'resource_name_s',
            ],
        ])->getContent();
        $forms = $this->getServiceLocator()->get('FormElementManager');
        $form = $forms->get(\Omeka\Form\ConfirmForm::class);
        $this->dispatch($solrNode3->adminUrl('delete'), 'POST', [
            'confirmform_csrf' => $form->get('confirmform_csrf')->getValue(),
        ]);
        $this->assertRedirectTo('/admin/solr');
    }
}
