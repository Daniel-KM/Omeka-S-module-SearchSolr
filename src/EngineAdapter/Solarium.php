<?php declare(strict_types=1);

/*
 * Copyright Daniel Berthereau, 2017-2026
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

namespace SearchSolr\EngineAdapter;

use AdvancedSearch\EngineAdapter\AbstractEngineAdapter;
use Laminas\I18n\Translator\TranslatorInterface;
use Omeka\Api\Manager as ApiManager;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use SearchSolr\Form\Admin\SolrConfigFieldset;

class Solarium extends AbstractEngineAdapter
{
    /**
     * @param \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @param \Laminas\I18n\Translator\TranslatorInterface
     */
    protected $translator;

    protected $label = 'Solr [via Solarium]'; // @translate

    protected $configFieldsetClass = \SearchSolr\Form\Admin\SolrConfigFieldset::class;

    protected $indexerClass = \SearchSolr\Indexer\SolariumIndexer::class;

    protected $querierClass = \SearchSolr\Querier\SolariumQuerier::class;

    /**
     * @param ApiManager $api
     * @param TranslatorInterface $translator
     */
    public function __construct(ApiManager $api, TranslatorInterface $translator)
    {
        $this->api = $api;
        $this->translator = $translator;
    }

    public function getConfigFieldset(): ?\Laminas\Form\Fieldset
    {
        $solrCores = $this->api->search('solr_cores')->getContent();
        return new SolrConfigFieldset(null, ['solrCores' => $solrCores]);
    }

    public function getAvailableFields(): array
    {
        $solrCore = $this->getSolrCore();
        if (!$solrCore) {
            return [];
        }

        // TODO Add support of input field for id (from o:id).

        $fields = [];
        foreach ($solrCore->mapsOrderedByStructure() as $map) {
            $name = $map->fieldName();
            $fieldLabel = $map->setting('label', '');
            $fields[$name] = [
                'name' => $name,
                'label' => $fieldLabel,
                'from' => $map->source(),
            ];
        }

        // TODO Add support of aliases. See internal engine. Support aliases of Omeka arguments by default.

        return $fields;
    }

    public function getAvailableSortFields(): array
    {
        $solrCore = $this->getSolrCore();
        if (!$solrCore) {
            return [];
        }

        $schema = $solrCore->schema();

        $sortFields = [
            'relevance desc' => [
                'name' => 'relevance desc',
                'label' => $this->translator->translate('Relevance'),
            ],
        ];

        $directionLabel = [
            'asc' => $this->translator->translate('Asc'),
            'desc' => $this->translator->translate('Desc'),
        ];

        foreach ($solrCore->mapsOrderedByStructure() as $map) {
            $fieldName = $map->fieldName();
            $schemaField = $schema->getField($fieldName);
            if (!$schemaField || $schemaField->isMultivalued()) {
                continue;
            }
            $fieldLabel = $map->setting('label', '');
            foreach ($directionLabel as $direction => $labelDirection) {
                $name = $fieldName . ' ' . $direction;
                $sortFields[$name] = [
                    'name' => $name,
                    'label' => $fieldLabel ? $fieldLabel . ' ' . $labelDirection : '',
                ];
            }
        }

        return $sortFields;
    }

    public function getAvailableFacetFields(): array
    {
        $solrCore = $this->getSolrCore();
        if (!$solrCore) {
            return [];
        }

        $schema = $solrCore->schema();

        $fields = [];
        foreach ($solrCore->mapsOrderedByStructure() as $map) {
            $name = $map->fieldName();
            $schemaField = $schema->getField($name);
            if (!$schemaField || $schemaField->isGeneralText()) {
                continue;
            }
            $fieldLabel = $map->setting('label', '');
            $fields[$name] = [
                'name' => $name,
                'label' => $fieldLabel,
            ];
        }

        return $fields;
    }

    public function getAvailableFieldsForSelect(): array
    {
        $solrCore = $this->getSolrCore();
        if (!$solrCore) {
            return [];
        }

        $indexed = [];
        $mappedTerms = [];
        foreach ($solrCore->mapsOrderedByStructure() as $map) {
            $name = $map->fieldName();
            $fieldLabel = $map->setting('label', '');
            $label = $fieldLabel
                ? sprintf(
                    $this->translator->translate('%1$s (%2$s)'),
                    $fieldLabel, $name
                )
                : $name;
            $indexed[$name] = $label;
            $source = $map->source();
            if (strpos($source, ':') !== false
                && strpos($source, '/') === false
            ) {
                $mappedTerms[$source] = true;
            }
        }

        return $this->appendUnmappedProperties(
            $indexed, $mappedTerms
        );
    }

    public function getAvailableSortFieldsForSelect(): array
    {
        $solrCore = $this->getSolrCore();
        if (!$solrCore) {
            return [];
        }

        $schema = $solrCore->schema();

        $indexed = [
            'relevance desc' => $this->translator->translate('Relevance'),
        ];

        $directionLabel = [
            'asc' => $this->translator->translate('Asc'),
            'desc' => $this->translator->translate('Desc'),
        ];

        $mappedTerms = [];
        foreach ($solrCore->mapsOrderedByStructure() as $map) {
            $fieldName = $map->fieldName();
            $schemaField = $schema->getField($fieldName);
            if (!$schemaField || $schemaField->isMultivalued()) {
                continue;
            }
            $fieldLabel = $map->setting('label', '');
            foreach ($directionLabel as $direction => $labelDirection) {
                $name = $fieldName . ' ' . $direction;
                $indexed[$name] = $fieldLabel
                    ? sprintf(
                        $this->translator->translate('%1$s (%2$s)'),
                        $fieldLabel . ' ' . $labelDirection, $name
                    )
                    : $name;
            }
            $source = $map->source();
            if (strpos($source, ':') !== false
                && strpos($source, '/') === false
            ) {
                $mappedTerms[$source] = true;
            }
        }

        // For sort, unmapped properties use the term with
        // direction. The map will be created on sync.
        $unmapped = $this->getUnmappedPropertyTerms($mappedTerms);
        if ($unmapped) {
            $groupLabel = $this->translator->translate(
                'Properties (index auto-created on sync)'
            );
            $options = [];
            foreach ($unmapped as $term => $label) {
                foreach ($directionLabel as $dir => $dirLabel) {
                    $options[$term . ' ' . $dir] = $label
                        . ' ' . $dirLabel;
                }
            }
            $indexed[$groupLabel] = [
                'label' => $groupLabel,
                'options' => $options,
            ];
        }

        return $indexed;
    }

    public function getAvailableFacetFieldsForSelect(): array
    {
        $solrCore = $this->getSolrCore();
        if (!$solrCore) {
            return [];
        }

        $schema = $solrCore->schema();

        $indexed = [];
        $mappedTerms = [];
        foreach ($solrCore->mapsOrderedByStructure() as $map) {
            $name = $map->fieldName();
            $schemaField = $schema->getField($name);
            if (!$schemaField || $schemaField->isGeneralText()) {
                continue;
            }
            $fieldLabel = $map->setting('label', '');
            $indexed[$name] = sprintf(
                $this->translator->translate('%1$s (%2$s)'),
                $fieldLabel, $name
            );
            $source = $map->source();
            if (strpos($source, ':') !== false
                && strpos($source, '/') === false
            ) {
                $mappedTerms[$source] = true;
            }
        }

        return $this->appendUnmappedProperties(
            $indexed, $mappedTerms
        );
    }

    /**
     * Get property terms not yet mapped in the Solr core.
     *
     * @param array $mappedTerms [term => true] of already-mapped terms.
     * @return array [term => label] of unmapped properties.
     */
    protected function getUnmappedPropertyTerms(
        array $mappedTerms
    ): array {
        $properties = $this->api->search('properties')
            ->getContent();
        $unmapped = [];
        foreach ($properties as $property) {
            $term = $property->term();
            if (!isset($mappedTerms[$term])) {
                $unmapped[$term] = $property->label()
                    ? $property->label() . ' (' . $term . ')'
                    : $term;
            }
        }
        return $unmapped;
    }

    /**
     * Append unmapped properties as a grouped optgroup to the
     * indexed fields list, for use in select elements.
     */
    protected function appendUnmappedProperties(
        array $indexed,
        array $mappedTerms
    ): array {
        $unmapped = $this->getUnmappedPropertyTerms($mappedTerms);
        if (!$unmapped) {
            return $indexed;
        }
        $groupLabel = $this->translator->translate(
            'Properties (index auto-created on sync)'
        );
        $indexed[$groupLabel] = [
            'label' => $groupLabel,
            'options' => $unmapped,
        ];
        return $indexed;
    }

    public function getSolrCore(): ?SolrCoreRepresentation
    {
        if (!$this->searchEngine) {
            return null;
        }

        $solrCoreId = $this->searchEngine->settingEngineAdapter('solr_core_id');
        if (!$solrCoreId) {
            return null;
        }

        try {
            return $this->api->read('solr_cores', $solrCoreId)->getContent();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
