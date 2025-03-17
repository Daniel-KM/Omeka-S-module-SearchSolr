<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016
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

namespace SearchSolr\Querier;

use AdvancedSearch\Querier\AbstractQuerier;
use AdvancedSearch\Querier\Exception\QuerierException;
use AdvancedSearch\Response;
use AdvancedSearch\Stdlib\SearchResources;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use SearchSolr\Feature\TransliteratorCharacterTrait;
use Solarium\Client as SolariumClient;
use Solarium\QueryType\Select\Query\Query as SolariumQuery;

/**
 * @todo Rewrite the querier to simplify it and to use all solarium features directly.
 * @todo Use Solarium helpers (geo, escape, xml, etc.).
 * @see \Solarium\Core\Query\Helper
 * @see https://solarium.readthedocs.io/en/stable/getting-started/
 * @see https://solarium.readthedocs.io/en/stable/queries/select-query/building-a-select-query/building-a-select-query/
 */
class SolariumQuerier extends AbstractQuerier
{
    use TransliteratorCharacterTrait;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var array
     */
    protected $resourceTypes;

    /**
     * @var bool
     */
    protected $byResourceType = false;

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

        $this->byResourceType = $this->query ? $this->query->getByResourceType() : false;
        $this->response->setByResourceType($this->byResourceType);

        $this->solariumQuery = $this->getPreparedQuery();

        // When no query or resource types are set.
        // The solr query cannot be an empty string.
        if ($this->solariumQuery === null) {
            return $this->response
                ->setMessage('An issue occurred.'); // @translate
        }

        // TODO Rewrite the process by resource types, etc. Use more solarium features. Use the response directly.

        try {
            /** @var \Solarium\QueryType\Select\Result\Result $solariumResultSet */
            $solariumResultSet = $this->solariumClient->execute($this->solariumQuery);
        } catch (\Exception $e) {
            // To get the query sent by solarium to solr, check the url in
            // vendor/solarium/solarium/src/Core/Client/Adapter/Http.php
            /** @see \Solarium\Core\Client\Adapter\Http::getData() */
            throw new QuerierException($e->getMessage(), $e->getCode(), $e);
        }

        // Fill the response according to settings.
        // getData() allows to get everything as array.
        // $solariumResultSet->getData();

        // The result is always grouped, so getNumFound() is empty. The same for getDocuments().
        // There is only one grouping here: by resource type (item sets, items,
        // etc.), and only if needed in query.

        foreach ($solariumResultSet->getGrouping() as $fieldGroup) {
            $this->response->setTotalResults($fieldGroup->getMatches());
            /** @var \Solarium\Component\Result\Grouping\ValueGroup $valueGroup */
            // The order of the group fields may be different than the original
            // resource types, so store them in a temp variable.
            $resourceTotalResults = array_fill_keys($this->resourceTypes, 0);
            foreach ($fieldGroup as $valueGroup) {
                // The group name is the resource type.
                $resourceType = $valueGroup->getValue();
                $resourceTotalResults[$resourceType] = $valueGroup->getNumFound();
                foreach ($valueGroup as $document) {
                    $resourceId = basename($document['id']);
                    $this->response->addResult($resourceType, ['id' => is_numeric($resourceId) ? (int) $resourceId : $resourceId]);
                }
            }
            $this->response->setAllResourceTotalResults($resourceTotalResults);
            $this->response->setResults(array_replace(array_fill_keys($this->resourceTypes, []), $this->response->getResults()));
        }

        // TODO If less than pagination, get it directly.
        try {
            // Normally no exception, since previous query has no issue.
            /** @var \Solarium\QueryType\Select\Result\Result $solariumResultSetAll */
            $this->solariumQuery->setFields(['id']);
            $solariumResultSetAll = $this->solariumClient->execute($this->solariumQuery);
        } catch (\Exception $e) {
            throw new QuerierException($e->getMessage(), $e->getCode(), $e);
        }

        // TODO Optimize output and conversion (solr argument to get id only as array).
        foreach ($solariumResultSetAll->getGrouping() as $fieldGroup) {
            /** @var \Solarium\Component\Result\Grouping\ValueGroup $valueGroup */
            foreach ($fieldGroup as $valueGroup) {
                // The group name is the resource type.
                $resourceType = $valueGroup->getValue();
                $result = array_column($valueGroup->getDocuments(), 'id');
                foreach ($result as &$documentId) {
                    $resourceId = basename($documentId);
                    $documentId = is_numeric($resourceId) ? (int) $resourceId : $resourceId;
                }
                unset($documentId);
                $this->response->setAllResourceIdsForResourceType($resourceType, $result);
            }
        }

        $this->response->setCurrentPage($this->query->getPage());
        $this->response->setPerPage($this->query->getPerPage());

        // Remove specific results when settings are not by resource type.
        // TODO Check option "by resource type" earlier.
        // Facets are always grouped.
        // This is the same in InternalQuerier.
        if (!$this->byResourceType
            && $this->resourceTypes
            && count($this->resourceTypes) > 1
        ) {
            $allResourceIdsByType = $this->response->getAllResourceIds(null, true);
            if (isset($allResourceIdsByType['resources'])) {
                $this->response->setAllResourceIdsByResourceType(['resources' => $allResourceIdsByType['resources']]);
            } else {
                $this->response->setAllResourceIdsByResourceType(['resources' => array_merge(...array_values($allResourceIdsByType))]);
            }
            $resultsByType = $this->response->getResults();
            if (isset($resultsByType['resources'])) {
                $this->response->setResults(['resources' => $resultsByType['resources']]);
            } else {
                $this->response->setResults(['resources' => array_replace(...array_values($resultsByType))]);
            }
            $totalResultsByType = $this->response->getResourceTotalResults();
            $total = isset($totalResultsByType['resources']) ? $totalResultsByType['resources'] : array_sum($totalResultsByType);
            $this->response->setResourceTotalResults('resources', $total);
            $this->response->setTotalResults($total);
        }

