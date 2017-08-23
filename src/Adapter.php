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

namespace Solr;

use Zend\I18n\Translator\TranslatorInterface;
use Omeka\Api\Manager as ApiManager;
use Search\Adapter\AbstractAdapter;
use Search\Api\Representation\SearchIndexRepresentation;
use Solr\Form\ConfigFieldset;

class Adapter extends AbstractAdapter
{
    protected $api;
    protected $translator;

    public function __construct(ApiManager $api, TranslatorInterface $translator)
    {
        $this->api = $api;
        $this->translator = $translator;
    }

    public function getLabel()
    {
        return 'Solr';
    }

    public function getConfigFieldset()
    {
        $solrNodes = $this->api->search('solr_nodes')->getContent();

        return new ConfigFieldset(null, ['solrNodes' => $solrNodes]);
    }

    public function getIndexerClass()
    {
        return 'Solr\Indexer';
    }

    public function getQuerierClass()
    {
        return 'Solr\Querier';
    }

    public function getAvailableFacetFields(SearchIndexRepresentation $index)
    {
        return $this->getAvailableFields($index);
    }

    public function getAvailableSortFields(SearchIndexRepresentation $index)
    {
        $settings = $index->settings();
        $solrNodeId = $settings['adapter']['solr_node_id'];
        if (!$solrNodeId) {
            return [];
        }

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

        $solrNode = $this->api->read('solr_nodes', $solrNodeId)->getContent();
        $schema = $solrNode->schema();
        $response = $this->api->search('solr_mappings', [
            'solr_node_id' => $solrNode->id(),
        ]);
        $mappings = $response->getContent();
        foreach ($mappings as $mapping) {
            $fieldName = $mapping->fieldName();
            $schemaField = $schema->getField($fieldName);
            if ($schemaField && !$schemaField->isMultivalued()) {
                foreach (['asc', 'desc'] as $direction) {
                    $name = $fieldName . ' ' . $direction;
                    $sortFields[$name] = [
                        'name' => $name,
                    ];
                }
            }
        }

        return $sortFields;
    }

    public function getAvailableFields(SearchIndexRepresentation $index)
    {
        $settings = $index->settings();
        $solrNodeId = $settings['adapter']['solr_node_id'];
        $response = $this->api->search('solr_mappings', [
            'solr_node_id' => $solrNodeId,
        ]);
        $mappings = $response->getContent();
        $fields = [];
        foreach ($mappings as $mapping) {
            $name = $mapping->fieldName();
            $facetFields[$name] = [
                'name' => $name,
            ];
        }

        return $facetFields;
    }
}
