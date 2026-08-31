<?php declare(strict_types=1);

namespace SearchSolr\Form\Admin;

use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * Select the sources to collect the properties from when syncing the maps.
 *
 * The sync creates the maps for the union of the checked sources, so the admin
 * chooses the coverage (search configs, settings, resource templates, used
 * properties) in one place instead of a list of preset actions.
 */
class SolrCoreSyncForm extends Form
{
    /**
     * @var array
     */
    protected $sourceList = [
        'configs' => 'Search configs (facets, filters, sorts, suggesters)', // @translate
        'settings' => 'Main settings (bounce links)', // @translate
        'site_settings' => 'Site settings (bounce links)', // @translate
        'templates' => 'Resource templates: a text and an exact index for every property of the used templates', // @translate
        'used' => 'Used properties: a text and an exact index for every property with a value', // @translate
        'media' => 'Media values: a text index on the item for every property used by its media, so a search matching a media returns the item', // @translate
        'datatypes' => 'Numeric values: an integer or decimal index for the properties whose values are numbers, for a real sort and range facets', // @translate
        'datatypes_text' => 'Numeric values: keep a text index too, so the numbers and the coordinates remain searchable in the main field', // @translate
        'media_long' => 'Media long values (ocr, transcription): include them in the index of the item. Warning: the whole text of every media is copied in the document of its item, so the index may become very large', // @translate
    ];

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
                        'sync' => 'Align the maps', // @translate
                        'audit' => 'Audit only: report what would change', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'sync_mode',
                    'value' => 'sync',
                ],
            ])
            ->add([
                'name' => 'sync_sources',
                'type' => 'multicheckbox',
                'options' => [
                    'label' => 'Sources of the properties to map', // @translate
                    'info' => 'The maps are created for the union of the checked sources.', // @translate
                    'value_options' => $this->sourceList,
                ],
                'attributes' => [
                    'id' => 'sync_sources',
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
                    'info' => 'Remove the property maps not referenced by the checked sources. Customized maps are kept.', // @translate
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

        // Default: the maps follow the real usages (configs and settings) and
        // the unused maps are removed. The exploratory sources (templates,
        // used properties) index every property and stay an explicit choice.
        // A multicheckbox reads its checked options from the element value,
        // not from an attribute.
        $this->get('sync_sources')->setValue(['configs', 'settings', 'site_settings']);
        $this->get('multilingual')->setValue(true);
        $this->get('clean')->setValue(true);
    }
}
