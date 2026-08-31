<?php declare(strict_types=1);

namespace SearchSolr\Querier;

use AdvancedSearch\Querier\AbstractQuerier;
use AdvancedSearch\Querier\Exception\QuerierException;
use AdvancedSearch\Query;
use AdvancedSearch\Response;
use AdvancedSearch\Stdlib\SearchResources;
use SearchSolr\Stdlib\SolrCore as SolrCoreRepresentation;
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
    /**
     * Max number of buckets of a range facet without an explicit step.
     */
    const FACET_RANGE_MAX_BUCKETS = 1000;

    protected Response $response;
    protected int $appendToKey = 0;
    protected bool $byResourceType = false;
    protected array $resourceTypes = [];
    protected array $responseData = [];
    protected ?SelectQuery $select = null;
    protected SolariumClient $solariumClient;
    protected SolrCoreRepresentation $solrCore;

    /**
     * Cache for Solr field names to avoid repeated API queries.
     */
    protected ?array $solrFieldNamesCache = null;

    /**
     * Cache for foldable fields.
     */
    protected ?array $fieldsFoldableCache = null;

    /**
     * Cache for field type checks to avoid repeated schema lookups.
     */
    protected array $fieldTypeCache = [];

    /**
     * Cache for Transliterator instance.
     */
    protected static ?\Transliterator $transliterator = null;

    /**
     * Cache for Collator instance.
     */
    protected static ?\Collator $collator = null;

    /**
     * Cache for solrCoreField() lookups.
     */
    protected array $solrCoreFieldCache = [];

    /**
     * Flag to track if aliases have been appended.
     */
    protected bool $aliasesAppended = false;

    public function setQuery(Query $query): self
    {
        parent::setQuery($query);
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
        if ($this->query) {
            $this->response->setQuery($this->query);
        }

        $suggestOptions = $this->query ? $this->query->getSuggestOptions() : [];

        // Build suggester names from settings.
        $suggesterNames = $this->getSuggesterNames($suggestOptions);
        if (empty($suggesterNames)) {
            return $this->response->setMessage('Solr suggester not configured.'); // @translate
        }

        $q = $this->query ? $this->query->getQuery() : '';
        if ($q === '') {
            return $this->response
                ->setSuggestions([])
                ->setIsSuccess(true);
        }

        // Max length for suggestions (in characters).
        $maxLength = (int) ($suggestOptions['length'] ?? 20);

        try {
            $client = $this->getClient();
            $suggesterQuery = $client->createSuggester();
            $suggesterQuery->setQuery($q);
            // setDictionary accepts a string or an array of dictionary names.
            // Solr handles merging results from multiple suggesters.
            $suggesterQuery->setDictionary($suggesterNames);
            $suggesterQuery->setCount($this->query ? $this->query->getLimit() : 10);

            $result = $client->suggester($suggesterQuery);

            $limit = $this->query ? $this->query->getLimit() : 10;
            // Resolve the locale used to strip elided articles from suggested
            // surface forms. Solr's AnalyzingInfixLookupFactory returns the raw
            // stored text, so the dedup and the displayed value are normalized
            // here: "l'écomusée", "l’écomusée" and "écomusée" collapse to one.
            $elisionLocale = $suggestOptions['elision_locale']
                ?? $this->resolveTranslatorLocale();
            $seen = [];
            $suggestions = [];
            foreach ($result as $dictionary) {
                foreach ($dictionary as $term) {
                    foreach ($term->getSuggestions() as $suggestion) {
                        $value = trim(strip_tags($suggestion['term']));
                        if ($value === '') {
                            continue;
                        }
                        $value = $this->stripElidedArticles($value, $elisionLocale);
                        // Truncate long suggestions at word boundary.
                        if ($maxLength && mb_strlen($value) > $maxLength) {
                            $value = mb_substr($value, 0, $maxLength);
                            $lastSpace = mb_strrpos($value, ' ');
                            if ($lastSpace) {
                                $value = mb_substr($value, 0, $lastSpace);
                            }
                            $value = rtrim($value, ' ,;:.-');
                        }
                        if ($value === '') {
                            continue;
                        }
                        $key = mb_strtolower($value);
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $suggestions[] = [
                            'value' => $value,
                            'data' => $suggestion['weight'] ?? 1,
                        ];
                    }
                }
            }

            // Sort by weight descending, keep top results.
            usort($suggestions, fn ($a, $b) => $b['data'] <=> $a['data']);
            $suggestions = array_slice($suggestions, 0, $limit);

            return $this->response
                ->setSuggestions($suggestions)
                ->setIsSuccess(true);
        } catch (\Throwable $e) {
            $this->getLogger()->err('Solr suggester error: ' . $e->getMessage());
            return $this->response->setMessage('Solr suggester error: ' . $e->getMessage());
        }
    }

    /**
     * Per-language elided articles (aligned on Solr's lang/contractions_*.txt).
     * Keys are 2-letter ISO 639-1 codes. Articles are matched
     * case-insensitively and accept both ASCII (U+0027) and curly (U+2019)
     * apostrophes.
     */
    protected const ELISION_ARTICLES = [
        'fr' => ['l', 'd', 'n', 'qu', 'j', 'm', 't', 's', 'c', 'jusqu', 'lorsqu', 'puisqu', 'quoiqu'],
        'it' => ['c', 'l', 'all', 'dall', 'dell', 'nell', 'sull', 'coll', 'pell', 'gl', 'agl', 'dagl', 'degl', 'negl', 'sugl', 'un', 'm', 't', 's', 'v', 'd', 'st', 'n', 'd'],
        'ca' => ['d', 'l', 'm', 'n', 's', 't'],
        'ga' => ['d', 'm', 'b'],
    ];

    /**
     * Strip a leading elided article from a value, based on locale. Matches the
     * Solr ElisionFilter but applied to the displayed surface form so that the
     * deduplication and the displayed suggestion are language-aware.
     */
    protected function stripElidedArticles(string $value, ?string $locale): string
    {
        if ($value === '' || $locale === null) {
            return $value;
        }
        $lang = strtolower(substr($locale, 0, 2));
        $articles = self::ELISION_ARTICLES[$lang] ?? null;
        if (!$articles) {
            return $value;
        }
        $pattern = '/^(' . implode('|', array_map('preg_quote', $articles)) . ')[\'\x{2019}]\s*/iu';
        $stripped = preg_replace($pattern, '', $value);
        return $stripped === null ? $value : ltrim($stripped);
    }

    /**
     * Resolve the locale used as fallback for elision stripping. Looks first at
     * the site locale (from the query site id) so that suggestions on a French
     * site strip French articles even when the global translator has not been
     * switched yet to the site locale.
     */
    protected function resolveTranslatorLocale(): ?string
    {
        try {
            $siteId = $this->query ? $this->query->getSiteId() : null;
            if ($siteId) {
                $siteSettings = $this->services->get('Omeka\Settings\Site');
                $siteSettings->setTargetId($siteId);
                $locale = $siteSettings->get('locale');
                if (is_string($locale) && $locale !== '') {
                    return $locale;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to translator locale.
        }
        try {
            $translator = $this->services->get('MvcTranslator');
            $locale = method_exists($translator, 'getLocale') ? $translator->getLocale() : null;
            return is_string($locale) && $locale !== '' ? $locale : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build suggester names from settings.
     *
     * @return array|string Suggester name(s) to query.
     */
    protected function getSuggesterNames(array $suggestOptions)
    {
        // Support both old single field (solr_field) and new multi-field
        // (solr_fields).
        $solrFields = $suggestOptions['solr_fields'] ?? [];
        if (empty($solrFields) && !empty($suggestOptions['solr_field'])) {
            $solrFields = [$suggestOptions['solr_field']];
        }

        // When a catchall is explicitly selected, a single suggester on it is
        // enough. Kept consistent with CreateSolrSuggesters.
        $solrFields = \SearchSolr\Stdlib\SuggesterFields::reduceToCatchall($solrFields);

        // Resolve "auto": stored text and string fields, preferring _txt.
        if (empty($solrFields) || in_array('auto', $solrFields)) {
            $allowedSuffixes = ['_txt', '_ss', '_s'];
            $solrCore = $this->getSolrCore();
            $txtPrefixes = [];
            $candidates = [];
            foreach ($solrCore->mapsOrderedByStructure() as $map) {
                $fieldName = $map->fieldName();
                foreach ($allowedSuffixes as $suffix) {
                    if (substr($fieldName, -strlen($suffix)) === $suffix) {
                        $prefix = substr($fieldName, 0, -strlen($suffix));
                        $candidates[] = ['name' => $fieldName, 'suffix' => $suffix, 'prefix' => $prefix];
                        if ($suffix === '_txt') {
                            $txtPrefixes[$prefix] = true;
                        }
                        break;
                    }
                }
            }
            $solrFields = [];
            foreach ($candidates as $c) {
                if ($c['suffix'] !== '_txt' && isset($txtPrefixes[$c['prefix']])) {
                    continue;
                }
                $solrFields[] = $c['name'];
            }
        }

        if (empty($solrFields)) {
            // Fallback to direct suggester name if provided.
            return $suggestOptions['solr_suggester_name'] ?? null;
        }

        // Generate base suggester name.
        $baseSuggesterName = $suggestOptions['solr_suggester_name'] ?? 'omeka_suggester';

        // For single field, use base name; for multiple, append field names.
        if (count($solrFields) === 1) {
            return $baseSuggesterName;
        }

        $suggesterNames = [];
        foreach ($solrFields as $field) {
            $suggesterNames[] = $baseSuggesterName . '_' . preg_replace('/[^a-z0-9_]/i', '_', $field);
        }

        return $suggesterNames;
    }

    /**
     * Get indexed Solr documents.
     *
     * Resource types are required to differentiate resources.
     *
     * @todo Merge queryDocuments() of SolariumQuerier with SolrRepresentation.
     *
     * Adapted:
     * @see \SearchSolr\Stdlib\SolrCore::queryDocuments()
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
     * @see \SearchSolr\Stdlib\SolrCore::queryValues()
     * @see \SearchSolr\Querier\SolariumQuerier::queryValues()
     *
     * {@inheritDoc}
     * @see \AdvancedSearch\Querier\AbstractQuerier::queryValues()
     */
    public function queryValues(
        string $field,
        ?string $prefix = null,
        int $limit = 0
    ): array {
        if (!$field) {
            return [];
        }

        try {
            // Init solr client.
            $this->getClient();

            // Check if the field is a special or a multifield.
            $aliases = $this->query->getAliases();
            $fields = $aliases[$field]['fields'] ?? [$field];
            $fields = is_array($fields) ? $fields : [$fields];

            // For full values (autocomplete), use _ss fields
            // instead of _txt: Solr terms and facets on _txt
            // return individual tokens, not complete values.
            $schema = $this->solrCore->schema();
            $fields = array_map(function ($f) use ($schema) {
                if (str_ends_with($f, '_txt')
                    || str_ends_with($f, '_t')
                ) {
                    $base = preg_replace(
                        '/(_txt|_t)$/', '', $f
                    );
                    $ssField = $base . '_ss';
                    if ($schema->getField($ssField)) {
                        return $ssField;
                    }
                }
                return $f;
            }, $fields);

            $isPublicField = $this->solrCoreField('is_public');
            $sitesField = $this->solrCoreField('site/o:id');

            // Use facets when prefix is set (case-insensitive)
            // or when filtering by public/site is needed.
            // Terms don't support case-insensitive prefix.
            if ($prefix !== null
                || ($this->query->getIsPublic() && $isPublicField)
                || ($this->query->getSiteId() && $sitesField)
                || $this->fieldIsNumeric(reset($fields))
            ) {
                $result = $this->queryValuesWithFacets(
                    $fields, $isPublicField, $sitesField,
                    $prefix, $limit
                );
            } else {
                $result = $this->queryValuesWithTerms(
                    $fields, null, $limit
                );
            }

            $list = array_merge(...array_values($result));
            natcasesort($list);
            $list = array_keys(
                array_flip(array_filter($list, 'strlen'))
            );
            return array_combine($list, $list);
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function queryValuesWithFacets(
        array $fields,
        ?string $isPublicField,
        ?string $sitesField,
        ?string $prefix = null,
        int $limit = 0
    ): array {
        // Solr uses -1 for unlimited.
        $limit = $limit ?: -1;
        $query = $this->solariumClient->createSelect();

        if ($this->query->getIsPublic() && $isPublicField) {
            $val = $this->fieldIsBool($isPublicField)
                ? 'true' : 1;
            $query->createFilterQuery('pub')
                ->setQuery("$isPublicField:$val");
        }

        if ($siteId = $this->query->getSiteId()) {
            $query->createFilterQuery('site')
                ->setQuery("$sitesField:$siteId");
        }

        $facetSet = $query->getFacetSet();
        foreach ($fields as $i => $field) {
            $facet = $facetSet->createFacetField("f$i")
                ->setField($field)
                ->setSort('index')
                ->setLimit($limit)
                ->setMinCount(1);
            if ($prefix !== null && $prefix !== '') {
                $facet
                    ->setContains($prefix)
                    ->setContainsIgnoreCase(true);
            }
        }

        $resultSet = $this->solariumClient->select($query);
        $result = [];
        foreach ($resultSet->getFacetSet()->getFacets() as $facet) {
            $result[] = array_keys($facet->getValues());
        }
        return $result;
    }

    protected function queryValuesWithTerms(
        array $fields,
        ?string $prefix = null,
        int $limit = 0
    ): array {
        // Solr uses -1 for unlimited.
        $limit = $limit ?: -1;
        $query = $this->solariumClient->createTerms();
        $query->setFields($fields)
            ->setSort('index')
            ->setLimit($limit)
            ->setMinCount(1);
        if ($prefix !== null && $prefix !== '') {
            $query->setPrefix($prefix);
        }

        $resultSet = $this->solariumClient->terms($query);
        return array_map(
            fn ($v) => array_keys($v),
            $resultSet->getResults()
        );
    }

    /**
     * Warning: unlike queryValues, the field isn't an alias but a real index.
     *
     * Currently only used in admin, so no check for public or site.
     *
     * @todo Merge queryValuesCount() of SolariumQuerier with SolrRepresentation.
     *
     * Adapted:
     * @see \SearchSolr\Stdlib\SolrCore::queryValuesCount()
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

    /**
     * List the fields a visitor may target in the query itself.
     *
     * @see https://solr.apache.org/guide/solr/latest/query-guide/edismax-query-parser.html
     *
     * @return string The value of the param "uf": the fields, else "-*".
     */
    protected function userFields(): string
    {
        $fields = [];

        // The fields of the maps of the core: they are the indexed ones.
        try {
            foreach ($this->getSolrCore()->maps() as $solrMap) {
                $fieldName = $solrMap->fieldName();
                if ($fieldName) {
                    $fields[$fieldName] = true;
                }
            }
        } catch (\Throwable $e) {
            // Without the maps, no field is allowed.
        }

        // The aliases of the search config point to these fields, but they may
        // be used by their own name too.
        foreach (array_keys($this->query->getAliases() ?: []) as $alias) {
            if (is_string($alias) && $alias !== '') {
                $fields[$alias] = true;
            }
        }

        return $fields
            ? implode(' ', array_keys($fields))
            : '-*';
    }

    /**
     * Get the min and the max of some fields, without listing their values.
     *
     * The component "stats" of Solr computes them in a single pass, unlike a
     * terms query, that returns every distinct value only to keep two of them:
     * a field with a high cardinality could exhaust the java heap.
     *
     * @return array Min and max by field, when the field has values.
     */
    public function queryFieldsMinMax(array $fields): array
    {
        if (!$fields) {
            return [];
        }

        $this->getClient();
        $this->appendCoreAliasesToQuery();

        $query = $this->solariumClient->createSelect();
        $query
            ->setQuery('*:*')
            ->setRows(0)
            ->setFields(['id']);

        $stats = $query->getStats();
        foreach ($fields as $field) {
            $stats->createField($field);
        }

        try {
            $resultSet = $this->solariumClient->select($query);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->err(
                    'Solr query failed to get the bounds of the fields {fields}: {message}', // @translate
                    ['fields' => implode(', ', $fields), 'message' => $e->getMessage()]
                );
            }
            return [];
        }

        $statsResult = $resultSet->getStats();
        if (!$statsResult) {
            return [];
        }

        $result = [];
        foreach ($fields as $field) {
            $fieldResult = $statsResult->getResult($field);
            if (!$fieldResult) {
                continue;
            }
            $min = $fieldResult->getMin();
            $max = $fieldResult->getMax();
            // A field without any value has no bounds, or null ones.
            if ($min === null || $max === null) {
                continue;
            }
            $result[$field] = ['min' => $min, 'max' => $max];
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
            // Clone and fetch all ids without pagination limits and with
            // grouping preserved.
            $allQuery = clone $this->select;
            $allQuery
                ->setFields(['id'])
                ->setStart(0);

            if ($allQuery->getGrouping()->getFields()) {
                // Solr default group.limit is 1, so set it explicitly to get
                // all documents per group.
                $allQuery
                    ->setRows(100)
                    ->getGrouping()
                    ->setLimit(1000000)
                    ->setOffset(0);
            } else {
                $allQuery->setRows(1000000);
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
            $this->getLogger()->err(
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
     *
     * The number of query fields is limited to avoid exceeding Solr maxClauseCount
     * (default 1024). With many fields, each search term creates a clause per
     * field, and each clause is expanded by the analyzer (lowercasing, ascii
     * folding, synonyms…), quickly hitting the limit.
     *
     * If the catchall field `_text_` exists, use it instead of listing all
     * fields individually.
     */
    protected function configureEDisMax(): self
    {
        if (!$this->select) {
            return $this;
        }

        // Solarium asks "*,score" by default, so Solr returns every stored
        // field, including the full text of the ocr: a page of results could
        // weigh hundreds of megabytes, exhausting the heap of Solr and the
        // memory of php, while only the id is read from the documents.
        /** @see \SearchSolr\Querier\SolariumQuerier::hydrateResponse() */
        $this->select->setFields(['id', 'score']);

        $this->select
            ->addParam('defType', 'edismax')
            ->addParam('sow', 'false')
            // Restrict the fields a visitor may target with the syntax
            // "field:value" in the query: by default, edismax allows any field
            // of the index, including fields that store data indexed whatever
            // the visibility. Only the mapped fields and the aliases are
            // allowed, and none when there is no map.
            ->addParam('uf', $this->userFields());

        $dismax = $this->select->getDisMax();

        // Use catchall field _text_ if available.
        // Add only fields with custom boosts (≠1) for scoring priority.
        if ($this->solrCore->schema()->checkDefaultField()) {
            $boostedFields = $this->getCustomBoostedFields();
            if ($boostedFields) {
                $dismax->setQueryFields('_text_ ' . implode(' ', $boostedFields));
            } else {
                $dismax->setQueryFields('_text_');
            }
            return $this;
        }

        $maxFields = $this->maxQueryFields();

        $existing = trim((string) $dismax->getQueryFields());

        if ($existing !== '') {
            // Limit existing query fields to avoid clause explosion.
            $allowed = array_flip($this->fieldsFoldable());
            $kept = array_filter(
                preg_split('/\s+/', $existing) ?: [],
                fn ($p) => isset($allowed[preg_replace('~\^.*$~', '', $p)])
            );
            if ($kept) {
                $kept = array_slice($kept, 0, $maxFields);
                $dismax->setQueryFields(implode(' ', $kept));
            }
            return $this;
        }

        // Fallback: use foldable fields with limit.
        $foldable = $this->fieldsFoldable();
        if ($foldable) {
            // Prioritize important fields (title, description, subject).
            $priority = [];
            $rest = [];
            foreach ($foldable as $field) {
                if (preg_match('/title|description|subject|creator/i', $field)) {
                    $priority[] = $field;
                } else {
                    $rest[] = $field;
                }
            }
            $foldable = array_merge($priority, $rest);
            $foldable = array_slice($foldable, 0, $maxFields);
            $dismax->setQueryFields(implode(' ', $foldable));
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

        $mainQuery = trim($this->query->getQuery());
        $refineQuery = trim($this->query->getQueryRefine());

        if ($mainQuery === '' && $refineQuery === '') {
            $this->select->setQuery('*:*');
            return $this;
        }

        if ($this->query->getOption('remove_diacritics', false)) {
            $mainQuery !== '' && $mainQuery = $this->removeDiacritics($mainQuery);
            $refineQuery !== '' && $refineQuery = $this->removeDiacritics($refineQuery);
        }

        $excludedFields = array_merge(
            $this->query->getExcludedFields(),
            $this->getFullTextFieldsForSearchInRecord()
        );

        // When there are excluded fields, we need to search only in allowed
        // fields. Use the same field selection as before but limit count to
        // stay under Solr maxClauseCount of 1024.
        if (($mainQuery !== '' || $refineQuery !== '') && $excludedFields) {
            $allFields = $this->usedSolrFields(
                ['t_', 'txt_', 'ss_', 'sm_', 'ws_'],
                ['_t', '_txt', '_ss', '_s', '_ss_lower', '_s_lower', '_ws'],
                []
            );
            $allowedFields = array_diff($allFields, $excludedFields);
            if ($allowedFields) {
                // Limit fields to avoid clause explosion. Use DisMax with
                // restricted qf for efficient multi-field search.
                $allowedFields = array_slice(
                    array_values($allowedFields),
                    0,
                    $this->maxQueryFields()
                );
                $dismax = $this->select->getDisMax();
                $dismax->setQueryFields(implode(' ', $allowedFields));
            }
        }

        // Use simple query with DisMax (configured in configureEDisMax).
        // Escape each query separately, then combine with "+" (required) prefix
        // for AND behavior between main and refine queries.
        $mainEscaped = $mainQuery !== '' ? $this->escapeTermOrPhrase($mainQuery) : '';
        $refineEscaped = $refineQuery !== '' ? $this->escapeTermOrPhrase($refineQuery) : '';

        if ($mainEscaped !== '' && $refineEscaped !== '') {
            $this->select->setQuery("+($mainEscaped) +($refineEscaped)");
        } elseif ($mainEscaped !== '') {
            $this->select->setQuery($mainEscaped);
        } else {
            $this->select->setQuery($refineEscaped);
        }

        // The query relevance settings are a facet of the query context, so
        // they are set by search page in its config.
        // TODO These options and other DisMax ones can be passed directly as options. Even the query is an option.
        $minimumMatch = $this->query->getMinimumMatch();
        $tieBreaker = $this->query->getTieBreaker();
        if ($minimumMatch !== '' || $tieBreaker !== '') {
            $dismax = $this->select->getDisMax();
            $minimumMatch === '' || $dismax->setMinimumMatch($minimumMatch);
            $tieBreaker === '' || $dismax->setTie((float) $tieBreaker);
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

        // Visibility (is_public + module Group).
        // In module Access, is_public and the access level are independent: a
        // public resource may still be access-restricted.
        if ($this->query->getIsPublic() && ($field = $this->solrCoreField('is_public'))) {
            $val = $this->fieldIsBool($field) ? 'true' : '1';
            $publicClause = "$field:$val";
            // Module Group: also show resources reserved to a group the current
            // user belongs to (so reserved private content is searchable and
            // faceted, like it is already browsable).
            $groupClause = $this->groupVisibilityClause();
            $this->select->addFilterQuery([
                'key' => 'is_public',
                'query' => $groupClause ? "($publicClause OR $groupClause)" : $publicClause,
            ]);
        }

        // Access level (module Access).
        // A public resource (is_public) may still be hidden from public lists
        // when its access level is "protected" or "forbidden" AND Access is
        // configured to hide them from listings (Doctrine filter "access_level"
        // enabled).
        if ($this->query->getIsPublic()
            && ($field = $this->solrCoreField('access_level'))
            && $this->isAccessLevelFilterEnabled()
        ) {
            $this->select->addFilterQuery([
                'key' => 'access_level',
                'query' => "-$field:(protected OR forbidden)",
            ]);
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
        if ($this->getSolrCore()->setting('index_name') && ($field = $this->solrCoreField('search_index'))) {
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

            // Resolve the configured field (property term or alias) to its Solr
            // index. Skip the facet when the field is not mapped, to avoid an
            // "undefined field" error from Solr.
            $field = $this->resolveFieldOrNull($data['field']);
            if ($field === null) {
                $this->getLogger()
                    ->warn(
                        'Solr: skipped facet on unmapped field "{field}".', // @translate
                        ['field' => $data['field']]
                    );
                continue;
            }
            $data['field'] = $field;

            // Handle range facets.
            if (in_array($data['type'] ?? '', ['Range', 'RangeDouble', 'SelectRange'])) {
                $min = $data['min'] ?? ($fieldRanges[$name]['min'] ?? 0);
                $max = $data['max'] ?? ($fieldRanges[$name]['max'] ?? 0);
                $step = (int) ($data['step'] ?? 1);

                // Without a step, the default one is 1, so a field with a wide
                // range, like a timestamp, would build one bucket by unit and
                // exhaust the memory of Solr. So the step is adapted to keep a
                // number of buckets that a facet can display.
                if (empty($data['step'])) {
                    $amplitude = (int) $max - (int) $min;
                    if ($amplitude > self::FACET_RANGE_MAX_BUCKETS) {
                        $step = (int) ceil($amplitude / self::FACET_RANGE_MAX_BUCKETS);
                        if ($this->logger) {
                            $this->logger->notice(
                                'Facet "{facet_name}": the range from {min} to {max} has no step, so the step is set to {step} to limit the number of buckets. Set it in the search page.', // @translate
                                ['facet_name' => $name, 'min' => $min, 'max' => $max, 'step' => $step]
                            );
                        }
                    }
                }

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
                // The domain option is used to exclude the tagged search filter
                // related to the facet.
                // see: https://yonik.com/multi-select-faceting/
                /** @var \Solarium\Component\Facet\FieldValueParametersInterface $facet */
                // A facet joined with "and" narrows the results, so its own
                // filter is kept in the domain of the counts: else a value
                // would promise results that the "and" cannot return.
                $isAnd = ($data['join'] ?? $data['options']['join'] ?? 'or') === 'and';
                $excludeTag = strtoupper($name . '-facet');
                $facet = $facetSet->createJsonFacetTerms($name)
                    ->setField($data['field'])
                    ->setSort($orders[$data['order'] ?? 'default'] ?? $orders['default']);
                if (!$isAnd) {
                    $facet->setOptions(['domain' => ['excludeTags' => [$excludeTag]]]);
                }

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
        $facetsConfig = $this->query->getFacets();
        foreach ($activeFacets as $fname => $values) {
            if (!is_array($values) || !count($values)) {
                continue;
            }

            // Resolve the active facet field; skip when it is not mapped.
            $facetData = $facetsConfig[$fname] ?? [];
            $startField = $this->resolveFieldOrNull($facetData['field'] ?? $fname);
            if ($startField === null) {
                continue;
            }
            $endField = !empty($facetData['field_end'])
                ? $this->resolveFieldOrNull($facetData['field_end'])
                : null;

            $firstKey = key($values);
            // Check for range facet.
            if (count($values) <= 2 && ($firstKey === 'from' || $firstKey === 'to')) {
                $hasFrom = isset($values['from']) && $values['from'] !== '';
                $hasTo = isset($values['to']) && $values['to'] !== '';

                if ($endField) {
                    // Interval overlap on uncertain dates: start ≤ to AND end ≥
                    // from.
                    $clauses = [];
                    if ($hasTo) {
                        $clauses[] = "$startField:[* TO " . $this->escapePhrase($values['to']) . ']';
                    }
                    if ($hasFrom) {
                        $clauses[] = "$endField:[" . $this->escapePhrase($values['from']) . ' TO *]';
                    }
                    if ($clauses) {
                        $this->select->addFilterQuery([
                            'key' => $fname . '-facet',
                            'query' => implode(' AND ', $clauses),
                            'tag' => 'exclude',
                        ]);
                    }
                } elseif ($hasFrom && $hasTo) {
                    $from = $this->escapePhrase($values['from']);
                    $to = $this->escapePhrase($values['to']);
                    $this->select->addFilterQuery([
                        'key' => $fname . '-facet',
                        'query' => "$startField:[$from TO $to]",
                        'tag' => 'exclude',
                    ]);
                } elseif ($hasFrom) {
                    $from = $this->escapePhrase($values['from']);
                    $this->select->addFilterQuery([
                        'key' => $fname . '-facet',
                        'query' => "$startField:[$from TO *]",
                        'tag' => 'exclude',
                    ]);
                } elseif ($hasTo) {
                    $to = $this->escapePhrase($values['to']);
                    $this->select->addFilterQuery([
                        'key' => $fname . '-facet',
                        'query' => "$startField:[* TO $to]",
                        'tag' => 'exclude',
                    ]);
                }
            } else {
                // Term facet - add tag for multi-select.
                // A tag should be added to the facet filter query to be able to
                // exclude it in the facet query 'tag' option is ignored when
                // using 'query', add the tag in the query statement.
                $key = $fname . '-facet';
                $tag = strtoupper($key);
                // With the joiner "and", a resource must match all the selected
                // values, so each new value narrows the results.
                $joiner = ($facetData['join'] ?? $facetData['options']['join'] ?? 'or') === 'and'
                    ? 'AND'
                    : 'OR';
                $escaped = $this->escapePhraseValue($values, $joiner);
                $this->select->addFilterQuery([
                    'key' => $key,
                    'query' => "{!tag=$tag}$startField:$escaped",
                ]);
            }
        }

        return $this;
    }

    protected function prepareRangeFacetBounds(array $facets): array
    {
        $fieldRanges = [];
        // Map facet name to Solr field name (start) and optional end field.
        $nameToField = [];
        $nameToFieldEnd = [];

        foreach ($facets as $name => $data) {
            if (in_array($data['type'] ?? '', ['Range', 'RangeDouble', 'SelectRange'])) {
                if (!isset($data['min']) || !isset($data['max'])) {
                    $field = !empty($data['field'])
                        ? $this->resolveFieldOrNull($data['field'])
                        : null;
                    if ($field) {
                        $fieldRanges[$name] = [];
                        $nameToField[$name] = $field;
                        if (!empty($data['field_end'])) {
                            $endField = $this->resolveFieldOrNull($data['field_end']);
                            if ($endField !== null) {
                                $nameToFieldEnd[$name] = $endField;
                            }
                        }
                    }
                }
            }
        }

        if ($fieldRanges && $nameToField) {
            // Use the actual Solr field names for the query.
            $solrFields = array_unique(array_merge(
                array_values($nameToField),
                array_values($nameToFieldEnd)
            ));
            $all = $this->queryFieldsMinMax($solrFields);

            // Map results back to facet names. For interval mode, min comes
            // from the start field and max from the end field.
            foreach ($fieldRanges as $name => &$range) {
                $field = $nameToField[$name] ?? null;
                $fieldEnd = $nameToFieldEnd[$name] ?? null;
                if ($field && isset($all[$field])) {
                    $range['min'] = $all[$field]['min'];
                    $range['max'] = $fieldEnd && isset($all[$fieldEnd])
                        ? $all[$fieldEnd]['max']
                        : $all[$field]['max'];
                } else {
                    $range['min'] = 0;
                    $range['max'] = 0;
                }
            }
            unset($range);
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
            $name = $this->resolveFieldOrNull($field);
            if ($name === null) {
                $this->getLogger()
                    ->warn(
                        'Solr: skipped sort on unmapped field "{field}".', // @translate
                        ['field' => $field]
                    );
            } else {
                $solariumOrder = strtolower($order) === 'desc' ? SelectQuery::SORT_DESC : SelectQuery::SORT_ASC;
                // The sort follows the collation when a folded variant of the
                // field exists (a plain string field sorts in byte order).
                $name = $this->preferFoldedField($name);
                $this->select->addSort($name, $solariumOrder);
                // Like the api: the resource id is always the tie-breaker, in
                // the same order (see AbstractEntityAdapter::search()).
                $idField = $this->fieldToIndex('id');
                if ($idField && $idField !== $name && $idField !== 'id') {
                    $this->select->addSort($idField, $solariumOrder);
                }
            }
        }

        return $this;
    }

    protected function applyPagination(): self
    {
        // Limit is per page and offset is page x limit.
        $limit = $this->query->getLimit();
        $offset = $this->query->getOffset();

        // With grouping (by resource type), pagination works differently:
        // - rows/start control groups (not documents)
        // - group.limit/group.offset control documents within each group
        if ($this->select->getGrouping()->getFields()) {
            // Return all groups (resource types), paginate within each.
            if ($limit !== null) {
                $this->select->getGrouping()->setLimit($limit);
            }
            if ($offset !== null) {
                $this->select->getGrouping()->setOffset($offset);
            }
        } else {
            // No grouping: standard pagination.
            if ($limit !== null) {
                $this->select->setRows($limit);
            }
            if ($offset !== null) {
                $this->select->setStart($offset);
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

    /**
     * Estimate max number of query fields (qf) to stay under Solr max clauses.
     *
     * The maxClauseCount is 1024.
     *
     * Each query term generates one clause per qf field, and the analyzer may
     * expand each clause (lowercase, ASCII folding, synonyms…). A conservative
     * expansion factor of 5 is used.
     */
    protected function maxQueryFields(): int
    {
        $q = trim($this->query->getQuery()
            . ' ' . $this->query->getQueryRefine());
        // Count whitespace-separated tokens as a rough term estimate.
        $terms = max(1, preg_match_all('/\S+/', $q));
        // expansion ≈ 5 (lowercase + folding + synonyms + variants).
        $max = (int) floor(1024 / ($terms * 5));
        // At least 10 fields, at most 100.
        return max(10, min(100, $max));
    }

    /**
     * Get fields with custom boosts (different from default 1).
     *
     * @return string[] Array of "field^boost" strings
     */
    protected function getCustomBoostedFields(): array
    {
        $coreBoosts = $this->solrCore->setting('field_boost') ?: [];
        $queryBoosts = $this->query->getFieldBoosts();
        $merged = array_merge($coreBoosts, $queryBoosts);

        $result = [];
        foreach ($merged as $field => $boost) {
            $boost = (float) $boost;
            if ($boost === 1.0 || $boost <= 0) {
                continue;
            }
            // A boost may be keyed by a property term ("dcterms:title"), so
            // resolve it to the Solr field, and skip it when it is not mapped:
            // Solr rejects the whole query on an unknown field in "qf".
            $index = $this->resolveFieldOrNull((string) $field);
            if ($index !== null) {
                $result[] = "$index^$boost";
            }
        }
        return $result;
    }

    protected function applyBoosts(): self
    {
        // The boost is only useful when there is a query.
        $q = (string) $this->select->getQuery();
        if (!$q || $q === '*:*') {
            return $this;
        }

        // Boosts from the index and from the query.
        $coreBoosts = $this->solrCore->setting('field_boost', []);
        $queryBoosts = $this->query->getFieldBoosts();
        $merged = array_merge($coreBoosts, $queryBoosts);

        if (!$merged) {
            return $this;
        }

        // Keep only fields with a real boost (≠ 1): boost=1 is the default and
        // just adds fields to qf without benefit, which can cause maxClauseCount
        // overflow with many fields.
        $boosted = [];
        foreach ($merged as $field => $boost) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $boost = (float) $boost;
            if ($boost <= 0 || $boost === 1.0) {
                continue;
            }
            // Resolve property terms to Solr fields and skip unmapped ones,
            // else Solr rejects the query ("is not a valid field name").
            $index = $this->resolveFieldOrNull($field);
            if ($index !== null) {
                $boosted[$index] = "$index^$boost";
            }
        }

        if (!$boosted) {
            return $this;
        }

        $dismax = $this->select->getDisMax();
        $existing = trim((string) $dismax->getQueryFields());

        // Append only the truly boosted fields to the existing qf.
        $dismax->setQueryFields(
            $existing !== ''
                ? $existing . ' ' . implode(' ', $boosted)
                : implode(' ', $boosted)
        );

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
                ->setRows(100)
                ->setStart(0);
            $allQuery->getGrouping()
                ->setLimit(1000000)
                ->setOffset(0);

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
        } catch (\Throwable $e) {
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

        // Aggregate only when not grouped by resource type and there are
        // multiple types.
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
        if (!$hidden) {
            return $this;
        }

        // Hidden filters may mix two shapes per field:
        // - flat values  : ['field' => [val1, val2, ...]]              → processFilters
        // - filter rows  : ['field' => [['join','type','val',...], ]]  → processAdvancedFilters
        // Split before dispatch to keep both shapes supported.
        $flat = [];
        $advanced = [];
        foreach ($hidden as $field => $values) {
            if (!is_array($values)) {
                $flat[$field] = $values;
                continue;
            }
            // The whole keyed structure of "numeric" is one arg.
            if ($field === 'numeric') {
                $flat['numeric'] = $values;
                continue;
            }
            foreach ($values as $value) {
                if (is_array($value) && !empty($value['type'])) {
                    $advanced[$field][] = $value;
                } else {
                    $flat[$field][] = $value;
                }
            }
        }

        if ($flat) {
            $this->processFilters($flat);
        }
        if ($advanced) {
            $this->processAdvancedFilters($advanced);
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

            // Standard args that are not a plain "field = values" filter.
            if ($fieldName === 'not_item_set_id') {
                // fieldToIndex: the item set map is scoped to "items", so the
                // generic-only solrCoreField() cannot resolve it.
                $itemSetField = $this->fieldToIndex('item_set_id');
                $ids = array_filter(array_map('intval', is_array($values) ? $values : [$values]));
                if ($itemSetField && $ids) {
                    $this->select
                        ->createFilterQuery('not_item_set_' . ++$this->appendToKey)
                        ->setQuery("-$itemSetField:(" . implode(' OR ', $ids) . ')');
                }
                continue;
            }
            // Presence args: the criterion is the existence of a related
            // index (a boolean arg on a non boolean concept).
            static $presenceArgs = [
                'in_sites' => 'site/o:id',
                'has_asset' => 'asset',
            ];
            if (isset($presenceArgs[$fieldName])) {
                $maps = $this->solrCore->mapsBySource($presenceArgs[$fieldName]);
                $presenceField = $maps ? (reset($maps))->fieldName() : null;
                if ($presenceField) {
                    $value = is_array($values) ? reset($values) : $values;
                    $this->select
                        ->createFilterQuery('presence_' . ++$this->appendToKey)
                        ->setQuery(
                            filter_var($value, FILTER_VALIDATE_BOOLEAN)
                                ? "$presenceField:[* TO *]"
                                : "-$presenceField:[* TO *]"
                        );
                } else {
                    $this->getLogger()->warn(
                        'Solr: the arg "{arg}" needs a map of the source "{source}"; the filter is ignored.', // @translate
                        ['arg' => $fieldName, 'source' => $presenceArgs[$fieldName]]
                    );
                }
                continue;
            }
            // Known args without solr equivalent: ignored explicitly (the api
            // itself ignores unknown args), with a log.
            if (in_array($fieldName, ['sort_ids', 'site_attachments_only'], true)) {
                $this->getLogger()->warn(
                    'Solr: the arg "{arg}" is not supported by the solr querier and is ignored.', // @translate
                    ['arg' => $fieldName]
                );
                continue;
            }
            if ($fieldName === 'numeric') {
                $this->processNumericArgs(is_array($values) ? $values : []);
                continue;
            }
            if ($fieldName === 'search') {
                // Core semantic: match in any property (see
                // AbstractResourceEntityAdapter). Routed to the aggregated
                // property values index; tokenization makes it approximate.
                $this->processAdvancedFilters(['property_values' => [[
                    'join' => 'and',
                    'field' => 'property_values',
                    'type' => 'in',
                    'val' => $values,
                ]]]);
                continue;
            }

            $resolved = $this->fieldToIndex($fieldName);
            $name = $resolved ?? $fieldName;

            // An unknown arg without resolution nor index suffix is ignored
            // like the api ignores unknown args (a raw filter on a nonexistent
            // field would match nothing and silently diverge).
            // A suffix is not enough: a field like "ct_s" would look like an
            // index and Solr would reject the whole query, so check the schema.
            if ($resolved === null
                && strpos($fieldName, ':') === false
                && !$this->isSchemaField($fieldName)
            ) {
                $this->getLogger()->warn(
                    'Solr: unknown arg "{arg}" ignored.', // @translate
                    ['arg' => $fieldName]
                );
                continue;
            }

            // A property term (with ":") that was not resolved to a Solr field
            // means the field is not indexed.
            if (strpos($name, ':') !== false) {
                $this->getLogger()
                    ->err(
                        'Solr: skipped filter on unmapped field "{field}".', // @translate
                        ['field' => $fieldName]
                    );
                $this->select->createFilterQuery('unmapped_' . ++$this->appendToKey)
                    ->setQuery('-*:*');
                return;
            }

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
                    $this->select
                        ->createFilterQuery($name . '_' . ++$this->appendToKey)
                        ->setQuery("$name:$value");
                }
                continue;
            }

            // Avoid issue with basic direct hidden quey filter like
            // "resource_template_id_i=1".

            $values = is_array($values) ? $values : [$values];

            $scalars = [];
            foreach ($values as $v) {
                if (is_array($v)) {
                    // Skip date range queries (for hidden queries).
                    continue;
                }
                if (is_scalar($v) && strlen((string) $v)) {
                    $scalars[] = $v;
                }
            }
            if (!$scalars) {
                continue;
            }

            $scalars = $this->convertStandardFilterValues($fieldName, $name, $scalars);

            // Multiple values of one arg mean "any of them" (like the api), so
            // they are joined in a single filter query with OR.
            $escaped = $this->escapePhraseValue($scalars, 'OR');
            if (strlen($escaped)) {
                $this->select
                    ->createFilterQuery($name . '_' . ++$this->appendToKey)
                    ->setQuery("$name:$escaped");
            }
        }
    }

    /**
     * Convert the values of a standard arg to what the Solr field stores.
     *
     * The api uses numeric ids for classes and templates while the mapped Solr
     * fields store the term or the label; boolean fields store true/false.
     */
    /**
     * Translate the arg "numeric" of the module NumericDataTypes into range
     * filter queries on the mapped fields of each property: timestamps need a
     * date map (suffix _dt) or, in fallback, a year map (suffix _year_is);
     * integers need an integer map (suffix _i or _is). Durations and
     * intervals are not translated and are logged.
     */
    protected function processNumericArgs(array $numeric): void
    {
        $fieldFor = function (int $pid, array $suffixes, array $exclude = []): ?string {
            $term = $this->easyMeta->propertyTerm($pid);
            if (!$term) {
                return null;
            }
            $base = strtr($term, ':', '_') . '_';
            foreach ($this->usedSolrFields([], $suffixes, []) as $field) {
                if (strncmp($field, $base, strlen($base)) !== 0) {
                    continue;
                }
                foreach ($exclude as $suffix) {
                    if (str_ends_with($field, $suffix)) {
                        continue 2;
                    }
                }
                return $field;
            }
            return null;
        };

        // Bounds of a partial date, via the edtf formatter (fallback parser
        // included when the edtf-php library is missing).
        $dateBound = function (string $value, bool $isMax): ?string {
            static $formatters = [];
            $key = $isMax ? 'max' : 'min';
            if (!isset($formatters[$key])) {
                $formatters[$key] = $this->services
                    ->get('SearchSolr\ValueFormatterManager')
                    ->get('edtf');
                $formatters[$key]->setSettings(['part' => $key]);
            }
            $result = $formatters[$key]->format($value);
            return $result ? (string) reset($result) : null;
        };

        foreach ($numeric['ts'] ?? [] as $operator => $row) {
            $pid = (int) ($row['pid'] ?? 0);
            $value = trim((string) ($row['val'] ?? ''));
            if (!$pid || $value === '' || !in_array($operator, ['gt', 'gte', 'lt', 'lte'], true)) {
                continue;
            }
            $isUpperBound = in_array($operator, ['gt', 'lte'], true);
            $bound = $dateBound($value, $isUpperBound);
            if ($bound === null) {
                continue;
            }
            $dateField = $fieldFor($pid, ['_dt', '_dts'], []);
            if ($dateField) {
                $iso = $this->normalizeDate($bound);
                $ranges = [
                    'gt' => '{' . $iso . ' TO *]',
                    'gte' => '[' . $iso . ' TO *]',
                    'lt' => '[* TO ' . $iso . '}',
                    'lte' => '[* TO ' . $iso . ']',
                ];
                $this->select
                    ->createFilterQuery('numeric_ts_' . ++$this->appendToKey)
                    ->setQuery($dateField . ':' . $ranges[$operator]);
                continue;
            }
            // Fallback on the year index, with a year precision.
            $yearField = $fieldFor($pid, ['_year_is', '_year_i'], []);
            if ($yearField) {
                $year = (int) explode('-', ltrim($bound, '-'), 2)[0] * (strncmp($bound, '-', 1) === 0 ? -1 : 1);
                $ranges = [
                    'gt' => '{' . $year . ' TO *]',
                    'gte' => '[' . $year . ' TO *]',
                    'lt' => '[* TO ' . $year . '}',
                    'lte' => '[* TO ' . $year . ']',
                ];
                $this->select
                    ->createFilterQuery('numeric_ts_' . ++$this->appendToKey)
                    ->setQuery($yearField . ':' . $ranges[$operator]);
                continue;
            }
            $this->getLogger()->warn(
                'Solr: the numeric timestamp filter needs a date map (suffix _dt) or a year map (suffix _year_is) of the property #{property}; the filter is ignored.', // @translate
                ['property' => $pid]
            );
        }

        foreach ($numeric['int'] ?? [] as $operator => $row) {
            $pid = (int) ($row['pid'] ?? 0);
            $value = $row['val'] ?? '';
            if (!$pid || !is_numeric($value) || !in_array($operator, ['gt', 'lt'], true)) {
                continue;
            }
            $intField = $fieldFor($pid, ['_i', '_is'], ['_link_is', '_year_is', '_min_i', '_max_i']);
            if (!$intField) {
                $this->getLogger()->warn(
                    'Solr: the numeric integer filter needs an integer map (suffix _i) of the property #{property}; the filter is ignored.', // @translate
                    ['property' => $pid]
                );
                continue;
            }
            $int = (int) $value;
            $this->select
                ->createFilterQuery('numeric_int_' . ++$this->appendToKey)
                ->setQuery($intField . ':' . ($operator === 'gt' ? '{' . $int . ' TO *]' : '[* TO ' . $int . '}'));
        }

        foreach (['dur', 'ivl'] as $unsupported) {
            if (!empty($numeric[$unsupported])) {
                $this->getLogger()->warn(
                    'Solr: the numeric arg "{arg}" is not supported by the solr querier and is ignored.', // @translate
                    ['arg' => $unsupported]
                );
            }
        }
    }

    protected function convertStandardFilterValues(string $fieldName, string $solrField, array $values): array
    {
        if ($fieldName === 'resource_class_id') {
            return array_values(array_filter(
                array_map(fn ($v) => $this->easyMeta->resourceClassTerm(is_numeric($v) ? (int) $v : $v), $values)
            ));
        }
        if ($fieldName === 'resource_template_id') {
            return array_values(array_filter(
                array_map(fn ($v) => $this->easyMeta->resourceTemplateLabel(is_numeric($v) ? (int) $v : $v), $values)
            ));
        }
        if ($this->fieldIsBool($solrField)) {
            return array_map(
                fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
                $values
            );
        }
        return $values;
    }

    protected function processDateRangeFilters(array $filters): void
    {
        foreach ($filters as $field => $ranges) {
            if (!is_array($ranges)) {
                continue;
            }

            $name = $this->fieldToIndex($field) ?? $field;
            $isDate = substr($name, -3) === '_dt' || substr($name, -4) === '_dts' || substr($name, -4) === '_pdt' || substr($name, -4) === '_tdt' || substr($name, -5) === '_pdts' || substr($name, -5) === '_tdts';

            // Two-field interval overlap: use start field for the upper bound
            // and end field for the lower bound. Triggered when the form filter
            // declares a "field_end" option.
            $formFilter = $this->query->getFormFilter($field);
            $endField = null;
            if ($formFilter && !empty($formFilter['field_end'])) {
                $endField = $this->fieldToIndex($formFilter['field_end']) ?? $formFilter['field_end'];
            }

            foreach ($ranges as $range) {
                if (!is_array($range)) {
                    continue;
                }

                $from = isset($range['from']) && $range['from'] !== '' ? $range['from'] : '*';
                $to = isset($range['to']) && $range['to'] !== '' ? $range['to'] : '*';

                if ($isDate && $from !== '*') {
                    $from = $this->normalizeDate($from);
                }
                if ($isDate && $to !== '*') {
                    $to = $this->normalizeDate($to);
                }

                if ($from === '*' && $to === '*') {
                    continue;
                }

                if ($endField) {
                    $clauses = [];
                    if ($to !== '*') {
                        $clauses[] = "$name:[* TO $to]";
                    }
                    if ($from !== '*') {
                        $clauses[] = "$endField:[$from TO *]";
                    }
                    $this->select->createFilterQuery($name . '_' . ++$this->appendToKey)
                        ->setQuery(implode(' AND ', $clauses));
                } else {
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

        // Accumulate all rows of all fields in a single filter query, like the
        // api does with its single where: a per-field filter query would make
        // "or" between rows of different fields structurally impossible
        // (filter queries are always joined with AND by Solr). The api where is
        // a flat sql string, so it follows the sql precedence (AND binds
        // tighter than OR); Solr has no such precedence, so the chain is
        // encoded explicitly as AND-groups joined with OR: "A AND B OR C"
        // becomes "(A AND B) OR (C)".
        $orGroups = [];
        $andGroup = [];
        $first = true;

        foreach ($filters as $field => $filterList) {
            // Avoid issue with basic direct hidden quey filter like
            // "resource_template_id_i=1".
            if (!is_array($filterList)) {
                continue;
            }

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
                    // A leading "not" keeps its negation even without a
                    // previous condition to join with.
                    if ($joiner === 'not') {
                        $type = SearchResources::FIELD_QUERY['reciprocal'][$type];
                    }
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

                // A row may hold several fields (aggregated alias): the api
                // combines them in one predicate with OR, so the sub-clauses
                // are OR-joined inside a single clause of the chain.
                $rowFields = isset($f['fields']) && is_array($f['fields']) && $f['fields']
                    ? array_values(array_unique(array_filter($f['fields'], 'is_string')))
                    : [$field];

                $isNegative = substr($type, 0, 1) === 'n';
                $wrap = $isNegative ? '(NOT ' : '(';
                $end = ')';
                $isAbsence = in_array($type, ['nex', 'nexs', 'nexm'], true);

                $isYearType = in_array($type, ['yreq', 'nyreq', 'yrgte', 'yrlte', 'yrgt', 'yrlt'], true);

                $subClauses = [];
                foreach ($rowFields as $rowField) {
                    $resolved = $requireInteger
                        ? ($this->fieldToIndexNumeric($rowField) ?? $this->fieldToIndex($rowField))
                        : $this->fieldToIndex($rowField);
                    // An arg that is neither an alias nor a map is used only
                    // when it is a real field of the schema: a query arg that
                    // is not a field, like the tracking parameter of a mailing
                    // ("?ct=EMAIL_CAMPAIGN"), would build a filter on an
                    // undefined field and Solr would reject the whole query.
                    if ($resolved === null && !$this->isSchemaField($rowField)) {
                        $this->getLogger()->warn(
                            'Solr: unknown arg "{arg}" ignored in the advanced filters.', // @translate
                            ['arg' => $rowField]
                        );
                        continue;
                    }
                    $name = $resolved ?? $rowField;
                    // The year types target the year index of the field
                    // (suffix _year_is, formatter edtf_year), when mapped.
                    if ($isYearType) {
                        $name = $this->fieldToIndexYear($rowField) ?? $name;
                    }
                    // Alphabetical comparisons follow the collation when a
                    // folded variant of the field exists.
                    if (in_array($type, ['lt', 'lte', 'gte', 'gt', '<', '≤', '≥', '>'], true)) {
                        $name = $this->preferFoldedField($name);
                    }
                    // A property term (with ":") not resolved to a Solr field
                    // means the field is not indexed. The clause is adapted
                    // locally: a positive condition on a missing field matches
                    // nothing, an absence condition (nex…) matches everything.
                    // A local clause keeps the other branches of an "or" chain
                    // working, unlike a global -*:*.
                    if (strpos($name, ':') !== false) {
                        $this->getLogger()
                            ->err(
                                'Solr: no index for the field "{field}", filter adapted.', // @translate
                                ['field' => $rowField]
                            );
                        if ($isAbsence) {
                            $subClauses[] = '*:*';
                        }
                        continue;
                    }
                    $subClause = $this->buildAdvancedFilterQuery($type, $val, $name, $wrap, $end);
                    if ($subClause) {
                        $subClauses[] = $subClause;
                    }
                }

                if ($subClauses) {
                    $query = count($subClauses) === 1
                        ? reset($subClauses)
                        : '(' . implode(' OR ', $subClauses) . ')';
                } elseif ($isAbsence || $isNegative) {
                    // No resolvable field: an absence or a negative condition
                    // (nex, neq…) is true on a missing field.
                    $query = '*:*';
                } else {
                    // A positive condition on a missing field matches nothing.
                    $query = '(NOT *:*)';
                }

                if ($joiner === 'OR') {
                    $orGroups[] = $andGroup;
                    $andGroup = [];
                }
                $andGroup[] = $query;
            }
        }

        if ($andGroup) {
            $orGroups[] = $andGroup;
        }
        $orGroups = array_filter($orGroups);
        if ($orGroups) {
            $this->select->createFilterQuery('adv_fq_' . ++$this->appendToKey)
                ->setQuery(implode(' OR ', array_map(
                    fn ($group) => '(' . implode(' AND ', $group) . ')',
                    $orGroups
                )));
        }
    }

    /**
     * Build advanced search filter like omeka api.
     *
     * Regex requires string (_s), not text or anything else.
     * So if the field is not a string, use a simple "+", that will be enough
     * in most of the cases.
     * Furthermore, unlike sql, solr regex doesn't manage insensitive search,
     * neither flag "i".
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
                    // A string field is not tokenized, so "contains" needs a
                    // regex; escape() quoted the value, so the ".*" was
                    // searched literally and never matched (like sw/ew).
                    $val = $this->regexValue($val, '.*', '.*');
                } else {
                    $val = $this->escapePhraseValue($val, 'AND');
                }
                return "$field:$wrap$val$end";

            // Starts with.
            case 'nsw':
            case 'sw':
                if ($this->fieldIsString($field)) {
                    $val = $this->regexValue($val, '', '.*');
                } else {
                    $val = $this->escapePhraseValue($val, 'AND');
                }
                return "$field:$wrap$val$end";

            // Ends with.
            case 'new':
            case 'ew':
                if ($this->fieldIsString($field)) {
                    $val = $this->regexValue($val, '.*', '');
                } else {
                    $val = $this->escapePhraseValue($val, 'AND');
                }
                return "$field:$wrap$val$end";

            // Matches.
            case 'nma':
            case 'ma':
                // The value is a user regex with sql semantics (partial match,
                // ^/$ anchors); a Lucene regex is anchored on the full value
                // and has no ^/$, so the anchors are translated and the rest is
                // wrapped with ".*". Dialects differ beyond that, so exotic
                // patterns stay approximate. Regex requires a string field.
                if (!$this->fieldIsString($field)) {
                    return '';
                }
                $vals = array_filter(array_map('strval', is_array($val) ? $val : [$val]), 'strlen');
                if (!$vals) {
                    return '';
                }
                $regexes = array_map(function ($v) {
                    $pre = str_starts_with($v, '^') ? '' : '.*';
                    $post = str_ends_with($v, '$') && !str_ends_with($v, '\$') ? '' : '.*';
                    $v = preg_replace('~^\^~', '', $v);
                    $v = preg_replace('~(?<!\\\\)\$$~', '', $v);
                    return '/' . $pre . str_replace('/', '\/', $v) . $post . '/';
                }, $vals);
                $val = count($regexes) === 1 ? reset($regexes) : '(' . implode(' OR ', $regexes) . ')';
                return "$field:$wrap$val$end";

            // Greater/lower.
            case 'lt':
            case 'lte':
            case 'gte':
            case 'gt':
                // For date fields (e.g. EDTF indexed via ValueFormatter\Edtf),
                // normalize input (EDTF or partial date) to ISO 8601 bounds
                // supported by Solr date fields, including BCE years.
                if ($this->fieldIsDate($field)) {
                    $val = $this->edtfValuesToIso(
                        $val,
                        $type === 'lt' || $type === 'lte'
                    );
                    if (empty($val)) {
                        return '';
                    }
                }
                // With a list of lt/lte/gte/gt, get the right value first in
                // order to avoid multiple sql conditions.
                // But the language cannot be determined: language of the site?
                // of the data? of the user who does query?
                // Practically, mysql/mariadb sort with generic unicode rules by
                // default, so use a generic sort.
                /* @see https://www.unicode.org/reports/tr10/ */
                if (count($val) > 1) {
                    if (extension_loaded('intl')) {
                        $this->getCollator()->sort($val);
                    } else {
                        natcasesort($val);
                    }
                }
                // A folded field stores lowercased ascii terms, so the
                // bounds are folded the same way.
                if (str_ends_with($field, '_fold_s')) {
                    $val = array_map(fn ($v) => mb_strtolower($this->removeDiacritics((string) $v)), $val);
                }
                // TODO Manage uri and resources with lt, lte, gte, gt (it has a meaning at least for resource ids, but separate).
                // Strict bounds use the exclusive range brackets of Solr:
                // the previous string decrement/increment was a no-op on
                // strings, making lt/gt inclusive.
                if ($type === 'lt') {
                    $val = $this->escapePhrase(reset($val));
                    return "$field:[* TO $val}";
                } elseif ($type === 'lte') {
                    $val = $this->escapePhrase(reset($val));
                    return "$field:[* TO $val]";
                } elseif ($type === 'gte') {
                    $val = $this->escapePhrase(array_pop($val));
                    return "$field:[$val TO *]";
                } elseif ($type === 'gt') {
                    $val = $this->escapePhrase(array_pop($val));
                    return $field . ':{' . $val . ' TO *]';
                }
                break;

            case '<':
            case '≤':
            case '≥':
            case '>':
                // Normalize EDTF/partial dates to ISO 8601 for Solr date
                // fields (BCE included).
                if ($this->fieldIsDate($field)) {
                    $val = $this->edtfValuesToIso(
                        is_array($val) ? $val : [$val],
                        $type === '<' || $type === '≤'
                    );
                    if (empty($val)) {
                        return '';
                    }
                }
                // The values are already cleaned.
                $first = reset($val);
                if (count($val) > 1) {
                    if (is_int($first)) {
                        $val = ($type === '<' || $type === '≤') ? min($val) : max($val);
                    } else {
                        extension_loaded('intl') ? $this->getCollator()->sort($val, \Collator::SORT_NUMERIC) : sort($val);
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

            // Linked resource by id.
            // The value is a resource id (integer). The ideal index is _link_is
            // (integer), but when only _link_ss (string) is available, the
            // resource id is compared as string (e.g. "30945" instead of 30945).
            case 'nres':
            case 'res':
                // Filter to keep only valid numeric ids.
                $fqValues = is_array($val)
                    ? array_filter($val, 'is_numeric')
                    : (is_numeric($val) ? [$val] : []);
                if (!$fqValues) {
                    return '';
                }
                if ($this->fieldIsInteger($field)) {
                    $fqValues = implode(' OR ', array_map('intval', $fqValues));
                    return "$field:$wrap($fqValues)$end";
                }
                // Fallback for string field: only meaningful when the field
                // stores the linked resource ids as strings; a "_link_ss" of
                // titles cannot match ids, so a dedicated "_link_is" map is
                // required for res/nres.
                if (!str_ends_with($field, '_link_ss')) {
                    $this->getLogger()->warn(
                        'Solr: the type res/nres on "{field}" compares ids as strings; run the maps sync to create the integer index of the linked resource ids (_link_is).', // @translate
                        ['field' => $field]
                    );
                }
                $fqValues = $this->escapePhraseValue(array_map('strval', $fqValues), 'OR');
                return "$field:$wrap$fqValues$end";

            // Exists (has a value). These types have no value ($val is null),
            // so the previous phrase escaping produced field:"" that never
            // matched. The existence is the open range on the field; the
            // absence embeds *:* so the clause stays valid inside OR groups.
            case 'nex':
                return "(*:* NOT $field:[* TO *])";
            case 'ex':
                return "$field:[* TO *]";

            default:
                return '';
        }

        return '';
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get cached Collator instance for Unicode sorting.
     */
    protected function getCollator(): \Collator
    {
        if (self::$collator === null) {
            self::$collator = new \Collator('root');
        }
        return self::$collator;
    }

    protected function removeDiacritics(string $text): string
    {
        if (extension_loaded('intl')) {
            // Cache the Transliterator instance for performance.
            if (self::$transliterator === null) {
                self::$transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
            }
            return self::$transliterator->transliterate($text);
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
        } catch (\Throwable $e) {
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
     * The default text fields usually include ASCII folding and tokenization.
     * When multiple field types exist for the same base name (e.g. _txt, _ss,
     * _s_lower), prefer _txt/_t fields as they are analyzed and best for
     * full-text search.
     */
    protected function fieldsFoldable(): array
    {
        if ($this->fieldsFoldableCache !== null) {
            return $this->fieldsFoldableCache;
        }

        // Get all text fields (_txt, _t): tokenized and best for search.
        $textFields = $this->usedSolrFields([], ['_txt', '_t'], []);

        // Extract base names from text fields to avoid duplicates.
        $textBases = [];
        foreach ($textFields as $field) {
            // Remove suffix to get base name.
            $base = preg_replace('/(_txt|_t)$/', '', $field);
            $textBases[$base] = true;
        }

        // Get lowercase fields only if no _txt/_t version exists.
        $lowerFields = $this->usedSolrFields([], ['_s_lower', '_ss_lower'], []);
        $filteredLower = [];
        foreach ($lowerFields as $field) {
            $base = preg_replace('/(_s_lower|_ss_lower)$/', '', $field);
            // Only add if no text field exists for this base.
            if (!isset($textBases[$base])) {
                $filteredLower[] = $field;
            }
        }

        $this->fieldsFoldableCache = array_values(array_unique(array_merge($textFields, $filteredLower)));

        return $this->fieldsFoldableCache;
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

        // System field aliases: a config made for the internal engine uses keys
        // like "resource_template_id"; map them to the Solr field via the map
        // source, so facets/filters/sort still resolve after switching to Solr.
        static $systemSources = [
            'resource_type' => 'resource_name',
            'resource_name' => 'resource_name',
            'is_public' => 'is_public',
            'id' => 'o:id',
            'owner_id' => 'owner/o:id',
            'site_id' => 'site/o:id',
            'resource_class_id' => 'resource_class/o:term',
            'resource_class_term' => 'resource_class/o:term',
            'resource_template_id' => 'resource_template/o:label',
            'item_set_id' => 'item_set/o:id',
            'has_media' => 'has_media',
            'has_original' => 'has_original',
            'has_thumbnails' => 'has_thumbnails',
            'media_type' => 'o:media_type',
            'media_types' => 'media/o:media_type',
            'item_id' => 'item/o:id',
            'is_open' => 'is_open',
            'asset_id' => 'asset',
            // Pseudo-field for "any property" rows and the arg "search".
            'property_values' => 'property_values',
            // Standard sort keys.
            'created' => 'created',
            'modified' => 'modified',
            'title' => 'o:title',
        ];
        if (isset($systemSources[$field])) {
            $maps = $this->solrCore->mapsBySource($systemSources[$field]);
            if ($maps) {
                return reset($maps)->fieldName();
            }
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

        // Match standard fields (prefix_prop_suffix) and Drupal-style fields (suffix_prefix_prop).
        $base = strtr($term, ':', '_');
        $indices = $this->usedSolrFields(
            [$base . '_'],
            ['_' . $base],
            []
        );
        if (!$indices) {
            return null;
        }

        return $this->selectBestIndex($indices);
    }

    /**
     * Resolve a configured field to its Solr index, or null when it is a
     * property term not mapped to any Solr field.
     *
     * An unmapped term must be skipped instead of being sent raw to Solr, which
     * would raise an "undefined field" error or match nothing.
     */
    protected function resolveFieldOrNull(string $field): ?string
    {
        $name = $this->fieldToIndex($field);

        // Not an alias and not a map: keep the argument only when it is a real
        // field of the schema. Else a query argument that is not a field, like
        // the tracking parameter of a mailing ("?ct=EMAIL_CAMPAIGN"), would
        // build a filter on an undefined field and Solr would reject the whole
        // query, so the page would fail.
        if ($name === null) {
            if (!$this->isSchemaField($field)) {
                return null;
            }
            $name = $field;
        }

        return strpos($name, ':') === false ? $name : null;
    }

    /**
     * Check if a name is a field of the schema, static or dynamic.
     *
     * When the schema is not available, no field can be checked, so none is
     * used: an invalid field would make the whole query fail anyway.
     */
    protected function isSchemaField(string $field): bool
    {
        if ($field === '' || strpos($field, ':') !== false) {
            return false;
        }

        // Schema::getField() returns null when the schema is unavailable, like
        // when Solr restarts, so check the schema itself first: else every
        // field would look unknown and all the facets and the filters would be
        // removed from a page that works.
        try {
            $this->getSolrCore()->schema()->getSchema();
        } catch (\Throwable $e) {
            return true;
        }

        try {
            return (bool) $this->getSchemaField($field);
        } catch (\Throwable $e) {
            return true;
        }
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

        // Match standard fields (prefix_prop_suffix) and Drupal-style fields (suffix_prefix_prop).
        $base = strtr($term, ':', '_');
        $indices = $this->usedSolrFields(
            [$base . '_'],
            ['_' . $base],
            []
        );
        if (!$indices) {
            return null;
        }

        // Filter to only include actual integer fields.
        $integerIndices = array_filter($indices, fn ($idx) => $this->fieldIsInteger($idx));
        if (!$integerIndices) {
            return null;
        }

        return $this->selectBestIndexNumeric($integerIndices);
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
        // Value indexes first: the "_link_*" variants store the titles of the
        // linked resources, not the own values of the resource, so they must
        // never win for a plain value filter or sort. Linked-resource query
        // types resolve through fieldToIndexNumeric(), which has its own
        // priority with "_link_is" first.
        if (str_ends_with($field, '_link_ss') || str_ends_with($field, '_link')) {
            return 9;
        }
        if (str_ends_with($field, '_ss_lower')) {
            return 1;
        }
        if (str_ends_with($field, '_ss')) {
            return 0;
        }
        if (str_starts_with($field, 'sm_')) {
            return 2;
        }
        return 3;
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
        if (!array_key_exists($source, $this->solrCoreFieldCache)) {
            $maps = $this->solrCore->mapsBySource($source, 'generic');
            // When several maps share a source (e.g. during the is_public_i →
            // is_public_b transition), pick the oldest one deterministically:
            // it is the field currently populated, so the switch to the new
            // field happens only once the legacy map is removed.
            usort($maps, fn ($a, $b) => $a->id() <=> $b->id());
            $this->solrCoreFieldCache[$source] = $maps
                ? (reset($maps))->fieldName()
                : null;
        }
        return $this->solrCoreFieldCache[$source];
    }

    /**
     * Solr clause matching resources reserved to a group of the current user.
     *
     * Returns null (no widening, public only) when module Group is inactive, no
     * group field is mapped, the user is anonymous, or the user belongs to no
     * group. The clause only ever adds the user's own group ids, so it cannot
     * widen visibility beyond the user's groups.
     */
    protected function groupVisibilityClause(): ?string
    {
        if (!class_exists(\Group\Module::class, false)) {
            return null;
        }
        $field = $this->solrCoreField('group_id');
        if (!$field) {
            return null;
        }
        $user = $this->services->get('Omeka\AuthenticationService')->getIdentity();
        if (!$user) {
            return null;
        }
        $connection = $this->services->get('Omeka\Connection');
        $groupIds = $connection->executeQuery(
            'SELECT `group_id` FROM `group_user` WHERE `user_id` = :id',
            ['id' => $user->getId()]
        )->fetchFirstColumn();
        return $this->buildGroupClause($field, $groupIds);
    }

    /**
     * Whether module Access hides "protected"/"forbidden" resources from public
     * lists, i.e. its Doctrine filter "access_level" is registered and enabled.
     * When it is not (notice stays listed, only the file is gated), the Solr
     * count must keep these resources, so the caller skips the access filter.
     */
    protected function isAccessLevelFilterEnabled(): bool
    {
        if (!class_exists(\Access\Module::class, false)) {
            return false;
        }
        $filters = $this->services->get('Omeka\EntityManager')->getFilters();
        return $filters->isEnabled('access_level');
    }

    /**
     * Build the Solr clause "field:(id OR id ...)" from a list of group ids.
     *
     * Returns null when there is no field or no valid id, so the visibility
     * filter stays restricted to public resources (no leak).
     *
     * @param int[]|string[] $groupIds
     */
    protected function buildGroupClause(?string $field, array $groupIds): ?string
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if (!$field || !$groupIds) {
            return null;
        }
        return $field . ':(' . implode(' OR ', $groupIds) . ')';
    }

    /**
     * @todo Replace by a single regex?
     */
    /**
     * Resolve a field to its year index (suffix _year_is or _year_i), if any.
     */
    protected function fieldToIndexYear(string $field): ?string
    {
        $term = $this->easyMeta->propertyTerm($field) ?? $field;
        $base = strtr($term, ':', '_') . '_';
        $candidates = array_filter(
            $this->usedSolrFields([], ['_year_is', '_year_i'], []),
            fn ($v) => strncmp($v, $base, strlen($base)) === 0
        );
        return $candidates ? reset($candidates) : null;
    }

    /**
     * Prefer the folded variant of a string field, when it is mapped: sorts
     * and alphabetical comparisons then follow the database collation (case
     * and diacritics insensitive) instead of the byte order.
     */
    protected function preferFoldedField(string $name): string
    {
        if (str_ends_with($name, '_fold_s')
            || !preg_match('~_(ss|s)$~', $name)
        ) {
            return $name;
        }
        $candidate = preg_replace('~_(ss|s)$~', '_fold_s', $name);
        return in_array($candidate, $this->usedSolrFields([], ['_fold_s'], []))
            ? $candidate
            : $name;
    }

    protected function usedSolrFields(array $prefixes, array $suffixes, array $contains): array
    {
        // Cache all field names on first call to avoid repeated API queries.
        if ($this->solrFieldNamesCache === null) {
            $api = $this->services->get('Omeka\ApiManager');
            $this->solrFieldNamesCache = $api->search('solr_maps', [
                'solr_core_id' => $this->solrCore->id(),
            ], ['returnScalar' => 'fieldName'])->getContent();
        }

        return array_filter($this->solrFieldNamesCache, function ($v) use ($prefixes, $suffixes, $contains) {
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

    /**
     * Get cached schema field to avoid repeated lookups.
     */
    protected function getSchemaField(string $name): ?\SearchSolr\Schema\Field
    {
        if (!isset($this->fieldTypeCache[$name])) {
            $this->fieldTypeCache[$name] = $this->getSolrCore()->schema()->getField($name);
        }
        return $this->fieldTypeCache[$name];
    }

    protected function fieldIsBool(string $name): bool
    {
        $field = $this->getSchemaField($name);
        return $field ? $field->isBoolean() : false;
    }

    protected function fieldIsDate(string $name): bool
    {
        $field = $this->getSchemaField($name);
        return $field ? $field->isDate() : false;
    }

    protected function fieldIsFloat(string $name): bool
    {
        $field = $this->getSchemaField($name);
        return $field ? $field->isFloat() : false;
    }

    protected function fieldIsInteger(string $name): bool
    {
        $field = $this->getSchemaField($name);
        return $field ? $field->isInteger() : false;
    }

    protected function fieldIsLowercase(string $name): bool
    {
        $field = $this->getSchemaField($name);
        return $field ? $field->isLowercase() : false;
    }

    protected function fieldIsNumeric(string $name): bool
    {
        $field = $this->getSchemaField($name);
        return $field ? $field->isNumeric() : false;
    }

    protected function fieldIsString(string $name): bool
    {
        $field = $this->getSchemaField($name);
        return $field ? $field->isString() : false;
    }

    protected function fieldIsTokenized(string $name): bool
    {
        $field = $this->getSchemaField($name);
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
     * Build a Solr regex term (or OR-list) for "starts/ends with" on a string
     * field.
     *
     * Each value is wrapped as /<pre><escaped value><post>/, where $pre and
     * $post are raw regex fragments (".*"), and the value's own regex
     * metacharacters are escaped so it matches literally. The previous code
     * used escape() (escapePhrase), which quoted the value: the ".*" was then
     * searched as a literal phrase and never matched anything.
     */
    protected function regexValue($val, string $pre, string $post): string
    {
        $vals = is_array($val) ? $val : [$val];
        $vals = array_filter(array_map('strval', $vals), 'strlen');
        if (!$vals) {
            return '';
        }
        $escaped = array_map(
            fn ($v) => '/' . $pre . $this->escapeRegexChars($v) . $post . '/',
            $vals
        );
        return implode(' OR ', $escaped);
    }

    /**
     * Escape Lucene regex metacharacters so the value is matched literally
     * inside a Solr "/.../" regex query.
     */
    protected function escapeRegexChars(string $s): string
    {
        return preg_replace('~([.\\\\+*?()\[\]{}|^$/"@<>#&\x7e])~', '\\\\$1', $s);
    }

    /**
     * Escape a string to query keeping meaning of solr special characters.
     *
     * @see https://solr.apache.org/guide/solr/latest/query-guide/standard-query-parser.html#escaping-special-characters
     * @see https://lucene.apache.org/core/10_1_0/queryparser/org/apache/lucene/queryparser/classic/package-summary.html#Escaping_Special_Characters
     * @uses \Solarium\Core\Query\Helper::escapeTerm()
     * @uses \Solarium\Core\Query\Helper::escapePhrase()
     */
    /**
     * Minimum literal characters before the first user wildcard ("*"/"?") for
     * it to be kept active, so the term enumeration is anchored on a prefix
     * (never a leading wildcard scanning the whole term dictionary).
     */
    const WILDCARD_MIN_PREFIX = 3;

    /**
     * Maximum number of wildcard terms kept per query, to bound the per-field
     * expansion done by edismax. Extra wildcards are escaped to literals.
     */
    const WILDCARD_MAX_TERMS = 3;

    protected function escapeTermOrPhrase(string $string): string
    {
        $string = trim($string);

        // substr_count() is unicode-safe.
        $countQuotes = substr_count($string, '"');

        // TODO Manage the escaping of query with an odd number of quotes. Check for escaped quote \".
        if ($countQuotes < 2 || ($countQuotes % 2) === 1) {
            // Google-like search: escape each word individually and prefix with
            // "+" to require all terms (like refine behavior).
            $words = preg_split('/\s+/', $string, -1, PREG_SPLIT_NO_EMPTY);
            $wildcardCount = 0;
            if (count($words) > 1) {
                $escaped = [];
                foreach ($words as $w) {
                    $escaped[] = '+' . $this->escapeWord($w, $wildcardCount);
                }
                return implode(' ', $escaped);
            }
            return $this->escapeWord($string, $wildcardCount);
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
     * Escape one query word, keeping an explicit user wildcard ("*"/"?") only
     * when it is safe.
     *
     * A wildcard is kept active only when at least {@see WILDCARD_MIN_PREFIX}
     * literal characters precede the first one (so the term lookup is anchored
     * on a prefix, never a leading wildcard scanning the whole dictionary) and
     * within {@see WILDCARD_MAX_TERMS} wildcard terms per query. Position of
     * the wildcard (middle or end) does not matter, only the leading prefix.
     * Otherwise "*"/"?" are escaped to literals. Structured identifiers (with
     * ":" or "/") are kept as phrases.
     */
    protected function escapeWord(string $w, int &$wildcardCount): string
    {
        if (strpbrk($w, ':/') !== false) {
            return $this->escapePhrase($w);
        }

        $firstWildcard = strcspn($w, '*?');
        $hasWildcard = $firstWildcard < strlen($w);
        $prefixLength = $hasWildcard
            ? mb_strlen(substr($w, 0, $firstWildcard))
            : 0;
        if ($hasWildcard
            && $prefixLength >= self::WILDCARD_MIN_PREFIX
            && $wildcardCount < self::WILDCARD_MAX_TERMS
        ) {
            ++$wildcardCount;
            // Escape every special character, then re-enable the wildcards.
            return str_replace(
                ['\\*', '\\?'],
                ['*', '?'],
                $this->select->getHelper()->escapeTerm($w)
            );
        }

        return $this->select->getHelper()->escapeTerm($w);
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
     * Normalize a list of user-typed date values to ISO 8601 strings
     * compatible with Solr date fields, including BCE.
     *
     * Accepts EDTF strings, ISO dates, partial dates (year, year-month), and
     * already-ISO values. For "lower bound" queries ($useMin=true), values are
     * expanded to the earliest moment; for "upper bound", to the latest.
     * Invalid values are dropped.
     */
    protected function edtfValuesToIso(
        array $values,
        bool $useMin
    ): array {
        $hasEdtf = class_exists(\EDTF\EdtfFactory::class);
        if (!$hasEdtf) {
            // Fallback: return values untouched, Solr will parse what it can
            // (standard ISO only).
            return array_filter(
                array_map(fn ($v) => trim((string) $v), $values),
                'strlen'
            );
        }
        static $formatter;
        if ($formatter === null) {
            $formatter = new \SearchSolr\ValueFormatter\Edtf();
        }
        $formatter->setSettings([
            'part' => $useMin ? 'min' : 'max',
        ]);
        $result = [];
        foreach ($values as $v) {
            $s = trim((string) $v);
            if ($s === '') {
                continue;
            }
            $iso = $formatter->format($s);
            if ($iso) {
                $result[] = reset($iso);
            }
        }
        return $result;
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
        // Avoid re-processing if already done.
        if ($this->aliasesAppended) {
            return $this;
        }
        $this->aliasesAppended = true;

        // Search config aliases have priority.
        $aliases = $this->query->getAliases();

        // TODO Check !isset($aliases[$alias]) like before?

        // Get all aliases, then sort them like in fieldToIndex() and more
        // specific resource, then take the first one.
        $aliasFields = [];

        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation $map */
        // The same for specific resources in maps, so reverse maps.
        $allMaps = array_reverse($this->getSolrCore()->mapsOrderedByStructure());
        foreach ($allMaps as $map) {
            $alias = $map->alias();
            if ($alias) {
                $aliasFields[$alias][$map->fieldName()] = $map;
            }
        }

        // Include sibling maps (same source, no explicit alias) as candidates
        // for priority sorting, so _link_ss is considered even without alias.
        // Fix tef:partenaireRecherche with uri doesn't return the index.
        $aliasSources = [];
        foreach ($aliasFields as $alias => $maps) {
            foreach ($maps as $map) {
                $aliasSources[$map->source()][] = $alias;
            }
        }
        foreach ($allMaps as $map) {
            if (!$map->alias()) {
                $source = $map->source();
                if (isset($aliasSources[$source])) {
                    foreach ($aliasSources[$source] as $alias) {
                        $aliasFields[$alias][$map->fieldName()] = $map;
                    }
                }
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

    protected function getSolrCore(): \SearchSolr\Stdlib\SolrCore
    {
        if (!isset($this->solrCore)) {
            if ($this->searchEngine) {
                // The core is a facet of the engine.
                $this->solrCore = new \SearchSolr\Stdlib\SolrCore($this->searchEngine, $this->services);
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
