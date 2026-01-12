<?php declare(strict_types=1);

namespace SearchSolr\Querier;

use AdvancedSearch\Querier\AbstractQuerier;
use AdvancedSearch\Querier\Exception\QuerierException;
use AdvancedSearch\Query;
use AdvancedSearch\Response;
use AdvancedSearch\Stdlib\SearchResources;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use Solarium\Client as SolariumClient;
use Solarium\QueryType\Select\Query\Query as SelectQuery;
use Solarium\QueryType\Select\Result\Result as SolariumResult;

/**
 * @todo Rewrite the querier to simplify it and to use all solarium features directly.
 * @todo Use Solarium helpers (geo, escape, xml, etc.).
 *
 * Important: it is useless to try to manage diacritics with _ss, because it is
 * not designed for.
 *
 * @see \Solarium\Core\Query\Helper
 * @see https://solarium.readthedocs.io/en/stable/getting-started/
 * @see https://solarium.readthedocs.io/en/stable/queries/select-query/building-a-select-query/building-a-select-query/
 */
class SolariumQuerier extends AbstractQuerier
{
    protected Response $response;
    protected int $appendToKey = 0;
    protected bool $byResourceType = false;
    protected array $resourceTypes = [];
    protected array $responseData = [];
    protected ?SelectQuery $select = null;
    protected SolariumClient $solariumClient;
    protected SolrCoreRepresentation $solrCore;

    public function setQuery(Query $query): self
    {
        $this->query = $query;
        $this->appendCoreAliasesToQuery();
        return $this;
    }

    public function query(): Response
    {
        $this->response = new Response();
        $this->response->setApi($this->services->get('Omeka\ApiManager'));
        $this->byResourceType = $this->query
            ? $this->query->getByResourceType()
            : false;
        $this->response->setByResourceType($this->byResourceType);

        $this->getPreparedQuery();

        if ($this->select === null) {
            return $this->response->setMessage('An issue occurred.'); // @translate
        }

        try {
            $resultSet = $this->solariumClient->execute($this->select);
        } catch (\Throwable $e) {
            // $this->solariumQuery->getQuery() is only the main query, without filters.
            // To get the query sent by solarium to solr, check the url in
            // vendor/solarium/solarium/src/Core/Client/Adapter/Http.php
            /* @see \Solarium\Core\Client\Adapter\Http::getData() */
            $this->getLogger()->err('Solr query error {url}: {message}', [ // @translate
                'url' => urldecode($this->select->getRequestBuilder()->build($this->select)->getUri()),
                'message' => $e->getMessage(),
            ]);
            throw new QuerierException($e->getMessage(), (int) $e->getCode(), $e);
        }

        $this->hydrateResponse($resultSet);

        return $this->response->setIsSuccess(true);
    }

    public function querySuggestions(): Response
    {
        $this->response = new Response();
        $this->response->setApi($this->services->get('Omeka\ApiManager'));
        $this->query
            ? $this->response->setQuery($this->query)
            : null;
        return $this->response->setMessage('Suggestions not implemented. Use direct URL.'); // @translate
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

        $resourceTypeField = $this->solrCoreField('resource_name');
        $resourceIdField = $this->solrCoreField('o:id');

        if (!$resourceTypeField || !$resourceIdField) {
            return [];
        }

        $query = $this->solariumClient->createSelect();
        $query->createFilterQuery('res_type')->setQuery($resourceTypeField . ':' . $this->escapeTerm($resourceType));
        $query->createFilterQuery('res_ids')->setQuery($resourceIdField . ':(' . implode(' OR ', $ids) . ')');

        $resultSet = $this->solariumClient->select($query);

        // TODO Reorder by ids? Check for duplicate resources first.

        return $resultSet->getData()['response']['docs'] ?? [];
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
        $fields = is_array($fields) ? $fields : [$fields];

        // The terms query in solarium does not support filtering by field, so
        // it is not possible to filter by is_public or by site.
        // So either index values by is_public and site or use a standard query.
        $isPublicField = $this->solrCoreField('is_public');
        $sitesField = $this->solrCoreField('site/o:id');

        if (($this->query->getIsPublic() && $isPublicField)
            || ($this->query->getSiteId() && $sitesField)
            // "Terms" cannot be used for numeric fields (date, integer, float).
            || $this->fieldIsNumeric(reset($fields))
        ) {
            $result = $this->queryValuesWithFacets($fields, $isPublicField, $sitesField);
        } else {
            $result = $this->queryValuesWithTerms($fields);
        }

        $list = array_merge(...array_values($result));
        natcasesort($list);
        $list = array_keys(array_flip(array_filter($list, 'strlen')));
        return array_combine($list, $list);
    }

    protected function queryValuesWithFacets(array $fields, ?string $isPublicField, ?string $sitesField): array
    {
        $query = $this->solariumClient->createSelect();

        if ($this->query->getIsPublic() && $isPublicField) {
            // The field may be a boolean or an integer.
            $val = $this->fieldIsBool($isPublicField) ? 'true' : 1;
            $query->createFilterQuery('pub')->setQuery("$isPublicField:$val");
        }

        if ($siteId = $this->query->getSiteId()) {
            $query->createFilterQuery('site')->setQuery("$sitesField:$siteId");
        }

        $facetSet = $query->getFacetSet();
        foreach ($fields as $i => $field) {
            $facetSet->createFacetField("f$i")
                ->setField($field)
                ->setSort(\Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC)
                ->setLimit(-1)
                // Only used values in the current site.
            ->setMinCount(1);
        }

        $resultSet = $this->solariumClient->select($query);
        $result = [];
        foreach ($resultSet->getFacetSet()->getFacets() as $facet) {
            $result[] = array_keys($facet->getValues());
        }
        return $result;
    }

    protected function queryValuesWithTerms(array $fields): array
    {
        // In Sort, a query value is a terms query.
        $query = $this->solariumClient->createTerms();
        $query->setFields($fields)
            ->setSort(\Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC)
            ->setLimit(-1)
            // Only used values. Anyway, by default there is no predefined list.
            ->setMinCount(1);

        $resultSet = $this->solariumClient->terms($query);
        // Results are structured by field and term/count.
        return array_map(fn ($v) => array_keys($v), $resultSet->getResults());
    }

