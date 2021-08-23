<?php declare(strict_types=1);

/*
 * Copyright Daniel Berthereau, 2017-2021
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

namespace SearchSolr\Adapter;

use AdvancedSearch\Adapter\AbstractAdapter;
use AdvancedSearch\Api\Representation\SearchEngineRepresentation;
use Laminas\I18n\Translator\TranslatorInterface;
use Omeka\Api\Manager as ApiManager;
use SearchSolr\Form\Admin\SolrConfigFieldset;

class SolariumAdapter extends AbstractAdapter
{
    /**
     * @param ApiManager $api
     */
    protected $api;

    /**
     * @param TranslatorInterface $translator
     */
    protected $translator;

    /**
     * @param ApiManager $api
     * @param TranslatorInterface $translator
     */
    public function __construct(ApiManager $api, TranslatorInterface $translator)
    {
        $this->api = $api;
        $this->translator = $translator;
    }

    public function getLabel(): string
    {
        return 'Solr [via Solarium]';
    }

    public function getConfigFieldset(): ?\Laminas\Form\Fieldset
    {
        $solrCores = $this->api->search('solr_cores')->getContent();
        return new SolrConfigFieldset(null, ['solrCores' => $solrCores]);
    }

    public function getIndexerClass(): string
    {
        return \SearchSolr\Indexer\SolariumIndexer::class;
    }

    public function getQuerierClass(): string
    {
        return \SearchSolr\Querier\SolariumQuerier::class;
    }

    public function getAvailableFields(SearchEngineRepresentation $index): array
    {
        $solrCoreId = $index->settingAdapter('solr_core_id');
        if (!$solrCoreId) {
            return [];
        }

        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $this->api->read('solr_cores', $solrCoreId)->getContent();

        $facetFields = [];
        foreach ($solrCore->maps() as $map) {
            $name = $map->fieldName();
            $label = $map->setting('label', '');
            $facetFields[$name] = [
                'name' => $name,
                'label' => $label,
            ];
        }

        ksort($facetFields);

        return $facetFields;
    }

    public function getAvailableSortFields(SearchEngineRepresentation $index): array
    {
        $solrCoreId = $index->settingAdapter('solr_core_id');
        if (!$solrCoreId) {
            return [];
        }

        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $this->api->read('solr_cores', $solrCoreId)->getContent();
        $schema = $solrCore->schema();

        $sortFields = [
            'score desc' => [
                'name' => 'score desc',
                'label' => $this->translator->translate('Relevance'),
            ],
        ];

        $directionLabel = [
            'asc' => $this->translator->translate('Asc'),
            'desc' => $this->translator->translate('Desc'),
        ];

        foreach ($solrCore->maps() as $map) {
            $fieldName = $map->fieldName();
            $schemaField = $schema->getField($fieldName);
            if (!$schemaField || $schemaField->isMultivalued()) {
                continue;
            }
            $label = $map->setting('label', '');
            foreach ($directionLabel as $direction => $labelDirection) {
                $name = $fieldName . ' ' . $direction;
                $sortFields[$name] = [
                    'name' => $name,
                    'label' => $label ? $label . ' ' . $labelDirection : '',
                ];
            }
        }

        ksort($sortFields);

        return $sortFields;
    }

    public function getAvailableFacetFields(SearchEngineRepresentation $index): array
    {
        return $this->getAvailableFields($index);
    }
}
