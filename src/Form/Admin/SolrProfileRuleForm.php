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

use Zend\Form\Fieldset;
use Zend\Form\Form;
use Zend\I18n\Translator\TranslatorAwareInterface;
use Zend\I18n\Translator\TranslatorAwareTrait;
use Solr\ValueExtractor\Manager as ValueExtractorManager;

class SolrProfileRuleForm extends Form implements TranslatorAwareInterface
{
    use TranslatorAwareTrait;

    protected $valueExtractorManager;
    protected $apiManager;

    public function init()
    {
        $translator = $this->getTranslator();

        $solrFieldFieldset = new Fieldset('o:solr_field');
        $solrFieldFieldset->add([
            'name' => 'o:id',
            'type' => 'Select',
            'options' => [
                'label' => $translator->translate('Solr field'),
                'value_options' => $this->getSolrFieldsOptions(),
            ],
            'attributes' => [
                'required' => true,
            ],
        ]);
        $this->add($solrFieldFieldset);

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
    }

    public function setValueExtractorManager(ValueExtractorManager $valueExtractorManager)
    {
        $this->valueExtractorManager = $valueExtractorManager;
    }

    public function getValueExtractorManager()
    {
        return $this->valueExtractorManager;
    }

    public function setApiManager($apiManager)
    {
        $this->apiManager = $apiManager;
    }

    public function getApiManager()
    {
        return $this->apiManager;
    }

    protected function getSolrFieldsOptions()
    {
        $api = $this->getApiManager();

        $solrProfile = $this->getSolrProfile();
        $response = $api->search('solr_fields', [
            'solr_node_id' => $solrProfile->solrNode()->id(),
        ]);
        $solrFields = $response->getContent();

        $options = [];
        foreach ($solrFields as $solrField) {
            $options[$solrField->id()] = $solrField->name();
        }
        return $options;
    }

    protected function getSourceOptions()
    {
        $valueExtractorManager = $this->getValueExtractorManager();

        $solrProfile = $this->getSolrProfile();
        $valueExtractor = $valueExtractorManager->get($solrProfile->resourceName());
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
                $label = $labelPrefix ? "$labelPrefix / $label" : $label;
                $options[$value] = $label;
            }
        }

        return $options;
    }

    protected function getSolrProfile()
    {
        $api = $this->getApiManager();
        $solrProfileId = $this->getOption('solr_profile_id');
        return $api->read('solr_profiles', $solrProfileId)->getContent();
    }
}
