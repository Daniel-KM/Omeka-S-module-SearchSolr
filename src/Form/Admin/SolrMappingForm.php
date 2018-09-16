<?php

/*
 * Copyright BibLibre, 2017
 * Copyright Daniel Berthereau, 2017-2018
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

use Omeka\Api\Manager as ApiManager;
use Solr\ValueExtractor\Manager as ValueExtractorManager;
use Solr\ValueFormatter\Manager as ValueFormatterManager;
use Zend\Form\Element;
use Zend\Form\Fieldset;
use Zend\Form\Form;

class SolrMappingForm extends Form
{
    /**
     * @var ValueExtractorManager
     */
    protected $valueExtractorManager;

    /**
     * @var ValueFormatterManager
     */
    protected $valueFormatterManager;

    /**
     * @var ApiManager
     */
    protected $apiManager;

    public function init()
    {
        $this->add([
            'name' => 'o:source',
            'type' => Element\Collection::class,
            'options' => [
                'count' => 1,
                'should_create_template' => true,
                'allow_add' => true,
                'label' => 'Source', // @translate
                'info' => 'To select a sub-property allows to store a linked metadata when the property is filled with a resource. Thereby, an item can be found from the specified value of a linked item. For example an issue of a journal can be linked with the journal, so the issue can be found from the title of the journal.', // @translate
                'target_element' => new SourceFieldset(null, [
                    'options' => $this->getSourceOptions(),
                ]),
            ],
            'attributes' => [
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'o:field_name',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Solr field', // @translate
            ],
            'attributes' => [
                'required' => true,
            ],
        ]);

        $settingsFieldset = new Fieldset('o:settings');
        $settingsFieldset->add([
            'name' => 'formatter',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Formatter', // @translate
                'value_options' => $this->getFormatterOptions(),
                'empty_option' => 'None', // @translate
            ],
        ]);
        $settingsFieldset->add([
            'name' => 'label',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Default label', // @translate
                'info' => 'The label is automatically translated if it exists in Omeka.', // @translate
            ],
        ]);
        $this->add($settingsFieldset);

        $inputFilter = $this->getInputFilter();
        $settingsFilter = $inputFilter->get('o:settings');
        $settingsFilter->add([
            'name' => 'formatter',
            'required' => false,
        ]);
    }

    /**
     * @param ValueExtractorManager $valueExtractorManager
     */
    public function setValueExtractorManager(ValueExtractorManager $valueExtractorManager)
    {
        $this->valueExtractorManager = $valueExtractorManager;
    }

    /**
     * @return \Solr\ValueExtractor\Manager
     */
    public function getValueExtractorManager()
    {
        return $this->valueExtractorManager;
    }

    /**
     * @param ValueFormatterManager $valueFormatterManager
     */
    public function setValueFormatterManager(ValueFormatterManager $valueFormatterManager)
    {
        $this->valueFormatterManager = $valueFormatterManager;
    }

    /**
     * @return \Solr\ValueFormatter\Manager
     */
    public function getValueFormatterManager()
    {
        return $this->valueFormatterManager;
    }

    /**
     * @param ApiManager $apiManager
     */
    public function setApiManager(ApiManager $apiManager)
    {
        $this->apiManager = $apiManager;
    }

    /**
     * @return \Omeka\Api\Manager
     */
    public function getApiManager()
    {
        return $this->apiManager;
    }

    /**
     * @return array|null
     */
    protected function getSourceOptions()
    {
        $valueExtractorManager = $this->getValueExtractorManager();

        $resourceName = $this->getOption('resource_name');
        /** @var \Solr\ValueExtractor\ValueExtractorInterface $valueExtractor */
        $valueExtractor = $valueExtractorManager->get($resourceName);
        if (!isset($valueExtractor)) {
            return null;
        }

        return $this->getFieldsOptions($valueExtractor->getAvailableFields());
    }

    /**
     * @return array
     */
    protected function getFieldsOptions($fields, $valuePrefix = '', $labelPrefix = '')
    {
        $options = [];

        foreach ($fields as $name => $field) {
            $label = $field['label'];
            $value = $name;

            if (!empty($field['children'])) {
                $childrenOptions = $this->getFieldsOptions($field['children'],
                    $valuePrefix ? "$valuePrefix/$value" : $value,
                    $labelPrefix ? "$labelPrefix / $label" : $label);
                $options = array_merge($options, $childrenOptions);
            } else {
                $value = $valuePrefix ? "$valuePrefix/$value" : $value;
                if ($labelPrefix) {
                    if (!isset($options[$labelPrefix])) {
                        $options[$labelPrefix] = ['label' => $labelPrefix];
                    }
                    $options[$labelPrefix]['options'][$value] = $label;
                } else {
                    $options[$value] = $label;
                }
            }
        }

        return $options;
    }

    /**
     * @return array
     */
    protected function getFormatterOptions()
    {
        $valueFormatterManager = $this->getValueFormatterManager();

        $options = [];

        foreach ($valueFormatterManager->getRegisteredNames() as $name) {
            $valueFormatter = $valueFormatterManager->get($name);
            $options[$name] = $valueFormatter->getLabel();
        }

        return $options;
    }
}
