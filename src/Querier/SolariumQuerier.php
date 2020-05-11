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
use Solarium\QueryType\Select\Query\Query as SolariumQuery;

class SolariumQuerier extends AbstractQuerier
{
    /**
     * @var Response
     */
    protected $response;

    /**
     * @var SolariumQuery
     */
    protected $solariumQuery;

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

        $this->solariumQuery = $this->getPreparedQuery();
        if (is_null($this->solariumQuery)) {
            return $this->response;
        }

        try {
            $solariumResultSet = $this->solariumClient->execute($this->solariumQuery);
        } catch (\Exception $e) {
            // TODO Is it still needed?
            // The query may be badly formatted, so try to escape all reserved
            // characters instead of returning an exception.
            // @link https://lucene.apache.org/core/8_5_1/queryparser/org/apache/lucene/queryparser/classic/package-summary.html#Escaping_Special_Characters
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
            $this->solariumQuery->setQuery($escapedQ);
            try {
                $solariumResultSet = $this->solariumClient->execute($this->solariumQuery);
            } catch (\Exception $e) {
                throw new QuerierException($e->getMessage(), $e->getCode(), $e);
            }
        } catch (\Exception $e) {
            throw new QuerierException($e->getMessage(), $e->getCode(), $e);
        }

        // Normalize the response for module Search.
        // getData() allows to get everything as array.
        // $solariumResultSet->getData();

        // The result is always grouped, so getNumFound() is empty. The same for getDocuments().
        // There is only one grouping here: by resource name (items/item sets).
        foreach ($solariumResultSet->getGrouping() as $fieldGroup) {
            $this->response->setTotalResults($fieldGroup->getMatches());
            foreach ($fieldGroup as $valueGroup) {
                $groupName = $valueGroup->getValue();
                $this->response->setResourceTotalResults($groupName, $valueGroup->getNumFound());
                foreach ($valueGroup as $document) {
                    list(, $resourceId) = explode(':', $document['id']);
                    $this->response->addResult($groupName, ['id' => $resourceId]);
                }
            }
        }

