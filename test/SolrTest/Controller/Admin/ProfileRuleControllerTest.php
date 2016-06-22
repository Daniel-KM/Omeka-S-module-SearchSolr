<?php

namespace SolrTest\Controller\Admin;

require_once __DIR__ . '/../SolrControllerTestCase.php';

use SolrTest\Controller\SolrControllerTestCase;

class ProfileRuleControllerTest extends SolrControllerTestCase
{
    public function testBrowseAction()
    {
        $this->dispatch($this->solrProfile->ruleUrl('browse'));
        $this->assertResponseStatusCode(200);
    }

    public function testAddAction()
    {
        $this->dispatch($this->solrProfile->ruleUrl('add'));
        $this->assertResponseStatusCode(200);
    }

    public function testEditAction()
    {
        $this->dispatch($this->solrProfileRule->adminUrl('edit'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteConfirmAction()
    {
        $this->dispatch($this->solrProfileRule->adminUrl('delete-confirm'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteAction()
    {
        $solrProfileRule2 = $this->api()->create('solr_profile_rules', [
            'o:solr_field' => [
                'o:id' => $this->solrField->id(),
            ],
            'o:source' => 'dcterms:description',
            'o:settings' => [
                'formatter' => '',
            ],
            'o:solr_profile' => [
                'o:id' => $this->solrProfile->id(),
            ],
        ])->getContent();

        $forms = $this->getServiceLocator()->get('FormElementManager');
        $form = $forms->get(\Omeka\Form\ConfirmForm::class);
        $this->dispatch($solrProfileRule2->adminUrl('delete'), 'POST', [
            'confirmform_csrf' => $form->get('confirmform_csrf')->getValue(),
        ]);
        $this->assertRedirectTo($this->solrProfile->ruleUrl('browse'));
    }
}
