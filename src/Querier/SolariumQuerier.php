<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016
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

namespace SearchSolr\Querier;

use AdvancedSearch\Querier\AbstractQuerier;
use AdvancedSearch\Querier\Exception\QuerierException;
use AdvancedSearch\Response;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use SearchSolr\Feature\TransliteratorCharacterTrait;
use Solarium\Client as SolariumClient;
use Solarium\QueryType\Select\Query\Query as SolariumQuery;

/**
 * @todo Rewrite the querier to simplify it and to use all solarium features.
 * @todo Remove grouping (item/itemset): this is native in Omeka and most of the time, user want them mixed.
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
            $solariumResultSet = $this->solariumClient->execute($this->solariumQuery);
        } catch (\Exception $e) {
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

        $solrCoreSettings = $this->solrCore->settings();
        $isPublicField = $solrCoreSettings['is_public_field'];
        $resourceNameField = $solrCoreSettings['resource_name_field'];
        $sitesField = $solrCoreSettings['sites_field'] ?? null;
        $indexField = ($solrCoreSettings['index_field'] ?? null) && $this->engine->settingAdapter('index_name')
            ? $solrCoreSettings['index_field']
            : null;

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

        $filters = $this->query->getFilters();
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
                        ->createFilterQuery($name)
                        ->setQuery("$name:$value");
                }
                continue;
            }
            foreach ($values as $key => $value) {
                $value = $this->encloseValue($value);
                if (!strlen($value)) {
                    continue;
                }
                $this->solariumQuery
                    ->createFilterQuery($name . '_' . $key)
                    ->setQuery("$name:$value");
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
                    $from = $normalizeDate($filterValue['from']);
                    $to = $normalizeDate($filterValue['to']);
                } else {
                    $from = $filterValue['from'] ? $filterValue['from'] : '*';
                    $to = $filterValue['to'] ? $filterValue['to'] : '*';
                }
                $this->solariumQuery
                    ->createFilterQuery($name)
                    ->setQuery("$name:[$from TO $to]");
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
        $solariumFacetSet = $this->solariumQuery->getFacetSet();
        if (count($facetFields)) {
            foreach ($facetFields as $facetField) {
                $solariumFacetSet->createFacetField($facetField)->setField($facetField);
            }
        }

        $facetLimit = $this->query->getFacetLimit();
        if ($facetLimit) {
            $solariumFacetSet->setLimit($facetLimit);
        }

        // Only two choices here.
        $facetOrder = $this->query->getFacetOrder();
        if ($facetOrder === 'total asc' || $facetOrder === 'total desc') {
            $solariumFacetSet->setSort(\Solarium\Component\Facet\AbstractField::SORT_COUNT);
        } elseif ($facetOrder === 'alphabetic asc') {
            $solariumFacetSet->setSort(\Solarium\Component\Facet\AbstractField::SORT_INDEX);
        }

        // TODO Manage facet languages for Solr.

        /** @link https://petericebear.github.io/php-solarium-multi-select-facets-20160720/ */
        $activeFacets = $this->query->getActiveFacets();
        if ($activeFacets) {
            foreach ($activeFacets as $name => $values) {
                if (!count($values)) {
                    continue;
                }
                $enclosedValues = $this->encloseValue($values);
                $this->solariumQuery->addFilterQuery([
                    'key' => $name . '-facet',
                    'query' => "$name:$enclosedValues",
                    'tag' => 'exclude',
                ]);
                $solariumFacetSet->createFacetField([
                    'field' => $name,
                    'exclude' => 'exclude',
                ]);
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
        $queryConfig = array_filter($solrCoreSettings['query']);
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

    protected function addFilterQueries(): void
    {
        $filters = $this->query->getFilterQueries();
        if (!$filters) {
            return;
        }

        // Filters are boolean: in or out. nevertheless, the check can be more
        // complex than "equal": before or after a date, like a string, etc.

        foreach ($filters as $name => $values) {
            $fq = '';
            $first = true;
            foreach ($values as $value) {
                // There is no default in Omeka.
                // @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::buildPropertyQuery()
                $type = $value['type'];
                $val = isset($value['value']) ? trim($value['value']) : '';
                if ($val === '' && !in_array($type, ['ex', 'nex'])) {
                    continue;
                }
                if ($first) {
                    $join = '';
                    $first = false;
                } else {
                    $join = isset($value['join']) && $value['join'] === 'or' ? 'OR' : 'AND';
                }
                // "AND/NOT" cannot be used as first.
                if (substr($type, 0, 1) === 'n') {
                    $bool = '(NOT ';
                    $endBool = ')';
                } else {
                    $bool = '(';
                    $endBool = ')';
                }
                switch ($type) {
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
                            $val = $this->regexDiacriticsValue($val, '', '');
                        } else {
                            $val = $this->encloseValue($val);
                        }
                        $fq .= " $join ($name:$bool$val$endBool)";
                        break;

                    // Contains.
                    case 'nin':
                    case 'in':
                        if ($this->fieldIsString($name)) {
                            $val = $this->regexDiacriticsValue($val, '.*', '.*');
                        } else {
                            $val = $this->encloseValueAnd($val);
                        }
                        $fq .= " $join ($name:$bool$val$endBool)";
                        break;

                    // Starts with.
                    case 'nsw':
                    case 'sw':
                        if ($this->fieldIsString($name)) {
                            $val = $this->regexDiacriticsValue($val, '', '.*');
                        } else {
                            $val = $this->encloseValueAnd($val);
                        }
                        $fq .= " $join ($name:$bool$val$endBool)";
                        break;

                    // Ends with.
                    case 'new':
                    case 'ew':
                        if ($this->fieldIsString($name)) {
                            $val = $this->regexDiacriticsValue($val, '.*', '');
                        } else {
                            $val = $this->encloseValueAnd($val);
                        }
                        $fq .= " $join ($name:$bool$val$endBool)";
                        break;

                    // Matches.
                    case 'nma':
                    case 'ma':
                        // Matches is already an regular expression, so just set
                        // it. Note that Solr can manage only a small part of
                        // regex and anchors are added by default.
                        // TODO Add // or not?
                        // TODO Escape regex for regexes…
                        $val = $this->fieldIsString($name) ? $val : $this->encloseValue($val);
                        $fq .= " $join ($name:$bool$val$endBool)";
                        break;

                    // In list.
                    case 'nlist':
                    case 'list':
                        // TODO Manage api filter in list (not used in standard forms).
                        break;

                    // Resource with id.
                    case 'nres':
                    case 'res':
                        // Like equal, but the field must be an integer.
                        if (substr($name, -2) === '_i' || substr($name, -3) === '_is') {
                            $val = (int) $val;
                            $fq .= " $join ($name:$bool$val$endBool)";
                        }
                        break;

                    // Exists (has a value).
                    case 'nex':
                        $val = $this->encloseValue($val);
                        $fq .= " $join (-$name:$val)";
                        break;
                    case 'ex':
                        $val = $this->encloseValue($val);
                        $fq .= " $join (+$name:$val)";
                        break;

                    default:
                        throw new \AdvancedSearch\Querier\Exception\QuerierException(sprintf(
                            'Search type "%s" is not managed.', // @translate
                            $type
                        ));
                }
            }
            $this->solariumQuery
                // The name must be different from simple filter, or merge them.
                ->createFilterQuery($name . '-fq')
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
            }
        }
        return $this->solrCore;
    }

    protected function getClient(): SolariumClient
    {
        if (!isset($this->solariumClient)) {
            $this->solariumClient = $this->getSolrCore()->solariumClient();
        }
        return $this->solariumClient;
    }
}
