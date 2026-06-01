<?php declare(strict_types=1);

namespace SearchSolrTest\ValueExtractor;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Cover the DigitalObject integration in SearchSolr indexing pipeline:
 *  - Item → digital_object sub-source resolution
 *  - has_digital_object boolean
 *  - resource-name map entries for queries and id caches.
 *
 * Source-level checks: targets are inside SearchSolr internals not easily
 * reachable through the API. Each test skips when DigitalObject is absent.
 */
class DigitalObjectSupportTest extends AbstractHttpControllerTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists('DigitalObject\Module', false)) {
            $this->markTestSkipped('Module DigitalObject not installed.');
        }
    }

    public function testCompleteSolrMapsRegistersDigitalObjectsEntity(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Job/CompleteSolrMaps.php');
        self::assertNotFalse($source);
        // Decoupled checks, robust to "=>" vs "=" mapping syntax.
        self::assertStringContainsString("'digital_objects'", $source);
        self::assertStringContainsString('DigitalObject\\Entity\\DigitalObject::class', $source);
    }

    public function testReduceSolrFieldsRegistersDigitalObjectsEntity(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Job/ReduceSolrFields.php');
        self::assertNotFalse($source);
        self::assertStringContainsString("'digital_objects'", $source);
        self::assertStringContainsString('DigitalObject\\Entity\\DigitalObject::class', $source);
    }

    public function testAbstractValueExtractorRegistersDigitalObjects(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/ValueExtractor/AbstractResourceEntityValueExtractor.php'
        );
        self::assertNotFalse($source);
        // Static id cache bucket
        self::assertStringContainsString("'digital_objects' => []", $source);
        // Representation → resource_name map
        self::assertStringContainsString(
            "DigitalObjectRepresentation::class] = 'digital_objects'",
            $source
        );
        // Sub-source labels and field branches
        self::assertStringContainsString("'digital_object' => 'Item: Digital object'", $source);
        self::assertStringContainsString("'has_digital_object' => 'Item: Has digital object'", $source);
        self::assertStringContainsString('extractItemDigitalObjectsValue', $source);
        self::assertStringContainsString('itemHasDigitalObjects', $source);
        self::assertStringContainsString('collectItemDigitalObjects', $source);
    }

    public function testMapControllerSourceLabelHasDigitalObject(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Controller/Admin/MapController.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString("'digital_object' => 'Digital object'", $source);
    }
}
