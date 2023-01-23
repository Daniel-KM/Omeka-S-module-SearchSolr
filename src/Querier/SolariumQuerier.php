<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016
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

namespace SearchSolr\Querier;

use AdvancedSearch\Mvc\Controller\Plugin\SearchResources;
use AdvancedSearch\Querier\AbstractQuerier;
use AdvancedSearch\Querier\Exception\QuerierException;
use AdvancedSearch\Response;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use SearchSolr\Feature\TransliteratorCharacterTrait;
use Solarium\Client as SolariumClient;
use Solarium\QueryType\Select\Query\Query as SolariumQuery;

/**
 * @todo Rewrite the querier to simplify it and to use all solarium features directly.
 * @todo Remove grouping (item/itemset): this is native in Omeka and most of the time, user want them mixed.
 *
 * @todo Use Solarium helpers (geo, escape, xml, etc.).
 * @see \Solarium\Core\Query\Helper
 */
class SolariumQuerier extends AbstractQuerier
{
    use TransliteratorCharacterTrait;

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

    /**
     * Allow to manage hidden filter queries.
     *
     * @var int
     */
    protected $appendToKey = 0;

    public function query(): Response
    {
        $this->response = new Response;
        $this->response->setApi($this->services->get('Omeka\ApiManager'));

        $this->solariumQuery = $this->getPreparedQuery();
        if (is_null($this->solariumQuery)) {
            return $this->response
                ->setIsMessage('An issue occurred.'); // @translate
        }

        try {
            /** @var \Solarium\QueryType\Select\Result\Result $solariumResultSet */
            $solariumResultSet = $this->solariumClient->execute($this->solariumQuery);
        } catch (\Exception $e) {
            // To get the query sent by solarium to solr, check the url in
            // vendor/solarium/solarium/src/Core/Client/Adapter/Http.php
            /** @see \Solarium\Core\Client\Adapter\Http::getData() */
            // The solr query cannot be an empty string.
            $q = $this->query->getQuery();
            if (!$q) {
                throw new QuerierException($e->getMessage(), $e->getCode(), $e);
            }
            // TODO Is it still needed? Not with DisMax.
            // The query may be badly formatted, so try to escape all reserved
            // characters instead of returning an exception.
            // @link https://lucene.apache.org/core/8_5_1/queryparser/org/apache/lucene/queryparser/classic/package-summary.html#Escaping_Special_Characters
            // TODO Check before the query.
            $escapedQ = $this->escapeSolrQuery($q);
            $this->solariumQuery->setQuery($escapedQ);
            try {
                $solariumResultSet = $this->solariumClient->execute($this->solariumQuery);
                /*
                } catch (\Solarium\Exception\HttpException $e) {
                    // Http Exception has getStatusMessage() and getBody(), but useless.
                    // TODO Get the error with the same url, but direct query. Or find where Solarium store the error message.
                    throw new QuerierException($e->getMessage(), $e->getCode(), $e);
                */
            } catch (\Exception $e) {
                throw new QuerierException($e->getMessage(), $e->getCode(), $e);
            }
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
                    $resourceId = basename($document['id']);
                    $this->response->addResult($groupName, ['id' => is_numeric($resourceId) ? (int) $resourceId : $resourceId]);
                }
            }
        }

        /** @var \Solarium\Component\Result\FacetSet $facetSet */
        $facetSet = $solariumResultSet->getFacetSet();
        if ($facetSet) {
            $facetCounts = [];
            /** @var \Solarium\Component/Result/Facet/FacetResultInterface $facetResult */
            // foreach $facetSet = foreach $facetSet->getFacets().
            foreach ($facetSet->getFacets() as $name => $facetResult) {
                // TODO Use getValues(), then array_column, then array_filter.
                if ($facetResult instanceof \Solarium\Component\Result\Facet\Buckets) {
                    // JsonRange extends Buckets with getBefore(), getAfter()
                    // and getBetween(), that are not used for now.
                    $facetCount = [];
                    /** @var \Solarium\Component\Result\Facet\Bucket $bucket */
                    // foreach $facetResult = foreach $facetResult->getBuckets().
                    foreach ($facetResult->getBuckets() as $bucket) {
                        $count = $bucket->getCount();
                        if ($count > 0) {
                            // $this->response->addFacetCount($name, $value, $count);
                            $facetCount[] = ['value' => $bucket->getValue(), 'count' => $count];
                        }
                    }
                    $facetCounts[$name] = $facetCount;
                }
                // Else not managed or useless here (Aggregation: count, etc.).
            }
            $this->response->setFacetCounts($facetCounts);
        }

        $this->response->setActiveFacets($this->query->getActiveFacets());

        return $this->response
            ->setIsSuccess(true);
    }

    public function querySuggestions(): Response
    {
        $this->response = new Response;
        $this->response->setApi($this->services->get('Omeka\ApiManager'));
        return $this->response
            ->setMessage('Suggestions are not implemented here. Use direct url.'); // @translate
    }

    /**
     * @todo Improve the integration of Solarium. Many things can be added directly as option or as array.
     * @todo Create an Omeka json output directly in Solr (via solarium nevertheless).
     * @todo Remove checks from here.
     *
     * @return SolariumQuery|null
     *
     * {@inheritDoc}
     * @see \AdvancedSearch\Querier\AbstractQuerier::getPreparedQuery()
     */
    public function getPreparedQuery()
    {
        $this->init();

        if (empty($this->query)) {
            $this->solariumQuery = null;
            return $this->solariumQuery;
        }

        $resourceNameField = $this->solrCore->mapsBySource('resource_name', 'generic');
        $resourceNameField = $resourceNameField ? (reset($resourceNameField))->fieldName() : null;
        $isPublicField = $this->solrCore->mapsBySource('is_public', 'generic');
        $isPublicField = $isPublicField ? (reset($isPublicField))->fieldName() : null;
        $sitesField = $this->solrCore->mapsBySource('site/o:id', 'generic');
        $sitesField = $sitesField ? (reset($sitesField))->fieldName() : null;
        if (!$resourceNameField || !$isPublicField || !$sitesField) {
            $this->solariumQuery = null;
            return $this->solariumQuery;
        }

        if (empty($this->engine->settingAdapter('index_name'))) {
            $indexField = null;
        } else {
            $indexField = $this->solrCore->mapsBySource('search_index', 'generic');
            $indexField = $indexField ? (reset($indexField))->fieldName() : null;
        }

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
                ->setQuery("$isPublicField:1");
        }

        $this->solariumQuery
            ->getGrouping()
            ->addField($resourceNameField)
            ->setNumberOfGroups(true);

        $resources = $this->query->getResources();
        $this->solariumQuery
            ->createFilterQuery($resourceNameField)
            ->setQuery($resourceNameField . ':(' . implode(' OR ', $resources) . ')');

        if ($sitesField) {
            $siteId = $this->query->getSiteId();
            if (isset($siteId)) {
                $this->solariumQuery
                    ->createFilterQuery($sitesField)
                    ->setQuery("$sitesField:$siteId");
            }
        }

        if ($indexField) {
            $this->solariumQuery
                ->createFilterQuery($indexField)
                ->setQuery($indexField . ':' . $this->engine->shortName());
        }

        $this->appendHiddenFilters();
        $this->filterQuery();

        $sort = $this->query->getSort();
        if ($sort) {
            @[$sortField, $sortOrder] = explode(' ', $sort, 2);
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

        // Manage facets.
        /** @var \Solarium\Component\FacetSet $solariumFacetSet */
        $solariumFacetSet = $this->solariumQuery->getFacetSet();

        // TODO Remove generic limit and order and set them by field.
        // This is the default limit for facets.
        $facetLimit = $this->query->getFacetLimit();
        if ($facetLimit) {
            $solariumFacetSet->setLimit($facetLimit);
        }

        // This is the default order for facets.
        // Only two choices here: index asc (alphabetic/numeric) or count desc.
        // Default is by total desc.
        $facetOrder = $this->query->getFacetOrder();
        $facetSort = \Solarium\Component\Facet\AbstractField::SORT_COUNT;
        if ($facetOrder === 'total asc' || $facetOrder === 'total desc') {
            $solariumFacetSet->setSort(\Solarium\Component\Facet\AbstractField::SORT_COUNT);
        } elseif ($facetOrder === 'alphabetic asc') {
            $facetSort = \Solarium\Component\Facet\AbstractField::SORT_INDEX;
            $solariumFacetSet->setSort(\Solarium\Component\Facet\AbstractField::SORT_INDEX);
        }

        $facets = $this->query->getFacets();
        if (count($facets)) {
            // Use "json facets" output, that is recommended by Solr.
            /* @see https://solr.apache.org/guide/solr/latest/query-guide/json-facet-api.html */
            foreach ($facets as $facetField => $facetData) {
                if ($facetData['type'] === 'SelectRange') {
                    // TODO Use arbitrary range to use default values for start/end/gap? No, the range are not arbitrary.
                    $solariumFacetSet
                        ->createJsonFacetRange($facetField)
                        ->setField($facetField)
                        // For year.
                        // FIXME Find a way to get min and max year (with query?).
                        // FIXME Start, end, and gap for facet range are required and hard coded, but depends on values.
                        ->setStart(0)
                        ->setEnd(2100)
                        ->setGap(1)
                        // MinCount is used only with standard facet range.
                        // ->setMinCount(1)
                    ;
                } else {
                    $solariumFacetSet
                        ->createJsonFacetTerms($facetField)
                        ->setField($facetField)
                        ->setLimit($facetLimit)
                        ->setSort($facetSort)
                    ;
                }
            }
        }

        // TODO Manage facet languages for Solr: index them separately?

        /** @link https://petericebear.github.io/php-solarium-multi-select-facets-20160720/ */
        $activeFacets = $this->query->getActiveFacets();
        if ($activeFacets) {
            foreach ($activeFacets as $name => $values) {
                if (!count($values)) {
                    continue;
                }
                $firstKey = key($values);
                // Check for a facet range.
                if (count($values) <= 2 && ($firstKey === 'from' || $firstKey === 'to')) {
                    $hasFrom = isset($values['from']) && $values['from'] !== '';
                    $hasTo = isset($values['to']) && $values['to'] !== '';
                    if ($hasFrom && $hasTo) {
                        $from = $this->enclose($values['from']);
                        $to = $this->enclose($values['to']);
                        $this->solariumQuery->addFilterQuery([
                           'key' => $name . '-facet',
                           'query' => "$name:[$from TO $to]",
                           'tag' => 'exclude',
                        ]);
                    } elseif ($hasFrom) {
                        $from = $this->enclose($values['from']);
                        $this->solariumQuery->addFilterQuery([
                           'key' => $name . '-facet',
                           'query' => "$name:[$from TO *]",
                           'tag' => 'exclude',
                        ]);
                    } elseif ($hasTo) {
                        $to = $this->enclose($values['to']);
                        $this->solariumQuery->addFilterQuery([
                           'key' => $name . '-facet',
                           'query' => "$name:[* TO $to]",
                           'tag' => 'exclude',
                        ]);
                    }
                    // TODO Add a exclude facet field?
                } else {
                    $enclosedValues = $this->encloseValue($values);
                    $this->solariumQuery->addFilterQuery([
                        'key' => $name . '-facet',
                        'query' => "$name:$enclosedValues",
                        'tag' => 'exclude',
                    ]);
                    // TODO Is excluding selected facet still needed?
                    $solariumFacetSet->createFacetField([
                        'field' => $name,
                        'exclude' => 'exclude',
                    ]);
                }
            }
        }

        return $this->solariumQuery;
    }

    protected function defaultQuery()
    {
        // The default query is managed by the module Search.
        // Here, this is a catch-them-all query.
        // The default query with Solarium returns all results.
        // $defaultQuery = '';

        // $this->solariumQuery->getDisMax()->setQueryAlternative($defaultQuery);

        $q = $this->query->getQuery();
        if (strlen($q)) {
            return false;
        }

        // $this->solariumQuery->setQuery($defaultQuery);
        return true;
    }

    protected function mainQuery(): void
    {
        $q = $this->query->getQuery();
        $excludedFiles = $this->query->getExcludedFields();

        $solrCoreSettings = $this->solrCore->settings();
        $queryConfig = array_filter($solrCoreSettings['query'] ?? []);
        if ($queryConfig) {
            // TODO These options and other DisMax ones can be passed directly as options. Even the query is an option.
            $dismax = $this->solariumQuery->getDisMax();
            if (isset($queryConfig['minimum_match'])) {
                $dismax->setMinimumMatch($queryConfig['minimum_match']);
            }
            if (isset($queryConfig['tie_breaker'])) {
                $dismax->setTie((float) $queryConfig['tie_breaker']);
            }
        }

        if (strlen($q)) {
            if ($excludedFiles
                && $q !== '*:*' && $q !== '*%3A*' && $q !== '*'
            ) {
                $this->mainQueryWithExcludedFields();
            } else {
                $this->solariumQuery->setQuery($q);
            }
        }
    }

    /**
     * Only called from mainQuery(). $q is never empty.
     */
    protected function mainQueryWithExcludedFields(): void
    {
        // Currently, the only way to exclude fields is to search in all other
        // fields.
        // TODO Manage multinlingual.
        $usedFields = $this->usedSolrFields(
            // Manage Drupal prefixes too.
            ['t_', 'txt_', 'ss_', 'sm_'],
            ['_t', '_txt', '_ss', '_s', '_ss_lower', '_s_lower'],
            ['_txt_']
        );
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

    protected function appendHiddenFilters(): void
    {
        $hiddenFilters = $this->query->getHiddenQueryFilters();
        if (!$hiddenFilters) {
            return;
        }
        $this->filterQueryValues($hiddenFilters);
        $this->filterQueryDateRange($hiddenFilters);
        $this->filterQueryFilters($hiddenFilters);
    }

    /**
     * Filter the query.
     */
    protected function filterQuery(): void
    {
        $this->filterQueryValues($this->query->getFilters());
        $this->filterQueryDateRange($this->query->getDateRangeFilters());
        $this->filterQueryFilters($this->query->getFilterQueries());
    }

    protected function filterQueryValues(array $filters): void
    {
        foreach ($filters as $name => $values) {
            if ($name === 'id') {
                $value = [];
                array_walk_recursive($values, function ($v) use (&$value): void {
                    $value[] = $v;
                });
                $values = array_unique(array_map('intval', $value));
                if (count($values)) {
                    $value = '("items:' . implode('" OR "items:', $values)
                        . '" OR "item_sets:' . implode('" OR "item_sets:', $values) . '")';
                    $this->solariumQuery
                        ->createFilterQuery($name . '_' . ++$this->appendToKey)
                        ->setQuery("$name:$value");
                }
                continue;
            }
            // Avoid issue with basic direct hidden quey filter like "resource_template_id_i=1".
            if (!is_array($values)) {
                $values = [$values];
            }
            foreach ($values as $value) {
                if (is_array($value)) {
                    // Skip date range queries (for hidden queries).
                    if (isset($value['from']) || isset($value['to'])) {
                        continue;
                    }
                    // Skip queries filters (for hidden queries).
                    if (isset($value['joiner']) || isset($value['type']) || isset($value['text']) || isset($value['join']) || isset($value['value'])) {
                        continue;
                    }
                }
                $value = $this->encloseValue($value);
                if (!strlen($value)) {
                    continue;
                }
                $this->solariumQuery
                    ->createFilterQuery($name . '_' . ++$this->appendToKey)
                    ->setQuery("$name:$value");
            }
        }
    }

    protected function filterQueryDateRange(array $dateRangeFilters): void
    {
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
            // Avoid issue with basic direct hidden quey filter like "resource_template_id_i=1".
            if (!is_array($filterValues)) {
                continue;
            }
            // Normalize dates if needed.
            $normalize = substr_compare($name, '_dt', -3) === 0
                || substr_compare($name, '_dts', -4) === 0
                || substr_compare($name, '_pdt', -4) === 0
                || substr_compare($name, '_tdt', -4) === 0
                || substr_compare($name, '_pdts', -5) === 0
                || substr_compare($name, '_tdts', -5) === 0;
            foreach ($filterValues as $filterValue) {
                // Skip simple and query filters (for hidden queries).
                if (!is_array($filterValue)) {
                    continue;
                }
                if ($normalize) {
                    $from = empty($filterValue['from']) ? '*' : $normalizeDate($filterValue['from']);
                    $to = empty($filterValue['to']) ? '*' : $normalizeDate($filterValue['to']);
                } else {
                    $from = empty($filterValue['from']) ? '*' : $filterValue['from'];
                    $to = empty($filterValue['to']) ? '*' : $filterValue['to'];
                }
                if ($from === '*' && $to === '*') {
                    continue;
                }
                $this->solariumQuery
                    ->createFilterQuery($name . '_' . ++$this->appendToKey)
                    ->setQuery("$name:[$from TO $to]");
            }
        }
    }

    /**
     * Append filter queries.
     *
     * Filters are boolean: in or out. nevertheless, the check can be more
     * complex than "equal": before or after a date, like a string, etc.
     *
     * @see \AdvancedSearch\Mvc\Controller\Plugin\SearchResources::buildPropertyQuery()
     *
     * Warning: filter queries use "name" (as key) + "join", "type", "value", not "property", "joiner", "type", "text".
     *
     * Solr does not support query on omeka datatypes.
     *
     * Solr supports two more query types:
     *   - ma: matches a simple regex
     *   - nma: does not match a simple regex
     */
    protected function filterQueryFilters(array $filters): void
    {
        $moreSupportedQueryTypes = [
            'ma',
            'nma',
        ];

        $allReciprocalTypes = SearchResources::PROPERTY_QUERY['reciprocal']
            + $moreSupportedQueryTypes;

        $unsupportedQueryTypes = [
            'tp',
            'ntp',
            'tpl',
            'ntpl',
            'tpr',
            'ntpr',
            'tpu',
            'ntpu',
            'dtp',
            'ndtp',
        ];

        foreach ($filters as $name => $queryFilters) {
            // Avoid issue with basic direct hidden quey filter like "resource_template_id_i=1".
            if (!is_array($queryFilters)) {
                continue;
            }

            $fq = '';
            $first = true;
            foreach ($queryFilters as $queryFilter) {
                // Skip simple filters (for hidden queries).
                if (!$queryFilter
                    || !is_array($queryFilter)
                    || empty($queryFilter['type'])
                    || !isset($allReciprocalTypes[$queryFilter['type']])
                    || in_array($queryFilter['type'], $unsupportedQueryTypes)
                ) {
                    continue;
                }

                // There is no default in Omeka.
                /** @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::buildPropertyQuery() */
                $queryType = $queryFilter['type'];
                $value = $queryFilter['value'] ?? '';

                // Quick check of value.
                // A empty string "" is not a value, but "0" is a value.
                if (in_array($queryType, SearchResources::PROPERTY_QUERY['value_none'], true)) {
                    $value = null;
                }
                // Check array of values.
                elseif (in_array($queryType, SearchResources::PROPERTY_QUERY['value_array'], true)) {
                    if ((is_array($value) && !count($value))
                        || (!is_array($value) && !strlen((string) $value))
                    ) {
                        continue;
                    }
                    if (!is_array($value)) {
                        $value = [$value];
                    }
                    // To use array_values() avoids doctrine issue with string keys.
                    $value = in_array($queryType, SearchResources::PROPERTY_QUERY['value_integer'])
                        ? array_values(array_unique(array_map('intval', $value)))
                        : array_values(array_unique(array_filter(array_map('trim', array_map('strval', $value)), 'strlen')));
                    if (empty($value)) {
                        continue;
                    }
                }
                // The value should be scalar in all other cases (int or string).
                elseif (is_array($value)) {
                    continue;
                } else {
                    $value = trim((string) $value);
                    if (!strlen($value)) {
                        continue;
                    }
                    if (in_array($queryType, SearchResources::PROPERTY_QUERY['value_integer'])) {
                        if (!is_numeric($value)) {
                            continue;
                        }
                        $value = (int) $value;
                    }
                }

                // Check joiner and invert the query type for joiner "not".
                $joiner = $queryFilter['join'] ?? '';
                if ($first) {
                    $joiner = '';
                    $first = false;
                } elseif ($joiner) {
                    if ($joiner === 'or') {
                        $joiner = 'OR';
                    } elseif ($joiner === 'not') {
                        $joiner = 'AND';
                        $queryType = $allReciprocalTypes[$queryType];
                    } else {
                        $joiner = 'AND';
                    }
                } else {
                    $joiner = 'AND';
                }

                // "AND/NOT" cannot be used as first.
                // TODO Will be simplified in version 3.5.38.3.
                $isNegative = isset(SearchResources::PROPERTY_QUERY['negative'])
                    ? (in_array($queryType, SearchResources::PROPERTY_QUERY['negative']) || $queryType === 'nma')
                    : substr($queryType, 0, 1) === 'n';
                if ($isNegative) {
                    $bool = '(NOT ';
                    $endBool = ')';
                } else {
                    $bool = '(';
                    $endBool = ')';
                }
                switch ($queryType) {
                    // Regex requires string (_s), not text or anything else.
                    // So if the field is not a string, use a simple "+", that
                    // will be enough in most of the cases.
                    // Furthermore, unlike sql, solr regex doesn't manage
                    // insensitive search, neither flag "i".
                    // The pattern is limited to 1000 characters by default.
                    // TODO Check the size of the pattern.
                    // @link https://lucene.apache.org/core/6_6_6/core/org/apache/lucene/util/automaton/RegExp.html

                    // Equal.
                    case 'neq':
                    case 'eq':
                        if ($this->fieldIsString($name)) {
                            $value = $this->regexDiacriticsValue($value, '', '');
                        } else {
                            $value = $this->encloseValue($value);
                        }
                        $fq .= " $joiner ($name:$bool$value$endBool)";
                        break;

                    // Contains.
                    case 'nin':
                    case 'in':
                        if ($this->fieldIsString($name)) {
                            $value = $this->regexDiacriticsValue($value, '.*', '.*');
                        } else {
                            $value = $this->encloseValueAnd($value);
                        }
                        $fq .= " $joiner ($name:$bool$value$endBool)";
                        break;

                    /*
                    // In list.
                    case 'nlist':
                    case 'list':
                        // TODO Manage api filter in list (not used in standard forms).
                        break;
                    */

                    // Starts with.
                    case 'nsw':
                    case 'sw':
                        if ($this->fieldIsString($name)) {
                            $value = $this->regexDiacriticsValue($value, '', '.*');
                        } else {
                            $value = $this->encloseValueAnd($value);
                        }
                        $fq .= " $joiner ($name:$bool$value$endBool)";
                        break;

                    // Ends with.
                    case 'new':
                    case 'ew':
                        if ($this->fieldIsString($name)) {
                            $value = $this->regexDiacriticsValue($value, '.*', '');
                        } else {
                            $value = $this->encloseValueAnd($value);
                        }
                        $fq .= " $joiner ($name:$bool$value$endBool)";
                        break;

                    // Matches.
                    case 'nma':
                    case 'ma':
                        // Matches is already an regular expression, so just set
                        // it. Note that Solr can manage only a small part of
                        // regex and anchors are added by default.
                        // TODO Add // or not?
                        // TODO Escape regex for regexes…
                        $value = $this->fieldIsString($name) ? $value : $this->encloseValue($value);
                        $fq .= " $joiner ($name:$bool$value$endBool)";
                        break;

                    // Resource with id.
                    case 'nres':
                    case 'res':
                        // Like equal, but the field must be an integer.
                        if (substr($name, -2) === '_i' || substr($name, -3) === '_is') {
                            $value = (int) $value;
                            $fq .= " $joiner ($name:$bool$value$endBool)";
                        }
                        break;

                    // Exists (has a value).
                    case 'nex':
                        $value = $this->encloseValue($value);
                        $fq .= " $joiner (-$name:$value)";
                        break;
                    case 'ex':
                        $value = $this->encloseValue($value);
                        $fq .= " $joiner (+$name:$value)";
                        break;

                    /*
                    case 'nexs':
                    case 'exs':
                        break;

                    case 'nexm':
                    case 'exm':
                        break;

                    case 'ntp':
                    case 'tp':
                    case 'ntpl':
                    case 'tpl':
                    case 'ntpr':
                    case 'tpr':
                    case 'ntpu':
                    case 'tpu':
                    case 'ndtp':
                    case 'dtp':
                        break;

                    // The linked resources (subject values) use the same sub-query.
                    case 'nlex':
                    case 'nlres':
                    case 'lex':
                    case 'lres':
                        break;

                    // TODO Manage uri and resources with gt, gte, lte, lt (it has a meaning at least for resource ids, but separate).
                    case 'gt':
                        break;
                    case 'gte':
                        break;
                    case 'lte':
                        break;
                    case 'lt':
                        break;
                    */

                    default:
                        throw new \AdvancedSearch\Querier\Exception\QuerierException(sprintf(
                            'Search type "%s" is not managed currently by SearchSolr.', // @translate
                            $queryType
                        ));
                }
            }
            $this->solariumQuery
                // The name must be different from simple filter, or merge them.
                ->createFilterQuery($name . '_fq' . '_' . ++$this->appendToKey)
                ->setQuery(ltrim($fq));
        }
    }

    /**
     * @todo Replace by a single regex?
     */
    protected function usedSolrFields(array $prefixes = [], array $suffixes = [], array $contains = []): array
    {
        $api = $this->services->get('Omeka\ApiManager');
        $fields = $api->search('solr_maps', [
            'solr_core_id' => $this->solrCore->id(),
        ], ['returnScalar' => 'fieldName'])->getContent();

        $fields = array_filter($fields, function ($v) use ($prefixes, $suffixes, $contains) {
            if ($prefixes) {
                foreach ($prefixes as $prefix) {
                    if (strncmp($v, $prefix, strlen($prefix)) === 0) {
                        return true;
                    }
                }
            }
            if ($suffixes) {
                foreach ($suffixes as $suffix) {
                    if (substr($v, - strlen($suffix)) === $suffix) {
                        return true;
                    }
                }
            }
            if ($contains) {
                foreach ($contains as $contain) {
                    if (strpos($v, $contain) !== false) {
                        return true;
                    }
                }
            }
            return false;
        });

        return $fields;
    }

    protected function fieldIsTokenized($name): bool
    {
        return substr($name, -2) === '_t'
            || substr($name, -4) === '_txt'
            || substr($name, -3) === '_ws'
            || strpos($name, '_txt_') !== false;
    }

    protected function fieldIsString($name): bool
    {
        return substr($name, -2) === '_s'
            || substr($name, -3) === '_ss'
            || substr($name, -8) === '_s_lower'
            || substr($name, -9) === '_ss_lower';
    }

    protected function fieldIsLower($name): bool
    {
        return substr($name, -6) === '_lower';
    }

    /**
     * Enclose a string to protect a query for Solr.
     *
     * @param array|string $string
     * @return string
     */
    protected function encloseValue($value): string
    {
        if (is_array($value)) {
            if (empty($value)) {
                $value = '';
            } else {
                $value = '(' . implode(' OR ', array_unique(array_map([$this, 'enclose'], $value))) . ')';
            }
        } else {
            $value = $this->enclose($value);
        }
        return $value;
    }

    /**
     * Enclose a string to protect a query for Solr.
     *
     * @param array|string $string
     * @return string
     */
    protected function encloseValueAnd($value): string
    {
        if (is_array($value)) {
            if (empty($value)) {
                $value = '';
            } else {
                $value = '(' . implode(' +', array_map([$this, 'enclose'], $value)) . ')';
            }
        } else {
            $value = $this->enclose($value);
        }
        return $value;
    }

    /**
     * Prepare a value for a regular expression, managing diacritics and case.
     *
     * @param array|string $value
     * @param string $append
     * @param string $prepend
     * @return string
     */
    protected function regexDiacriticsValue($value, string $prepend = '', string $append = ''): string
    {
        static $basicDiacritics;
        if (is_null($basicDiacritics)) {
            $basicDiacritics = [
                '\\' => '\\\\',
                '.' => '\.',
                '*' => '.*',
                '?' => '.',
                '+' => '\+',
                '[' => '\[',
                '^' => '\^',
                ']' => '\]',
                '$' => '\$',
                '(' => '\(',
                ')' => '\)',
                '{' => '\{',
                '}' => '\}',
                '=' => '\=',
                '!' => '\!',
                '<' => '\<',
                '>' => '\>',
                '|' => '\|',
                ':' => '\:',
                '-' => '\-',
                '/' => '\/',
            ] + array_map(function ($v) {
                return substr($v, 0, 1);
            }, $this->baseDiacritics);
        }
        $regexVal = function ($string) use ($prepend, $append, $basicDiacritics) {
            $latinized = str_replace(array_keys($basicDiacritics), array_values($basicDiacritics), mb_strtolower($string));
            return '/' . $prepend
                . str_replace(array_keys($this->regexDiacritics), array_values($this->regexDiacritics), $latinized)
                . $append . '/';
        };
        $values = array_map($regexVal, is_array($value) ? $value : [$value]);

        return implode(' OR ', $values);
    }

    /**
     * Enclose a string to protect a query for Solr.
     *
     * @param string $string
     * @return string
     */
    protected function enclose($string): string
    {
        return '"' . addcslashes((string) $string, '"') . '"';
    }

    /**
     * Enclose a string to protect a filter query for Solr.
     *
     * @param $string $string
     * @return $string
     */
    protected function escape($string): string
    {
        return preg_replace('/([+\-&|!(){}[\]\^"~*?:])/', '\\\\$1', (string) $string);
    }

    protected function escapeSolrQuery($q): string
    {
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
        return str_replace(
            array_keys($reservedCharacters),
            array_values($reservedCharacters),
            (string) $q
        );
    }

    /**
     * @return self
     */
    protected function init(): self
    {
        $this->getSolrCore();
        return $this;
    }

    protected function getSolrCore(): \SearchSolr\Api\Representation\SolrCoreRepresentation
    {
        if (!isset($this->solrCore)) {
            $solrCoreId = $this->engine->settingAdapter('solr_core_id');
            if ($solrCoreId) {
                $api = $this->services->get('Omeka\ApiManager');
                // Automatically throw an exception when empty.
                $this->solrCore = $api->read('solr_cores', $solrCoreId)->getContent();
                $this->solariumClient = $this->solrCore->solariumClient();
                $clientSettings = $this->solrCore->clientSettings();
                if (($clientSettings['http_request_type'] ?? 'post') !== 'get') {
                    $this->solariumClient->getPlugin('postbigrequest');
                }
            }
        }
        return $this->solrCore;
    }

    protected function getClient(): SolariumClient
    {
        if (!isset($this->solariumClient)) {
            $core = $this->getSolrCore();
            $this->solariumClient = $core->solariumClient();
            $clientSettings = $core->clientSettings();
            if (($clientSettings['http_request_type'] ?? 'post') !== 'get') {
                $this->solariumClient->getPlugin('postbigrequest');
            }
        }
        return $this->solariumClient;
    }
}
