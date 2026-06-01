<?php declare(strict_types=1);

namespace SearchSolrTest\Unit\ValueFormatter;

use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;
use SearchSolr\ValueFormatter\AbstractValueFormatter;

/**
 * Unit tests for the "Table" normalization (module Table integration).
 *
 * They mock the api and a TableRepresentation, so they don't require a real
 * Table nor a database. The formatTable() method caches tables statically, so
 * each scenario uses a distinct table id to avoid cross-test pollution.
 *
 * @group unit
 * @group table
 */
class FormatTableTest extends TestCase
{
    public function testLabelModeMapsCodeToLabel(): void
    {
        $formatter = $this->formatter(
            ['table' => 'tbl-label', 'table_mode' => 'label'],
            'tbl-label',
            ['1' => 'Yes', '0' => 'No'],
            []
        );
        $this->assertSame(['Yes'], $formatter->formatTable('1'));
    }

    public function testCodeModeMapsLabelToCode(): void
    {
        $formatter = $this->formatter(
            ['table' => 'tbl-code', 'table_mode' => 'code'],
            'tbl-code',
            [],
            ['Yes' => '1', 'No' => '0']
        );
        $this->assertSame(['1'], $formatter->formatTable('Yes'));
    }

    public function testBothModeReturnsLabelAndCode(): void
    {
        $formatter = $this->formatter(
            ['table' => 'tbl-both', 'table_mode' => 'both'],
            'tbl-both',
            ['1' => 'Yes'],
            ['1' => 'one']
        );
        $this->assertSame(['Yes', 'one'], $formatter->formatTable('1'));
    }

    public function testIndexOriginalKeepsRawValue(): void
    {
        $formatter = $this->formatter(
            ['table' => 'tbl-orig', 'table_mode' => 'label', 'table_index_original' => true],
            'tbl-orig',
            ['1' => 'Yes'],
            []
        );
        $this->assertSame(['1', 'Yes'], $formatter->formatTable('1'));
    }

    public function testStrictOptionIsPassedToTable(): void
    {
        $table = $this->table(['1' => 'Yes'], []);
        $formatter = $this->formatterWithTable(
            ['table' => 'tbl-strict', 'table_mode' => 'label', 'table_check_strict' => true],
            'tbl-strict',
            $table
        );
        $formatter->formatTable('1');
        $this->assertTrue($table->lastStrict);
    }

    public function testUnknownCodeReturnsEmpty(): void
    {
        $formatter = $this->formatter(
            ['table' => 'tbl-unknown', 'table_mode' => 'label'],
            'tbl-unknown',
            ['1' => 'Yes'],
            []
        );
        $this->assertSame([], $formatter->formatTable('42'));
    }

    public function testMissingTableSettingReturnsRawValue(): void
    {
        $formatter = $this->formatter([], 'tbl-none', [], []);
        $this->assertSame(['raw'], $formatter->formatTable('raw'));
    }

    public function testAbsentTableReturnsRawValue(): void
    {
        // The api throws for an unknown slug: the value is returned as-is.
        $formatter = $this->formatterWithApi(
            ['table' => 'tbl-absent', 'table_mode' => 'label'],
            $this->api('other-slug', null)
        );
        $this->assertSame(['raw'], $formatter->formatTable('raw'));
    }

    private function formatter(array $settings, string $slug, array $codeToLabel, array $labelToCode): AbstractValueFormatter
    {
        return $this->formatterWithTable($settings, $slug, $this->table($codeToLabel, $labelToCode));
    }

    private function formatterWithTable(array $settings, string $slug, object $table): AbstractValueFormatter
    {
        return $this->formatterWithApi($settings, $this->api($slug, $table));
    }

    private function formatterWithApi(array $settings, object $api): AbstractValueFormatter
    {
        $logger = new class {
            public function err($message, array $context = []): void
            {
            }
        };
        $services = new ServiceManager();
        $services->setService('Omeka\Logger', $logger);
        $services->setService('Omeka\ApiManager', $api);

        $formatter = new class extends AbstractValueFormatter {
            public function format($value): array
            {
                return [];
            }
        };
        $formatter->setServiceLocator($services);
        $formatter->setSettings($settings);
        return $formatter;
    }

    private function table(array $codeToLabel, array $labelToCode): object
    {
        return new class($codeToLabel, $labelToCode) {
            public bool $lastStrict = false;

            public function __construct(private array $codeToLabel, private array $labelToCode)
            {
            }

            public function labelFromCode($code, bool $strict = false): ?string
            {
                $this->lastStrict = $strict;
                return $this->codeToLabel[$code] ?? null;
            }

            public function codeFromLabel($label, bool $strict = false): ?string
            {
                $this->lastStrict = $strict;
                return $this->labelToCode[$label] ?? null;
            }
        };
    }

    private function api(string $knownSlug, ?object $table): object
    {
        $response = new class($table) {
            public function __construct(private ?object $table)
            {
            }

            public function getContent(): ?object
            {
                return $this->table;
            }
        };
        return new class($knownSlug, $response) {
            public function __construct(private string $knownSlug, private object $response)
            {
            }

            public function read($resource, $query)
            {
                $ref = $query['slug'] ?? $query['id'] ?? null;
                if ($ref !== $this->knownSlug) {
                    throw new \RuntimeException('Table not found.');
                }
                return $this->response;
            }
        };
    }
}