        /** @var \Solarium\Component\Result\FacetSet $facetSet */
        $facetSet = $solariumResultSet->getFacetSet();
        if ($facetSet) {
            $facetCounts = [];
            $explode = fn ($string): array => explode(strpos((string) $string, '|') === false ? ',' : '|', (string) $string);
            $queryFacets = $this->query->getFacets();
            $facetListAll = $this->query->getOption('facet_list') === 'all';
            /** @var \Solarium\Component/Result/Facet/FacetResultInterface $facetResult */
            // foreach $facetSet = foreach $facetSet->getFacets().
            foreach ($facetSet->getFacets() as $name => $facetResult) {
                // TODO Use getValues(), then array_column, then array_filter.
                if ($facetResult instanceof \Solarium\Component\Result\Facet\Buckets) {
                    // JsonRange extends Buckets with getBefore(), getAfter()
                    // and getBetween(), that are not used for now.
                    $facetCount = [];
                    /** @var \Solarium\Component\Result\Facet\Bucket $bucket */
                    foreach ($facetResult->getBuckets() as $bucket) {
                        $count = $bucket->getCount();
                        // Warning: for a range, all values are returned from
                        // min to max. See config of facets below.
                        if ($facetListAll || $count) {
                            // $this->response->addFacetCount($name, $value, $count);
                            $value = $bucket->getValue();
                            $facetCount[$value] = ['value' => $value, 'count' => $count];
                        }
                    }
                    $facetData = $queryFacets[$name] ?? [];
                    if (!empty($facetData['order'])
                        && !empty($facetData['values'])
                        && in_array($facetData['order'], ['values', 'values asc', 'values desc'])
                    ) {
                        $orderValues = is_array($facetData['values']) ? $facetData['values'] : $explode($facetData['values']);
                        if ($facetData['order'] === 'values desc') {
                            $orderValues = array_reverse($orderValues, true);
                        }
                        $orderValues = array_fill_keys($orderValues, ['value' => '', 'count' => 0]);
                        $facetCountFiltered = array_intersect_key($facetCount, $orderValues);
                        $facetCount = array_replace(array_intersect_key($orderValues, $facetCountFiltered), $facetCountFiltered);
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
        // TODO Implement querySuggestions(). See queryValues().
        $this->response = new Response;
        $this->response->setApi($this->services->get('Omeka\ApiManager'));
        $this->query ? $this->response->setQuery($this->query) : null;
        return $this->response
            ->setMessage('Suggestions are not implemented here. Use direct url.'); // @translate
    }

    /**
     * Get indexed Solr documents.
     *
     * Resource types are required to differentiate resources.
     *
     * @todo Merge queryDocuments() of SolariumQuerier with SolrRepresentation.
     *
     * Adapted:
     * @see \SearchSolr\Api\Representation\SolrCoreRepresentation::queryDocuments()
     * @see \SearchSolr\Querier\SolariumQuerier::queryDocuments()
     */
    public function queryDocuments(string $resourceType, array $ids): array
    {
        $ids = array_map('intval', $ids);
        if (!$resourceType || !$ids) {
            return [];
        }

        // Init solr client.
        $this->getClient();

        $resourceTypeField = $this->solrCore->mapsBySource('resource_name', 'generic');
        $resourceTypeField = $resourceTypeField ? (reset($resourceTypeField))->fieldName() : null;

        $this->solariumQuery
            ->createSelect()
            ->createFilterQuery($resourceTypeField)
            ->setQuery($resourceTypeField . ':' . $resourceType)
            ->createFilterQuery('is_id_i')
            ->setQuery('is_id_i:' . implode(' OR ', $ids));

        $resultSet = $this->solariumClient->select($this->solariumQuery);
        $data = $resultSet->getData();

        // TODO Reorder by ids? Check for duplicate resources first.

        return $data['response']['docs'] ?? [];
    }

    /**
     * @todo Merge queryValues() of SolariumQuerier with SolrRepresentation.
     *
     * Adapted:
     * @see \SearchSolr\Api\Representation\SolrCoreRepresentation::queryValues()
     * @see \SearchSolr\Querier\SolariumQuerier::queryValues()
     *
     * {@inheritDoc}
     * @see \AdvancedSearch\Querier\AbstractQuerier::queryValues()
     */
    public function queryValues(string $field): array
    {
        if (!$field) {
            return [];
        }

        // Init solr client.
        $this->getClient();

        // Check if the field is a special or a multifield.
        $aliases = $this->query->getAliases();
        $fields = $aliases[$field]['fields'] ?? [$field];
        if (!is_array($fields)) {
            $fields = [$fields];
        }

        // The terms query in solarium does not support filtering by field, so
        // it is not possible to filter by site. So either index values by site,
        // or use a standard query.
        $siteId = $this->query->getSiteId();
        $sitesField = $this->solrCore->mapsBySource('site/o:id', 'generic');
        $sitesField = $sitesField ? (reset($sitesField))->fieldName() : null;
        if ($siteId && $sitesField) {
            $query = $this->solariumClient->createSelect();
            $query
                ->createFilterQuery($sitesField)
                ->setQuery("$sitesField:$siteId");
            $facetSet = $query->getFacetSet();
            $index = 0;
            foreach ($fields as $field) {
                $facetSet
                    ->createFacetField($field . '_' . ++$index)
                    ->setField($field)
                    ->setSort(\Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC)
                    ->setLimit(-1)
                    // Only used values in the current site.
                    ->setMinCount(1);
            }
            $resultSet = $this->solariumClient->select($query);
            $index = 0;
            $facets = $resultSet->getFacetSet()->getFacets();
            $result = [];
            foreach ($facets as $facet) {
                $result[] = array_keys($facet->getValues());
            }
        } else {
            // In Sort, a query value is a terms query.
            $query = $this->solariumClient->createTerms();
            $query
                ->setFields($fields)
                ->setSort(\Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC)
                ->setLimit(-1)
                // Only used values. Anyway, by default there is no predefined list.
                ->setMinCount(1);
            $resultSet = $this->solariumClient->terms($query);
            // Results are structured by field and term/count.
            $result = array_map(fn ($v) => array_keys($v), $resultSet->getResults());
        }

        // Merge fields.
        $list = array_merge(...array_values($result));

        natcasesort($list);

        // Fix false empty duplicate or values without title.
        $list = array_keys(array_flip($list));
        unset($list['']);

        return array_combine($list, $list);
    }

    /**
     * Warning: unlike queryValues, the field isn't an alias but a real index.
     *
     * Currently only used in admin.
     *
     * @todo Merge queryValuesCount() of SolariumQuerier with SolrRepresentation.
     *
     * Adapted:
     * @see \SearchSolr\Api\Representation\SolrCoreRepresentation::queryValuesCount()
     * @see \SearchSolr\Querier\SolariumQuerier::queryValuesCount()
     */
    public function queryValuesCount($fields, ?string $sort = 'index asc'): array
    {
        if (!$fields) {
            return [];
        }

        // Init solr client.
        $this->getClient();

        if (!is_array($fields)) {
            $fields = [$fields];
        }

        // TODO Limit output by site when set in query (or index by site).

        $sorts = [
            \Solarium\Component\Facet\JsonTerms::SORT_COUNT_ASC,
            \Solarium\Component\Facet\JsonTerms::SORT_COUNT_DESC,
            \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
            \Solarium\Component\Facet\JsonTerms::SORT_INDEX_DESC,
        ];
        $sort = in_array($sort, $sorts) ? $sort : \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC;

        // In Sort, a query value is a terms query.
        $query = $this->solariumClient->createTerms();
        $query
            ->setFields($fields)
            ->setSort($sort)
            ->setLimit(-1)
            // Only used values. Anyway, by default there is no predefined list.
            ->setMinCount(1);
        $resultSet = $this->solariumClient->terms($query);

        // TODO The sort does not seem to work, so for now resort locally.
        $result = [];
        foreach ($fields as $field) {
            $terms = $resultSet->getTerms($field);
            switch ($sort) {
                default:
                case \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC:
                    uksort($terms, 'strnatcasecmp');
                    break;
                case \Solarium\Component\Facet\JsonTerms::SORT_INDEX_DESC:
                    uksort($terms, 'strnatcasecmp');
                    $terms = array_reverse($terms, true);
                    break;
                case \Solarium\Component\Facet\JsonTerms::SORT_COUNT_ASC:
                    asort($terms);
                    break;
                case \Solarium\Component\Facet\JsonTerms::SORT_COUNT_DESC:
                    arsort($terms);
                    break;
            }
            $result[$field] = $terms;
        }

        return $result;
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
        // Init the solr core here when called directly.
        $this->getSolrCore();

        if (empty($this->query)) {
            $this->solariumQuery = null;
            return $this->solariumQuery;
        }

        $resourceTypeField = $this->solrCore->mapsBySource('resource_name', 'generic');
        $resourceTypeField = $resourceTypeField ? (reset($resourceTypeField))->fieldName() : null;
        $isPublicField = $this->solrCore->mapsBySource('is_public', 'generic');
        $isPublicField = $isPublicField ? (reset($isPublicField))->fieldName() : null;
        $sitesField = $this->solrCore->mapsBySource('site/o:id', 'generic');
        $sitesField = $sitesField ? (reset($sitesField))->fieldName() : null;
        if (!$resourceTypeField || !$isPublicField || !$sitesField) {
            $this->solariumQuery = null;
            return $this->solariumQuery;
        }

        $indexerResourceTypes = $this->searchEngine->setting('resource_types', []);
        $this->resourceTypes = $this->query->getResourceTypes() ?: $indexerResourceTypes;
        $this->resourceTypes = array_intersect($this->resourceTypes, $indexerResourceTypes);
        if (empty($this->resourceTypes)) {
            $this->solariumQuery = null;
            return $this->solariumQuery;
        }

        if (empty($this->searchEngine->settingEngineAdapter('index_name'))) {
            $indexField = null;
        } else {
            $indexField = $this->solrCore->mapsBySource('search_index', 'generic');
            $indexField = $indexField ? (reset($indexField))->fieldName() : null;
        }

        // TODO Add a param to select DisMaxQuery, standard query, eDisMax, or external query parsers.

        $this->solariumQuery = $this->solariumClient->createSelect();

        // Assign the default query field if it is defined in the given AdvancedSearch\Query.
        if (!empty($this->query->getQueryDefaultField())) {
            // $this->solariumQuery->setQueryDefaultField('public_property_values_txt');
            $this->solariumQuery->setQueryDefaultField(
                $this->query->getQueryDefaultField()
            );
        }

        $isDefaultQuery = $this->defaultQuery();
        if (!$isDefaultQuery) {
            $this->mainQuery();
        }

        $this->solariumQuery->addField('id');

        // IsPublic is set by the server automatically, not by the user.
        // TODO Check if the arguments are set by the user and remove them.
        $isPublic = $this->query->getIsPublic();

        // Since version of module Access 3.4.17, the access level is a standard
        // filter that may be enable or not.

        if ($isPublic) {
            $this->solariumQuery
                ->createFilterQuery($isPublicField)
                ->setQuery("$isPublicField:1");
        }

        $this->solariumQuery
            ->getGrouping()
            ->addField($resourceTypeField)
            ->setNumberOfGroups(true);

        $resourceTypes = $this->query->getResourceTypes();
        $this->solariumQuery
            ->createFilterQuery($resourceTypeField)
            ->setQuery($resourceTypeField . ':(' . implode(' OR ', $resourceTypes) . ')');

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
                ->setQuery($indexField . ':' . $this->searchEngine->shortName());
        }

        $this->appendHiddenFilters();
        $this->filterQuery();

        $sort = $this->query->getSort();
        if ($sort) {
            @[$sortField, $sortOrder] = explode(' ', $sort, 2);
            if ($sortField === 'relevance'
                // Support old config, but the default solr field name anyway.
                || $sortField === 'score'
            ) {
                $sortField = 'score';
                $sortOrder = $sortOrder === 'asc' ? SolariumQuery::SORT_ASC : SolariumQuery::SORT_DESC;
            } else {
                $sortOrder = $sortOrder === 'desc' ? SolariumQuery::SORT_DESC : SolariumQuery::SORT_ASC;
            }
            $this->solariumQuery->addSort($sortField, $sortOrder);
        }

        // Limit is per page and offset is page x limit.
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

        $facetOrders = [
            // Default alphabetic order is asc.
            'alphabetic' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
            'alphabetic asc' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
            'alphabetic desc' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_DESC,
            // Default total order is desc.
            'total' => \Solarium\Component\Facet\JsonTerms::SORT_COUNT_DESC,
            'total asc' => \Solarium\Component\Facet\JsonTerms::SORT_COUNT_ASC,
            'total desc' => \Solarium\Component\Facet\JsonTerms::SORT_COUNT_DESC,
            // Default values order is asc.
            'values' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
            'values asc' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
            'values desc' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_DESC,
            // Default values order is alphabetic asc.
            'default' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
        ];

        $facetOrderDefault = \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC;

        $facets = $this->query->getFacets();

        if (count($facets)) {
            // Explode with separator "|" if present, else ",".
            // For complex cases, an array should be used.
            $explode = fn ($string): array => explode(strpos((string) $string, '|') === false ? ',' : '|', (string) $string);

            // Use "json facets" output, that is recommended by Solr.
            /** @see https://solr.apache.org/guide/solr/latest/query-guide/json-facet-api.html */

            // Early prepare min/max for all ranges.
            $fieldRanges = [];
            foreach ($facets as $facetName => $facetData) {
                if (in_array($facetData['type'], ['Range', 'RangeDouble', 'SelectRange'])) {
                    /*
                    if (!isset($facetData['min']) || !isset($facetData['max'])) {
                        $this->logger->info(
                            'With a facet range ({field}), it is recommended to set min and max for performance.', // @ translate
                            ['field' => $facetName]
                        );
                    }
                    */
                    $fieldRanges[$facetName] = [];
                }
            }
            if ($fieldRanges) {
                $all = $this->queryValuesCount(array_keys($fieldRanges));
                foreach ($all as $facetName => $values) {
                    $values = array_keys(array_filter($values));
                    $fieldRanges[$facetName]['min'] = $values ? min($values) : 0;
                    $fieldRanges[$facetName]['max'] = $values ? max($values) : 0;
                }
            }

            foreach ($facets as $facetName => $facetData) {
                if (empty($facetData['field'])) {
                    continue;
                }
                $facetField = $facetData['field'];
                $facetOrder = isset($facetData['order'])
                    ? $facetOrders[$facetData['order']] ?? $facetOrderDefault
                    : $facetOrderDefault;
                $facetLimit = $facetData['limit'] ?? 0;
                $facetValues = $facetData['values'] ?? [];
                if (in_array($facetData['type'], ['Range', 'RangeDouble', 'SelectRange'])) {
                    $min = isset($facetData['min']) ? $facetData['min'] : $fieldRanges[$facetName]['min'];
                    $max = isset($facetData['max']) ? $facetData['max'] : $fieldRanges[$facetName]['max'];;
                    $step = isset($facetData['step']) ? (int) $facetData['step'] : 1;
                    $facet = $solariumFacetSet
                        ->createJsonFacetRange($facetName)
                        ->setField($facetField)
                        ->setStart($min)
                        ->setEnd($max)
                        ->setGap($step ?: 1)
                        // MinCount is used only with standard facet range.
                        // ->setMinCount(1)
                    ;
                } else {
                    /** @var \Solarium\Component\Facet\FieldValueParametersInterface $facet */
                    $facet = $solariumFacetSet
                        ->createJsonFacetTerms($facetName)
                        ->setField($facetField)
                        ->setLimit($facetLimit)
                        ->setSort($facetOrder)
                    ;
                }
                if ($facetValues) {
                    if (is_string($facetValues)) {
                        $facetValues = $explode($facetValues);
                    }
                    // Escape all strings as regex.
                    $facet
                        ->setMatches('~^' . implode('|', array_map(fn ($v) => preg_quote($v, '~'), $facetValues)) . '$~');
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
                        $from = $this->escapePhrase($values['from']);
                        $to = $this->escapePhrase($values['to']);
                        $this->solariumQuery->addFilterQuery([
                           'key' => $name . '-facet',
                           'query' => "$name:[$from TO $to]",
                           'tag' => 'exclude',
                        ]);
                    } elseif ($hasFrom) {
                        $from = $this->escapePhrase($values['from']);
                        $this->solariumQuery->addFilterQuery([
                           'key' => $name . '-facet',
                           'query' => "$name:[$from TO *]",
                           'tag' => 'exclude',
                        ]);
                    } elseif ($hasTo) {
                        $to = $this->escapePhrase($values['to']);
                        $this->solariumQuery->addFilterQuery([
                           'key' => $name . '-facet',
                           'query' => "$name:[* TO $to]",
                           'tag' => 'exclude',
                        ]);
                    }
                    // TODO Add a exclude facet field?
                } else {
                    $enclosedValues = $this->escapePhraseValue($values, 'OR');
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
        // The default query is managed by the module Advanced Search.
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
        $qr = $this->query->getQueryRefine();
        $q = trim($q . ' ' . $qr);
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
                $this->mainQueryWithExcludedFields($q);
            } else {
                if ($this->query->getOption('remove_diacritics', false)) {
                    if (extension_loaded('intl')) {
                        $transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
                        $q = $transliterator->transliterate($q);
                    } elseif (extension_loaded('iconv')) {
                        $q = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $q);
                    } else {
                        $q = $this->latinize($q);
                    }
                }
                $q = $this->escapeTermOrPhrase($q);
                $this->solariumQuery->setQuery($q);
            }
        }
    }

    /**
     * Only called from mainQuery(). $q is never empty.
     */
    protected function mainQueryWithExcludedFields($q): void
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

        if ($this->query->getOption('remove_diacritics', false)) {
            if (extension_loaded('intl')) {
                $transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
                $q = $transliterator->transliterate($q);
            } elseif (extension_loaded('iconv')) {
                $q = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $q);
            } else {
                $q = $this->latinize($q);
            }
        }

