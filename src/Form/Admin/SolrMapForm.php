<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2017
 * Copyright Daniel Berthereau, 2017-2023
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

use AdvancedSearch\Form\Element as AdvancedSearchElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Omeka\Api\Manager as ApiManager;
use SearchSolr\Form\Element as SearchSolrElement;
use SearchSolr\ValueExtractor\Manager as ValueExtractorManager;
use SearchSolr\ValueFormatter\Manager as ValueFormatterManager;

class SolrMapForm extends Form
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

    public function init(): void
    {
        $this
            ->add([
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
                    'id' => 'o:source',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'o:pool',
                'type' => Fieldset::class,
            ])
            ->add([
                'name' => 'o:field_name',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Solr field', // @translate
                ],
                'attributes' => [
                    'id' => 'o:pool',
                    'required' => true,
                ],
            ]);

        $this
            ->get('o:pool')
            ->add([
                'name' => 'filter_values',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Index only values matching this regex', // @translate
                ],
                'attributes' => [
                    'id' => 'filter_values',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'filter_uris',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Index only uris matching this regex', // @translate
                ],
                'attributes' => [
                    'id' => 'filter_uris',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'filter_resources',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Index only resources matching this standard query', // @translate
                ],
                'attributes' => [
                    'id' => 'filter_resources',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'filter_value_resources',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Index only value resources matching this standard query', // @translate
                ],
                'attributes' => [
                    'id' => 'filter_value_resources',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'data_types',
                'type' => SearchSolrElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Index only these data types', // @translate
                ],
                'attributes' => [
                    'id' => 'data_types',
                    'data-placeholder' => 'Select data types…', // @translate
                    'multiple' => true,
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'data_types_exclude',
                'type' => SearchSolrElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Exclude data types', // @translate
                ],
                'attributes' => [
                    'id' => 'data_types_exclude',
                    'data-placeholder' => 'Select data types to exclude…', // @translate
                    'multiple' => true,
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'filter_visibility',
                'type' => AdvancedSearchElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Only visibility', // @translate
                    'value_options' => [
                        '' => 'All', // @translate
                        'public' => 'Public', // @translate
                        'private' => 'Private', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'filter_visibility',
                    'required' => false,
                    'value' => '',
                ],
            ]);

        $settingsFieldset = new Fieldset('o:settings');
        $settingsFieldset
            ->add([
                'name' => 'formatter',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Formatter', // @translate
                    'value_options' => $this->getFormatterOptions(),
                    'empty_option' => 'None', // @translate
                ],
                'attributes' => [
                    'id' => 'formatter',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'label',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Default label', // @translate
                    'info' => 'The label is automatically translated if it exists in Omeka.', // @translate
                ],
                'attributes' => [
                    'id' => 'label',
                ],
            ]);
        $this->add($settingsFieldset);

        $inputFilter = $this->getInputFilter();
        $inputFilter->get('o:settings')
            ->add([
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
        return $this;
    }

    /**
     * @param ValueFormatterManager $valueFormatterManager
     */
    public function setValueFormatterManager(ValueFormatterManager $valueFormatterManager)
    {
        $this->valueFormatterManager = $valueFormatterManager;
        return $this;
    }

    /**
     * @param ApiManager $apiManager
     */
    public function setApiManager(ApiManager $apiManager)
    {
        $this->apiManager = $apiManager;
        return $this;
    }

    /**
     * @return array|null
     */
    protected function getSourceOptions()
    {
        $resourceName = $this->getOption('resource_name');
        /** @var \SearchSolr\ValueExtractor\ValueExtractorInterface $valueExtractor */
        $valueExtractor = $this->valueExtractorManager->get($resourceName);
        if (!isset($valueExtractor)) {
            return null;
        }

        // Recursive select is no more used, neither prefix/suffix.
        // See older version if needed.
        return $valueExtractor->getMapFields();
    }

    protected function getFormatterOptions(): array
    {
        $options = [];
        foreach ($this->valueFormatterManager->getRegisteredNames() as $name) {
            $valueFormatter = $this->valueFormatterManager->get($name);
            $options[$name] = $valueFormatter->getLabel();
        }
        return $options;
    }
}
