<?php

/*
 * Copyright BibLibre, 2016
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

namespace Solr\Querier;

use Search\Querier\AbstractQuerier;
use Search\Querier\Exception\QuerierException;
use Search\Query;
use Search\Response;
use Solr\Api\Representation\SolrNodeRepresentation;
use SolrClient;
use SolrClientException;
use SolrQuery;
use SolrServerException;

class SolrQuerier extends AbstractQuerier
{
    /**
     * @var \SolrClient
     */
    protected $client;

    /**
     * @var SolrNodeRepresentation
     */
    protected $solrNode;

    public function query(Query $query)
    {
        $client = $this->getClient();

        $solrNode = $this->getSolrNode();
        $solrNodeSettings = $solrNode->settings();
        $isPublicField = $solrNodeSettings['is_public_field'];
        $resourceNameField = $solrNodeSettings['resource_name_field'];
        $sitesField = isset($solrNodeSettings['sites_field']) ? $solrNodeSettings['sites_field'] : null;

        $solrQuery = new SolrQuery;
        $q = $query->getQuery();
        if (empty($q)) {
            $q = '*:*';
        }
        $solrQuery->setQuery($q);
        $solrQuery->addField('id');

        $isPublic = $query->getIsPublic();
        if ($isPublic) {
            $solrQuery->addFilterQuery($isPublicField . ':' . 1);
        }

        $solrQuery->setGroup(true);
        $solrQuery->addGroupField($resourceNameField);

        $resources = $query->getResources();
        $fq = $resourceNameField . ':' . implode(' OR ', $resources);
        $solrQuery->addFilterQuery($fq);

        if ($sitesField) {
            $siteId = $query->getSiteId();
            if (isset($siteId)) {
                $fq = $sitesField . ':' . $siteId;
                $solrQuery->addFilterQuery($fq);
            }
        }

        $filters = $query->getFilters();
        foreach ($filters as $name => $values) {
            foreach ($values as $value) {
                if (is_array($value)) {
                    if (empty($value)) {
                        continue;
                    }
                    $value = '(' . implode(' OR ', array_map([$this, 'enclose'], $value)) . ')';
                } else {
                    $value = $this->enclose($value);
                }
                $solrQuery->addFilterQuery("$name:$value");
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

        $filters = $query->getFilterQueries();
        foreach ($filters as $name => $values) {
            foreach ($values as $value) {
                // There is no default in Omeka.
                // @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::buildPropertyQuery()
                $type = $value['type'];
                if (is_array($value['value'])) {
                    if (empty($value)) {
                        $value = '';
                    } else {
                        $value = '(' . implode(' OR ', array_map([$this, 'enclose'], $value['value'])) . ')';
                    }
                } else {
                    $value = $this->enclose($value['value']);
                }
                switch ($type) {
                    case 'neq':
                        $solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'eq':
                        $solrQuery->addFilterQuery("$name:$value");
                        break;

                    // TODO Fixes theses Solr queries.
                    case 'nin':
                        $solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'in':
                        $solrQuery->addFilterQuery("$name:$value");
                        break;

                    case 'nlist':
                        $solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'list':
                        $solrQuery->addFilterQuery("$name:$value");
                        break;

                    case 'nsw':
                        $solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'sw':
                        $solrQuery->addFilterQuery("$name:$value");
                        break;

                    case 'new':
                        $solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'ew':
                        $solrQuery->addFilterQuery("$name:$value");
                        break;

                    case 'nma':
                        $solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'ma':
                        $solrQuery->addFilterQuery("$name:$value");
                        break;

                    case 'nres':
                        $solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'res':
                        $solrQuery->addFilterQuery("$name:$value");
                        break;

                    case 'nex':
                        $solrQuery->addFilterQuery("-$name:[* TO *]");
                        break;
                    case 'ex':
                        $solrQuery->addFilterQuery("$name:[* TO *]");
                        break;
                }
            }
        }

        $sort = $query->getSort();
        if ($sort) {
            @list($sortField, $sortOrder) = explode(' ', $sort, 2);
            if ($sortField === 'score') {
                $sortOrder = $sortOrder === 'asc' ? SolrQuery::ORDER_ASC : SolrQuery::ORDER_DESC;
            } else {
                $sortOrder = $sortOrder === 'desc' ? SolrQuery::ORDER_DESC : SolrQuery::ORDER_ASC;
            }
            $solrQuery->addSortField($sortField, $sortOrder);
        }

        $limit = $query->getLimit();
        if ($limit) {
            $solrQuery->setGroupLimit($limit);
        }

        $offset = $query->getOffset();
        if ($offset) {
            $solrQuery->setGroupOffset($offset);
        }

        $facetFields = $query->getFacetFields();
        if (count($facetFields)) {
            $solrQuery->setFacet(true);
            foreach ($facetFields as $facetField) {
                $solrQuery->addFacetField($facetField);
            }
        }

        $facetLimit = $query->getFacetLimit();
        if ($facetLimit) {
            $solrQuery->setFacetLimit($facetLimit);
        }

        try {
            $solrQueryResponse = $client->query($solrQuery);
        } catch (SolrServerException $e) {
            // The query may be badly formatted, so try to escape all reserved
            // characters instead of returning an exception.
            // @link https://lucene.apache.org/core/7_2_1/queryparser/org/apache/lucene/queryparser/classic/package-summary.html#Escaping_Special_Characters
            // TODO Check before the query.
            $reservedCharacters = [
                // The character "\" must be escaped first.
                '\\' => '\\\\',
                '+' => '\+',
                '-' => '\-' ,
                '&&' => '\&\&',
                '||' => '\|\|',
                '!' => '\!',
                '(' => '\(' ,
                ')' => '\)',
                '{' => '\{',
                '}' => '\}',
                '[' => '\[',
                ']' => '\]',
                '^' => '\^',
                '"' => '\"',
                '~' => '\~',
                '*' => '\*',
                '?' => '\?',
                ':' => '\:',
            ];
            $escapedQ = str_replace(array_keys($reservedCharacters), array_values($reservedCharacters), $q);
            $solrQuery->setQuery($escapedQ);
            try {
                $solrQueryResponse = $client->query($solrQuery);
            } catch (SolrServerException $e) {
                throw new QuerierException($e->getMessage(), $e->getCode(), $e);
            }
        } catch (SolrClientException $e) {
            throw new QuerierException($e->getMessage(), $e->getCode(), $e);
        }
        $solrResponse = $solrQueryResponse->getResponse();

        $response = new Response;
        $response->setTotalResults($solrResponse['grouped'][$resourceNameField]['matches']);
        foreach ($solrResponse['grouped'][$resourceNameField]['groups'] as $group) {
            $response->setResourceTotalResults($group['groupValue'], $group['doclist']['numFound']);
            // In some cases, numFound can be greater than 1 and docs empty,
            // probably related to a config issue between items/item_sets.
            if ($group['doclist']['docs']) {
                foreach ($group['doclist']['docs'] as $doc) {
                    list(, $resourceId) = explode(':', $doc['id']);
                    $response->addResult($group['groupValue'], ['id' => $resourceId]);
                }
            }
        }

        if (!empty($solrResponse['facet_counts']['facet_fields'])) {
            foreach ($solrResponse['facet_counts']['facet_fields'] as $name => $values) {
                foreach ($values as $value => $count) {
                    if ($count > 0) {
                        $response->addFacetCount($name, $value, $count);
                    }
                }
            }
        }

        return $response;
    }

    /**
     * Protect a string for Solr.
     *
     * @param string $value
     * @return string
     */
    protected function enclose($value)
    {
        return '"' . addcslashes($value, '"') . '"';
    }

    /**
     * @return \SolrClient
     */
    protected function getClient()
    {
        if (!isset($this->client)) {
            $solrNode = $this->getSolrNode();
            $this->client = new SolrClient($solrNode->clientSettings());
        }

        return $this->client;
    }

    /**
     * @return SolrNodeRepresentation
     */
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
