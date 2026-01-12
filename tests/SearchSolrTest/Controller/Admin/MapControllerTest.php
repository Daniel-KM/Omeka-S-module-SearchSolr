<?php declare(strict_types=1);

namespace SearchSolrTest\Controller\Admin;

use SearchSolrTest\Controller\SearchSolrControllerTestCase;

class MapControllerTest extends SearchSolrControllerTestCase
{
    /**
     * Check if Solr is available for tests that require it.
     */
    protected function isSolrAvailable(): bool
    {
        try {
            $schema = $this->solrCore->schema();
            $schema->getSchema();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @group solr-required
     */
    public function testResourceBrowseAction(): void
    {
        if (!$this->isSolrAvailable()) {
            $this->markTestSkipped('Solr server not available.');
        }
        // Use resourceMapUrl to generate the correct URL.
        // Default action for this route is 'browseResource'.
        $this->dispatch($this->solrCore->resourceMapUrl('items'));
        $this->assertResponseStatusCode(200);
    }

    /**
     * @group solr-required
     */
    public function testAddAction(): void
    {
        if (!$this->isSolrAvailable()) {
            $this->markTestSkipped('Solr server not available.');
        }
        $this->dispatch($this->solrCore->resourceMapUrl('items', 'add'));
        $this->assertResponseStatusCode(200);
    }

    /**
     * @group solr-required
     */
    public function testEditAction(): void
    {
        if (!$this->isSolrAvailable()) {
            $this->markTestSkipped('Solr server not available.');
        }
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
            'o:field_name' => 'dcterms_description_txt',
            'o:alias' => '',
            'o:source' => 'dcterms:description',
            'o:settings' => [
                'formatter' => '',
            ],
        ])->getContent();

        $this->dispatchPost(
            $solrMap->adminUrl('delete'),
            [],
            \Omeka\Form\ConfirmForm::class,
            [],
            'confirmform_csrf'
        );
        // After deletion, redirects to core page (not to map list).
        $this->assertRedirectTo($this->solrCore->adminUrl());
    }
}