    /**
     * Warning: unlike queryValues, the field isn't an alias but a real index.
     *
     * Currently only used in admin, so no check for public or site.
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

        $this->getClient();
        $this->appendCoreAliasesToQuery();
        $fields = is_array($fields) ? $fields : [$fields];

        $sorts = [
            'count asc' => \Solarium\Component\Facet\JsonTerms::SORT_COUNT_ASC,
            'count desc' => \Solarium\Component\Facet\JsonTerms::SORT_COUNT_DESC,
            'index asc' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
            'index desc' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_DESC,
        ];
        $solrSort = $sorts[$sort] ?? \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC;

        // In Sort, a query value is a terms query.
        $query = $this->solariumClient->createTerms();
        $query
            ->setFields($fields)
            ->setSort($solrSort)
            ->setLimit(-1)
            // Only used values. Anyway, by default there is no predefined list.
            ->setMinCount(1);
        $resultSet = $this->solariumClient->terms($query);

        // TODO The sort does not seem to work, so for now resort locally.
        $result = [];
        foreach ($fields as $field) {
            $terms = $resultSet->getTerms($field) ?: [];
            switch ($solrSort) {
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

    public function queryAllResourceIds(?string $resourceType = null, bool $byResourceType = false): array
    {
        // Build the current query if needed.
        $this->getPreparedQuery();
        if ($this->select === null) {
            return [];
        }

        try {
            // Clone and fetch all ids without pagination limits and with grouping preserved.
            $allQuery = clone $this->select;
            $allQuery
                ->setFields(['id'])
                ->setRows(null)
                ->setStart(null);

            if ($allQuery->getGrouping()->getFields()) {
                $allQuery->getGrouping()
                    ->setLimit(null)
                    ->setOffset(null);
            }

            $resultSetAll = $this->solariumClient->execute($allQuery);

            // Collect ids grouped by resource type.
            $grouped = [];
            $groupComponent = $resultSetAll->getGrouping();
            if ($groupComponent) {
                foreach ($groupComponent as $fieldGroup) {
                    foreach ($fieldGroup as $valueGroup) {
                        $type = $valueGroup->getValue();
                        $ids = array_column($valueGroup->getDocuments(), 'id');
                        foreach ($ids as &$documentId) {
                            $resourceId = basename($documentId);
                            $documentId = is_numeric($resourceId) ? (int) $resourceId : $resourceId;
                        }
                        unset($documentId);
                        $grouped[$type] = $ids;
                    }
                }
            }

            // Return according to requested shape.
            if ($resourceType !== null) {
                return $grouped[$resourceType] ?? [];
            }
            if ($byResourceType) {
                return $grouped;
            }
            return $grouped ? array_merge(...array_values($grouped)) : [];
        } catch (\Throwable $e) {
            $this->getLogger()->warn(
                'Could not fetch all resource ids: {message}', // @translate
                ['message' => $e->getMessage()]
            );
            return [];
        }
    }

    /**
     * @todo Improve the integration of Solarium. Many things can be added directly as option or as array.
     * @todo Create an Omeka json output directly in Solr (via solarium nevertheless).
     * @todo Remove checks from here.
     *
     * {@inheritDoc}
     * @see \AdvancedSearch\Querier\AbstractQuerier::getPreparedQuery()
     */
    public function getPreparedQuery(): ?SelectQuery
    {
        $this
            ->prepareCoreAndClient()
            ->buildSelectQuery();

        if ($this->select === null) {
            return null;
        }

        $this
            ->configureEDisMax()
            ->applyMainQuery()
            ->normalizeQueryString()
            ->applyDefaultFilters()
            ->applyUserFiltersAndRanges()
            ->applyFacets()
            ->applySort()
            ->applyPagination()
            ->clampClausesForQuery()
            ->applyBoosts();

        return $this->select;
    }

    public function getResponseData(): array
    {
        return $this->responseData;
    }

    // =========================================================================
    // QUERY BUILDING METHODS
    // =========================================================================

    protected function prepareCoreAndClient(): self
    {
        $this->getSolrCore();
        $this->getClient();
        return $this;
    }

    protected function buildSelectQuery(): self
    {
        if (!$this->query) {
            $this->select = null;
            return $this;
        }

        $this->select = $this->solariumClient->createSelect();
        $this->select->addField('id');

        if ($df = $this->query->getQueryDefaultField()) {
            $this->select->setQueryDefaultField($df);
        }

        $indexerTypes = $this->searchEngine->setting('resource_types', []);
        $this->resourceTypes = array_intersect(
            $this->query->getResourceTypes() ?: $indexerTypes,
            $indexerTypes
        );

        if (!$this->resourceTypes) {
            $this->select = null;
        }

        return $this;
    }

    /**
     * Configure EDisMax per-request and keep only foldable query fields.
     *
     * Also disable SOW to avoid splitting tokens like "949.0252" into too many
     * clauses.
     */
    protected function configureEDisMax(): self
    {
        if (!$this->select) {
            return $this;
        }

        $this->select->addParam('defType', 'edismax')->addParam('sow', 'false');
        $dismax = $this->select->getDisMax();
        $existing = trim((string) $dismax->getQueryFields());

        if ($existing === '') {
            $foldable = $this->fieldsFoldable();
            if ($foldable) {
                $dismax->setQueryFields(implode(' ', $foldable));
            }
        } else {
            $allowed = array_flip($this->fieldsFoldable());
            $kept = array_filter(
                preg_split('/\s+/', $existing) ?: [],
                fn ($p) => isset($allowed[preg_replace('~\^.*$~', '', $p)])
            );
            if ($kept) {
                $dismax->setQueryFields(implode(' ', $kept));
            }
        }

        return $this;
    }

    protected function applyMainQuery(): self
    {
        if (!$this->select) {
            return $this;
        }

        // The default query is managed by the module Advanced Search.
        // Here, this is a catch-them-all query.
        // The default query with Solarium returns all results.
        // $defaultQuery = '';

        $raw = trim($this->query->getQuery()
            . ' ' . $this->query->getQueryRefine());

        if ($raw === '') {
            $this->select->setQuery('*:*');
            return $this;
        }

        if ($this->query->getOption('remove_diacritics', false)) {
            $raw = $this->removeDiacritics($raw);
        }

        $excludedFields = array_merge(
            $this->query->getExcludedFields(),
            $this->getFullTextFieldsForSearchInRecord()
        );

        /**
         * Only called from mainQuery(). $q is never empty.
         */
        if ($raw !== '*:*' && $excludedFields) {
            $fields = array_diff(
                $this->usedSolrFields(
                    ['t_', 'txt_', 'ss_', 'sm_', 'ws_'],
                    ['_t', '_txt', '_ss', '_s', '_ss_lower', '_s_lower', '_ws'],
                    []
                ),
                $excludedFields
            );

            if ($fields) {
                $escaped = $this->escapeTermOrPhrase($raw);
                $this->select->setQuery(implode(' OR ', array_map(fn ($f) => "$f:$escaped", $fields)));
                return $this;
            }
        }

        $this->select->setQuery($this->escapeTermOrPhrase($raw));

        // Set settings used for main search.
        $cfg = array_filter($this->solrCore->settings()['query'] ?? []);
        if ($cfg) {
            // TODO These options and other DisMax ones can be passed directly as options. Even the query is an option.
            $dismax = $this->select->getDisMax();
            isset($cfg['minimum_match'])
                && $dismax->setMinimumMatch($cfg['minimum_match']);
            isset($cfg['tie_breaker'])
                && $dismax->setTie((float) $cfg['tie_breaker']);
        }

        return $this;
    }

    protected function normalizeQueryString(): self
    {
        if (!$this->select) {
            return $this;
        }

        $q = trim((string) $this->select->getQuery());

        if ($q && $q !== '*:*') {
            $q = preg_replace('/\s+/', ' ', $q);
            // Quote dot numbers to avoid analyzer splitting and nested clause
            // growth.
            if (preg_match('~^\d[\d.\-_/]*\d$~u', $q)) {
                $q = $this->escapePhrase($q);
            }
            $this->select->setQuery($q);
        }

        return $this;
    }

