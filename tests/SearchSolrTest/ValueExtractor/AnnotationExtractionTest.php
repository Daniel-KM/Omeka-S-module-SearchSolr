<?php declare(strict_types=1);

namespace SearchSolrTest\ValueExtractor;

use Omeka\Api\Representation\ItemRepresentation;
use SearchSolrTest\TestCase;
use SearchSolrTest\SearchSolrTestTrait;

/**
 * Tests for value annotation extraction in Solr indexing.
 *
 * Annotation extraction uses the path format: property/annotation[/annotationProperty]
 * Examples:
 *   - dcterms:creator/annotation → all annotations of dcterms:creator values
 *   - dcterms:creator/annotation/dcterms:source → specific annotation property
 */
class AnnotationExtractionTest extends TestCase
{
    use SearchSolrTestTrait;

    /**
     * @var \SearchSolr\Api\Representation\SolrCoreRepresentation
     */
    protected $solrCore;

    /**
     * @var ItemRepresentation
     */
    protected $itemWithAnnotations;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();

        // Create a Solr core for testing.
        $this->solrCore = $this->createSolrCore('test-annotation-core');

        // Create an item with annotated values.
        $this->itemWithAnnotations = $this->createItemWithAnnotations();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * Create an item with values that have annotations.
     */
    protected function createItemWithAnnotations(): ItemRepresentation
    {
        $services = $this->getServiceLocator();
        $easyMeta = $services->get('Common\EasyMeta');

        $creatorPropertyId = $easyMeta->propertyId('dcterms:creator');
        $sourcePropertyId = $easyMeta->propertyId('dcterms:source');
        $datePropertyId = $easyMeta->propertyId('dcterms:date');
        $titlePropertyId = $easyMeta->propertyId('dcterms:title');

        // Create item with annotated values.
        $itemData = [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => $titlePropertyId,
                    '@value' => 'Test Item with Annotations',
                ],
            ],
            'dcterms:creator' => [
                // First creator with annotation.
                [
                    'type' => 'literal',
                    'property_id' => $creatorPropertyId,
                    '@value' => 'John Smith',
                    '@annotation' => [
                        'dcterms:source' => [
                            [
                                'type' => 'literal',
                                'property_id' => $sourcePropertyId,
                                '@value' => 'Library of Congress',
                            ],
                        ],
                        'dcterms:date' => [
                            [
                                'type' => 'literal',
                                'property_id' => $datePropertyId,
                                '@value' => '2024-01-15',
                            ],
                        ],
                    ],
                ],
                // Second creator with different annotation.
                [
                    'type' => 'literal',
                    'property_id' => $creatorPropertyId,
                    '@value' => 'Jane Doe',
                    '@annotation' => [
                        'dcterms:source' => [
                            [
                                'type' => 'literal',
                                'property_id' => $sourcePropertyId,
                                '@value' => 'British Library',
                            ],
                        ],
                    ],
                ],
                // Third creator without annotation.
                [
                    'type' => 'literal',
                    'property_id' => $creatorPropertyId,
                    '@value' => 'Bob Wilson',
                ],
            ],
        ];

        $response = $this->api()->create('items', $itemData);
        $item = $response->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        return $item;
    }

    /**
     * Get the value extractor for items.
     */
    protected function getValueExtractor(): \SearchSolr\ValueExtractor\ItemValueExtractor
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $baseFilepath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        return new \SearchSolr\ValueExtractor\ItemValueExtractor(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Logger'),
            $baseFilepath
        );
    }

    /**
     * Create a mock SolrMap for testing extraction.
     */
    protected function createTestSolrMap(string $source): \SearchSolr\Api\Representation\SolrMapRepresentation
    {
        return $this->createSolrMap(
            $this->solrCore->id(),
            'items',
            'test_field_ss',
            $source,
            []
        );
    }

    /**
     * Test that annotation option is available in map fields.
     */
    public function testAnnotationOptionInMapFields(): void
    {
        $extractor = $this->getValueExtractor();
        $fields = $extractor->getMapFields();

        $this->assertArrayHasKey('generic', $fields);
        $this->assertArrayHasKey('options', $fields['generic']);
        $this->assertArrayHasKey('annotation', $fields['generic']['options']);
    }

    /**
     * Test extraction of specific annotation property (dcterms:creator/annotation/dcterms:source).
     */
    public function testExtractSpecificAnnotationProperty(): void
    {
        $extractor = $this->getValueExtractor();
        $solrMap = $this->createTestSolrMap('dcterms:creator/annotation/dcterms:source');

        $values = $extractor->extractValue($this->itemWithAnnotations, $solrMap);

        // Should get 2 source values from the 2 annotated creators.
        $this->assertCount(2, $values);

        // Extract the string values.
        $stringValues = array_map(function ($v) {
            return (string) $v;
        }, $values);

        $this->assertContains('Library of Congress', $stringValues);
        $this->assertContains('British Library', $stringValues);
    }

    /**
     * Test extraction of all annotation values (dcterms:creator/annotation).
     */
    public function testExtractAllAnnotationValues(): void
    {
        $extractor = $this->getValueExtractor();
        $solrMap = $this->createTestSolrMap('dcterms:creator/annotation');

        $values = $extractor->extractValue($this->itemWithAnnotations, $solrMap);

        // Should get 3 annotation values:
        // - 2 from first creator (source + date)
        // - 1 from second creator (source)
        $this->assertCount(3, $values);

        $stringValues = array_map(function ($v) {
            return (string) $v;
        }, $values);

        $this->assertContains('Library of Congress', $stringValues);
        $this->assertContains('2024-01-15', $stringValues);
        $this->assertContains('British Library', $stringValues);
    }

    /**
     * Test that values without annotations are skipped when extracting annotations.
     */
    public function testValuesWithoutAnnotationsAreSkipped(): void
    {
        $extractor = $this->getValueExtractor();
        $solrMap = $this->createTestSolrMap('dcterms:creator/annotation/dcterms:source');

        $values = $extractor->extractValue($this->itemWithAnnotations, $solrMap);

        // Only 2 creators have annotations with dcterms:source.
        // Bob Wilson has no annotation, so should not contribute.
        $this->assertCount(2, $values);

        $stringValues = array_map(function ($v) {
            return (string) $v;
        }, $values);

        // Bob Wilson should not appear.
        $this->assertNotContains('Bob Wilson', $stringValues);
    }

    /**
     * Test extraction when property has no annotated values.
     */
    public function testExtractAnnotationFromPropertyWithoutAnnotations(): void
    {
        $extractor = $this->getValueExtractor();
        // dcterms:title has no annotations in our test item.
        $solrMap = $this->createTestSolrMap('dcterms:title/annotation');

        $values = $extractor->extractValue($this->itemWithAnnotations, $solrMap);

        $this->assertEmpty($values);
    }

    /**
     * Test extraction of non-existent annotation property.
     */
    public function testExtractNonExistentAnnotationProperty(): void
    {
        $extractor = $this->getValueExtractor();
        // dcterms:description doesn't exist in our annotations.
        $solrMap = $this->createTestSolrMap('dcterms:creator/annotation/dcterms:description');

        $values = $extractor->extractValue($this->itemWithAnnotations, $solrMap);

        $this->assertEmpty($values);
    }

    /**
     * Test that normal property extraction still works (no annotation path).
     */
    public function testNormalPropertyExtractionStillWorks(): void
    {
        $extractor = $this->getValueExtractor();
        $solrMap = $this->createTestSolrMap('dcterms:creator');

        $values = $extractor->extractValue($this->itemWithAnnotations, $solrMap);

        // Should get 3 creator values (the values themselves, not annotations).
        $this->assertCount(3, $values);

        $stringValues = array_map(function ($v) {
            return (string) $v;
        }, $values);

        $this->assertContains('John Smith', $stringValues);
        $this->assertContains('Jane Doe', $stringValues);
        $this->assertContains('Bob Wilson', $stringValues);
    }
}
