<?php

/*
 * Copyright BibLibre, 2016
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

namespace Solr\Form\Admin;

use Zend\Form\Element;
use Zend\Form\Fieldset;
use Zend\Form\Form;

class SolrNodeForm extends Form
{
    public function init()
    {
        $this->add([
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

        $clientSettingsFieldset->add([
            'name' => 'hostname',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'IP or hostname', // @translate
            ],
            'attributes' => [
                'id' => 'hostname',
                'required' => true,
                'placeholder' => 'localhost',
            ],
        ]);

        $clientSettingsFieldset->add([
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
        ]);

        $clientSettingsFieldset->add([
            'name' => 'path',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Solr node path', // @translate
            ],
            'attributes' => [
                'id' => 'path',
                'required' => true,
                'placeholder' => 'solr/omeka',
            ],
        ]);

        $clientSettingsFieldset->add([
            'name' => 'secure',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Is secure', // @translate
            ],
            'attributes' => [
                'id' => 'secure',
            ],
        ]);

        $clientSettingsFieldset->add([
            'name' => 'login',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Solr login (if secured)', // @translate
            ],
            'attributes' => [
                'id' => 'login',
                'required' => false,
                'placeholder' => 'admin@example.org',
            ],
        ]);

        $clientSettingsFieldset->add([
            'name' => 'password',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Solr password (if secured)', // @translate
                'info' => 'Note: the password is saved clear in the database, so it is recommended to create a specific user.', // @translate
            ],
            'attributes' => [
                'id' => 'password',
                'required' => false,
                'placeholder' => '******',
            ],
        ]);

        $settingsFieldset->add($clientSettingsFieldset);

        $settingsFieldset->add([
            'name' => 'is_public_field',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Is public field', // @translate
                'info' => 'Name of Solr field that will be set when a resource is public.
It must be a single-valued, boolean-based field (*_b).', // @translate
            ],
            'attributes' => [
                'id' => 'is_public_field',
                'required' => true,
                'placeholder' => 'is_public_b',
            ],
        ]);

        $settingsFieldset->add([
            'name' => 'resource_name_field',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Resource name field', // @translate
                'info' => 'Name of Solr field that will contain the resource name (or resource type, e.g. "items", "item_sets"…).
It must be a single-valued, string-based field (*_s).', // @translate
            ],
            'attributes' => [
                'id' => 'resource_name_field',
                'required' => true,
                'placeholder' => 'resource_name_s',
            ],
        ]);

        $settingsFieldset->add([
            'name' => 'sites_field',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Site ids field', // @translate
                'info' => 'Name of Solr field that will contain the sites ids.
It must be a multi-valued, integer-based field (*_is).', // @translate
            ],
            'attributes' => [
                'id' => 'sites_field',
                'required' => true,
                'placeholder' => 'site_id_is',
            ],
        ]);

        $this->add($settingsFieldset);

        $querySettingsFieldset = new Fieldset('query');

        $querySettingsFieldset->add([
            'name' => 'query_alt',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Default query', // @translate
                'info' => 'This alternative query is used when the user does not query anything, allowing to choose a default result.
If empty, the config of the solr node (solrconfig.xml) will be used.', // @translate
                'documentation' => 'https://lucene.apache.org/solr/guide/7_5/the-dismax-query-parser.html#q-alt-parameter',
            ],
            'attributes' => [
                'id' => 'query_alt',
                'required' => false,
                'value' => '',
                'placeholder' => '*:*',
            ],
        ]);

        $querySettingsFieldset->add([
            'name' => 'minimum_match',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Minimum match (or/and)', // @translate
                'info' => 'Integer "1" means "OR", "100%" means "AND". Complex expressions are possible.
If empty, the config of the solr node (solrconfig.xml) will be used.', // @translate
                'documentation' => 'https://lucene.apache.org/solr/guide/7_5/the-dismax-query-parser.html#mm-minimum-should-match-parameter',
            ],
            'attributes' => [
                'required' => false,
                'value' => '',
                'placeholder' => '50%',
            ],
        ]);

        $querySettingsFieldset->add([
            'name' => 'tie_breaker',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Tie breaker', // @translate
                'info' => 'Increase score according to the number of matched fields.
If empty, the config of the solr node (solrconfig.xml) will be used.', // @translate
                'documentation' => 'https://lucene.apache.org/solr/guide/7_5/the-dismax-query-parser.html#the-tie-tie-breaker-parameter',
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

        $inputFilter = $this->getInputFilter([]);
        $inputFilter->get('o:settings')->get('query')->add([
            'name' => 'tie_breaker',
            'required' => false,
        ]);
    }
}
