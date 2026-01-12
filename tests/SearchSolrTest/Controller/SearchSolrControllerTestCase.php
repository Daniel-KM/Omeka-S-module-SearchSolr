<?php declare(strict_types=1);

namespace SearchSolrTest\Controller;

use SearchSolrTest\TestCase;

abstract class SearchSolrControllerTestCase extends TestCase
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
                    'scheme' => 'http',
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
            'o:engine_adapter' => 'solarium',
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
            'o:slug' => 'test/search',
            'o:search_engine' => [
                'o:id' => $searchEngine->id(),
            ],
            'o:form_adapter' => 'basic',
            'o:settings' => [
                'request' => [],
                'q' => [],
                'index' => [],
                'form' => [],
                'results' => [],
                'facet' => [],
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
        // Re-authenticate in case test logged out.
        $this->loginAsAdmin();

        $this->api()->delete('search_configs', $this->searchConfig->id());
        $this->api()->delete('search_engines', $this->searchEngine->id());
        $this->api()->delete('solr_maps', $this->solrMap->id());
        $this->api()->delete('solr_cores', $this->solrCore->id());
    }

    /**
     * Get the service locator (alias for getApplicationServiceLocator).
     */
    protected function getServiceLocator()
    {
        return $this->getApplicationServiceLocator();
    }

    /**
     * Login as admin using adapter (avoids static caching issues with Doctrine).
     */
    protected function loginAsAdmin(): void
    {
        $services = $this->getApplicationServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Get a valid CSRF token for a form.
     *
     * @param string $formClass The form class name.
     * @param array $options Form options.
     * @param string $csrfName The CSRF element name (default 'csrf').
     * @return string The CSRF token value.
     */
    protected function getCsrfToken(string $formClass, array $options = [], string $csrfName = 'csrf'): string
    {
        $forms = $this->getServiceLocator()->get('FormElementManager');
        $form = $forms->get($formClass, $options);
        return $form->get($csrfName)->getValue();
    }

    /**
     * Dispatch a POST request with automatic CSRF token injection.
     *
     * @param string $url The URL to dispatch.
     * @param array $params POST parameters (without CSRF).
     * @param string $formClass The form class to get CSRF from.
     * @param array $formOptions Form options for getting the form.
     * @param string $csrfName The CSRF element name.
     */
    protected function dispatchPost(
        string $url,
        array $params,
        string $formClass,
        array $formOptions = [],
        string $csrfName = 'csrf'
    ): void {
        // Reset and setup first.
        $this->reset();
        $this->getApplication();

        $this->loginAsAdmin();

        // Get CSRF from the fresh application.
        $params[$csrfName] = $this->getCsrfToken($formClass, $formOptions, $csrfName);

        // Dispatch without reset.
        \Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase::dispatch($url, 'POST', $params);
    }
}
