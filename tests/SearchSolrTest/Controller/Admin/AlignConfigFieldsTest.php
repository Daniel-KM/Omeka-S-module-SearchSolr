<?php declare(strict_types=1);

namespace SearchSolrTest\Controller\Admin;

use Omeka\Test\AbstractHttpControllerTestCase;
use SearchSolr\Controller\Admin\CoreController;
use SearchSolrTest\SearchSolrTestTrait;

/**
 * The alignment of the maps points the facets and the filters of the search
 * pages to the aligned index, so the next alignment does not remove it.
 *
 * @see https://github.com/Daniel-KM/Omeka-S-module-AdvancedSearch/issues/33
 * @see https://github.com/Daniel-KM/Omeka-S-module-AdvancedSearch/issues/34
 *
 * @group controller
 */
class AlignConfigFieldsTest extends AbstractHttpControllerTestCase
{
    use SearchSolrTestTrait;

    protected $configId;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();

        $engines = $this->api()->search('search_engines', ['adapter' => 'internal'])->getContent();
        $engineId = $engines ? reset($engines)->id() : null;
        if (!$engineId) {
            $this->markTestSkipped('No search engine.');
        }

        $config = $this->api()->create('search_configs', [
            'o:name' => 'Align fields',
            'o:slug' => 'align-fields-' . substr(md5((string) microtime(true)), 0, 8),
            'o:search_engine' => ['o:id' => $engineId],
            'o:form_adapter' => 'main',
            'o:settings' => [
                'facet' => ['facets' => [
                    'subject' => ['field' => 'dcterms_subject_link_ss', 'type' => 'Checkbox'],
                    'subjectfr' => ['field' => 'dcterms_subject_fr_ss', 'type' => 'Checkbox'],
                    'creator' => ['field' => 'dcterms_creator_ss', 'type' => 'Checkbox'],
                ]],
                'form' => ['filters' => [
                    'extent' => ['field' => 'dcterms:extent', 'type' => 'RangeDouble'],
                    'spatial' => ['field' => 'dcterms_spatial_link_ss', 'type' => 'RangeDouble'],
                ]],
            ],
        ])->getContent();
        $this->configId = $config->id();
    }

    public function tearDown(): void
    {
        if ($this->configId) {
            try {
                $this->api()->delete('search_configs', $this->configId);
            } catch (\Throwable $e) {
                // Ignore.
            }
        }
        parent::tearDown();
    }

    protected function api(): \Omeka\Api\Manager
    {
        return $this->getApplicationServiceLocator()->get('Omeka\ApiManager');
    }

    protected function controller(): CoreController
    {
        $services = $this->getApplicationServiceLocator();
        $controller = $services->get('ControllerManager')->get(CoreController::class);
        $controller->setPluginManager($services->get('ControllerPluginManager'));
        $refs = [
            [$this->configId, 'facet', 'facets', 'subject', 'dcterms_subject_link_ss', '_ss'],
            [$this->configId, 'facet', 'facets', 'subjectfr', 'dcterms_subject_fr_ss', '_ss'],
            [$this->configId, 'facet', 'facets', 'creator', 'dcterms_creator_ss', '_ss'],
            [$this->configId, 'form', 'filters', 'extent', 'dcterms:extent', '_i'],
            [$this->configId, 'form', 'filters', 'spatial', 'dcterms_spatial_link_ss', '_i'],
        ];
        $property = new \ReflectionProperty($controller, 'configFieldRefs');
        $property->setAccessible(true);
        $property->setValue($controller, $refs);
        return $controller;
    }

    /**
     * @param array|null $populated The indexes with documents, or all of them.
     */
    protected function align(bool $isAudit, ?array $populated = null): array
    {
        $alignedFields = [
            'dcterms:subject' => ['_ss' => 'dcterms_subject_ss'],
            'dcterms:creator' => ['_ss' => 'dcterms_creator_ss'],
            'dcterms:extent' => ['_i' => 'dcterms_extent_i'],
            'dcterms:spatial' => ['_i' => 'dcterms_spatial_i'],
        ];
        $existing = ['dcterms_subject_ss', 'dcterms_subject_fr_ss', 'dcterms_creator_ss', 'dcterms_extent_i', 'dcterms_spatial_i'];
        $controller = $this->controller();
        $method = new \ReflectionMethod($controller, 'alignConfigFields');
        $method->setAccessible(true);
        $isPopulated = fn (string $field): bool => $populated === null || in_array($field, $populated, true);
        return $method->invoke($controller, $alignedFields, $existing, $isAudit, $isPopulated);
    }

    protected function fields(): array
    {
        $settings = $this->api()->read('search_configs', $this->configId)->getContent()->settings();
        return [
            'subject' => $settings['facet']['facets']['subject']['field'],
            'subjectfr' => $settings['facet']['facets']['subjectfr']['field'],
            'creator' => $settings['facet']['facets']['creator']['field'],
            'extent' => $settings['form']['filters']['extent']['field'],
            'spatial' => $settings['form']['filters']['spatial']['field'],
        ];
    }

    public function testFieldsArePointedToTheAlignedIndex(): void
    {
        [$updated, $pending] = $this->align(false);
        $this->assertArrayHasKey($this->configId, $updated);
        $this->assertEmpty($pending);

        $fields = $this->fields();
        // A link index and a property are replaced.
        $this->assertSame('dcterms_subject_ss', $fields['subject']);
        $this->assertSame('dcterms_extent_i', $fields['extent']);
        // A string index used for a range is replaced.
        $this->assertSame('dcterms_spatial_i', $fields['spatial']);
        // A language index is a choice and the right index is kept.
        $this->assertSame('dcterms_subject_fr_ss', $fields['subjectfr']);
        $this->assertSame('dcterms_creator_ss', $fields['creator']);
    }

    public function testAuditDoesNotUpdateTheConfig(): void
    {
        $before = $this->fields();
        [$updated] = $this->align(true);
        $this->assertArrayHasKey($this->configId, $updated, 'The audit reports the update');
        $this->assertSame($before, $this->fields(), 'The audit does not update the config');
    }

    /**
     * A new index is empty until the reindexing, so the config keeps its field
     * until a next alignment, else the facet or the filter would be empty.
     */
    public function testEmptyIndexIsPending(): void
    {
        $before = $this->fields();
        [$updated, $pending] = $this->align(false, ['dcterms_extent_i']);

        $this->assertArrayHasKey($this->configId, $updated);
        $this->assertArrayHasKey($this->configId, $pending);
        $this->assertStringContainsString('dcterms_subject_link_ss → dcterms_subject_ss', $pending[$this->configId]);

        $fields = $this->fields();
        $this->assertSame('dcterms_extent_i', $fields['extent'], 'A populated index is used');
        $this->assertSame($before['subject'], $fields['subject'], 'An empty index is not used yet');
        $this->assertSame($before['spatial'], $fields['spatial'], 'An empty index is not used yet');
    }

    public function testFieldTermResolvesIndexes(): void
    {
        $controller = $this->controller();
        $method = new \ReflectionMethod($controller, 'fieldTerm');
        $method->setAccessible(true);
        $cases = [
            'dcterms:subject' => ['dcterms:subject', null],
            'dcterms_subject_link_ss' => ['dcterms:subject', '_link_ss'],
            'dcterms_title_fr_ss' => ['dcterms:title', '_ss'],
            'dcterms_title_txt_fr' => ['dcterms:title', '_txt'],
            'dcterms_date_min_i' => ['dcterms:date', '_min_i'],
            'relevance' => [null, null],
        ];
        foreach ($cases as $value => [$term, $suffix]) {
            $args = [$value, null];
            $result = $method->invokeArgs($controller, [$value, &$args[1]]);
            $this->assertSame($term, $result, $value);
            $this->assertSame($suffix, $args[1], $value);
        }
    }
}
