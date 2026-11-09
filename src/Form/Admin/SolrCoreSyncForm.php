<?php declare(strict_types=1);

namespace SearchSolr\Form\Admin;

use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * Select the properties and the additional indexes to map when syncing.
 *
 * The three choices are converted into the list of sources used by the sync,
 * so the stored options and the query arguments keep the same format.
 */
class SolrCoreSyncForm extends Form
{
    public const PROPERTIES = [
        'configs' => ['configs', 'bounce'],
        'templates' => ['configs', 'bounce', 'templates'],
        'used' => ['configs', 'bounce', 'templates', 'used'],
    ];

    public const NUMERIC = [
        'none' => [],
        'datatypes' => ['datatypes'],
        'datatypes_text' => ['datatypes', 'datatypes_text'],
    ];

    public const MEDIA = [
        'none' => [],
        'media' => ['media'],
        'media_long' => ['media', 'media_long'],
    ];

    /**
     * Convert the choices of the form into the list of sources.
     */
    public static function sourcesFromData(array $data): array
    {
        return array_merge(
            self::PROPERTIES[$data['sync_properties'] ?? ''] ?? self::PROPERTIES['configs'],
            self::NUMERIC[$data['sync_numeric'] ?? ''] ?? [],
            self::MEDIA[$data['sync_media'] ?? ''] ?? []
        );
    }

    /**
     * Convert a list of sources into the choices of the form.
     */
    public static function dataFromSources(array $sources): array
    {
        $has = fn (string $source): bool => in_array($source, $sources, true);
        return [
            'sync_properties' => $has('used') ? 'used' : ($has('templates') ? 'templates' : 'configs'),
            'sync_numeric' => $has('datatypes_text') ? 'datatypes_text' : ($has('datatypes') ? 'datatypes' : 'none'),
            'sync_media' => $has('media_long') ? 'media_long' : ($has('media') ? 'media' : 'none'),
        ];
    }

    public function init(): void
    {
        $this
            ->setAttribute('id', 'solr-core-sync-form')
            ->add([
                'name' => 'mode',
                'type' => 'radio',
                'options' => [
                    'label' => 'Mode', // @translate
                    'value_options' => [
                        'audit' => 'Audit to report what would change', // @translate
                        'sync' => 'Align the maps', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'sync_mode',
                    'value' => 'audit',
                ],
            ])
            ->add([
                'name' => 'sync_properties',
                'type' => 'radio',
                'options' => [
                    'label' => 'Properties to map', // @translate
                    'info' => 'The properties of the search pages (facets, filters, sorts, suggesters) and of the bounce links of the module Advanced Resource Template are always mapped. Each property gets a text index and an exact index.', // @translate
                    'value_options' => [
                        'configs' => 'Search pages and bounce links', // @translate
                        'templates' => 'Plus the properties of the resource templates', // @translate
                        'used' => 'Plus all the used properties', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'sync_properties',
                    'value' => 'configs',
                ],
            ])
            ->add([
                'name' => 'sync_numeric',
                'type' => 'radio',
                'options' => [
                    'label' => 'Numeric values', // @translate
                    'info' => 'An integer or decimal index is added to the properties whose values are numbers, for a real sort and range facets. Their text index is useless in most cases, but it keeps the numbers and the coordinates searchable in the main field.', // @translate
                    'value_options' => [
                        'none' => 'No numeric index', // @translate
                        'datatypes' => 'Numeric index', // @translate
                        'datatypes_text' => 'Numeric and text indexes', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'sync_numeric',
                    'value' => 'none',
                ],
            ])
            ->add([
                'name' => 'sync_media',
                'type' => 'radio',
                'options' => [
                    'label' => 'Media values on their item', // @translate
                    'info' => 'The values of the media are indexed on their item, so a search matching a media returns the item. The long values (ocr, transcription) copy the whole text of every media in the document of its item, so the index may become very large.', // @translate
                    'value_options' => [
                        'none' => 'Not indexed', // @translate
                        'media' => 'Short values', // @translate
                        'media_long' => 'Short and long values', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'sync_media',
                    'value' => 'none',
                ],
            ])
            ->add([
                'name' => 'max_cardinality',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Maximum number of distinct values for an exact index', // @translate
                    'info' => 'A property with more distinct values than this limit gets no exact index (_ss): such a facet or filter is unusable. A property used by a facet or a filter of a search page keeps its index anyway. Set 0 to skip this check.', // @translate
                ],
                'attributes' => [
                    'id' => 'max_cardinality',
                    'min' => '0',
                    'step' => '1',
                    'value' => '100',
                ],
            ])
            ->add([
                'name' => 'multilingual',
                'type' => 'checkbox',
                'options' => [
                    'label' => 'Support of multilingual values', // @translate
                    'info' => 'Add a language index by property and language, for example dcterms_subject_fr_ss and dcterms_subject_en_ss. Used only when the sites have at least two distinct locales and the property has values in at least two of these languages. The values without language are included in each language index.', // @translate
                ],
                'attributes' => [
                    'id' => 'multilingual',
                ],
            ])
            ->add([
                'name' => 'clean',
                'type' => 'checkbox',
                'options' => [
                    'label' => 'Remove unused maps automatically', // @translate
                    'info' => 'Remove the property maps not referenced by the selected options. Customized maps are kept.', // @translate
                ],
                'attributes' => [
                    'id' => 'clean',
                ],
            ])
            ->add([
                'name' => 'csrf',
                'type' => 'csrf',
                'options' => [
                    'csrf_options' => ['timeout' => 3600],
                ],
            ]);

        // Default: the maps follow the real usages (configs and bounce links)
        // and the unused maps are removed. The exploratory choices index every
        // property and stay explicit.
        $this->get('multilingual')->setValue(true);
        $this->get('clean')->setValue(true);
    }
}
