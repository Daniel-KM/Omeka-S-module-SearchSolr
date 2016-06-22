<?php

namespace SolrTest\Controller\Admin;

require_once __DIR__ . '/../SolrControllerTestCase.php';

use SolrTest\Controller\SolrControllerTestCase;

class FieldControllerTest extends SolrControllerTestCase
{
    public function testBrowseAction()
    {
        $this->dispatch($this->solrNode->fieldUrl('browse'));
        $this->assertResponseStatusCode(200);
    }

    public function testAddAction()
    {
        $this->dispatch($this->solrNode->fieldUrl('add'));
        $this->assertResponseStatusCode(200);
    }

    public function testEditAction()
    {
        $this->dispatch($this->solrField->adminUrl('edit'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteConfirmAction()
    {
        $this->dispatch($this->solrField->adminUrl('delete-confirm'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteAction()
    {
        $solrField2 = $this->api()->create('solr_fields', [
            'o:name' => 'solr_field2_t',
            'o:description' => 'SolrField2',
            'o:is_indexed' => '1',
            'o:is_multivalued' => '1',
            'o:solr_node' => [
                'o:id' => $this->solrNode->id(),
            ],
        ])->getContent();

        $forms = $this->getServiceLocator()->get('FormElementManager');
        $form = $forms->get(\Omeka\Form\ConfirmForm::class);
        $this->dispatch($solrField2->adminUrl('delete'), 'POST', [
            'confirmform_csrf' => $form->get('confirmform_csrf')->getValue(),
        ]);
        $this->assertRedirectTo($this->solrNode->fieldUrl('browse'));
    }
}
