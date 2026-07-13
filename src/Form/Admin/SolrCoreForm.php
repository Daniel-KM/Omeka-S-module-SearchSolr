<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016
 * Copyright Daniel Berthereau, 2020-2026
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
            ->setAttribute('id', 'solr-core-form');

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
        $this
            ->add($settingsFieldset);

        $clientSettingsFieldset = new Fieldset('client');
        $settingsFieldset
            ->add($clientSettingsFieldset);

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
                'name' => 'config_set',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Config set (to create the core)', // @translate
                    'info' => 'Used only when creating the core on the server. The config set must exist in SOLR_HOME/configsets on the Solr server ("_default" is shipped with Solr). Leave empty when the core is created manually on the server.', // @translate
                ],
                'attributes' => [
                    'id' => 'config_set',
                    'required' => false,
                    'placeholder' => '_default',
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
                ],
            ])
            ->add([
                'name' => 'admin_username',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Solr admin user (core operations)', // @translate
                    'info' => 'Used only to create or delete cores on the server, which requires admin rights. Leave empty to reuse the Solr user above it it has admin rights.', // @translate
                ],
                'attributes' => [
                    'id' => 'admin_username',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'admin_password',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Solr admin password (core operations)', // @translate
                    'info' => 'Note: the password is saved clear in the database, so it is recommended to create a specific user.', // @translate
                ],
                'attributes' => [
                    'id' => 'admin_password',
                    'required' => false,
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
            ])
            ->add([
                'name' => 'http_request_type',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Http request type', // @translate
                    'info' => 'With "get by default", only big queries use "post" (limit is 1024 bytes) and it avoids "414 URI too long" with many facets or filters, but post queries are not cached.With "get", requests always use "get" and fail when too long. So keep "post" unless an HTTP cache layer requires pure "get".', // @translate
                    'documentation' => 'https://solarium.readthedocs.io/en/latest/plugins/#postbigrequest-plugin',
                    'value_options' => [
                        'post' => '"Get" by default and "Post" for big queries (not cacheable)', // @translate
                        'get' => '"Get" only (fail on big queries)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'http_request_type',
                    'required' => false,
                    'value' => 'post',
                ],
            ])
        ;

        $settingsFieldset
            ->add([
                'name' => 'filter_resources',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Filter resources to index with a specific query', // @translate
                    'info' => 'Allow to store only an item set, a template, an owner, a visibility, etc.', // @translate
                ],
                'attributes' => [
                    'id' => 'filter_resources',
                    'value' => '',
                    'required' => false,
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
                    'label' => 'Resource languages 2-letters iso codes for shared core', // @translate
                    'info' => 'A third party may need to know the languages of a resource, even if it has no meaning in Omeka. Use "und" for undetermined.', // @translate
                ],
                'attributes' => [
                    'id' => 'resource_languages',
                    'multiple' => true,
                    'placeholder' => 'fr de sp und',
                ],
            ])
        ;

        /*
        $settingsFieldset->get('resource_languages')
            ->setValueOptions(['und' => 'Undetermined'] + $settingsFieldset->get('resource_languages')->getValueOptions()) // @translate
            ->setEmptyOption(null);
        */

        $querySettingsFieldset = new Fieldset('query');
        $querySettingsFieldset
            ->setLabel('Query settings'); // @translate
        $settingsFieldset
            ->add($querySettingsFieldset);

        // Add informational message about the copy field _text_.
        $copyFieldInfo = $this->getOption('copy_field_info');
        if ($copyFieldInfo) {
            if ($copyFieldInfo['has_copy_field']) {
                $fieldType = $copyFieldInfo['field_type'] ?? 'unknown';
                $isOptimized = $fieldType === 'text_search';
                $info = $isOptimized
                    ? 'The catchall copy field "_text_" is present and uses the type "text_search" (Google-like search with EdgeNGram). To change it, use the "Search configuration" section in the core show page.' // @translate
                    : 'The catchall copy field "_text_" is present and uses a standard type (strict matching). To change it, use the "Search configuration" section in the core show page.'; // @translate
            } else {
                $info = 'The catchall copy field "_text_" is not configured. Without it, full-text search will not return results. Use the "Create _text_" button in the core show page to create it.'; // @translate
            }
            $querySettingsFieldset
                ->add([
                    'name' => 'copy_field_info',
                    'type' => Element\Hidden::class,
                    'options' => [
                        'label' => 'Catchall copy field', // @translate
                        'info' => $info,
                    ],
                    'attributes' => [
                        'id' => 'copy_field_info',
                        'value' => '',
                    ],
                ]);
        }

        // Query relevance settings (minimum match, tie breaker) were moved to
        // the "Search configuration" section on the core show page, with the
        // catchall analyzer, since they tune search behaviour rather than the
        // connection/indexing defined here.

        // TODO Other fields (boost...) requires multiple fields. See https://secure.php.net/manual/en/class.solrdismaxquery.php.

        $inputFilter = $this->getInputFilter();
        $settingFilters = $inputFilter->get('o:settings');
        $settingFilters
            ->add([
                'name' => 'support',
                'required' => false,
            ]);
        $settingFilters
            ->get('query')
            ->add([
                'name' => 'copy_field_info',
                'required' => false,
            ]);
        $settingFilters
            ->get('client')
            ->add([
                'name' => 'config_set',
                'required' => false,
            ])
            ->add([
                'name' => 'secure',
                'required' => false,
            ])
            ->add([
                'name' => 'bypass_certificate_check',
                'required' => false,
            ])
            ->add([
                'name' => 'http_request_type',
                'required' => false,
            ])
        ;
    }
}
