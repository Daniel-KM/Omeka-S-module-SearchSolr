<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016
 * Copyright Daniel Berthereau 2020
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace SearchSolr\Form\Admin;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;

class SolrCoreForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'o:name',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Name', // @translate
                ],
                'attributes' => [
                    'id' => 'o-name',
                    'required' => true,
                    'placeholder' => 'omeka',
                ],
            ]);

        $settingsFieldset = new Fieldset('o:settings');
        $clientSettingsFieldset = new Fieldset('client');

        $clientSettingsFieldset
            ->add([
                'name' => 'scheme',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Scheme', // @translate
                ],
                'attributes' => [
                    'id' => 'scheme',
                    'required' => true,
                    'placeholder' => 'https',
                    'value' => 'http',
                ],
            ])
            ->add([
                'name' => 'host',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'IP or hostname', // @translate
                ],
                'attributes' => [
                    'id' => 'host',
                    'required' => true,
                    'placeholder' => 'localhost',
                ],
            ])
            ->add([
                'name' => 'port',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Port', // @translate
                ],
                'attributes' => [
                    'id' => 'port',
                    'required' => true,
                    'placeholder' => '8983',
                ],
            ])
            ->add([
                'name' => 'core',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Solr core', // @translate
                ],
                'attributes' => [
                    'id' => 'core',
                    'required' => true,
                    'placeholder' => 'omeka',
                ],
            ])
            ->add([
                'name' => 'secure',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Is secure', // @translate
                ],
                'attributes' => [
                    'id' => 'secure',
                ],
            ])
            ->add([
                'name' => 'username',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Solr user', // @translate
                ],
                'attributes' => [
                    'id' => 'username',
                    'required' => false,
                    'placeholder' => 'admin_solr',
                ],
            ])
            ->add([
                'name' => 'password',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Solr password', // @translate
                    'info' => 'Note: the password is saved clear in the database, so it is recommended to create a specific user.', // @translate
                ],
                'attributes' => [
                    'id' => 'password',
                    'required' => false,
                    'placeholder' => '******',
                ],
            ])
            ->add([
                'name' => 'bypass_certificate_check',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Bypass certificate check', // @translate
                    'info' => 'Avoid issue when the certificate expires.', // @translate
                ],
                'attributes' => [
                    'id' => 'bypass_certificate_check',
                ],
            ]);

        $settingsFieldset->add($clientSettingsFieldset);

        $settingsFieldset
            ->add([
                'name' => 'is_public_field',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Is public field', // @translate
                    'info' => 'Name of Solr field that will be set when a resource is public.
It must be a single-valued, boolean-based field (*_b in default solr config).', // @translate
                ],
                'attributes' => [
                    'id' => 'is_public_field',
                    'required' => true,
                    'placeholder' => 'is_public_b',
                    'value' => 'is_public_b',
                ],
            ])
            ->add([
                'name' => 'resource_name_field',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Resource name field', // @translate
                    'info' => 'Name of Solr field that will contain the resource name (or resource type, e.g. "items", "item_sets"…).
It must be a single-valued, string-based field (*_s in default solr config).', // @translate
                ],
                'attributes' => [
                    'id' => 'resource_name_field',
                    'required' => true,
                    'placeholder' => 'resource_name_s',
                    'value' => 'resource_name_s',
                ],
            ])
            ->add([
                'name' => 'sites_field',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Site ids field', // @translate
                    'info' => 'Name of Solr field that will contain the sites ids.
It must be a multi-valued, integer-based field (*_is in default solr config).', // @translate
                ],
                'attributes' => [
                    'id' => 'sites_field',
                    'required' => true,
                    'placeholder' => 'site_id_is',
                    'value' => 'site_id_is',
                ],
            ])
            ->add([
                'name' => 'index_field',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Engine field', // @translate
                    'info' => 'Name of Solr field that will contain the advanced search engine name in order to support multiple indexes on the same core.
This is an advanced feature that is not required in most of the cases.
It must be a single-valued, string-based field, like "index_id".', // @translate
                ],
                'attributes' => [
                    'id' => 'index_field',
                    'required' => false,
                    'placeholder' => 'index_id',
                ],
            ])
            ->add([
                'name' => 'support',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Index specific fields', // @translate
                    'value_options' => [
                        '' => 'No', // @translate
                        'drupal' => 'Drupal', // @translate
                    ],
                    'info' => 'Allow to store specific data needed to share a core with a third party. All field names should be manually adapted.', // @translate
                ],
                'attributes' => [
                    'id' => 'support',
                    'value' => '',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'server_id',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Server id for shared core', // @translate
                    'info' => sprintf('May be empty, or may be or may not be the same unique id than the third party, depending on its configuration. For information, the unique id of the install is "%s".', // @translate
                        $this->getOption('server_id')
                    ),
                ],
                'attributes' => [
                    'id' => 'server_id',
                ],
            ])
            ->add([
                'name' => 'resource_languages',
                // TODO The locale select is not working.
                // 'type' => 'Omeka\Form\Element\LocaleSelect',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Resource languages codes for shared core', // @translate
                    'info' => 'A third party may need to know the languages of a resource, even if it has no meaning in Omeka.', // @translate
                ],
                'attributes' => [
                    'id' => 'resource_languages',
                    'multiple' => true,
                    // 'value' => 'und',
                ],
            ])
            // TODO Replace the checkbox by a button.
            ->add([
                'name' => 'clear_full_index',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Clear all indexes, included external ones', // @translate
                    'info' => 'Warning: this button will clear all indexes on the core, included indexes externally managed if multi-index is set.', // @translate
                ],
                'attributes' => [
                    'id' => 'clear_full_index',
                ],
            ]);

        /*
        $settingsFieldset->get('resource_languages')
            ->setValueOptions(['und' => 'Undetermined'] + $settingsFieldset->get('resource_languages')->getValueOptions()) // @translate
            ->setEmptyOption(null);
        */

        $this->add($settingsFieldset);

        $querySettingsFieldset = new Fieldset('query');

        $querySettingsFieldset
            ->add([
                'name' => 'minimum_match',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Minimum match (or/and)', // @translate
                    'info' => 'Integer "1" means "OR", "100%" means "AND". Complex expressions are possible.
If empty, the config of the solr core (solrconfig.xml) will be used.', // @translate
                    'documentation' => 'https://lucene.apache.org/solr/guide/8_5/the-dismax-query-parser.html#mm-minimum-should-match-parameter',
                ],
                'attributes' => [
                    'required' => false,
                    'value' => '',
                    'placeholder' => '50%',
                ],
            ])
            ->add([
                'name' => 'tie_breaker',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Tie breaker', // @translate
                    'info' => 'Increase score according to the number of matched fields.
If empty, the config of the solr core (solrconfig.xml) will be used.', // @translate
                    'documentation' => 'https://lucene.apache.org/solr/guide/8_5/the-dismax-query-parser.html#the-tie-tie-breaker-parameter',
                ],
                'attributes' => [
                    'id' => 'tie_breaker',
                    'required' => false,
                    'value' => '',
                    'placeholder' => '0.10',
                    'inclusive' => true,
                    'min' => '0.0',
                    'max' => '1.0',
                    'step' => '0.01',
                ],
            ]);

        // TODO Other fields (boost...) requires multiple fields. See https://secure.php.net/manual/en/class.solrdismaxquery.php.

        $settingsFieldset->add($querySettingsFieldset);

        $inputFilter = $this->getInputFilter();
        $settingFilters = $inputFilter->get('o:settings');
        $settingFilters
            ->add([
                'name' => 'clear_full_index',
                'required' => false,
            ])
            ->add([
                'name' => 'support',
                'required' => false,
            ]);
        $settingFilters
            ->get('query')
            ->add([
                'name' => 'tie_breaker',
                'required' => false,
            ]);
        $settingFilters
            ->get('client')
            ->add([
                'name' => 'secure',
                'required' => false,
            ])
            ->add([
                'name' => 'bypass_certificate_check',
                'required' => false,
            ]);
    }
}