    protected function applyDefaultFilters(): self
    {
        if (!$this->select) {
            return $this;
        }

        // IsPublic is set by the server automatically, not by the user.
        // TODO Check if the arguments are set by the user and remove them.

        // Since version of module Access 3.4.17, the access level is a standard
        // filter that may be enable or not.

        // Visibility.
        if ($this->query->getIsPublic() && ($field = $this->solrCoreField('is_public'))) {
            $val = $this->fieldIsBool($field) ? 'true' : '1';
            $this->select->addFilterQuery(['key' => 'is_public', 'query' => "$field:$val"]);
        }

        // Site.
        if (($siteId = $this->query->getSiteId()) && ($field = $this->solrCoreField('site/o:id'))) {
            $this->select->addFilterQuery(['key' => 'site', 'query' => "$field:$siteId"]);
        }

        // Resource types with grouping.
        if ($this->resourceTypes && ($field = $this->solrCoreField('resource_name'))) {
            $types = implode(' OR ', array_map([$this, 'escapeTerm'], $this->resourceTypes));
            $this->select
                ->addFilterQuery(['key' => 'rtype', 'query' => "$field:($types)"])
                ->getGrouping()->addField($field)->setNumberOfGroups(true);
        }

        // Index name.
        if ($this->searchEngine->settingEngineAdapter('index_name') && ($field = $this->solrCoreField('search_index'))) {
            $this->select->addFilterQuery(['key' => 'index_name', 'query' => "$field:" . $this->searchEngine->shortName()]);
        }

        return $this;
    }

    protected function applyUserFiltersAndRanges(): self
    {
        $this
            ->appendHiddenFilters()
            ->filterQuery();
        return $this;
    }

    protected function applyFacets(): self
    {
        $facets = $this->query->getFacets();
        if (!$facets) {
            return $this;
        }

        // Pre-calculate min/max for range facets.
        $fieldRanges = $this->prepareRangeFacetBounds($facets);

        /** @var \Solarium\Component\FacetSet $solariumFacetSet */
        $facetSet = $this->select->getFacetSet();
        $orders = [
            // Default alphabetic order is asc.
            'alphabetic' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
            'alphabetic asc' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
            'alphabetic desc' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_DESC,
            // Default total order is desc.
            'total' => \Solarium\Component\Facet\JsonTerms::SORT_COUNT_DESC,
            'total asc' => \Solarium\Component\Facet\JsonTerms::SORT_COUNT_ASC,
            'total desc' => \Solarium\Component\Facet\JsonTerms::SORT_COUNT_DESC,
            // Default total then alphabetic order is desc.
            'total_alpha' => \Solarium\Component\Facet\JsonTerms::SORT_COUNT_DESC,
            'total_alpha desc' => \Solarium\Component\Facet\JsonTerms::SORT_COUNT_DESC,
            // Default values order is asc.
            'values' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
            'values asc' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
            'values desc' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_DESC,
            // Default values order is alphabetic asc.
            'default' => \Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC,
        ];

        // Use "json facets" output, that is recommended by Solr.
        /** @see https://solr.apache.org/guide/solr/latest/query-guide/json-facet-api.html */

        foreach ($facets as $name => $data) {
            if (empty($data['field'])) {
                continue;
            }

            // Handle range facets.
            if (in_array($data['type'] ?? '', ['Range', 'RangeDouble', 'SelectRange'])) {
                $min = $data['min'] ?? ($fieldRanges[$name]['min'] ?? 0);
                $max = $data['max'] ?? ($fieldRanges[$name]['max'] ?? 0);
                $step = (int) ($data['step'] ?? 1);

                // Solr upper bounds are excluded by default, so add step to max.
                /** @see https://solr.apache.org/guide/solr/latest/query-guide/faceting.html */
                if ($max) {
                    $max = (int) $max + ($step ?: 1);
                }

                /** @var \Solarium\Component\Facet\JsonRange $facet */
                $facet = $facetSet->createJsonFacetRange($name)
                    ->setField($data['field'])
                    ->setStart($min)
                    ->setEnd($max)
                    /*
                    ->setInclude([
                        // Default is lower only, avoiding double counting.
                        // Edge is useless in most of the case.
                        \Solarium\Component\Facet\AbstractRange::INCLUDE_LOWER,
                        // \Solarium\Component\Facet\AbstractRange::INCLUDE_EDGE,
                    ])
                     */
                    ->setGap($step ?: 1)
                    // MinCount is used only with standard facet range.
                    // ->setMinCount(1)
                ;
            } else {
                // Term facets.
                // The domain option is used to exclude the tagged search
                // filter related to the facet.
                // see: https://yonik.com/multi-select-faceting/
                /** @var \Solarium\Component\Facet\FieldValueParametersInterface $facet */
                $excludeTag = strtoupper($name . '-facet');
                $facet = $facetSet->createJsonFacetTerms($name)
                    ->setField($data['field'])
                    ->setSort($orders[$data['order'] ?? 'default'] ?? $orders['default'])
                    ->setOptions(['domain' => ['excludeTags' => [$excludeTag]]]);

                if (isset($data['limit']) && $data['limit'] > 0) {
                    $facet
                        ->setLimit($data['limit']);
                }
            }

            if (!empty($data['values'])) {
                $vals = is_array($data['values'])
                    ? $data['values']
                    : (preg_split('/[|,]/', (string) $data['values']) ?: []);
                $vals = array_values(array_filter(array_map('strval', $vals), 'strlen'));
                if ($vals) {
                    // Escape all strings as regex.
                    $escaped = array_map(fn ($v) => '~^' . preg_quote($v, '~') . '$~', $vals);
                    $facet
                        ->setMatches('~^' . implode('|', array_map(fn ($v) => preg_quote($v, '~'), $vals)) . '$~');
                }
            }
        }

        // TODO Manage facet languages for Solr: index them separately?

        // Active facets.
        /** @link https://petericebear.github.io/php-solarium-multi-select-facets-20160720/ */
        $activeFacets = $this->query->getActiveFacets();
        foreach ($activeFacets as $fname => $values) {
            if (!is_array($values) || !count($values)) {
                continue;
            }

            $firstKey = key($values);
            // Check for range facet.
            if (count($values) <= 2 && ($firstKey === 'from' || $firstKey === 'to')) {
                $hasFrom = isset($values['from']) && $values['from'] !== '';
                $hasTo = isset($values['to']) && $values['to'] !== '';

                if ($hasFrom && $hasTo) {
                    $from = $this->escapePhrase($values['from']);
                    $to = $this->escapePhrase($values['to']);
                    $this->select->addFilterQuery([
                        'key' => $fname . '-facet',
                        'query' => "$fname:[$from TO $to]",
                        'tag' => 'exclude',
                    ]);
                } elseif ($hasFrom) {
                    $from = $this->escapePhrase($values['from']);
                    $this->select->addFilterQuery([
                        'key' => $fname . '-facet',
                        'query' => "$fname:[$from TO *]",
                        'tag' => 'exclude',
                    ]);
                } elseif ($hasTo) {
                    $to = $this->escapePhrase($values['to']);
                    $this->select->addFilterQuery([
                        'key' => $fname . '-facet',
                        'query' => "$fname:[* TO $to]",
                        'tag' => 'exclude',
                    ]);
                }
            } else {
                // Term facet - add tag for multi-select.
                // A tag should be added to the facet filter query to be
                // able to exclude it in the facet query 'tag' option is
                // ignored when using 'query', add the tag in the query
                // statement.
                $key = $fname . '-facet';
                $tag = strtoupper($key);
                $escaped = $this->escapePhraseValue($values, 'OR');
                $this->select->addFilterQuery([
                    'key' => $key,
                    'query' => "{!tag=$tag}$fname:$escaped",
                ]);
            }
        }

        return $this;
    }