        $q = $this->escapeTermOrPhrase($q);

        $qq = [];
        foreach ($usedFields as $field) {
            $qq[] = $field . ':' . $q;
        }
        $this->solariumQuery->setQuery(implode(' ', $qq));
    }

    protected function appendHiddenFilters(): void
    {
        $hiddenFilters = $this->query->getFiltersQueryHidden();
        if (!$hiddenFilters) {
            return;
        }
        $this->filterQueryValues($hiddenFilters);
        $this->filterQueryDateRange($hiddenFilters);
        $this->filterQueryFilters($hiddenFilters);
    }

    /**
     * Filter the query.
     * @todo Merge filterQueryValues() and filterQueryFilters() in filterQueryAny().
     */
    protected function filterQuery(): void
    {
        $this->filterQueryValues($this->query->getFilters());
        $this->filterQueryDateRange($this->query->getFiltersRange());
        $this->filterQueryFilters($this->query->getFiltersQuery());
    }

    protected function filterQueryValues(array $filters): void
    {
        // TODO Convert all simple filters to full filters (or do it early via form adapter).

        foreach ($filters as $fieldName => $values) {
            $fieldQueryArgs = $this->query->getFieldQueryArgs($fieldName);
            if ($fieldQueryArgs) {
                $filter = [
                    'join' => $fieldQueryArgs['join'] ?? 'and',
                    'field' => $fieldName,
                    'except' => $fieldQueryArgs['except'] ?? null,
                    'type' => $fieldQueryArgs['type'] ?? 'eq',
                    'val' => $values,
                    'datatype' => $fieldQueryArgs['datatype'] ?? null,
                ];
                $this->filterQueryFilters([$fieldName => [$filter]]);
                continue;
            }
            $name = $this->fieldToIndex($fieldName) ?? $fieldName;
            if ($name === 'id') {
                $value = [];
                array_walk_recursive($values, function ($v) use (&$value): void {
                    $value[] = $v;
                });
                $values = array_unique(array_map('intval', $value));
                if (count($values)) {
                    // Manage any special indexers for third party.
                    // TODO Add a second (hidden?) field "is_id_i".
                    // TODO Or reindex in the other way #id/items-index-serverId.
                    $value = '*\/' . implode(' OR *\/', $values);
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
                    if (isset($value['joiner']) || isset($value['type']) || isset($value['text'])
                        || isset($value['join']) || isset($value['val']) || isset($value['value'])
                    ) {
                        continue;
                    }
                }
                $value = $this->escapePhraseValue($value, 'OR');
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
                    // Nothing.
                }
            }
            return '*';
        };

        foreach ($dateRangeFilters as $field => $filterValues) {
            // Avoid issue with basic direct hidden quey filter like "resource_template_id_i=1".
            if (!is_array($filterValues)) {
                continue;
            }
            $name = $this->fieldToIndex($field) ?? $field;
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
     * @see \AdvancedSearch\Stdlib\SearchResources::buildFilterQuery()
     *
     * Filter queries use the api filter keys:
     * "field" (as key) + "join", "field", "except", "type", "val", "datatype".
     * The property keys are not supported: "joiner", "property", "type", "text".
     *
     * "except" and "datatype" are currently not supported in Query, neither here.
     * Solr does not support query on omeka datatypes.
     * For ma/nma, only simple regex are supported.
     */
    protected function filterQueryFilters(array $filters): void
    {
        /**
         * @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::buildPropertyQuery()
         * @see \AdvancedSearch\Stdlib\SearchResources::buildPropertyFilters()
         */

        $unsupportedQueryTypes = array_merge(
            SearchResources::FIELD_QUERY['value_linked_resource'],
            SearchResources::FIELD_QUERY['value_data_type'],
            SearchResources::FIELD_QUERY['value_duplicate'],
            [
                'near',
                'nnear',
                'resq',
                'nresq',
                'exs',
                'nex',
                'exm',
                'nexm',
            ]
        );

        foreach ($filters as $field => $filter) {
            // Avoid issue with basic direct hidden quey filter like "resource_template_id_i=1".
            if (!is_array($filter)) {
                continue;
            }

            $name = $this->fieldToIndex($field) ?? $field;

            $fq = '';
            $first = true;

            foreach ($filter as $queryFilter) {
                // There is no default in Omeka.
                // Skip simple filters (for hidden queries).
                if (!$queryFilter
                    || !is_array($queryFilter)
                    || empty($queryFilter['type'])
                    || !isset(SearchResources::FIELD_QUERY['reciprocal'][$queryFilter['type']])
                    || in_array($queryFilter['type'], $unsupportedQueryTypes)
                ) {
                    continue;
                }

                $joiner = $queryFilter['join'] ?? '';
                // $field = $queryFilter['field'] ?? null;
                $except = $queryFilter['except'] ?? null;
                $queryType = $queryFilter['type'];
                $value = $queryFilter['val'] ?? '';
                $dataType = $queryFilter['datatype'] ?? '';
                if ($except || $dataType) {
                    $this->logger->warn(
                        'Solr does not support search with "except" or "data type": {url}', // @translate
                        ['url' => $_SERVER['REQUEST_URI']]
                    );
                }

                // Adapted from SearchResources.
                // Quick check of value.
                // An empty string "" is not a value, but "0" is a value.
                if (in_array($queryType, SearchResources::FIELD_QUERY['value_none'], true)) {
                    $value = null;
                }
                // Check array of values, that are allowed only by filters.
                elseif (!in_array($queryType, SearchResources::FIELD_QUERY['value_single'], true)) {
                    if ((is_array($value) && !count($value))
                        || (!is_array($value) && !strlen((string) $value))
                    ) {
                        continue;
                    }
                    if (!in_array($queryType, SearchResources::FIELD_QUERY['value_single_array_or_string'])) {
                        if (!is_array($value)) {
                            $value = [$value];
                        }
                        // Normalize as array of integers or strings for next process.
                        // To use array_values() avoids doctrine issue with string keys.
                        if (in_array($queryType, SearchResources::FIELD_QUERY['value_integer'])) {
                            $value = array_values(array_unique(array_map('intval', array_filter($value, fn ($v) => is_numeric($v) && $v == (int) $v))));
                        } elseif (in_array($queryType, ['<', '≤', '≥', '>'])) {
                            // Casting to float is complex and rarely used, so only integer.
                            $value = array_values(array_unique(array_map(fn ($v) => is_numeric($v) && $v == (int) $v ? (int) $v : $v, $value)));
                            // When there is at least one string, set all values as
                            // string for doctrine.
                            if (count(array_filter($value, 'is_int')) !== count($value)) {
                                $value = array_map('strval', $value);
                            }
                        } else {
                            $value = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $value)), 'strlen')));
                        }
                        if (empty($value)) {
                            continue;
                        }
                    }
                }
                // The value should be scalar in all other cases (integer or string).
                elseif (is_array($value) || $value === '') {
                    continue;
                } else {
                    $value = trim((string) $value);
                    if (!strlen($value)) {
                        continue;
                    }
                    if (in_array($queryType, SearchResources::FIELD_QUERY['value_integer'])) {
                        if (!is_numeric($value) || $value != (int) $value) {
                            continue;
                        }
                        $value = (int) $value;
                    } elseif (in_array($queryType, ['<', '≤', '≥', '>'])) {
                        // The types "integer" and "string" are automatically
                        // infered from the php type.
                        // Warning: "float" is managed like string in mysql via pdo.
                        if (is_numeric($value) && $value == (int) $value) {
                            $value = (int) $value;
                        }
                    }
                    // Convert single values into array except if array isn't supported.
                    if (!in_array($queryType, SearchResources::FIELD_QUERY['value_single_array_or_string'], true)
                        && !in_array($queryType, SearchResources::FIELD_QUERY['value_single'], true)
                    ) {
                        $value = [$value];
                    }
                }

                // The three joiners are "and" (default), "or" and "not".
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
                        $queryType = SearchResources::FIELD_QUERY['reciprocal'][$queryType];
                    } else {
                        $joiner = 'AND';
                    }
                } else {
                    $joiner = 'AND';
                }

                // "AND/NOT" cannot be used as first.
                // TODO Will be simplified in a future version.
                $isNegative = isset(SearchResources::FIELD_QUERY['negative'])
                    ? (in_array($queryType, SearchResources::FIELD_QUERY['negative']))
                    // TODO Find a cleaner way to determine if unknown type is negative.
                    : substr($queryType, 0, 1) === 'n';
                if ($isNegative) {
                    $bool = '(NOT ';
                    $endBool = ')';
                } else {
                    $bool = '(';
                    $endBool = ')';
                }

                switch ($queryType) {
                    /**
                     * Regex requires string (_s), not text or anything else.
                     * So if the field is not a string, use a simple "+", that
                     * will be enough in most of the cases.
                     * Furthermore, unlike sql, solr regex doesn't manage
                     * insensitive search, neither flag "i".
                     * The pattern is limited to 1000 characters by default.
                     *
                     * @todo Check the size of the pattern.
                     *
                     * For diacritics and case: index and query without diacritics and lowercase.
                     *
                     * @link https://lucene.apache.org/core/6_6_6/core/org/apache/lucene/util/automaton/RegExp.html
                     * @link https://solr.apache.org/guide/solr/latest/indexing-guide/language-analysis.html
                     */

                    // Equal.
                    case 'neq':
                    case 'eq':
                    // list/nlist are deprecated, since eq/neq supports array.
                    case 'nlist':
                    case 'list':
                        if ($this->fieldIsString($name)) {
                            // $value = $this->->escapeTermOrPhrase($value);
                            $value = $this->regexDiacriticsValue($value, '', '');
                        } else {
                            $value = $this->escapePhraseValue($value, 'OR');
                        }
                        $fq .= " $joiner ($name:$bool$value$endBool)";
                        break;

                    // Contains.
                    case 'nin':
                    case 'in':
                        if ($this->fieldIsString($name)) {
                            $value = $this->regexDiacriticsValue($value, '.*', '.*');
                        } else {
                            $value = $this->escapePhraseValue($value, 'AND');
                        }
                        $fq .= " $joiner ($name:$bool$value$endBool)";
                        break;

                    // Starts with.
                    case 'nsw':
                    case 'sw':
                        if ($this->fieldIsString($name)) {
                            $value = $this->regexDiacriticsValue($value, '', '.*');
                        } else {
                            $value = $this->escapePhraseValue($value, 'AND');
                        }
                        $fq .= " $joiner ($name:$bool$value$endBool)";
                        break;

                    // Ends with.
                    case 'new':
                    case 'ew':
                        if ($this->fieldIsString($name)) {
                            $value = $this->regexDiacriticsValue($value, '.*', '');
                        } else {
                            $value = $this->escapePhraseValue($value, 'AND');
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
                        $value = $this->fieldIsString($name) ? $value : $this->escapePhraseValue($value, 'OR');
                        $fq .= " $joiner ($name:$bool$value$endBool)";
                        break;

                    case 'lt':
                    case 'lte':
                    case 'gte':
                    case 'gt':
                        // With a list of lt/lte/gte/gt, get the right value first in order
                        // to avoid multiple sql conditions.
                        // But the language cannot be determined: language of the site? of
                        // the data? of the user who does query?
                        // Practically, mysql/mariadb sort with generic unicode rules by
                        // default, so use a generic sort.
                        /** @see https://www.unicode.org/reports/tr10/ */
                        if (count($value) > 1) {
                            if (extension_loaded('intl')) {
                                $col = new \Collator('root');
                                $col->sort($value);
                            } else {
                                natcasesort($value);
                            }
                        }
                        // TODO Manage uri and resources with lt, lte, gte, gt (it has a meaning at least for resource ids, but separate).
                        if ($queryType === 'lt') {
                            $value = reset($value);
                            $value = $this->escapePhrase(--$value);
                            $fq .= " $joiner ($name:[* TO $value])";
                        } elseif ($queryType === 'lte') {
                            $value = reset($value);
                            $value = $this->escapePhrase($value);
                            $fq .= " $joiner ($name:[* TO $value])";
                        } elseif ($queryType === 'gte') {
                            $value = array_pop($value);
                            $value = $this->escapePhrase($value);
                            $fq .= " $joiner ($name:[$value TO *])";
                        } elseif ($queryType === 'gt') {
                            $value = array_pop($value);
                            $value = $this->escapePhrase(++$value);
                            $fq .= " $joiner ($name:[$value TO *])";
                        }
                        break;

                        case '<':
                        case '≤':
                            // The values are already cleaned.
                            $first = reset($value);
                            if (count($value) > 1) {
                                if (is_int($first)) {
                                    $value = min($value);
                                } else {
                                    extension_loaded('intl') ? (new \Collator('root'))->sort($value, \Collator::SORT_NUMERIC) : sort($value);
                                    $value = reset($value);
                                }
                            } else {
                                $value = $first;
                            }
                            $value = $queryType === '<' ? --$value : $value;
                            $fq .= " $joiner ($name:[* TO $value])";
                            break;
                        case '≥':
                        case '>':
                            $first = reset($value);
                            if (count($value) > 1) {
                                if (is_int($first)) {
                                    $value = max($value);
                                } else {
                                    extension_loaded('intl') ? (new \Collator('root'))->sort($value, \Collator::SORT_NUMERIC) : sort($value);
                                    $value = array_pop($value);
                                }
                            } else {
                                $value = $first;
                            }
                            $value = $queryType === '>' ? ++$value : $value;
                            $fq .= " $joiner ($name:[$value TO *])";
                            break;

                        case 'nyreq':
                        case 'yreq':
                            // The casting to integer is the simplest way to get the year:
                            // it avoids multiple substring_index, replace, etc. and it
                            // works fine in most of the real cases, except when the date
                            // does not look like a standard date, but normally it is
                            // checked earlier.
                            // Values are already casted to int.
                            $value = $this->escapePhraseValue($value, 'OR');
                            $fq .= " $joiner ($name:$bool$value$endBool)";
                            break;
                        case 'yrlt':
                        case 'yrlte':
                            $value = min($value);
                            $value = $queryType === 'yrlt' ? --$value : $value;
                            $fq .= " $joiner ($name:[* TO $value])";
                            break;
                        case 'yrgte':
                        case 'yrgt':
                            $value = max($value);
                            $value = $queryType === 'yrgt' ? ++$value : $value;
                            $fq .= " $joiner ($name:[$value TO *])";
                            break;

                    // Resource with id.
                    case 'nres':
                    case 'res':
                        // Like equal, but the field must be an integer.
                        if ($this->fieldIsInteger($name)) {
                            $value = (int) $value;
                            $fq .= " $joiner ($name:$bool$value$endBool)";
                        }
                        break;

                    // Exists (has a value).
                    case 'nex':
                        $value = $this->escapePhraseValue($value, 'OR');
                        $fq .= " $joiner (-$name:$value)";
                        break;
                    case 'ex':
                        $value = $this->escapePhraseValue($value, 'OR');
                        $fq .= " $joiner (+$name:$value)";
                        break;

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
     * Convert a field argument into one or more indexes.
     *
     * The indexes are the properties in internal sql.
     * This process allows to support same indexes in Solr.
     *
     * @todo For now, only one field is supported, since an index with multiple properties can be created.
     *
     * @return array|string|null
     */
    protected function fieldToIndex(string $field)
    {
        // TODO Allow to use property terms and dynamic fields (but should be indexed).
        $result = $this->query->getAliases()[$field]['fields']
            ?? null;
        if (!$result) {
            return null;
        }
        if (is_array($result)) {
            if (count($result) > 1) {
                $this->logger->warn(
                    'Solr does not support alias with more than one field for now: {url}', // @translate
                    ['url' => $_SERVER['REQUEST_URI']]
                );
            }
            return reset($result);
        }
        return $result;
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

    /**
     * @todo Use schema.
     */
    protected function fieldIsTokenized($name): bool
    {

        return substr($name, -2) === '_t'
            || substr($name, -4) === '_txt'
            || substr($name, -3) === '_ws'
            || strpos($name, '_txt_') !== false
            // For drupal.
            || substr($name, 0, 2) === 't_'
            || substr($name, 0, 4) === 'txt_'
            || substr($name, 0, 3) === 'ws_'
        ;
    }

    /**
     * @todo Use schema.
     */
    protected function fieldIsString($name): bool
    {
        return substr($name, -2) === '_s'
            || substr($name, -3) === '_ss'
            || substr($name, -8) === '_s_lower'
            || substr($name, -9) === '_ss_lower'
            // For drupal.
            || substr($name, 0, 3) === 'sm_'
            || substr($name, 0, 3) === 'ss_'
        ;
    }

    /**
     * @todo Use schema.
     */
    protected function fieldIsLower($name): bool
    {
        return strpos($name, '_lower') !==false;
    }

    /**
     * @todo Use schema.
     */
    protected function fieldIsInteger($name): bool
    {
        return substr($name, -2) === '_i'
            || substr($name, -3) === '_is'
            // For drupal.
            || substr($name, 0, 3) === 'is_'
            || substr($name, 0, 3) === 'im_'
        ;
    }

    /**
     * Escape a string to query keeping meaning of solr special characters.
     *
     * @see https://solr.apache.org/guide/solr/latest/query-guide/standard-query-parser.html#escaping-special-characters
     * @see https://lucene.apache.org/core/10_1_0/queryparser/org/apache/lucene/queryparser/classic/package-summary.html#Escaping_Special_Characters
     * @uses \Solarium\Core\Query\Helper::escapeTerm()
     * @uses \Solarium\Core\Query\Helper::escapePhrase()
     */
    protected function escapeTermOrPhrase($string): string
    {
        $string = trim((string) $string);

        // substr_count() is unicode-safe.
        $countQuotes = substr_count($string, '"');

        // TODO Manage the escaping of query with an odd number of quotes. Check for escaped quote \".
        if ($countQuotes < 2 || ($countQuotes % 2) === 1) {
            return $this->solariumQuery->getHelper()->escapeTerm((string) $string);
        }

        $output = [];
        $startWithQuote = (int) (mb_substr($string, 0, 1) === '"');
        foreach (explode('"', $string) as $key => $part) {
            $part = trim($part);
            if ($part !== '') {
                if ($key % 2 === $startWithQuote) {
                    $output[] = $this->solariumQuery->getHelper()->escapePhrase($part);
                } else {
                    $output[] = $this->solariumQuery->getHelper()->escapeTerm($part);
                }
            }
        }

        return implode(' AND ', $output);
    }

    /**
     * Escape a string to query keeping meaning of solr special characters.
     *
     * @see https://solr.apache.org/guide/solr/latest/query-guide/standard-query-parser.html#escaping-special-characters
     * @see https://lucene.apache.org/core/10_1_0/queryparser/org/apache/lucene/queryparser/classic/package-summary.html#Escaping_Special_Characters
     * @uses \Solarium\Core\Query\Helper::escapeTerm()
     */
    protected function escapeTerm($string): string
    {
        return $this->solariumQuery->getHelper()->escapeTerm((string) $string);
    }

    /**
     * Escape a string to query, so just enclose it with a double quote.
     *
     * The double quote and "\" are escaped too.
     *
     * @see https://solr.apache.org/guide/solr/latest/query-guide/standard-query-parser.html#escaping-special-characters
     * @uses \Solarium\Core\Query\Helper::escapePhrase()
     */
    protected function escapePhrase($phrase): string
    {
        return $this->solariumQuery->getHelper()->escapePhrase((string) $phrase);
    }

    /**
     * Enclose a value or a list of values (OR/AND) to protect a query for Solr.
     *
     * @param array|string $string
     * @return string
     */
    protected function escapePhraseValue($valueOrValues, string $joiner = 'OR'): string
    {
        if (!is_array($valueOrValues)) {
            return $this->escapePhrase($valueOrValues);
        } elseif (empty($valueOrValues)) {
            return '';
        } elseif (count($valueOrValues) === 1) {
            return $this->escapePhrase(reset($valueOrValues));
        } else {
            return '(' . implode(" $joiner ", array_unique(array_map([$this, 'escapePhrase'], $valueOrValues))) . ')';
        }
    }

    /**
     * Prepare a value for a regular expression, managing diacritics, case and
     * joker "*" and "?".
     *
     * For the list of characters to escape:
     * @see https://solr.apache.org/guide/solr/latest/query-guide/standard-query-parser.html#escaping-special-characters
     *
     * @deprecated Instead, index and query without diacritics.
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
                // To expand.
                '*' => '.*',
                '?' => '.',
                // To escape.
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
            ] + array_map(fn ($v) => substr($v, 0, 1), $this->baseDiacritics);
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

    protected function getSolrCore(): \SearchSolr\Api\Representation\SolrCoreRepresentation
    {
        if (!isset($this->solrCore)) {
            $solrCoreId = $this->searchEngine->settingEngineAdapter('solr_core_id');
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
