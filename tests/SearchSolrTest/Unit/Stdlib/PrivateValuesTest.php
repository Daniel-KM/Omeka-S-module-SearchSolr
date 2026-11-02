<?php declare(strict_types=1);

namespace SearchSolrTest\Unit\Stdlib;

use SearchSolr\Indexer\SolariumIndexer;
use SearchSolr\Schema\Schema;
use SearchSolr\Stdlib\SolrCore;
use SearchSolrTest\SearchSolrTestTrait;
use SearchSolrTest\TestCase;

/**
 * Private values indexed in separate fields, not copied into the catchall.
 *
 * The Solr schema is simulated, so no Solr server is required.
 *
 * @group privatevalues
 */
class PrivateValuesTest extends TestCase
{
    use SearchSolrTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    public function testPrivateValuesAreNotSeparatedByDefault(): void
    {
        $solrCore = $this->solrCoreWithSchema($this->schemaWithCatchall(), []);
        $this->assertFalse($solrCore->isPrivateValuesSeparated());
        $this->assertSame(['*'], $solrCore->catchallCopyFieldSources());
    }

    public function testCatchallSourcesExcludePrivateFields(): void
    {
        $solrCore = $this->solrCoreWithSchema($this->schemaWithCatchall(), ['private_values' => 'separate']);
        $sources = $solrCore->catchallCopyFieldSources();
        $this->assertContains('*_txt', $sources);
        $this->assertContains('*_ss', $sources);
        $this->assertContains('id', $sources);
        $this->assertNotContains('*', $sources);
        $this->assertNotContains('*_private', $sources);
        // A prefix pattern may match a private field.
        $this->assertNotContains('attr_*', $sources);
        // Internal and copy-only fields.
        $this->assertNotContains('_text_', $sources);
        $this->assertNotContains('suggest_txt', $sources);
    }

    public function testSchemaWithCopyOfAllFieldsIsNotReady(): void
    {
        $schema = $this->schemaWithCatchall();
        $schema['dynamicFields'][] = ['name' => '*_private', 'type' => 'text_general', 'indexed' => true, 'multiValued' => true];
        $schema['fields'][] = ['name' => SolrCore::PRIVATE_CATCHALL, 'type' => 'text_general', 'indexed' => true];
        $schema['copyFields'][] = ['source' => '*_private', 'dest' => SolrCore::PRIVATE_CATCHALL];
        $solrCore = $this->solrCoreWithSchema($schema, ['private_values' => 'separate']);
        $this->assertFalse($solrCore->hasPrivateValuesSchema());
    }

    public function testSchemaWithPublicCopiesIsReady(): void
    {
        $solrCore = $this->solrCoreWithSchema($this->schemaReady(), ['private_values' => 'separate']);
        $this->assertTrue($solrCore->hasPrivateValuesSchema());
        // The catchall is still detected as the default field.
        $this->assertTrue($solrCore->schema()->checkDefaultField());
    }

    public function testPrivateValuesAreIndexedInPrivateFields(): void
    {
        $document = $this->prepareDocument('separate');
        $fields = $document->getFields();
        $this->assertSame(['Public text'], (array) $fields['bibo_content_txt']);
        $this->assertSame(['Private text'], (array) $fields['bibo_content_txt_private']);
        // A public property is not duplicated in a private field.
        $this->assertSame(['Title'], (array) $fields['dcterms_title_txt']);
        $this->assertArrayNotHasKey('dcterms_title_txt_private', $fields);
    }

    public function testPrivateValuesAreSkippedWithoutSchema(): void
    {
        $fields = $this->prepareDocument('skip')->getFields();
        $this->assertSame(['Public text'], (array) $fields['bibo_content_txt']);
        $this->assertArrayNotHasKey('bibo_content_txt_private', $fields);
    }

    public function testPrivateValuesAreMixedByDefault(): void
    {
        $fields = $this->prepareDocument(null)->getFields();
        $this->assertEqualsCanonicalizing(['Public text', 'Private text'], (array) $fields['bibo_content_txt']);
        $this->assertArrayNotHasKey('bibo_content_txt_private', $fields);
    }

    public function testPrivateCatchallIsQueriedByAdminOnly(): void
    {
        $solrCore = $this->solrCoreWithSchema($this->schemaReady(), ['private_values' => 'separate']);
        $this->assertSame([], $this->privateQueryFields($solrCore, true));
        $this->assertSame([SolrCore::PRIVATE_CATCHALL], $this->privateQueryFields($solrCore, false));
    }

    public function testPrivateCatchallIsNotQueriedWithoutRights(): void
    {
        $solrCore = $this->solrCoreWithSchema($this->schemaReady(), ['private_values' => 'separate']);
        $this->getServiceLocator()->get('Omeka\AuthenticationService')->clearIdentity();
        $this->assertSame([], $this->privateQueryFields($solrCore, false));
    }

    public function testPrivateCatchallIsNotQueriedWhenMixed(): void
    {
        $solrCore = $this->solrCoreWithSchema($this->schemaReady(), []);
        $this->assertSame([], $this->privateQueryFields($solrCore, false));
    }

