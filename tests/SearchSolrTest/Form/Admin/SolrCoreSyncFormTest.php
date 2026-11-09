<?php declare(strict_types=1);

namespace SearchSolrTest\Form\Admin;

use PHPUnit\Framework\TestCase;
use SearchSolr\Form\Admin\SolrCoreSyncForm;

/**
 * Conversion between the choices of the sync form and the list of sources.
 *
 * @group unit
 * @group form
 */
class SolrCoreSyncFormTest extends TestCase
{
    public function testDefaultChoicesMapSearchPagesAndBounceLinksOnly(): void
    {
        $this->assertSame(['configs', 'bounce'], SolrCoreSyncForm::sourcesFromData([]));
        $this->assertSame(['configs', 'bounce'], SolrCoreSyncForm::sourcesFromData([
            'sync_properties' => 'configs',
            'sync_numeric' => 'none',
            'sync_media' => 'none',
        ]));
    }

    public function testUpperLevelsIncludeLowerOnes(): void
    {
        $this->assertSame(
            ['configs', 'bounce', 'templates', 'used', 'datatypes', 'datatypes_text', 'media', 'media_long'],
            SolrCoreSyncForm::sourcesFromData([
                'sync_properties' => 'used',
                'sync_numeric' => 'datatypes_text',
                'sync_media' => 'media_long',
            ])
        );
    }

    public function testUnknownChoicesFallBackToDefaults(): void
    {
        $this->assertSame(['configs', 'bounce'], SolrCoreSyncForm::sourcesFromData([
            'sync_properties' => 'all',
            'sync_numeric' => 'foo',
            'sync_media' => 'bar',
        ]));
    }

    /**
     * The options stored before the form used the list of sources directly.
     */
    public function testStoredSourcesAreConvertedToChoices(): void
    {
        $this->assertSame(
            ['sync_properties' => 'configs', 'sync_numeric' => 'none', 'sync_media' => 'none'],
            SolrCoreSyncForm::dataFromSources(['configs', 'bounce'])
        );
        $this->assertSame(
            ['sync_properties' => 'templates', 'sync_numeric' => 'datatypes', 'sync_media' => 'media_long'],
            SolrCoreSyncForm::dataFromSources(['configs', 'templates', 'datatypes', 'media_long'])
        );
        $this->assertSame(
            ['sync_properties' => 'used', 'sync_numeric' => 'datatypes_text', 'sync_media' => 'media'],
            SolrCoreSyncForm::dataFromSources(['used', 'datatypes_text', 'media'])
        );
    }

    public function testRoundTrip(): void
    {
        foreach (array_keys(SolrCoreSyncForm::PROPERTIES) as $properties) {
            foreach (array_keys(SolrCoreSyncForm::NUMERIC) as $numeric) {
                foreach (array_keys(SolrCoreSyncForm::MEDIA) as $media) {
                    $data = ['sync_properties' => $properties, 'sync_numeric' => $numeric, 'sync_media' => $media];
                    $this->assertSame($data, SolrCoreSyncForm::dataFromSources(SolrCoreSyncForm::sourcesFromData($data)));
                }
            }
        }
    }
}
