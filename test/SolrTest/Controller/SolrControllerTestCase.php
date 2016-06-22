<?php

namespace SolrTest\Controller;

use Omeka\Test\AbstractHttpControllerTestCase;

abstract class SolrControllerTestCase extends AbstractHttpControllerTestCase
{
    protected $solrNode;
    protected $solrField;
    protected $solrProfile;
    protected $solrProfileRule;
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

        $response = $this->api()->create('solr_fields', [
            'o:name' => 'solr_field_t',
            'o:description' => 'SolrField',
            'o:is_indexed' => '1',
            'o:is_multivalued' => '1',
            'o:solr_node' => [
                'o:id' => $solrNode->id(),
            ],
        ]);
        $solrField = $response->getContent();

        $response = $this->api()->create('solr_profiles', [
            'o:resource_name' => 'items',
            'o:solr_node' => [
                'o:id' => $solrNode->id(),
            ],
        ]);
        $solrProfile = $response->getContent();

        $response = $this->api()->create('solr_profile_rules', [
            'o:solr_field' => [
                'o:id' => $solrField->id(),
            ],
            'o:source' => 'dcterms:title',
            'o:settings' => [
                'formatter' => '',
            ],
            'o:solr_profile' => [
                'o:id' => $solrProfile->id(),
            ],
        ]);
        $solrProfileRule = $response->getContent();

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
        $this->solrField = $solrField;
        $this->solrProfile = $solrProfile;
        $this->solrProfileRule = $solrProfileRule;
        $this->searchIndex = $searchIndex;
        $this->searchPage = $searchPage;
    }

    public function tearDown()
    {
        $this->api()->delete('search_pages', $this->searchPage->id());
        $this->api()->delete('search_indexes', $this->searchIndex->id());
        $this->api()->delete('solr_profile_rules', $this->solrProfileRule->id());
        $this->api()->delete('solr_profiles', $this->solrProfile->id());
        $this->api()->delete('solr_fields', $this->solrField->id());
        $this->api()->delete('solr_nodes', $this->solrNode->id());
    }

    protected function loginAsAdmin()
    {
        $application = $this->getApplication();
        $serviceLocator = $application->getServiceManager();
        $auth = $serviceLocator->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    protected function getServiceLocator()
    {
        return $this->getApplication()->getServiceManager();
    }

    protected function api()
    {
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }

    protected function resetApplication()
    {
        $this->application = null;
    }
}