    protected function privateQueryFields(SolrCore $solrCore, bool $isPublic): array
    {
        $querier = new \SearchSolr\Querier\SolariumQuerier();
        $querier->setServiceLocator($this->getServiceLocator());
        $this->setProtected($querier, 'solrCore', $solrCore);
        $this->setProtected($querier, 'query', (new \AdvancedSearch\Query())->setIsPublic($isPublic));
        $method = new \ReflectionMethod($querier, 'privateQueryFields');
        $method->setAccessible(true);
        return $method->invoke($querier);
    }

    protected function prepareDocument(?string $mode): \Solarium\QueryType\Update\Query\Document
    {
        $services = $this->getServiceLocator();
        $easyMeta = $services->get('Common\EasyMeta');
        $engine = $this->createSolrCore('test-private-values', ['private_values' => $mode === null ? '' : 'separate']);
        $this->createSolrMap($engine->id(), 'items', 'bibo_content_txt', 'bibo:content');
        $this->createSolrMap($engine->id(), 'items', 'dcterms_title_txt', 'dcterms:title');
        $engine = $this->api()->read('search_engines', $engine->id())->getContent();

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', 'property_id' => $easyMeta->propertyId('dcterms:title'), '@value' => 'Title']],
            'bibo:content' => [
                ['type' => 'literal', 'property_id' => $easyMeta->propertyId('bibo:content'), '@value' => 'Public text', 'is_public' => true],
                ['type' => 'literal', 'property_id' => $easyMeta->propertyId('bibo:content'), '@value' => 'Private text', 'is_public' => false],
            ],
        ]);

        $solrCore = new SolrCore($engine, $services);
        $this->setProtected($solrCore, 'schema', $this->schema($this->schemaReady()));

        $indexer = $engine->indexer();
        $this->assertInstanceOf(SolariumIndexer::class, $indexer);
        $this->setProtected($indexer, 'solrCore', $solrCore);
        $this->setProtected($indexer, 'easyMeta', $easyMeta);
        $this->setProtected($indexer, 'valueExtractorManager', $services->get('SearchSolr\ValueExtractorManager'));
        $this->setProtected($indexer, 'valueFormatterManager', $services->get('SearchSolr\ValueFormatterManager'));
        $this->setProtected($indexer, 'isSingleValuedFields', array_fill_keys(
            array_map(fn ($map) => $map->fieldName(), $solrCore->maps()),
            false
        ));
        $this->setProtected($indexer, 'unknownFields', []);
        $this->setProtected($indexer, 'supportFields', []);
        $this->setProtected($indexer, 'privateValuesMode', $mode);

        $method = new \ReflectionMethod($indexer, 'prepareDocument');
        $method->setAccessible(true);
        return $method->invoke($indexer, $item);
    }

    protected function solrCoreWithSchema(array $schema, array $settings): SolrCore
    {
        $engine = $this->createSolrCore('test-private-values-schema', $settings);
        $solrCore = new SolrCore($engine, $this->getServiceLocator());
        $this->setProtected($solrCore, 'schema', $this->schema($schema));
        return $solrCore;
    }

    protected function schema(array $schema): Schema
    {
        return (new Schema('http://localhost:8983/solr/test/schema'))->setSchema($schema);
    }

    protected function schemaWithCatchall(): array
    {
        return [
            'fields' => [
                ['name' => '_text_', 'type' => 'text_general', 'indexed' => true, 'multiValued' => true],
                ['name' => '_version_', 'type' => 'plong', 'indexed' => true],
                ['name' => 'id', 'type' => 'string', 'indexed' => true],
                ['name' => 'suggest_txt', 'type' => 'text_general', 'indexed' => true, 'multiValued' => true],
            ],
            'dynamicFields' => [
                ['name' => '*_txt', 'type' => 'text_general', 'indexed' => true, 'multiValued' => true],
                ['name' => '*_ss', 'type' => 'strings', 'indexed' => true, 'multiValued' => true],
                ['name' => 'attr_*', 'type' => 'text_general', 'indexed' => true, 'multiValued' => true],
            ],
            'copyFields' => [
                ['source' => '*', 'dest' => '_text_'],
                ['source' => 'bibo_content_txt', 'dest' => 'suggest_txt'],
            ],
            'fieldTypes' => [],
        ];
    }

    protected function schemaReady(): array
    {
        $schema = $this->schemaWithCatchall();
        $schema['fields'][] = ['name' => SolrCore::PRIVATE_CATCHALL, 'type' => 'text_general', 'indexed' => true, 'multiValued' => true];
        $schema['dynamicFields'][] = ['name' => '*_private', 'type' => 'text_general', 'indexed' => true, 'multiValued' => true];
        $schema['copyFields'] = [
            ['source' => '*_txt', 'dest' => '_text_'],
            ['source' => '*_ss', 'dest' => '_text_'],
            ['source' => 'id', 'dest' => '_text_'],
            ['source' => '*_private', 'dest' => SolrCore::PRIVATE_CATCHALL],
        ];
        return $schema;
    }

    protected function setProtected(object $object, string $property, $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
