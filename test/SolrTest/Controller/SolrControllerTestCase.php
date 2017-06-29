<?php

namespace SolrTest\Controller;

use OmekaTestHelper\Controller\OmekaControllerTestCase;

abstract class SolrControllerTestCase extends OmekaControllerTestCase
{
    protected $solrNode;
    protected $solrMapping;
    protected $searchIndex;
    protected $searchPage;

    public function setUp()
    {
        parent::setUp();

        $this->loginAsAdmin();

        $response = $this->api()->create('solr_nodes', [
            'o:name' => 'TestNode',
            'o:settings' => [
                'client' => [
                    'hostname' => 'localhost',
                    'port' => '8983',
                    'path' => 'solr/test_node',
                ],
                'resource_name_field' => 'resource_name_s',
            ],
        ]);
        $solrNode = $response->getContent();

        $response = $this->api()->create('solr_mappings', [
            'o:solr_node' => [
                'o:id' => $solrNode->id(),
            ],
            'o:resource_name' => 'items',
            'o:field_name' => 'dc_terms_title_t',
            'o:source' => 'dcterms:title',
            'o:settings' => [
                'formatter' => '',
            ],
        ]);
        $solrMapping = $response->getContent();

        $response = $this->api()->create('search_indexes', [
            'o:name' => 'TestIndex',
            'o:adapter' => 'solr',
            'o:settings' => [
                'resources' => [
                    'items',
                    'item_sets',
                ],
                'adapter' => [
                    'solr_node_id' => $solrNode->id(),
                ],
            ],
        ]);
        $searchIndex = $response->getContent();
        $response = $this->api()->create('search_pages', [
            'o:name' => 'TestPage',
            'o:path' => 'test/search',
            'o:index_id' => $searchIndex->id(),
            'o:form' => 'basic',
            'o:settings' => [
                'facets' => [],
                'sort_fields' => [],
            ],
        ]);
        $searchPage = $response->getContent();

        $this->solrNode = $solrNode;
        $this->solrMapping = $solrMapping;
        $this->searchIndex = $searchIndex;
        $this->searchPage = $searchPage;
    }

    public function tearDown()
    {
        $this->api()->delete('search_pages', $this->searchPage->id());
        $this->api()->delete('search_indexes', $this->searchIndex->id());
        $this->api()->delete('solr_mappings', $this->solrMapping->id());
        $this->api()->delete('solr_nodes', $this->solrNode->id());
    }
}
