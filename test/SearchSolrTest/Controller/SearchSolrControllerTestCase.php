<?php declare(strict_types=1);

namespace SearchSolrTest\Controller;

use OmekaTestHelper\Controller\OmekaControllerTestCase;

abstract class SolrControllerTestCase extends OmekaControllerTestCase
{
    protected $solrCore;
    protected $solrMap;
    protected $searchEngine;
    protected $searchConfig;

    public function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();

        $response = $this->api()->create('solr_cores', [
            'o:name' => 'TestCore',
            'o:settings' => [
                'client' => [
                    'host' => 'localhost',
                    'port' => '8983',
                    'path' => '/',
                    'core' => 'test_core',
                ],
                'resource_name_field' => 'resource_name_s',
            ],
        ]);
        $solrCore = $response->getContent();

        $response = $this->api()->create('solr_maps', [
            'o:solr_core' => [
                'o:id' => $solrCore->id(),
            ],
            'o:resource_name' => 'items',
            'o:field_name' => 'dc_terms_title_t',
            'o:source' => 'dcterms:title',
            'o:settings' => [
                'formatter' => '',
            ],
        ]);
        $solrMap = $response->getContent();

        $response = $this->api()->create('search_engines', [
            'o:name' => 'TestIndex',
            'o:adapter' => 'solarium',
            'o:settings' => [
                'resources' => [
                    'items',
                    'item_sets',
                ],
                'adapter' => [
                    'solr_core_id' => $solrCore->id(),
                ],
            ],
        ]);
        $searchEngine = $response->getContent();
        $response = $this->api()->create('search_configs', [
            'o:name' => 'TestPage',
            'o:path' => 'test/search',
            'o:engine_id' => $searchEngine->id(),
            'o:form' => 'basic',
            'o:settings' => [
                'facets' => [],
                'sort_fields' => [],
            ],
        ]);
        $searchConfig = $response->getContent();

        $this->solrCore = $solrCore;
        $this->solrMap = $solrMap;
        $this->searchEngine = $searchEngine;
        $this->searchConfig = $searchConfig;
    }

    public function tearDown(): void
    {
        $this->api()->delete('search_configs', $this->searchConfig->id());
        $this->api()->delete('search_engines', $this->searchEngine->id());
        $this->api()->delete('solr_maps', $this->solrMap->id());
        $this->api()->delete('solr_cores', $this->solrCore->id());
    }
}
