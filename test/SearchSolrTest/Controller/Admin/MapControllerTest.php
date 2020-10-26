<?php declare(strict_types=1);

namespace SearchSolrTest\Controller\Admin;

use SolrTest\Controller\SolrControllerTestCase;

class MapControllerTest extends SolrControllerTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $schema = $this->solrCore->schema();
        $schema->setSchema([]);
    }

    public function testBrowseAction(): void
    {
        $this->dispatch($this->solrCore->mapUrl('browse'));
        $this->assertResponseStatusCode(200);
    }

    public function testResourceBrowseAction(): void
    {
        $this->dispatch($this->solrCore->resourceMapUrl('items', 'browse'));
        $this->assertResponseStatusCode(200);
    }

    public function testAddAction(): void
    {
        $this->dispatch($this->solrCore->resourceMapUrl('items', 'add'));
        $this->assertResponseStatusCode(200);
    }

    public function testEditAction(): void
    {
        $this->dispatch($this->solrMap->adminUrl('edit'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteConfirmAction(): void
    {
        $this->dispatch($this->solrMap->adminUrl('delete-confirm'));
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteAction(): void
    {
        $solrMap = $this->api()->create('solr_maps', [
            'o:solr_core' => [
                'o:id' => $this->solrCore->id(),
            ],
            'o:resource_name' => 'items',
            'o:field_name' => 'dcterms_description_t',
            'o:source' => 'dcterms:description',
            'o:settings' => [
                'formatter' => '',
            ],
        ])->getContent();

        $forms = $this->getServiceLocator()->get('FormElementManager');
        $form = $forms->get(\Omeka\Form\ConfirmForm::class);
        $this->dispatch($solrMap->adminUrl('delete'), 'POST', [
            'confirmform_csrf' => $form->get('confirmform_csrf')->getValue(),
        ]);
        $this->assertRedirectTo($this->solrCore->resourceMapUrl('items'));
    }
}