    protected function prepareRangeFacetBounds(array $facets): array
    {
        $fieldRanges = [];
        foreach ($facets as $name => $data) {
            if (in_array($data['type'] ?? '', ['Range', 'RangeDouble', 'SelectRange'])) {
                if (!isset($data['min']) || !isset($data['max'])) {
                    $fieldRanges[$name] = [];
                }
            }
        }

        if ($fieldRanges) {
            $all = $this->queryValuesCount(array_keys($fieldRanges));
            foreach ($all as $name => $values) {
                $values = array_keys(array_filter($values));
                $fieldRanges[$name]['min'] = $values ? min($values) : 0;
                $fieldRanges[$name]['max'] = $values ? max($values) : 0;
            }
        }

        return $fieldRanges;
    }

    protected function applySort(): self
    {
        $sort = $this->query->getSort();

        // Support old config, but the default solr field name anyway.
        if (in_array($sort, ['relevance', 'relevance desc', 'relevance asc', 'score', 'score desc', 'score asc'])) {
            if ($this->select) {
                // Clear any existing sort parameter accidentally set upstream.
                $this->select->clearSorts();
                $this->select->addSort('score', SelectQuery::SORT_DESC);
            }
        } elseif ($sort) {
            [$field, $order] = array_pad(explode(' ', $sort, 2), 2, 'asc');
            $field = $this->fieldToIndex($field) ?? $field;
            $this->select->addSort($field, strtolower($order) === 'desc' ? SelectQuery::SORT_DESC : SelectQuery::SORT_ASC);
        }

        return $this;
    }

    protected function applyPagination(): self
    {
        // Limit is per page and offset is page x limit.
        $limit = $this->query->getLimit();
        $offset = $this->query->getOffset();

        if ($limit !== null) {
            $this->select->setRows($limit);
        }

        if ($offset !== null) {
            $this->select->setStart($offset);
        }

        if ($this->select->getGrouping()->getFields()) {
            if ($limit !== null) {
                $this->select->getGrouping()->setLimit($limit);
            }
            if ($offset !== null) {
                $this->select->getGrouping()->setOffset($offset);
            }
        }

        return $this;
    }

    /**
     * Simple estimated clause guard. If too large, fallback to a single
     * foldable field with a phrase.
     */
    protected function clampClausesForQuery(): self
    {
        if (!$this->select) {
            return $this;
        }

        $q = (string) $this->select->getQuery();
        if ($q && strpos($q, ' OR ') !== false) {
            $parts = preg_split('/\s+OR\s+/i', $q) ?: [];
            if (count($parts) > 1024) {
                $this->select->setQuery(implode(' OR ', array_slice($parts, 0, 1024)));
            }
        }

        return $this;
    }

    protected function applyBoosts(): self
    {
        // The boost is only useful when there is a query.
        $q = (string) $this->select->getQuery();
        if (!$q || $q === '*:*') {
            return $this;
        }

        // DisMax is the only querier for now (not standard, not eDisMax).
        // Boosts from the index and from the query.
        // In practice, solr manage boost only at search time, so the difference
        // is only for configuration by the user.
        // Important: when used, the full list of fields should be set.
        // Note: field_boost is stored as array [field => boost] for solarium,
        // that matches solr string format "field1 field2^2".
        $coreBoosts = $this->solrCore->setting('field_boost');
        $queryBoosts = (array) $this->query->getFieldBoosts();
        $merged = array_merge($coreBoosts, $queryBoosts);

        if ($merged) {
            $qf = [];
            foreach ($merged as $field => $boost) {
                $boost = (float) $boost;
                $qf[] = $boost > 0 ? "$field^$boost" : $field;
            }
            $dismax = $this->select->getDisMax();
            $existing = trim((string) $dismax->getQueryFields());
            $final = $existing
                ? "$existing " . implode(' ', $qf)
                : implode(' ', $qf);
            $dismax->setQueryFields($final);
        }

        return $this;
    }

    // =========================================================================
    // RESPONSE HYDRATION
    // =========================================================================

    protected function hydrateResponse(SolariumResult $resultSet): self
    {
        $this->response->setQuery($this->query);
        $this->response->setCurrentPage($this->query->getPage());
        $this->response->setPerPage($this->query->getPerPage());

        // Process grouped results.
        $groupComponent = $resultSet->getGrouping();
        if ($groupComponent) {
            foreach ($groupComponent as $fieldGroup) {
                $this->response->setTotalResults($fieldGroup->getMatches());

                $resourceTotalResults = array_fill_keys($this->resourceTypes, 0);
                $resultsByType = [];

                foreach ($fieldGroup as $valueGroup) {
                    $type = $valueGroup->getValue();
                    $resourceTotalResults[$type] = $valueGroup->getNumFound();

                    foreach ($valueGroup as $doc) {
                        $id = basename($doc['id']);
                        $resultsByType[$type][] = ['id' => is_numeric($id) ? (int) $id : $id];
                    }
                }

                $this->response->setAllResourceTotalResults($resourceTotalResults);
                $this->response->setResults(array_replace(array_fill_keys($this->resourceTypes, []), $resultsByType));
            }
        }

        // Process facets.
        $this->processFacets($resultSet);

        $this->response->setActiveFacets($this->query->getActiveFacets());
        return $this;
    }

