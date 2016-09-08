<?php

namespace SolrTest\Controller\Admin;

use SolrTest\Controller\SolrControllerTestCase;

class ProfileControllerTest extends SolrControllerTestCase
{
    public function testBrowseAction()
    {
        $this->dispatch($this->solrNode->profileUrl('browse'));
        $this->assertResponseStatusCode(200);
    }

    public function testAddAction()
    {
        $this->dispatch($this->solrNode->profileUrl('add'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteConfirmAction()
    {
        $this->dispatch($this->solrProfile->adminUrl('delete-confirm'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteAction()
    {
        $solrProfile2 = $this->api()->create('solr_profiles', [
            'o:resource_name' => 'item_sets',
            'o:solr_node' => [
                'o:id' => $this->solrNode->id(),
            ],
        ])->getContent();

        $forms = $this->getServiceLocator()->get('FormElementManager');
        $form = $forms->get(\Omeka\Form\ConfirmForm::class);
        $this->dispatch($solrProfile2->adminUrl('delete'), 'POST', [
            'csrf' => $form->get('csrf')->getValue(),
        ]);
        $this->assertRedirectTo($this->solrNode->profileUrl('browse'));
    }
}
