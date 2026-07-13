<?php declare(strict_types=1);

namespace SearchSolr\Form\Admin;

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
        'templates' => 'Resource templates (used templates)', // @translate
        'used' => 'Used properties (every property with a value)', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'solr-core-sync-form')
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

        // Default: every source checked and the unused maps removed (the
        // widest, self-cleaning coverage). A multicheckbox reads its checked
        // options from the element value, not from an attribute.
        $this->get('sync_sources')->setValue(array_keys($this->sourceList));
        $this->get('multilingual')->setValue(true);
        $this->get('clean')->setValue(true);
    }
}
