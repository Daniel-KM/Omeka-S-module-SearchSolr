<?php

/*
 * Copyright BibLibre, 2016
 * Copyright Daniel Berthereau, 2017-2020
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

namespace SearchSolr\Querier;

use Search\Querier\AbstractQuerier;
use Search\Querier\Exception\QuerierException;
use Search\Response;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use Solarium\Client as SolariumClient;
use SolrClientException;
use SolrDisMaxQuery;
use SolrQuery;
use SolrServerException;

class SolariumQuerier extends AbstractQuerier
{
    /**
     * @var Response
     */
    protected $response;

    /**
     * @var SolrQuery
     */
    protected $solrQuery;

    /**
     * @var SolariumClient
     */
    protected $solariumClient;

    /**
     * @var SolrCoreRepresentation
     */
    protected $solrCore;

    public function query()
    {
        $this->response = new Response;

        $this->solrQuery = $this->getPreparedQuery() ;
        if (is_null($this->solrQuery)) {
            return $this->response;
        }

        try {
            $solrQueryResponse = $this->solrClient->query($this->solrQuery);
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
            // The solr query cannot be an empty string.
            $q = $this->query->getQuery() ?: '*:*';
            $escapedQ = str_replace(array_keys($reservedCharacters), array_values($reservedCharacters), $q);
            $this->solrQuery->setQuery($escapedQ);
            try {
                $solrQueryResponse = $this->solrClient->query($this->solrQuery);
            } catch (SolrServerException $e) {
                throw new QuerierException($e->getMessage(), $e->getCode(), $e);
            }
        } catch (SolrClientException $e) {
            throw new QuerierException($e->getMessage(), $e->getCode(), $e);
        }

        $solrResponse = $solrQueryResponse->getResponse();

        $solrCoreSettings = $this->solrCore->settings();
        $resourceNameField = $solrCoreSettings['resource_name_field'];

        $this->response->setTotalResults($solrResponse['grouped'][$resourceNameField]['matches']);
        foreach ($solrResponse['grouped'][$resourceNameField]['groups'] as $group) {
            $this->response->setResourceTotalResults($group['groupValue'], $group['doclist']['numFound']);
            // In some cases, numFound can be greater than 1 and docs empty,
            // probably related to a config issue between items/item_sets.
            if ($group['doclist']['docs']) {
                foreach ($group['doclist']['docs'] as $doc) {
                    list(, $resourceId) = explode(':', $doc['id']);
                    $this->response->addResult($group['groupValue'], ['id' => $resourceId]);
                }
            }
        }

        if (!empty($solrResponse['facet_counts']['facet_fields'])) {
            foreach ($solrResponse['facet_counts']['facet_fields'] as $name => $values) {
                foreach ($values as $value => $count) {
                    if ($count > 0) {
                        $this->response->addFacetCount($name, $value, $count);
                    }
                }
            }
        }

        return $this->response;
    }

    /**
     * @return SolrDisMaxQuery|SolrQuery|null
     *
     * {@inheritDoc}
     * @see \Search\Querier\AbstractQuerier::getPreparedQuery()
     */
    public function getPreparedQuery()
    {
        $this->init();

        if (empty($this->query)) {
            $this->solrQuery = null;
            return $this->solrQuery;
        }

        $solrCoreSettings = $this->solrCore->settings();
        $isPublicField = $solrCoreSettings['is_public_field'];
        $resourceNameField = $solrCoreSettings['resource_name_field'];
        $sitesField = isset($solrCoreSettings['sites_field']) ? $solrCoreSettings['sites_field'] : null;

        if (class_exists('SolrDisMaxQuery')) {
            $this->solrQuery = new SolrDisMaxQuery;
        } else {
            // Kept if the class SolrDisMaxQuery is not available.
            $this->solrQuery = new SolrQuery;
        }

        $isDefaultQuery = $this->defaultQuery();
        if (!$isDefaultQuery) {
            $this->mainQuery();
        }

        $this->solrQuery->addField('id');

        $isPublic = $this->query->getIsPublic();
        if ($isPublic) {
            $this->solrQuery->addFilterQuery($isPublicField . ':' . 1);
        }

        $this->solrQuery->setGroup(true);
        $this->solrQuery->addGroupField($resourceNameField);

        $resources = $this->query->getResources();
        $fq = $resourceNameField . ':(' . implode(' OR ', $resources) . ')';
        $this->solrQuery->addFilterQuery($fq);

        if ($sitesField) {
            $siteId = $this->query->getSiteId();
            if (isset($siteId)) {
                $fq = $sitesField . ':' . $siteId;
                $this->solrQuery->addFilterQuery($fq);
            }
        }

        $filters = $this->query->getFilters();
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
                $this->solrQuery->addFilterQuery("$name:$value");
            }
        }

        $normalizeDate = function ($value) {
            if ($value) {
                if (strlen($value) < 20) {
                    $value = substr_replace('0000-01-01T00:00:00Z', $value, 0, strlen($value) - 20);
                }
                try {
                    $value = new \DateTime($value);
                    return $value->format('Y-m-d\TH:i:s\Z');
                } catch (\Exception $e) {
                }
            }
            return '*';
        };

        $dateRangeFilters = $this->query->getDateRangeFilters();
        foreach ($dateRangeFilters as $name => $filterValues) {
            // Normalize dates if needed.
            $normalize = substr_compare($name, '_dt', -3) === 0
                || substr_compare($name, '_dts', -4) === 0
                || substr_compare($name, '_pdt', -4) === 0
                || substr_compare($name, '_tdt', -4) === 0
                || substr_compare($name, '_pdts', -5) === 0
                || substr_compare($name, '_tdts', -5) === 0;
            foreach ($filterValues as $filterValue) {
                if ($normalize) {
                    $start = $normalizeDate($filterValue['start']);
                    $end = $normalizeDate($filterValue['end']);
                } else {
                    $start = $filterValue['start'] ? $filterValue['start'] : '*';
                    $end = $filterValue['end'] ? $filterValue['end'] : '*';
                }
                $this->solrQuery->addFilterQuery("$name:[$start TO $end]");
            }
        }

        $this->addFilterQueries();

        $sort = $this->query->getSort();
        if ($sort) {
            @list($sortField, $sortOrder) = explode(' ', $sort, 2);
            if ($sortField === 'score') {
                $sortOrder = $sortOrder === 'asc' ? SolrQuery::ORDER_ASC : SolrQuery::ORDER_DESC;
            } else {
                $sortOrder = $sortOrder === 'desc' ? SolrQuery::ORDER_DESC : SolrQuery::ORDER_ASC;
            }
            $this->solrQuery->addSortField($sortField, $sortOrder);
        }

        $limit = $this->query->getLimit();
        if ($limit) {
            $this->solrQuery->setGroupLimit($limit);
        }

        $offset = $this->query->getOffset();
        if ($offset) {
            $this->solrQuery->setGroupOffset($offset);
        }

        $facetFields = $this->query->getFacetFields();
        if (count($facetFields)) {
            $this->solrQuery->setFacet(true);
            foreach ($facetFields as $facetField) {
                $this->solrQuery->addFacetField($facetField);
            }
        }

        $facetLimit = $this->query->getFacetLimit();
        if ($facetLimit) {
            $this->solrQuery->setFacetLimit($facetLimit);
        }

        return $this->solrQuery;
    }

    protected function defaultQuery()
    {
        // The default query is managed by the module Search.
        // Here, this is a catch-them-all query.
        $defaultQuery = '*:*';

        if (class_exists('SolrDisMaxQuery')) {
            // Kept to avoid a crash when there is no query or blank query,
            // and no alternative query.
            $this->solrQuery->setQueryAlt($defaultQuery);
        }

        $q = $this->query->getQuery();
        if (strlen($q)) {
            return false;
        }

        $this->solrQuery->setQuery($defaultQuery);
        return true;
    }

    protected function mainQuery()
    {
        $q = $this->query->getQuery();
        $excludedFiles = $this->query->getExcludedFields();

        $solrCoreSettings = $this->solrCore->settings();
        $queryConfig = array_filter($solrCoreSettings['query']);
        if ($queryConfig && class_exists('SolrDisMaxQuery')) {
            if (isset($queryConfig['minimum_match'])) {
                $this->solrQuery->setMinimumMatch($queryConfig['minimum_match']);
            }
            if (isset($queryConfig['tie_breaker'])) {
                $this->solrQuery->setTieBreaker($queryConfig['tie_breaker']);
            }
        }

        if (strlen($q)) {
            if ($excludedFiles && $q !== '*:*') {
                $this->mainQueryWithExcludedFields();
            } else {
                $this->solrQuery->setQuery($q);
            }
        }
    }

    /**
     * Only called from mainQuery(). $q is never empty.
     */
    protected function mainQueryWithExcludedFields()
    {
        // Currently, the only way to exclude fields is to search in all other
        // fields.
        $usedFields = $this->usedSolrFields();
        $excludedFields = $this->query->getExcludedFields();
        $usedFields = array_diff($usedFields, $excludedFields);
        if (!count($usedFields)) {
            return;
        }

        $q = $this->query->getQuery();
        $qq = [];
        foreach ($usedFields as $field) {
            $qq[] = $field . ':' . $q;
        }
        $this->solrQuery->setQuery(implode(' ', $qq));
    }

    protected function addFilterQueries()
    {
        // There are two way to add filter queries: multiple simple filters, or
        // one complex filter query. A complex filter may be required when the
        // joiners are mixed with "and" and "or".
        // if ($multiple) {
        //     $this->addMultipleFilterQueries();
        //     return;
        // }

        $filters = $this->query->getFilterQueries();
        if (!$filters) {
            return;
        }

        $first = true;
        $fq = '';
        foreach ($filters as $name => $values) {
            foreach ($values as $value) {
                // There is no default in Omeka.
                // @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::buildPropertyQuery()
                $type = $value['type'];
                $value = $this->encloseValue($value, $type);
                $joiner = @$value['joiner'] ?: 'and';
                switch ($type) {
                    case 'neq':
                    case 'nin':
                        if ($first) {
                            $fq .= "-$name:$value";
                            $first = false;
                        } else {
                            // FIXME "Or" + "not contains" ?
                            $fq .= $joiner === 'and' ? " -$name:$value" : " $name:$value";
                        }
                        break;
                    case 'eq':
                    case 'in':
                        if ($first) {
                            $fq .= "+$name:$value";
                            $first = false;
                        } else {
                            $fq .= $joiner === 'and' ? " +$name:$value" : " $name:$value";
                        }
                        break;
                }
            }
        }
        $this->solrQuery->addFilterQuery($fq);
    }

    protected function addMultipleFilterQueries()
    {
        $filters = $this->query->getFilterQueries();
        foreach ($filters as $name => $values) {
            foreach ($values as $value) {
                $type = $value['type'];
                $value = $this->encloseValue($value['value'], $type);
                // There is no default in Omeka.
                // @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::buildPropertyQuery()
                switch ($type) {
                    case 'neq':
                        $this->solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'eq':
                        $this->solrQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'nin':
                        $this->solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'in':
                        $this->solrQuery->addFilterQuery("+$name:$value");
                        break;

                    // TODO Fixes theses Solr queries.
                    case 'nlist':
                        $this->solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'list':
                        $this->solrQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'nsw':
                        $this->solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'sw':
                        $this->solrQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'new':
                        $this->solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'ew':
                        $this->solrQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'nma':
                        $this->solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'ma':
                        $this->solrQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'nres':
                        $this->solrQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'res':
                        $this->solrQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'nex':
                        $this->solrQuery->addFilterQuery("-$name:[* TO *]");
                        break;
                    case 'ex':
                        $this->solrQuery->addFilterQuery("+$name:[* TO *]");
                        break;
                }
            }
        }
    }

    protected function usedSolrFields()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation[] $maps */
        return $api->search('solr_maps', [
            'solr_core_id' => $this->solrCore->id(),
        ], ['returnScalar' => 'fieldName'])->getContent();
    }

    protected function encloseValue($value, $type = null)
    {
        if (in_array($type, ['in', 'nin'])) {
            if (is_array($value)) {
                if (empty($value)) {
                    $value = '';
                } else {
                    $value = array_map(function ($v) {
                        return '*' . $v . '*';
                    }, $value);
                }
            } else {
                $value = '*' . $value . '*';
            }
        }

        if (is_array($value)) {
            if (empty($value)) {
                $value = '';
            } else {
                $value = '(' . implode(' OR ', array_map([$this, 'enclose'], $value)) . ')';
            }
        } else {
            $value = $this->enclose($value);
        }

        return $value;
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
     * @return self
     */
    protected function init()
    {
        $this->getSolrCore();
        return $this;
    }

    /**
     * @return \SearchSolr\Api\Representation\SolrCoreRepresentation
     */
    protected function getSolrCore()
    {
        if (!isset($this->solrCore)) {
            $solrCoreId = $this->getAdapterSetting('solr_core_id');
            if ($solrCoreId) {
                $api = $this->getServiceLocator()->get('Omeka\ApiManager');
                // Automatically throw an exception when empty.
                $this->solrCore = $api->read('solr_cores', $solrCoreId)->getContent();
                $this->solariumClient = $this->solrCore->solariumClient();
            }
        }
        return $this->solrCore;
    }

    /**
     * @return \SolrClient
     */
    protected function getClient()
    {
        if (!isset($this->solariumClient)) {
            $this->solariumClient = $this->getSolrCore()->solariumClient();
        }
        return $this->solariumClient;
    }
}
