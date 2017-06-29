<?php

/*
 * Copyright BibLibre, 2017
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

use Zend\Form\Fieldset;
use Zend\Form\Form;
use Zend\I18n\Translator\TranslatorAwareInterface;
use Zend\I18n\Translator\TranslatorAwareTrait;
use Solr\ValueExtractor\Manager as ValueExtractorManager;
use Solr\ValueFormatter\Manager as ValueFormatterManager;

class SolrMappingForm extends Form implements TranslatorAwareInterface
{
    use TranslatorAwareTrait;

    protected $valueExtractorManager;
    protected $valueFormatterManager;
    protected $apiManager;

    public function init()
    {
        $translator = $this->getTranslator();

        $this->add([
            'name' => 'o:source',
            'type' => 'Select',
            'options' => [
                'label' => $translator->translate('Source'),
                'value_options' => $this->getSourceOptions(),
            ],
            'attributes' => [
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'o:field_name',
            'type' => 'Text',
            'options' => [
                'label' => $translator->translate('Solr field'),
            ],
            'attributes' => [
                'required' => true,
            ],
        ]);

        $settingsFieldset = new Fieldset('o:settings');
        $settingsFieldset->add([
            'name' => 'formatter',
            'type' => 'Select',
            'options' => [
                'label' => $translator->translate('Formatter'),
                'value_options' => $this->getFormatterOptions(),
            ],
        ]);
        $this->add($settingsFieldset);
    }

    public function setValueExtractorManager(ValueExtractorManager $valueExtractorManager)
    {
        $this->valueExtractorManager = $valueExtractorManager;
    }

    public function getValueExtractorManager()
    {
        return $this->valueExtractorManager;
    }

    public function setValueFormatterManager(ValueFormatterManager $valueFormatterManager)
    {
        $this->valueFormatterManager = $valueFormatterManager;
    }

    public function getValueFormatterManager()
    {
        return $this->valueFormatterManager;
    }

    public function setApiManager($apiManager)
    {
        $this->apiManager = $apiManager;
    }

    public function getApiManager()
    {
        return $this->apiManager;
    }

    protected function getSourceOptions()
    {
        $valueExtractorManager = $this->getValueExtractorManager();

        $resourceName = $this->getOption('resource_name');
        $valueExtractor = $valueExtractorManager->get($resourceName);
        if (!isset($valueExtractor)) {
            return null;
        }

        return $this->getFieldsOptions($valueExtractor->getAvailableFields());
    }

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

    protected function getFormatterOptions()
    {
        $valueFormatterManager = $this->getValueFormatterManager();

        // TODO Find a way to tell Zend to accept empty values
        $options = [
            '0' => $this->getTranslator()->translate('None'),
        ];

        foreach ($valueFormatterManager->getRegisteredNames() as $name) {
            $valueFormatter = $valueFormatterManager->get($name);
            $options[$name] = $valueFormatter->getLabel();
        }

        return $options;
    }
}
