<?php declare(strict_types=1);

namespace SearchSolrTest\Unit\ValueFormatter;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use PHPUnit\Framework\TestCase;
use SearchSolr\ValueFormatter\AbstractValueFormatter;

/**
 * Issue #14: the title of a linked resource can be indexed in a chosen language
 * via the per-map "resource_title_language" setting.
 *
 * @group unit
 * @group i18n
 */
class LinkedResourceTitleLanguageTest extends TestCase
{
    public function testMainUsesConfiguredLanguage(): void
    {
        $value = $this->linkedValue();
        $formatter = $this->formatter(['parts' => ['main'], 'resource_title_language' => 'es']);
        // preFormat() returns a re-indexed list of extracted values.
        $this->assertContains('Título ES', $formatter->preFormat($value));
    }

    public function testMainFallsBackToDefaultWithoutLanguage(): void
    {
        $value = $this->linkedValue();
        $formatter = $this->formatter(['parts' => ['main']]);
        $this->assertContains('Default Title', $formatter->preFormat($value));
    }

    public function testFullUsesConfiguredLanguage(): void
    {
        $value = $this->linkedValue();
        $formatter = $this->formatter(['parts' => ['full'], 'resource_title_language' => 'es']);
        $this->assertContains('Título ES', $formatter->preFormat($value));
    }

    private function formatter(array $settings): AbstractValueFormatter
    {
        $formatter = new class extends AbstractValueFormatter {
            public function format($value): array
            {
                return [];
            }
        };
        $formatter->setSettings($settings);
        return $formatter;
    }

    private function linkedValue(): ValueRepresentation
    {
        $resource = $this->createMock(AbstractResourceEntityRepresentation::class);
        $resource->method('displayTitle')->willReturnCallback(
            fn ($default = null, $lang = null) => $lang === 'es' ? 'Título ES' : 'Default Title'
        );

        $value = $this->createMock(ValueRepresentation::class);
        $value->method('value')->willReturn('');
        $value->method('uri')->willReturn('');
        $value->method('valueResource')->willReturn($resource);

        return $value;
    }
}
