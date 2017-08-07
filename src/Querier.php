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

use SolrClient;
use SolrClientException;
use SolrQuery;
use Search\Querier\AbstractQuerier;
use Search\Querier\Exception\QuerierException;
use Search\Query;
use Search\Response;

class Querier extends AbstractQuerier
{
    protected $client;
    protected $solrNode;

    public function query(Query $query)
    {
        $serviceLocator = $this->getServiceLocator();
        $settings = $serviceLocator->get('Omeka\Settings');

        $client = $this->getClient();

        $solrNode = $this->getSolrNode();
        $solrNodeSettings = $solrNode->settings();
        $resource_name_field = $solrNodeSettings['resource_name_field'];
        $sites_field = $solrNodeSettings['sites_field'];

        $solrQuery = new SolrQuery;
        $q = $query->getQuery();
        if (empty($q)) {
            $q = '*:*';
        }
        $solrQuery->setQuery($q);
        $solrQuery->addField('id');

        $solrQuery->setGroup(true);
        $solrQuery->addGroupField($resource_name_field);

        $resources = $query->getResources();
        $fq = $resource_name_field . ':' . implode(' OR ', $resources);
        $solrQuery->addFilterQuery($fq);

        $site = $query->getSite();
        if (isset($site)) {
            $fq = $sites_field . ':' . $site->id();
            $solrQuery->addFilterQuery($fq);
        }

        $facetFields = $query->getFacetFields();
        if (!empty($facetFields)) {
            $solrQuery->setFacet(true);
            foreach ($facetFields as $facetField) {
                $solrQuery->addFacetField($facetField);
            }
        }

        $facetLimit = $query->getFacetLimit();
        if ($facetLimit) {
            $solrQuery->setFacetLimit($facetLimit);
        }

        $filters = $query->getFilters();
        if (!empty($filters)) {
            foreach ($filters as $name => $values) {
                foreach ($values as $value) {
                    if (is_array($value) && !empty($value)) {
                        $value = '(' . implode(' OR ', array_map([$this, 'enclose'], $value)) . ')';
                    } else {
                        $value = $this->enclose($value);
                    }
                    $solrQuery->addFilterQuery("$name:$value");
                }
            }
        }

        $dateRangeFilters = $query->getDateRangeFilters();
        foreach ($dateRangeFilters as $name => $filterValues) {
            foreach ($filterValues as $filterValue) {
                $start = $filterValue['start'] ? $filterValue['start'] : '*';
                $end = $filterValue['end'] ? $filterValue['end'] : '*';
                $solrQuery->addFilterQuery("$name:[$start TO $end]");
            }
        }

        $sort = $query->getSort();
        if (isset($sort)) {
            list($sortField, $sortOrder) = explode(' ', $sort);
            $sortOrder = $sortOrder == 'asc' ? SolrQuery::ORDER_ASC : SolrQuery::ORDER_DESC;
            $solrQuery->addSortField($sortField, $sortOrder);
        }

        if ($limit = $query->getLimit()) {
            $solrQuery->setGroupLimit($limit);
        }

        if ($offset = $query->getOffset()) {
            $solrQuery->setGroupOffset($offset);
        }

        try {
            $solrQueryResponse = $client->query($solrQuery);
        } catch (SolrClientException $e) {
            throw new QuerierException($e->getMessage(), $e->getCode(), $e);
        }
        $solrResponse = $solrQueryResponse->getResponse();

        $response = new Response;
        $response->setTotalResults($solrResponse['grouped'][$resource_name_field]['matches']);
        foreach ($solrResponse['grouped'][$resource_name_field]['groups'] as $group) {
            $response->setResourceTotalResults($group['groupValue'], $group['doclist']['numFound']);
            foreach ($group['doclist']['docs'] as $doc) {
                list(, $resourceId) = explode(':', $doc['id']);
                $response->addResult($group['groupValue'], ['id' => $resourceId]);
            }
        }

        foreach ($solrResponse['facet_counts']['facet_fields'] as $name => $values) {
            foreach ($values as $value => $count) {
                if ($count > 0) {
                    $response->addFacetCount($name, $value, $count);
                }
            }
        }

        return $response;
    }

    protected function enclose($value)
    {
        return '"' . addcslashes($value, '"') . '"';
    }

    protected function getClient()
    {
        if (!isset($this->client)) {
            $solrNode = $this->getSolrNode();
            $this->client = new SolrClient($solrNode->clientSettings());
        }

        return $this->client;
    }

    protected function getSolrNode()
    {
        if (!isset($this->solrNode)) {
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');

            $solrNodeId = $this->getAdapterSetting('solr_node_id');
            if ($solrNodeId) {
                $response = $api->read('solr_nodes', $solrNodeId);
                $this->solrNode = $response->getContent();
            }
        }

        return $this->solrNode;
    }
}
