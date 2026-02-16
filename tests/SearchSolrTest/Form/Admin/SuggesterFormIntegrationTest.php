<?php declare(strict_types=1);

namespace SearchSolrTest\Form\Admin;

use AdvancedSearch\Form\Admin\SearchSuggesterForm;
use SearchSolrTest\SearchSolrTestTrait;
use SearchSolrTest\TestCase;

/**
 * Tests for Solr suggester form integration via events.
 *
 * @group form
 * @group suggester
 * @group solr
 */
class SuggesterFormIntegrationTest extends TestCase
{
    use SearchSolrTestTrait;

    /**
     * @var \SearchSolr\Api\Representation\SolrCoreRepresentation
     */
    protected $solrCore;

    /**
     * @var \AdvancedSearch\Api\Representation\SearchEngineRepresentation
     */
    protected $searchEngine;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();

        // Create Solr core.
        $this->solrCore = $this->createSolrCore('TestCore');

        // Create search engine with Solr adapter.
        $this->searchEngine = $this->createSolrSearchEngine('TestSolrEngine', $this->solrCore->id());
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    /**
     * Get a configured SearchSuggesterForm with Solr engine.
     */
    protected function getSolrSuggesterForm(): SearchSuggesterForm
    {
        $formElementManager = $this->getServiceLocator()->get('FormElementManager');
        $form = $formElementManager->get(SearchSuggesterForm::class);
        $form->setOption('add', false);
        $form->setOption('is_internal', false);
        $form->setOption('search_engine', $this->searchEngine);
        $form->init();
        return $form;
    }

    /**
     * Test Solr form has suggester name field via event.
     */
    public function testSolrFormHasSuggesterNameField(): void
    {
        $form = $this->getSolrSuggesterForm();

        $this->assertTrue($form->has('o:settings'));
        $settings = $form->get('o:settings');
        $this->assertTrue($settings->has('solr_suggester_name'));
    }

    /**
     * Test Solr form has field selection via event.
     */
    public function testSolrFormHasFieldSelection(): void
    {
        $form = $this->getSolrSuggesterForm();

        $settings = $form->get('o:settings');
        $this->assertTrue($settings->has('solr_fields'));
    }

    /**
     * Test Solr fields has _text_ as first option.
     */
    public function testSolrFieldsHasTextCatchallOption(): void
    {
        $form = $this->getSolrSuggesterForm();

        $settings = $form->get('o:settings');
        $fieldElement = $settings->get('solr_fields');
        $options = $fieldElement->getValueOptions();

        $this->assertArrayHasKey('_text_', $options);
        // _text_ should be the first key.
        $keys = array_keys($options);
        $this->assertEquals('_text_', $keys[0]);
    }

    /**
     * Test Solr fields is a multiple select.
     */
    public function testSolrFieldsIsMultipleSelect(): void
    {
        $form = $this->getSolrSuggesterForm();

        $settings = $form->get('o:settings');
        $fieldElement = $settings->get('solr_fields');

        $this->assertEquals('true', $fieldElement->getAttribute('multiple'));
    }

    /**
     * Test Solr form has lookup implementation field via event.
     */
    public function testSolrFormHasLookupImplField(): void
    {
        $form = $this->getSolrSuggesterForm();

        $settings = $form->get('o:settings');
        $this->assertTrue($settings->has('solr_lookup_impl'));
    }

    /**
     * Test Solr lookup impl has correct options.
     */
    public function testSolrLookupImplOptions(): void
    {
        $form = $this->getSolrSuggesterForm();

        $settings = $form->get('o:settings');
        $lookupImplElement = $settings->get('solr_lookup_impl');

        $options = $lookupImplElement->getValueOptions();

        $this->assertArrayHasKey('AnalyzingInfixLookupFactory', $options);
        $this->assertArrayHasKey('BlendedInfixLookupFactory', $options);
        $this->assertArrayHasKey('AnalyzingLookupFactory', $options);
        $this->assertArrayHasKey('FuzzyLookupFactory', $options);
    }

    /**
     * Test Solr form has build on commit field via event.
     */
    public function testSolrFormHasBuildOnCommitField(): void
    {
        $form = $this->getSolrSuggesterForm();

        $settings = $form->get('o:settings');
        $this->assertTrue($settings->has('solr_build_on_commit'));
    }

    /**
     * Test Solr form does not have internal-specific fields.
     */
    public function testSolrFormHasNoInternalFields(): void
    {
        $form = $this->getSolrSuggesterForm();

        $settings = $form->get('o:settings');

        // Solr form should not have internal-specific fields.
        $this->assertFalse($settings->has('stopwords'));
        $this->assertFalse($settings->has('stopwords_mode'));
        $this->assertFalse($settings->has('mode_index'));
        $this->assertFalse($settings->has('sites'));
    }
}