        $facetSet = $solariumResultSet->getFacetSet();
        if ($facetSet) {
            foreach ($facetSet as $name => $values) {
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
     * @todo Improve the integration of Solarium. Many things can be added directly as option or as array.
     *
     * @return SolariumQuery|null
     *
     * {@inheritDoc}
     * @see \Search\Querier\AbstractQuerier::getPreparedQuery()
     */
    public function getPreparedQuery()
    {
        $this->init();

        if (empty($this->query)) {
            $this->solariumQuery = null;
            return $this->solariumQuery;
        }

        $solrCoreSettings = $this->solrCore->settings();
        $isPublicField = $solrCoreSettings['is_public_field'];
        $resourceNameField = $solrCoreSettings['resource_name_field'];
        $sitesField = isset($solrCoreSettings['sites_field']) ? $solrCoreSettings['sites_field'] : null;

        // TODO Add a param to select DisMaxQuery, standard query, eDisMax, or external query parsers.

        $this->solariumQuery = $this->solariumClient->createSelect();

        $isDefaultQuery = $this->defaultQuery();
        if (!$isDefaultQuery) {
            $this->mainQuery();
        }

        $this->solariumQuery->addField('id');

        $isPublic = $this->query->getIsPublic();
        if ($isPublic) {
            $this->solariumQuery
                ->createFilterQuery($isPublicField)
                ->setQuery('1');
        }

        $this->solariumQuery
            ->getGrouping()
            ->addField($resourceNameField)
            ->setNumberOfGroups(true);

        $resources = $this->query->getResources();
        $this->solariumQuery
            ->createFilterQuery($resourceNameField)
            ->setQuery('(' . implode(' OR ', $resources) . ')');

        if ($sitesField) {
            $siteId = $this->query->getSiteId();
            if (isset($siteId)) {
                $this->solariumQuery
                    ->createFilterQuery($sitesField)
                    ->setQuery($siteId);
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
                $this->solariumQuery
                    ->createFilterQuery($name)
                    ->setQuery($value);
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
                $this->solariumQuery
                    ->createFilterQuery($name)
                    ->setQuery("[$start TO $end]");
            }
        }

        $this->addFilterQueries();

        $sort = $this->query->getSort();
        if ($sort) {
            @list($sortField, $sortOrder) = explode(' ', $sort, 2);
            if ($sortField === 'score') {
                $sortOrder = $sortOrder === 'asc' ? SolariumQuery::SORT_ASC : SolariumQuery::SORT_DESC;
            } else {
                $sortOrder = $sortOrder === 'desc' ? SolariumQuery::SORT_DESC : SolariumQuery::SORT_ASC;
            }
            $this->solariumQuery->addSort($sortField, $sortOrder);
        }

        $limit = $this->query->getLimit();
        if ($limit) {
            $this->solariumQuery->getGrouping()->setLimit($limit);
        }

        $offset = $this->query->getOffset();
        if ($offset) {
            $this->solariumQuery->getGrouping()->setOffset($offset);
        }

        $facetFields = $this->query->getFacetFields();
        if (count($facetFields)) {
            $facetSet = $this->solariumQuery->getFacetSet();
            foreach ($facetFields as $facetField) {
                $facetSet->createFacetField($facetField)->setField($facetField);
            }
        }

        $facetLimit = $this->query->getFacetLimit();
        if ($facetLimit) {
            $this->solariumQuery->getFacetSet()->setLimit($facetLimit);
        }

        return $this->solariumQuery;
    }

    protected function defaultQuery()
    {
        // The default query is managed by the module Search.
        // Here, this is a catch-them-all query.
        $defaultQuery = '*:*';

        $this->solariumQuery->getDisMax()->setQueryAlternative($defaultQuery);

        $q = $this->query->getQuery();
        if (strlen($q)) {
            return false;
        }

        $this->solariumQuery->setQuery($defaultQuery);
        return true;
    }

    protected function mainQuery()
    {
        $q = $this->query->getQuery();
        $excludedFiles = $this->query->getExcludedFields();

        $solrCoreSettings = $this->solrCore->settings();
        $queryConfig = array_filter($solrCoreSettings['query']);
        if ($queryConfig) {
            // TODO These options and other DisMax ones can be passed directly as options. Even the query is an option.
            $dismax = $this->solariumQuery->getDisMax();
            if (isset($queryConfig['minimum_match'])) {
                $dismax->setMinimumMatch($queryConfig['minimum_match']);
            }
            if (isset($queryConfig['tie_breaker'])) {
                $dismax->setTie($queryConfig['tie_breaker']);
            }
        }

        if (strlen($q)) {
            if ($excludedFiles && $q !== '*:*') {
                $this->mainQueryWithExcludedFields();
            } else {
                $this->solariumQuery->setQuery($q);
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
        $this->solariumQuery->setQuery(implode(' ', $qq));
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

        // FIXME Manage complex filters queries with solarium.
        return;

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

        $this->solariumQuery->addFilterQuery($fq);
    }

    protected function addMultipleFilterQueries()
    {
        // FIXME Manage complex filters queries with solarium.
        return;

        $filters = $this->query->getFilterQueries();
        foreach ($filters as $name => $values) {
            foreach ($values as $value) {
                $type = $value['type'];
                $value = $this->encloseValue($value['value'], $type);
                // There is no default in Omeka.
                // @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::buildPropertyQuery()
                switch ($type) {
                    case 'neq':
                        $this->solariumQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'eq':
                        $this->solariumQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'nin':
                        $this->solariumQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'in':
                        $this->solariumQuery->addFilterQuery("+$name:$value");
                        break;

                    // TODO Fixes theses Solr queries.
                    case 'nlist':
                        $this->solariumQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'list':
                        $this->solariumQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'nsw':
                        $this->solariumQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'sw':
                        $this->solariumQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'new':
                        $this->solariumQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'ew':
                        $this->solariumQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'nma':
                        $this->solariumQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'ma':
                        $this->solariumQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'nres':
                        $this->solariumQuery->addFilterQuery("-$name:$value");
                        break;
                    case 'res':
                        $this->solariumQuery->addFilterQuery("+$name:$value");
                        break;

                    case 'nex':
                        $this->solariumQuery->addFilterQuery("-$name:[* TO *]");
                        break;
                    case 'ex':
                        $this->solariumQuery->addFilterQuery("+$name:[* TO *]");
                        break;
                }
            }
        }
    }

    protected function usedSolrFields()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
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
     * @return SolariumClient
     */
    protected function getClient()
    {
        if (!isset($this->solariumClient)) {
            $this->solariumClient = $this->getSolrCore()->solariumClient();
        }
        return $this->solariumClient;
    }
}
