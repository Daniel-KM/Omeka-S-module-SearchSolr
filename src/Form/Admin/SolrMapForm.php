<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2017
 * Copyright Daniel Berthereau, 2017-2025
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

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Omeka\Api\Manager as ApiManager;
use SearchSolr\ValueExtractor\Manager as ValueExtractorManager;
use SearchSolr\ValueFormatter\Manager as ValueFormatterManager;

class SolrMapForm extends Form
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $apiManager;

    /**
     * @var \SearchSolr\ValueExtractor\Manager
     */
    protected $valueExtractorManager;

    /**
     * @var \SearchSolr\ValueFormatter\Manager
     */
    protected $valueFormatterManager;

    /**
     * @todo Set main index and label first then all other values a collection. In controller, manage them as individual map. The aim is to create an index from multiple sources, like in show view.
     *
     * {@inheritDoc}
     * @see \Laminas\Form\Element::init()
     */
    public function init(): void
    {
        $this
            ->setAttribute('id', 'form-solr-map')
            ->add([
                'name' => 'o:resource_name',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Scope', // @translate
                    'value_options' => $this->getValueExtractorOptions(),
                ],
                'attributes' => [
                    'id' => 'o:resource_name',
                    'value' => 'items',
                    'required' => true,
                ],
            ])

            // TODO Allow to set multiple sources in the same map (store as dcterms:creator|dcterms:contributor).
            ->add([
                'name' => 'o:source',
                'type' => Element\Collection::class,
                'options' => [
                    'count' => 1,
                    'should_create_template' => true,
                    'allow_add' => true,
                    'label' => 'Source', // @translate
                    'label_attributes' => [
                        'class' => 'hidden',
                    ],
                    'info' => 'To select a sub-property allows to store a linked metadata when the property is filled with a resource. Thereby, an item can be found from the specified value of a linked item. For example an issue of a journal can be linked with the journal, so the issue can be found from the title of the journal.', // @translate
                    'target_element' => new SourceFieldset(null, [
                        'options' => $this->getSourceOptions(),
                    ]),
                ],
                'attributes' => [
                    'id' => 'o-source',
                    'required' => true,
                    'class' => 'source-resource',
                ],
            ])
        ;

        foreach ($this->valueExtractorManager->getRegisteredNames() as $name) {
            $this
                ->add([
                    'name' => 'o:source/' . $name,
                    'type' => Element\Collection::class,
                    'options' => [
                        'count' => 1,
                        'should_create_template' => true,
                        'allow_add' => true,
                        'label' => 'Source', // @translate
                        'label_attributes' => [
                            'class' => 'hidden',
                        ],
                        'info' => 'To select a sub-property allows to store a linked metadata when the property is filled with a resource. Thereby, an item can be found from the specified value of a linked item. For example an issue of a journal can be linked with the journal, so the issue can be found from the title of the journal.', // @translate
                        'target_element' => new SourceFieldset(null, [
                            'options' => $this->getSourceOptions($name),
                        ]),
                    ],
                    'attributes' => [
                        'id' => 'o-source-' . $name,
                        'required' => true,
                        'data-value-extractor' => $name,
                        'class' => 'source-resource',
                    ],
                ]);
        }

        $this
            // Temp fix for empty value options in DataTypeSelect in fieldset.
            ->add([
                'name' => 'data_types',
                'type' => CommonElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Only these data types', // @translate
                ],
                'attributes' => [
                    'id' => 'data_types',
                    'data-placeholder' => 'Select data types…', // @translate
                    'multiple' => true,
                    'required' => false,
                ],
            ])
        ;

        $poolFieldset = new Fieldset('o:pool');
        $poolFieldset
            ->add([
                'name' => 'filter_resources',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Only values of resources matching this standard query', // @translate
                ],
                'attributes' => [
                    'id' => 'filter_resources',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'filter_values',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Only values matching this regex', // @translate
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
                    'label' => 'Only uris matching this regex', // @translate
                ],
                'attributes' => [
                    'id' => 'filter_uris',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'filter_value_resources',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Only linked resources matching this standard query', // @translate
                ],
                'attributes' => [
                    'id' => 'filter_value_resources',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'data_types',
                'type' => CommonElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Only these data types', // @translate
                    // Fix use of DataTypeSelect in a fieldset.
                    'disable_inarray_validator' => true,
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
                'type' => CommonElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Exclude data types', // @translate
                    // Fix use of DataTypeSelect in a fieldset.
                    'disable_inarray_validator' => true,
                ],
                'attributes' => [
                    'id' => 'data_types_exclude',
                    'data-placeholder' => 'Select data types to exclude…', // @translate
                    'multiple' => true,
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'filter_languages',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Only languages', // @translate
                    'value_separator' => ' ',
                ],
                'attributes' => [
                    'id' => 'filter_languages',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'filter_visibility',
                'type' => Element\Radio::class,
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

        $this
            ->add($poolFieldset)

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
            ])
        ;

        $settingsFieldset = new Fieldset('o:settings');
        $settingsFieldset
            ->add([
                'name' => 'part',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Values to extract', // @translate
                    'value_options' => [
                        'auto' => 'Auto (extracted values as it is)', // @translate
                        'string' => 'Extracted values as string', // @translate
                        'value' => 'Values only (as stored in database)', // @translate
                        'uri' => 'Uri (for values with an uri)', // @translate
                        'html' => 'Html (as seen)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'part',
                    'value' => [
                        'auto',
                    ],
                ],
            ])
            ->add([
                'name' => 'formatter',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Formatter', // @translate
                    'value_options' => $this->getFormatterLabelsAndComments(),
                    'empty_option' => 'None', // @translate
                ],
                'attributes' => [
                    'id' => 'formatter',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'normalization',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Cleaning and normalization', // @translate
                    'info' => 'The cleaning is processed in the following order.', // @translate'
                    'value_options' => [
                        'html_escaped' => 'Escape html', // @translate
                        'strip_tags' => 'Strip tags', // @translate
                        'lowercase' => 'Lower case', // @translate
                        'uppercase' => 'Upper case', // @translate
                        'ucfirst' => 'Upper case first character', // @translate
                        'remove_diacritics' => 'Remove diacritics', // @translate
                        'alphanumeric' => 'Alphanumeric only', // @translate
                        'max_length' => 'Max length', // @translate
                        'integer' => 'Number', // @translate
                        'year' => 'Year', // @translate
                        'table' => 'Map value to a code or code to a value (module Table)', // @translate
                        // Table may be first post normalization or finalization too.
                        // TODO Allow to specify order of normalizations.
                    ],
                ],
                'attributes' => [
                    'id' => 'normalization',
                    'value' => [
                        'strip_tags',
                    ],
                    // Is used with all values.
                    // 'data-formatter' => 'text',
                ],
            ])
            ->add([
                'name' => 'max_length',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Max length', // @translate
                ],
                'attributes' => [
                    'id' => 'max_length',
                    // Setting for normalization "max_length" only.
                    'data-normalization' => 'max_length',
                ],
            ])

            ->add([
                'name' => 'place_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Place', // @translate
                    'value_options' => [
                        'country_and_toponym' => 'Country and toponym separately', // @translate
                        'toponym_and_country' => 'Toponym and country separately', // @translate
                        'country_toponym' => 'Country and toponym together', // @translate
                        'toponym_country' => 'Toponym and country together', // @translate
                        'toponym' => 'Toponym', // @translate
                        'country' => 'Country', // @translate
                        'coordinates' => 'Coordinates', // @translate
                        'latitude' => 'Latitude', // @translate
                        'longitude' => 'Longitude', // @translate
                        'html' => 'String with all data', // @translate
                        'array' => 'All data separately', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'place_mode',
                    'value' => 'country_and_toponym',
                    'data-formatter' => 'place',
                ],
            ])
        ;

        if (class_exists('Table\Module', false)) {
            $settingsFieldset
                ->add([
                    'name' => 'table',
                    'type' => \Table\Form\Element\TablesSelect::class,
                    'options' => [
                        'label' => 'Table for normalization "Table"', // @translate
                        'disable_group_by_owner' => true,
                        'empty_option' => '',
                    ],
                    'attributes' => [
                        'id' => 'table',
                        'class' => 'chosen-select',
                        'required' => false,
                        'data-placeholder' => 'Select a table…', // @translate
                        'value' => '',
                        // Setting for normalization "table" only.
                        'data-normalization' => 'table',
                    ],
                ])
                ->add([
                    'name' => 'table_mode',
                    'type' => Element\Radio::class,
                    'options' => [
                        'label' => 'Table: Mode of normalization', // @translate
                        'info' => 'If the value is displayed (facets, filters…), it is recommended to index label only.', // @translate
                        'value_options' => [
                            'label' => 'Label only', // @translate
                            'code' => 'Code only', // @translate
                            'both' => 'Label and code', // @translate
                        ],
                    ],
                    'attributes' => [
                        'id' => 'table_mode',
                        'required' => false,
                        'value' => 'label',
                        'data-normalization' => 'table',
                    ],
                ])
                ->add([
                    'name' => 'table_index_original',
                    'type' => Element\Checkbox::class,
                    'options' => [
                        'label' => 'Table: index original value too', // @translate
                    ],
                    'attributes' => [
                        'id' => 'table_index_original',
                        'required' => false,
                        'data-normalization' => 'table',
                    ],
                ])
                ->add([
                    'name' => 'table_check_strict',
                    'type' => Element\Checkbox::class,
                    'options' => [
                        'label' => 'Table: strict check (same case, same diacritics)', // @translate
                    ],
                    'attributes' => [
                        'id' => 'table_check_strict',
                        'required' => false,
                        'data-normalization' => 'table',
                    ],
                ]);

            // TODO Why the fieldset does not use form manager to load and init form element?
            $settingsFieldset->get('table')
                ->setApiManager($this->apiManager);
        }

        if (class_exists('Thesaurus\Module', false)) {
            $settingsFieldset
                ->add([
                    'name' => 'thesaurus_resources',
                    'type' => CommonElement\OptionalRadio::class,
                    'options' => [
                        'label' => 'Thesaurus resources', // @translate
                        'value_options' => [
                            'scheme' => 'Scheme', // @translate
                            'tops' => 'Tops', // @translate
                            'top' => 'Top', // @translate
                            'self' => 'Self', // @translate
                            'broader' => 'Broader', // @translate
                            'narrowers' => 'Narrowers', // @translate
                            'relateds' => 'Relateds', // @translate
                            'siblings' => 'Siblings', // @translate
                            'ascendants' => 'Ascendants', // @translate
                            'descendants' => 'Descendants', // @translate
                            'branch' => 'Branch (top to descendants)', // @translate
                        ],
                    ],
                    'attributes' => [
                        'id' => 'thesaurus_resources',
                        'class' => 'chosen-select',
                        'required' => false,
                        'data-placeholder' => 'Select a type of resources', // @translate
                        'value' => '',
                        // Setting for formatter "table" only.
                        'data-formatter' => 'thesaurus',
                    ],
                ])
                ->add([
                    'name' => 'thesaurus_self',
                    'type' => Element\Checkbox::class,
                    'options' => [
                        'label' => 'Include self', // @translate
                    ],
                    'attributes' => [
                        'id' => 'thesaurus_self',
                        'required' => false,
                        'data-formatter' => 'thesaurus',
                    ],
                ])
                ->add([
                    'name' => 'thesaurus_metadata',
                    'type' => CommonElement\OptionalMultiCheckbox::class,
                    'options' => [
                        'label' => 'Values indexed', // @translate
                        'value_options' => [
                            'o:id' => 'Resource id', // @translate
                            'skos:prefLabel' => 'Prefered label', // @translate
                            'skos:altLabel' => 'Alternative labels', // @translate
                            'skos:hiddenLabel' => 'Hidden labels', // @translate
                            'skos:notation' => 'Notation', // @translate
                            // TODO Other data? Useless for now.
                        ],
                    ],
                    'attributes' => [
                        'id' => 'thesaurus_metadata',
                        'required' => false,
                        'value' => 'skos:prefLabel',
                        'data-formatter' => 'thesaurus',
                    ],
                ])
            ;
        }

        $settingsFieldset
            ->add([
                'name' => 'finalization',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Finalization', // @translate
                    'info' => 'The option "path" allows to search ascendants or descendants inside a partial of full path automatically.', // @translate
                    'value_options' => [
                        'path' => 'Merge values as a path with parts separated with a "/"', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'finalization',
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
            ])
        ;

        $this
            ->add($settingsFieldset)
        ;

        $inputFilter = $this->getInputFilter();
        $inputFilter
            ->get('o:pool')
            ->add([
                'name' => 'filter_visibility',
                'required' => false,
            ]);
        $inputFilter
            ->get('o:settings')
            ->add([
                'name' => 'formatter',
                'required' => false,
            ])
            ->add([
                'name' => 'max_length',
                'required' => false,
            ])
            ->add([
                'name' => 'table_mode',
                'required' => false,
            ]);
    }

    /**
     * @param ApiManager $apiManager
     */
    public function setApiManager(ApiManager $apiManager): self
    {
        $this->apiManager = $apiManager;
        return $this;
    }

    /**
     * @param ValueExtractorManager $valueExtractorManager
     */
    public function setValueExtractorManager(ValueExtractorManager $valueExtractorManager): self
    {
        $this->valueExtractorManager = $valueExtractorManager;
        return $this;
    }

    /**
     * @param ValueFormatterManager $valueFormatterManager
     */
    public function setValueFormatterManager(ValueFormatterManager $valueFormatterManager): self
    {
        $this->valueFormatterManager = $valueFormatterManager;
        return $this;
    }

    public function getValueExtractorOptions(): array
    {
        $result = [];
        foreach ($this->valueExtractorManager->getRegisteredNames() as $name) {
            $result[$name] = $this->valueExtractorManager->get($name)->getLabel() ?: $name;
        }
        return $result;
    }

    protected function getSourceOptions(?string $resourceName = null): ?array
    {
        $resourceName ??= $this->getOption('resource_name');
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
        $noTableModule = !class_exists('Table\Module', false);
        $noThesaurusModule = !class_exists('Thesaurus\Module', false);

        $options = [];
        foreach ($this->valueFormatterManager->getRegisteredNames() as $name) {
            $valueFormatter = $this->valueFormatterManager->get($name);
            if ($noTableModule && $name === 'table') {
                $options[$name] = sprintf('%s (require module Table)', $valueFormatter->getLabel());
            } elseif ($noThesaurusModule && $name === 'thesaurus') {
                $options[$name] = sprintf('%s (require module Thesaurus)', $valueFormatter->getLabel());
            } else {
                $options[$name] = $valueFormatter->getLabel();
            }
        }

        return $options;
    }

    protected function getFormatterLabelsAndComments(): array
    {
        $noTableModule = !class_exists('Table\Module', false);
        $noThesaurusModule = !class_exists('Thesaurus\Module', false);

        $options = [];
        foreach ($this->valueFormatterManager->getRegisteredNames() as $name) {
            $valueFormatter = $this->valueFormatterManager->get($name);
            $optionsData = [
                'value' => $name,
                'label' => $valueFormatter->getLabel(),
                'attributes' => [
                    'title' => $valueFormatter->getComment(),
                ],
            ];
            if ($noTableModule && $name === 'table') {
                $optionsData['attributes']['disabled'] = 'disabled';
            } elseif ($noThesaurusModule && $name === 'thesaurus') {
                $optionsData['attributes']['disabled'] = 'disabled';
            }
            $options[] = $optionsData;
        }

        return $options;
    }
}