    protected function processFacets(SolariumResult $resultSet): void
    {
        $facetSet = $resultSet->getFacetSet();
        if (!$facetSet) {
            return;
        }

        $facetCounts = [];
        $queryFacets = $this->query->getFacets();
        $facetListAll = $this->query->getOption('facet_list') === 'all';

        // Explode with separator "|" if present, else ",".
        // For complex cases, an array should be used.
        $explode = fn ($string): array => explode(strpos((string) $string, '|') === false ? ',' : '|', (string) $string);

        foreach ($facetSet->getFacets() as $name => $facetResult) {
            if ($facetResult instanceof \Solarium\Component\Result\Facet\Buckets) {
                $facetCount = [];
                foreach ($facetResult->getBuckets() as $bucket) {
                    $count = $bucket->getCount();
                    if ($facetListAll || $count) {
                        $value = $bucket->getValue();
                        $facetCount[$value] = ['value' => $value, 'count' => $count];
                    }
                }

                // Apply custom value ordering if specified.
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
        }

        $this->response->setFacetCounts($facetCounts);
    }

    protected function prepareAllResourceIds(SolariumResult $resultSet): void
    {
        // Query for all resource ids (not just current page).
        try {
            $allQuery = clone $this->select;
            $allQuery
                ->setFields(['id'])
                ->setRows(null)
                ->setStart(null);
            $allQuery->getGrouping()
                ->setLimit(null)
                ->setOffset(null);

            $resultSetAll = $this->solariumClient->execute($allQuery);

            foreach ($resultSetAll->getGrouping() as $fieldGroup) {
                foreach ($fieldGroup as $valueGroup) {
                    $type = $valueGroup->getValue();
                    $result = array_column($valueGroup->getDocuments(), 'id');
                    foreach ($result as &$documentId) {
                        $resourceId = basename($documentId);
                        $documentId = is_numeric($resourceId) ? (int) $resourceId : $resourceId;
                    }
                    unset($documentId);
                    $this->response->setAllResourceIdsForResourceType($type, $result);
                }
            }
        } catch (\Exception $e) {
            $this->getLogger()->warn(
                'Could not fetch all resource ids: {message}', // @translate
                ['message' => $e->getMessage()]
            );
        }
    }

    protected function aggregateResultsByResourceType(): void
    {
        // Fetch all ids grouped by resource type (computed on demand).
        $allResourceIdsByType = $this->queryAllResourceIds(null, true);

        // Aggregate only when not grouped by resource type and there are multiple types.
        if (!$this->byResourceType && $this->resourceTypes && count($this->resourceTypes) > 1) {
            // Aggregate ids
            if (isset($allResourceIdsByType['resources'])) {
                $this->response->setAllResourceIdsByResourceType(['resources' => $allResourceIdsByType['resources']]);
            } else {
                $mergedIds = array_merge(...array_values($allResourceIdsByType ?: []));
                $this->response->setAllResourceIdsByResourceType(['resources' => $mergedIds]);
            }

            // Aggregate current page results
            $resultsByType = $this->response->getResults();
            if (isset($resultsByType['resources'])) {
                $this->response->setResults(['resources' => $resultsByType['resources']]);
            } else {
                $this->response->setResults(['resources' => array_replace(...array_values($resultsByType ?: []))]);
            }

            // Aggregate totals
            $totalResultsByType = $this->response->getResourceTotalResults();
            $total = isset($totalResultsByType['resources'])
                ? $totalResultsByType['resources']
                : array_sum($totalResultsByType ?: []);
            $this->response->setResourceTotalResults('resources', $total);
            $this->response->setTotalResults($total);
        }
    }

    // =========================================================================
    // FILTER METHODS
    // =========================================================================

    protected function appendHiddenFilters(): self
    {
        $hidden = $this->query->getFiltersQueryHidden();
        if ($hidden) {
            $this->processFilters($hidden);
        }
        return $this;
    }

    /**
     * Filter the query.
     * @todo Merge filterQueryValues() and filterQueryFilters() in filterQueryAny().
     */
    protected function filterQuery(): self
    {
        $this->processFilters($this->query->getFilters());
        $this->processDateRangeFilters($this->query->getFiltersRange());
        $this->processAdvancedFilters($this->query->getFiltersQuery());
        return $this;
    }

    protected function processFilters(array $filters): void
    {
        foreach ($filters as $fieldName => $values) {
            $args = $this->query->getFieldQueryArgs($fieldName);
            if ($args) {
                $this->processAdvancedFilters([$fieldName => [[
                    'join' => $args['join'] ?? 'and',
                    'field' => $fieldName,
                    'except' => $args['except'] ?? null,
                    'type' => $args['type'] ?? 'eq',
                    'val' => $values,
                    'datatype' => $args['datatype'] ?? null,
                ]]]);
                continue;
            }

            $name = $this->fieldToIndex($fieldName) ?? $fieldName;

            if ($name === 'id') {
                $value = [];
                array_walk_recursive($values, function ($v) use (&$value): void {
                    $value[] = (int) $v;
                });
                $values = array_values(array_unique($value));

                if (count($values)) {
                    // Manage any special indexers for third party.
                    // TODO Add a second (hidden?) field from source "o:id".
                    // TODO Or reindex in the other way #id/items-index-serverId.
                    $value = '*\/' . implode(' OR *\/', $values);
                    $value = '*\/' . implode(' OR *\/', $values);
                    $this->select
                        ->createFilterQuery($name . '_' . ++$this->appendToKey)
                        ->setQuery("$name:$value");
                }
                continue;
            }

            // Avoid issue with basic direct hidden quey filter like "resource_template_id_i=1".

            $values = is_array($values) ? $values : [$values];

            foreach ($values as $v) {
                if (is_array($v)) {
                    // Skip date range queries (for hidden queries).
                    if (isset($v['from']) || isset($v['to'])
                        || isset($v['joiner']) || isset($v['type']) || isset($v['text'])
                        || isset($v['join']) || isset($v['val']) || isset($v['value'])
                    ) {
                        continue;
                    }
                }

                if (is_scalar($v) && strlen((string) $v)) {
                    $escaped = $this->escapePhraseValue($v, 'OR');
                    $this->select
                        ->createFilterQuery($name . '_' . ++$this->appendToKey)
                        ->setQuery("$name:$escaped");
                }
            }
        }
    }

    protected function processDateRangeFilters(array $filters): void
    {
        foreach ($filters as $field => $ranges) {
            if (!is_array($ranges)) {
                continue;
            }

            $name = $this->fieldToIndex($field) ?? $field;
            $isDate = substr($name, -3) === '_dt' || substr($name, -4) === '_dts' || substr($name, -4) === '_pdt' || substr($name, -4) === '_tdt' || substr($name, -5) === '_pdts' || substr($name, -5) === '_tdts';

            foreach ($ranges as $range) {
                if (!is_array($range)) {
                    continue;
                }

                $from = $range['from'] ?? '*';
                $to = $range['to'] ?? '*';

                if ($isDate && $from !== '*') {
                    $from = $this->normalizeDate($from);
                }
                if ($isDate && $to !== '*') {
                    $to = $this->normalizeDate($to);
                }

                if ($from !== '*' || $to !== '*') {
                    $this->select->createFilterQuery($name . '_' . ++$this->appendToKey)
                        ->setQuery("$name:[$from TO $to]");
                }
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
    protected function processAdvancedFilters(array $filters): void
    {
        $unsupported = array_merge(
            SearchResources::FIELD_QUERY['value_linked_resource'],
            SearchResources::FIELD_QUERY['value_data_type'],
            SearchResources::FIELD_QUERY['value_duplicate'],
            [
                'near',
                'nnear',
                'resq',
                'nresq',
                'exs',
                'nexm',
            ]
        );

        foreach ($filters as $field => $filterList) {
            // Avoid issue with basic direct hidden quey filter like "resource_template_id_i=1".
            if (!is_array($filterList)) {
                continue;
            }

            $fq = '';
            $first = true;
            $name = null;
            $nameAny = null;
            $nameInteger = null;

            foreach ($filterList as $f) {
                if (!is_array($f) || empty($f['type']) || !isset(SearchResources::FIELD_QUERY['reciprocal'][$f['type']]) || in_array($f['type'], $unsupported)) {
                    continue;
                }

                $joiner = $f['join'] ?? '';
                $type = $f['type'];
                $val = $f['val'] ?? '';

                // Adapted from SearchResources.
                // Quick check of value.
                // An empty string "" is not a value, but "0" is a value.
                if (in_array($type, SearchResources::FIELD_QUERY['value_none'], true)) {
                    $val = null;
                }
                // Check array of values, that are allowed only by filters.
                elseif (!in_array($type, SearchResources::FIELD_QUERY['value_single'], true)) {
                    if ((is_array($val) && !count($val)) || (!is_array($val) && !strlen((string) $val))) {
                        continue;
                    }
                    if (!in_array($type, SearchResources::FIELD_QUERY['value_single_array_or_string'])) {
                        if (!is_array($val)) {
                            $val = [$val];
                        }
                        // Normalize as array of integers or strings for next process.
                        // To use array_values() avoids doctrine issue with string keys.
                        if (in_array($type, SearchResources::FIELD_QUERY['value_integer'])) {
                            $val = array_values(array_unique(array_map('intval', array_filter($val, fn ($v) => is_numeric($v) && $v == (int) $v))));
                        } elseif (in_array($type, ['<', '≤', '≥', '>'])) {
                            // Casting to float is complex and rarely used, so only integer.
                            $val = array_values(array_unique(array_map(fn ($v) => is_numeric($v) && $v == (int) $v ? (int) $v : $v, $val)));
                            // When there is at least one string, set all values as
                            // string for doctrine.
                            if (count(array_filter($val, 'is_int')) !== count($val)) {
                                $val = array_map('strval', $val);
                            }
                        } else {
                            $val = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $val)), 'strlen')));
                        }
                        if (empty($val)) {
                            continue;
                        }
                    }
                }
                // The value should be scalar in all other cases (integer or string).
                elseif (is_array($val) || $val === '') {
                    continue;
                } else {
                    $val = trim((string) $val);
                    if (!strlen($val)) {
                        continue;
                    }
                    if (in_array($type, SearchResources::FIELD_QUERY['value_integer'])) {
                        if (!is_numeric($val) || $val != (int) $val) {
                            continue;
                        }
                        $val = (int) $val;
                    } elseif (in_array($type, ['<', '≤', '≥', '>'])) {
                        // The types "integer" and "string" are automatically
                        // infered from the php type.
                        // Warning: "float" is managed like string in mysql via pdo.
                        if (is_numeric($val) && $val == (int) $val) {
                            $val = (int) $val;
                        }
                    }
                    if (!in_array($type, SearchResources::FIELD_QUERY['value_single_array_or_string'], true)
                        && !in_array($type, SearchResources::FIELD_QUERY['value_single'], true)
                    ) {
                        $val = [$val];
                    }
                }

                // The three joiners are "and" (default), "or" and "not".
                // Check joiner and invert the query type for joiner "not".

                if ($first) {
                    $joiner = '';
                    $first = false;
                } elseif ($joiner) {
                    if ($joiner === 'or') {
                        $joiner = 'OR';
                    } elseif ($joiner === 'not') {
                        $joiner = 'AND';
                        $type = SearchResources::FIELD_QUERY['reciprocal'][$type];
                    } else {
                        $joiner = 'AND';
                    }
                } else {
                    $joiner = 'AND';
                }

                $requireInteger = in_array($type, SearchResources::FIELD_QUERY['value_integer']);
                if ($requireInteger) {
                    $nameInteger ??= $this->fieldToIndexNumeric($field) ?? $nameAny ?? ($nameAny = ($this->fieldToIndex($field) ?? $field));
                    $name = $nameInteger;
                } else {
                    $nameAny ??= $this->fieldToIndex($field) ?? $field;
                    $name = $nameAny;
                }

                // "AND/NOT" cannot be used as first.
                // TODO Will be simplified in a future version.
                $isNegative = substr($type, 0, 1) === 'n';
                $wrap = $isNegative ? '(NOT ' : '(';
                $end = ')';

                $query = $this->buildAdvancedFilterQuery($type, $val, $name, $wrap, $end);
                if ($query) {
                    $fq .= " $joiner $query";
                }
            }

            if ($fq) {
                $this->select->createFilterQuery($name . '_fq_' . ++$this->appendToKey)
                ->setQuery(ltrim($fq));
            }
        }
    }

    /**
     * Build advanced search filter like omeka api.
     *
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
     * Static fields like _ss cannot be used for these queries. Use filters
     * instead.
     *
     * @link https://lucene.apache.org/core/6_6_6/core/org/apache/lucene/util/automaton/RegExp.html
     * @link https://solr.apache.org/guide/solr/latest/indexing-guide/language-analysis.html
     */
    protected function buildAdvancedFilterQuery(string $type, $val, string $field, string $wrap, string $end): string
    {
        // Equal.
        switch ($type) {
            case 'neq':
            case 'eq':
            // list/nlist are deprecated, since eq/neq supports array.
            case 'nlist':
            case 'list':
                if ($this->fieldIsString($field)) {
                    $val = $this->escape($val, '', '');
                } else {
                    $val = $this->escapePhraseValue($val, 'OR');
                }
                return "$field:$wrap$val$end";

            // Contains.
            case 'nin':
            case 'in':
                if ($this->fieldIsString($field)) {
                    // $value = $this->->escapeTermOrPhrase($value);
                    $val = $this->escape($val, '.*', '.*');
                } else {
                    $val = $this->escapePhraseValue($val, 'AND');
                }
                return "$field:$wrap$val$end";

            // Starts with.
            case 'nsw':
            case 'sw':
                if ($this->fieldIsString($field)) {
                    $val = $this->escape($val, '', '.*');
                } else {
                    $val = $this->escapePhraseValue($val, 'AND');
                }
                return "$field:$wrap$val$end";

            // Ends with.
            case 'new':
            case 'ew':
                if ($this->fieldIsString($field)) {
                    $val = $this->escape($val, '.*', '');
                } else {
                    $val = $this->escapePhraseValue($val, 'AND');
                }
                return "$field:$wrap$val$end";

            // Matches.
            case 'nma':
            case 'ma':
                // Matches is already an regular expression, so just set
                // it. Note that Solr can manage only a small part of
                // regex and anchors are added by default.
                // TODO Add // or not?
                // TODO Escape regex for regexes…
                $val = $this->fieldIsString($field) ? $val : $this->escapePhraseValue($val, 'OR');
                return "$field:$wrap$val$end";

            // Greater/lower.
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
                /* @see https://www.unicode.org/reports/tr10/ */
                if (count($val) > 1) {
                    if (extension_loaded('intl')) {
                        $col = new \Collator('root');
                        $col->sort($val);
                    } else {
                        natcasesort($val);
                    }
                }
                // TODO Manage uri and resources with lt, lte, gte, gt (it has a meaning at least for resource ids, but separate).
                if ($type === 'lt') {
                    $val = reset($val);
                    $val = $this->escapePhrase(--$val);
                    return "$field:[* TO $val]";
                } elseif ($type === 'lte') {
                    $val = reset($val);
                    $val = $this->escapePhrase($val);
                    return "$field:[* TO $val]";
                } elseif ($type === 'gte') {
                    $val = array_pop($val);
                    $val = $this->escapePhrase($val);
                    return "$field:[$val TO *]";
                } elseif ($type === 'gt') {
                    $val = array_pop($val);
                    $val = $this->escapePhrase(++$val);
                    return "$field:[$val TO *]";
                }
                break;

            case '<':
            case '≤':
            case '≥':
            case '>':
                // The values are already cleaned.
                $first = reset($val);
                if (count($val) > 1) {
                    if (is_int($first)) {
                        $val = ($type === '<' || $type === '≤') ? min($val) : max($val);
                    } else {
                        extension_loaded('intl') ? (new \Collator('root'))->sort($val, \Collator::SORT_NUMERIC) : sort($val);
                        $val = ($type === '<' || $type === '≤') ? reset($val) : array_pop($val);
                    }
                } else {
                    $val = $first;
                }
                $val = ($type === '<' || $type === '>') ? (($type === '<') ? --$val : ++$val) : $val;
                return ($type === '<' || $type === '≤') ? "$field:[* TO $val]" : "$field:[$val TO *]";

            case 'nyreq':
            case 'yreq':
                // The casting to integer is the simplest way to get the year:
                // it avoids multiple substring_index, replace, etc. and it
                // works fine in most of the real cases, except when the date
                // does not look like a standard date, but normally it is
                // checked earlier.
                // Values are already casted to int.
                $val = $this->escapePhraseValue($val, 'OR');
                return "$field:$wrap$val$end";
            case 'yrlt':
            case 'yrlte':
                $val = min($val);
                $val = ($type === 'yrlt') ? --$val : $val;
                return "$field:[* TO $val]";
            case 'yrgte':
            case 'yrgt':
                $val = max($val);
                $val = ($type === 'yrgt') ? ++$val : $val;
                return "$field:[$val TO *]";

            // Resource with id.
            case 'nres':
            case 'res':
                // Like equal, but the field must be an integer.
                if ($this->fieldIsInteger($field)) {
                    $fqValues = is_array($val) ? array_map('intval', $val) : [(int) $val];
                    $fqValues = implode(' OR ', $fqValues);
                    return "$field:$wrap($fqValues)$end";
                }
                break;

            // Exists (has a value).
            case 'nex':
                $val = $this->escapePhraseValue($val, 'OR');
                return "(-$field:$val)";
            case 'ex':
                $val = $this->escapePhraseValue($val, 'OR');
                return "(+$field:$val)";

            default:
                return '';
        }

        return '';
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    protected function removeDiacritics(string $text): string
    {
        if (extension_loaded('intl')) {
            return \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;')->transliterate($text);
        }
        if (extension_loaded('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            return $converted !== false ? $converted : $text;
        }
        // No local transliteration: rely on solr.
        return $text;
    }

    protected function normalizeDate(string $date): string
    {
        if (strlen($date) < 20) {
            $date = substr_replace('0000-01-01T00:00:00Z', $date, 0, strlen($date) - 20);
        }
        try {
            return (new \DateTime($date))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            return '*';
        }
    }

    protected function getFullTextFieldsForSearchInRecord(): array
    {
        if ($this->query->getRecordOrFullText() !== 'record') {
            return [];
        }
        $alias = $this->query->getAlias('full_text');
        return $alias['fields'] ?? [];
    }

    /**
     * Get default managed-schema dynamic text fields (so _txt and _t).
     *
     * The default text files usually include ASCII folding.
     */
    protected function fieldsFoldable(): array
    {
        $text = $this->usedSolrFields([], ['_txt', '_t'], []);
        $lower = $this->usedSolrFields([], ['_s_lower', '_ss_lower'], []);
        return array_values(array_unique(array_merge($text, $lower)));
    }

    /**
     * Convert a field argument into one or more indexes.
     *
     * The indexes are the properties in internal sql.
     * This method allows to support same indexes in Solr, in particular for
     * automatic and manual links, when the index is unknown.
     * Any property can be used, but the index should exist.
     * The default index used is "_link_ss", then "_ss", "_ss_lower". and
     * "_link" and "sm_" (drupal). Don't forget to index linked resource ids
     * when needed.
     *
     * The index "link" is useful for llnks that allow to rebound between pages:
     * it contains the uri or the id for exact search, but it can be displayed
     * with another index ("_ss") in facets and filters.
     *
     * @todo For now, only one field is supported, since an index with multiple properties can be created.
     * @todo Store the right order of indexes to avoid to repeat the sort when the list of index is stored.
     * @todo Check if the aliases can be used for the bounce links.
     *
     * @return array|string|null
     */
    protected function fieldToIndex(string $field): ?string
    {
        $result = $this->query->getAliases()[$field]['fields'] ?? null;

        // Allow to use property terms and dynamic fields. Note: they should be indexed.

        if ($result) {
            return is_array($result) ? reset($result) : $result;
        }

        // Handle special selection fields.
        if ($field === 'selection_id' || $field === 'selection_public_id') {
            return $this->getSelectionIdFieldName($field);
        }

        $term = $this->easyMeta->propertyTerm($field);
        if (!$term) {
            return null;
        }

        $indices = $this->usedSolrFields([], [], [strtr($term, ':', '_')]);
        if (!$indices) {
            return null;
        }

        return $this->selectBestIndex($indices);
    }

    /**
     * Convert a field argument into one or more numeric indexes.
     *
     * The indexes are the properties in internal sql.
     * This method allows to support same indexes in Solr, in particular for
     * automatic and manual links, when the index is unknown.
     * Any property can be used, but the index should exist.
     * The default index used is "_link_is", then "_is", and "_link" and "si_"
     * (drupal). Don't forget to index linked resource ids when needed.
     *
     * The index "link" is useful for llnks that allow to rebound between pages:
     * it contains the uri or the id for exact search, but it can be displayed
     * with another index ("_is") in facets and filters.
     *
     * @todo For now, only one field is supported, since an index with multiple properties can be created.
     * @todo Store the right order of indexes to avoid to repeat the sort when the list of index is stored.
     * @todo Check if the aliases can be used for the bounce links.
     *
     * @return array|string|null
     */
    protected function fieldToIndexNumeric(string $field): ?string
    {
        $result = $this->query->getAliases()[$field]['fields'] ?? null;
        if ($result) {
            return is_array($result)
                ? reset($result)
                : $result;
        }

        // Handle special selection fields.
        if ($field === 'selection_id' || $field === 'selection_public_id') {
            return $this->getSelectionIdFieldName($field);
        }

        // Try to convert terms into standard field.
        $term = $this->easyMeta->propertyTerm($field);
        if (!$term) {
            return null;
        }

        // Check if a standard index exists.
        $indices = $this->usedSolrFields([], [], [strtr($term, ':', '_')]);
        if (!$indices) {
            return null;
        }

        return $this->selectBestIndexNumeric($indices);
    }

    /**
     * Get the field use for selection.
     *
     * @todo Make the search of the field name more generic than just selection. For example for resource_type/resource_name, etc. Default aliases in fact.
     * @todo Clarify this method and this complex process.
     */
    protected function getSelectionIdFieldName(?string $fieldName = null): ?string
    {
        $mapping = [
            'selection_id',
            'selection_public_id',
        ];

        if ($fieldName) {
            $mapping = array_intersect($mapping, [$fieldName]);
        }

        // TODO Implement the o:selection/o:id in extractor.
        foreach ($mapping as $field) {
            $maps = $this->getSolrCore()->mapsBySource($field);
            if ($maps) {
                $map = reset($maps);
                if ($map) {
                    return $map->fieldName();
                }
            }
        }

        // Fallback.
        $checks = $this->usedSolrFields([], ['_is', '_i'], $mapping);
        foreach ($checks as $check) {
            if ($this->fieldIsInteger($check)) {
                return $check;
            }
        }

        // Second fallback: use of selection_public_id to selection_public_is.
        $mapping = [
            'selection_id',
            'selection_public_id',
            'selection',
            'selection_public',
        ];
        $checks = $this->usedSolrFields([], ['_is', '_i'], $mapping);
        foreach ($checks as $check) {
            if ($this->fieldIsInteger($check)) {
                return $check;
            }
        }

        return null;
    }

    protected function selectBestIndex(array $indices): string
    {
        usort($indices, function ($a, $b) {
            $pa = $this->getFieldPriority($a);
            $pb = $this->getFieldPriority($b);
            return $pa <=> $pb ?: strcmp($a, $b);
        });
        return reset($indices);
    }

    protected function selectBestIndexNumeric(array $indices): string
    {
        usort($indices, function ($a, $b) {
            $pa = $this->getFieldPriorityNumeric($a);
            $pb = $this->getFieldPriorityNumeric($b);
            return $pa <=> $pb ?: strcmp($a, $b);
        });
        return reset($indices);
    }

    protected function getFieldPriority(string $field): int
    {
        if (str_ends_with($field, '_link_ss')) {
            return 0;
        }
        if (str_ends_with($field, '_ss')) {
            return 1;
        }
        if (str_ends_with($field, '_ss_lower')) {
            return 2;
        }
        if (str_ends_with($field, '_link')) {
            return 3;
        }
        if (str_starts_with($field, 'sm_')) {
            return 4;
        }
        return 5;
    }

    protected function getFieldPriorityNumeric(string $field): int
    {
        if (str_ends_with($field, '_link_is')) {
            return 0;
        }
        if (str_ends_with($field, '_is')) {
            return 1;
        }
        if (str_ends_with($field, '_link')) {
            return 2;
        }
        if (str_starts_with($field, 'si_')) {
            return 3;
        }
        return 4;
    }

    protected function solrCoreField(string $source): ?string
    {
        $maps = $this->solrCore->mapsBySource($source, 'generic');
        return $maps
            ? (reset($maps))->fieldName()
            : null;
    }

    /**
     * @todo Replace by a single regex?
     */
    protected function usedSolrFields(array $prefixes, array $suffixes, array $contains): array
    {
        $api = $this->services->get('Omeka\ApiManager');
        $fields = $api->search('solr_maps', [
            'solr_core_id' => $this->solrCore->id(),
        ], ['returnScalar' => 'fieldName'])->getContent();

        return array_filter($fields, function ($v) use ($prefixes, $suffixes, $contains) {
            foreach ($prefixes as $p) {
                if (strncmp($v, $p, strlen($p)) === 0) {
                    return true;
                }
            }
            foreach ($suffixes as $s) {
                if (substr($v, -strlen($s)) === $s) {
                    return true;
                }
            }
            foreach ($contains as $c) {
                if (strpos($v, $c) !== false) {
                    return true;
                }
            }
            return false;
        });
    }

    protected function fieldIsBool(string $name): bool
    {
        /** @var \SearchSolr\Schema\Field $field */
        $field = $this->getSolrCore()->schema()->getField($name);
        return $field ? $field->isBoolean() : false;
    }

    protected function fieldIsDate(string $name): bool
    {
        $field = $this->getSolrCore()->schema()->getField($name);
        return $field ? $field->isDate() : false;
    }

    protected function fieldIsFloat(string $name): bool
    {
        $field = $this->getSolrCore()->schema()->getField($name);
        return $field ? $field->isFloat() : false;
    }

    protected function fieldIsInteger(string $name): bool
    {
        $field = $this->getSolrCore()->schema()->getField($name);
        return $field ? $field->isInteger() : false;
    }

    protected function fieldIsLowercase(string $name): bool
    {
        $field = $this->getSolrCore()->schema()->getField($name);
        return $field ? $field->isLowercase() : false;
    }

    protected function fieldIsNumeric(string $name): bool
    {
        $field = $this->getSolrCore()->schema()->getField($name);
        return $field ? $field->isNumeric() : false;
    }

    protected function fieldIsString(string $name): bool
    {
        $field = $this->getSolrCore()->schema()->getField($name);
        return $field ? $field->isString() : false;
    }

    protected function fieldIsTokenized(string $name): bool
    {
        $field = $this->getSolrCore()->schema()->getField($name);
        return $field ? $field->isTokenized() : false;
    }

    /**
     * Escape one or multiple value.
     *
     * Previous versions managed diacritics, case and joker "*" and "?", but it
     * is no more needed: Solr edismax parser should manage everything now via
     * ICU and ASCIIFolding.
     *
     * For the list of characters to escape:
     * @see https://solr.apache.org/guide/solr/latest/query-guide/standard-query-parser.html#escaping-special-characters
     */
    protected function escape($val, string $pre = '', string $post = ''): string
    {
        $vals = is_array($val) ? $val : [$val];
        $vals = array_filter(array_map('strval', $vals), 'strlen');
        if (!$vals) {
            return '';
        }
        $escaped = array_map(fn ($v) => $this->escapePhrase($pre . $v . $post), $vals);
        return implode(' OR ', $escaped);
    }

    /**
     * Escape a string to query keeping meaning of solr special characters.
     *
     * @see https://solr.apache.org/guide/solr/latest/query-guide/standard-query-parser.html#escaping-special-characters
     * @see https://lucene.apache.org/core/10_1_0/queryparser/org/apache/lucene/queryparser/classic/package-summary.html#Escaping_Special_Characters
     * @uses \Solarium\Core\Query\Helper::escapeTerm()
     * @uses \Solarium\Core\Query\Helper::escapePhrase()
     */
    protected function escapeTermOrPhrase(string $string): string
    {
        $string = trim($string);

        // substr_count() is unicode-safe.
        $countQuotes = substr_count($string, '"');

        // TODO Manage the escaping of query with an odd number of quotes. Check for escaped quote \".
        if ($countQuotes < 2 || ($countQuotes % 2) === 1) {
            return $this->select->getHelper()->escapeTerm($string);
        }

        $output = [];
        $startWithQuote = (int) (mb_substr($string, 0, 1) === '"');
        foreach (explode('"', $string) as $key => $part) {
            $part = trim($part);
            if ($part !== '') {
                $output[] = $key % 2 === $startWithQuote
                    ? $this->select->getHelper()->escapePhrase($part)
                    : $this->select->getHelper()->escapeTerm($part);
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
    protected function escapeTerm(string $s): string
    {
        return $this->select->getHelper()->escapeTerm($s);
    }

    /**
     * Escape a string to query, so just enclose it with a double quote.
     *
     * The double quote and "\" are escaped too.
     *
     * @see https://solr.apache.org/guide/solr/latest/query-guide/standard-query-parser.html#escaping-special-characters
     * @uses \Solarium\Core\Query\Helper::escapePhrase()
     */
    protected function escapePhrase(string $s): string
    {
        return $this->select->getHelper()->escapePhrase($s);
    }

    /**
     * Enclose a value or a list of values (OR/AND) to protect a query for Solr.
     */
    protected function escapePhraseValue($val, string $joiner = 'OR'): string
    {
        if (!is_array($val)) {
            return $this->escapePhrase((string) $val);
        }
        if (empty($val)) {
            return '';
        }
        if (count($val) === 1) {
            return $this->escapePhrase((string) reset($val));
        }
        return '(' . implode(" $joiner ", array_unique(array_map([$this, 'escapePhrase'], $val))) . ')';
    }

    /**
     * Append core aliases to search Query.
     *
     * The configured search alias of the page are not overridden.
     * When the same alias is used multiple times, the more specific is used,
     * so: specific resource > resource > generic.
     */
    protected function appendCoreAliasesToQuery(): self
    {
        // Search config aliases have priority.
        $aliases = $this->query->getAliases();

        // TODO Check !isset($aliases[$alias]) like before?

        // Get all aliases, then sort them like in fieldToIndex() and more
        // specific resource, then take the first one.
        $aliasFields = [];

        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation $map */
        // The same for specific resources in maps, so reverse maps.
        foreach (array_reverse($this->getSolrCore()->mapsOrderedByStructure()) as $map) {
            $alias = $map->alias();
            if ($alias) {
                $aliasFields[$alias][$map->fieldName()] = $map;
            }
        }

        foreach ($aliasFields as $alias => $maps) {
            if (count($maps) > 1) {
                // The fields are alredy sorted by specific/resource/generic.
                // Try to use full multiple strings, not the tokenized ones.
                // TODO Ideally, the sort should take the specificity fully.
                // See fieldToIndex().
                $fields = array_keys($maps);
                usort($fields, function ($a, $b) {
                    $pa = $this->getFieldPriority($a);
                    $pb = $this->getFieldPriority($b);
                    return $pa <=> $pb ?: strcmp($a, $b);
                });
                $map = $maps[reset($fields)];
            } else {
                $map = reset($maps);
            }

            $aliases[$alias] = [
                'name' => $alias,
                'label' => $map->setting('label') ?: $alias,
                'fields' => [$map->fieldName()],
            ];
        }

        $this->query->setAliases($aliases);
        return $this;
    }

    protected function getSolrCore(): SolrCoreRepresentation
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
