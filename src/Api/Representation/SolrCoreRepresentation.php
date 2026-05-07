<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2018-2026
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

namespace SearchSolr\Api\Representation;

use Common\Stdlib\PsrMessage;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use SearchSolr\Schema\Schema;
use Solarium\Client as SolariumClient;
use Solarium\Exception\HttpException as SolariumException;
use Solarium\QueryType\Select\Query\Query as SolariumQuery;

// TODO Use Laminas event manager when #12 will be merged.
// @see https://github.com/laminas/laminas-eventmanager/pull/12

class SolrCoreRepresentation extends AbstractEntityRepresentation
{
    /**
     * @var \SearchSolr\Entity\SolrCore
     */
    protected $resource;

    /**
     * @var SolariumClient
     */
    protected $solariumClient;

    /**use Solarium\Exception\HttpException as SolariumException;

     * {@inheritDoc}
     */
    public function getJsonLdType()
    {
        return 'o:SolrCore';
    }

    public function getJsonLd()
    {
        $entity = $this->resource;
        return [
            'o:name' => $entity->getName(),
            'o:settings' => $entity->getSettings(),
        ];
    }

    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        $params = [
            'action' => $action,
            'id' => $this->id(),
        ];
        $options = [
            'force_canonical' => $canonical,
        ];

        return $url('admin/search/solr/core-id', $params, $options);
    }

    public function name(): string
    {
        return $this->resource->getName();
    }

    public function settings(): array
    {
        return $this->resource->getSettings();
    }

    public function backupMaps(): ?array
    {
        return $this->resource->getBackupMaps();
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function setting($name, $default = null)
    {
        $settings = $this->resource->getSettings();
        return $settings[$name] ?? $default;
    }

    public function clientSettings(): array
    {
        // Currently, the keys from the old module Solr are kept.
        // TODO Convert settings during from old module Solr before saving.
        $clientSettings = (array) $this->setting('client', []);
        $clientSettings['endpoint'] = $this->endpoint();
        return $clientSettings + [
            'scheme' => null,
            'host' => null,
            'port' => null,
            'path' => '/',
            // Core and collection have same meaning on a standard solr.
            // 'collection' => null,
            'core' => null,
            'username' => null,
            'password' => null,
        ];
    }

    /**
     * @see \Solarium\Core\Client\Endpoint
     */
    public function endpoint(): array
    {
        $clientSettings = $this->setting('client') ?: [];
        if (!is_array($clientSettings)) {
            $clientSettings = (array) $clientSettings;
        }
        return array_replace(
            [
                // Solarium manages multiple endpoints, so the endpoint should
                // be identified, so the id is used.
                'key' => 'solr_' . $this->id(),
                'scheme' => null,
                'host' => null,
                'port' => null,
                'path' => '/',
                // "core" and "collection" have same meaning on a standard solr,
                // even if "collection" is designed for SolrCloud.
                'core' => null,
                // For Solr Cloud.
                // 'leader' => false,
                'collection' => null,
                // Can be set separately via getEndpoint()->setAuthentication().
                'username' => null,
                'password' => null,
            ],
            $clientSettings
        );
    }

    public function solariumClient(): ?SolariumClient
    {
        if (!isset($this->solariumClient)) {
            try {
                $services = $this->getServiceLocator();
                $this->solariumClient = $services->get('SearchSolr\Solarium\Client');
                $this->solariumClient
                    // Set the endpoint as default.
                    ->createEndpoint($this->endpoint(), true);
            } catch (\Solarium\Exception\InvalidArgumentException $e) {
                // Nothing.
            }
        }
        return $this->solariumClient;
    }

    public function clientUrl(): string
    {
        $settings = $this->clientSettings();
        $user = empty($settings['username']) ? '' : $settings['username'];
        $pass = empty($settings['password']) ? '' : ':' . $settings['password'];
        $credentials = ($user || $pass) ? $user . $pass . '@' : '';
        return $settings['scheme'] . '://' . $credentials . $settings['host'] . ':' . $settings['port'] . '/solr/' . $settings['core'];
    }

    /**
     * Get the url to the core without credentials.
     */
    public function clientUrlAdmin(): string
    {
        $settings = $this->clientSettings();
        return $settings['scheme'] . '://' . $settings['host'] . ':' . $settings['port'] . '/solr/' . $settings['core'];
    }

    public function clientUrlAdminBoard(): string
    {
        $settings = $this->clientSettings();
        if ($settings['host'] === 'localhost' || $settings['host'] === '127.0.0.1') {
            /** @var \Laminas\View\Helper\ServerUrl $serverUrl */
            $serverUrl = $this->getViewHelper('ServerUrl');
            $settings['host'] = $serverUrl->getHost();
        }
        return $settings['scheme'] . '://' . $settings['host'] . ':' . $settings['port'] . '/solr/#/' . $settings['core'];
    }

    /**
     * Check if Solr is working.
     *
     * @return bool|PsrMessage
     */
    public function status(bool $returnMessage = false)
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $translator = $services->get('MvcTranslator');

        if (!file_exists(dirname(__DIR__, 3) . '/vendor/solarium/solarium/src/Client.php')) {
            $message = new PsrMessage(
                'The composer library "{library}" is not installed. See readme.', // @translate
                ['library' => 'Solarium']
            );
            $logger->err($message->getMessage(), $message->getContext());
            return $returnMessage ? $message->setTranslator($translator) : false;
        }

        $clientSettings = $this->clientSettings();
        $client = $this->solariumClient();

        if (!$client) {
            $message = new PsrMessage(
                'Solr core #{solr_core_id}: incorrect or incomplete configuration.', // @translate
                ['solr_core_id' => $this->id()]
            );
            $logger->err($message->getMessage(), $message->getContext());
            return $returnMessage ? $message->setTranslator($translator) : false;
        }

        try {
            // Create a ping query.
            $query = $client->createPing();
            // Execute the ping query. Result is not checked, bug use exception.
            @$client->ping($query);
        } catch (SolariumException $e) {
            if ($e->getCode() === 404) {
                $message = new PsrMessage('Solr core not found. Check your url.'); // @translate
                $logger->err($message->getMessage());
                return $returnMessage ? $message->setTranslator($translator) : false;
            }
            if ($e->getCode() === 401) {
                $message = new PsrMessage('Solr core not found or unauthorized. Check your url and your credentials.'); // @translate
                $logger->err($message->getMessage());
                return $returnMessage ? $message->setTranslator($translator) : false;
            }
            $message = new PsrMessage(
                'Solr core #{solr_core_id}: {message}', // @translate
                ['solr_core_id' => $this->id(), 'message' => $e->getMessage()]
            );
            $logger->err($message->getMessage(), $message->getContext());
            return $returnMessage ? $e->getMessage() : false;
        } catch (\Throwable $e) {
            $message = new PsrMessage(
                'Solr core #{solr_core_id}: {message}', // @translate
                ['solr_core_id' => $this->id(), 'message' => $e->getMessage()]
            );
            $logger->err($message->getMessage(), $message->getContext());
            return $returnMessage ? $message->setTranslator($translator) : false;
        }

        // Check the schema too, in particular when there are credentials, but
        // the certificate is expired or incomplete.
        try {
            $this->schema()->getSchema();
        } catch (SolariumException $e) {
            $message = new PsrMessage(
                'Solr core #{solr_core_id} enpoint: {message}', // @translate
                ['solr_core_id' => $this->id(), 'message' => $e->getMessage()]
            );
            $logger->err($message->getMessage(), $message->getContext());
            return $returnMessage ? $message->setTranslator($translator) : false;
        } catch (\Throwable $e) {
            $message = new PsrMessage(
                'Solr core #{solr_core_id}: {message}', // @translate
                ['solr_core_id' => $this->id(), 'message' => $e->getMessage()]
            );
            $logger->err($message->getMessage(), $message->getContext());
            return $returnMessage ? $message->setTranslator($translator) : false;
        }

        // Check if the config bypass certificate check.
        if (!empty($clientSettings['secure']) && !empty($clientSettings['bypass_certificate_check'])) {
            $logger->warn('Solr: the config bypasses the check of the certificate.'); // @translate
            $message = new PsrMessage(
                'OK (warning: check of certificate disabled)' // @translate
            );
            return $returnMessage ? $message->setTranslator($translator) : true;
        }

        $message = new PsrMessage(
            'OK' // @translate
        );
        return $returnMessage ? $message->setTranslator($translator) : true;
    }

    public function resourceMapUrl(?string $resourceName, ?string $action = null, $canonical = false): string
    {
        $url = $this->getViewHelper('Url');
        $params = [
            'action' => $action,
            'core-id' => $this->id(),
            'resource-name' => $resourceName,
        ];
        $options = [
            'force_canonical' => $canonical,
        ];
        return $url('admin/search/solr/core-id-map-resource', $params, $options);
    }

    /**
     * Get the schema for the core.
     */
    public function schema():Schema
    {
        return $this->getServiceLocator()
            ->build(Schema::class, ['solr_core' => $this]);
    }

    public function getSchemaField($field)
    {
        return $this->schema()->getField($field);
    }

    public function schemaSupport($support): array
    {
        switch ($support) {
            case 'drupal':
                $fields = [
                    // Static fields.
                    'engine_id' => null,
                    'site' => null,
                    'hash' => null,
                    'timestamp' => null,
                    'boost_document' => null,
                    'boost_term' => null,
                    // Dynamic fields.
                    'ss_search_api_id' => null,
                    'ss_search_api_datasource' => null,
                    'ss_search_api_language' => null,
                    'sm_context_tags' => null,
                ];
                break;
            default:
                return [];
        }

        $schema = $this->schema();
        foreach (array_keys($fields) as $fieldName) {
            $field = $schema->getField($fieldName);
            $fields[$fieldName] = !empty($field);
        }

        return $fields;
    }

    /**
     * Get the solr / omeka mappings by id.
     *
     * @return \SearchSolr\Api\Representation\SolrMapRepresentation[]
     */
    public function maps(): array
    {
        static $maps;

        if ($maps === null) {
            $maps = [];
            $mapAdapter = $this->getAdapter('solr_maps');
            /** @var \SearchSolr\Entity\SolrMap $mapEntity */
            $sort = [];
            foreach ($this->resource->getMaps() as $mapEntity) {
                // Sort "resources" after "generic".
                $mapId = $mapEntity->getId();
                $mapName = $mapEntity->getResourceName();
                $sort[$mapId] = $mapName;
                $maps[$mapId] = $mapAdapter->getRepresentation($mapEntity);
            }
            uasort($sort, function ($a, $b) {
                if ($a === $b) {
                    return 0;
                } elseif ($a === 'generic') {
                    return -1;
                } elseif ($b === 'generic') {
                    return 1;
                } elseif ($a === 'resources') {
                    return -1;
                } elseif ($b === 'resources') {
                    return 1;
                } else {
                    // item_sets, items, media.
                    return $a <=> $b;
                }
            });
            $maps = array_replace($sort, $maps);
        }

        return $maps;
    }

    /**
     * Get solr / omeka mappings by id ordered by field name and structurally.
     *
     *  The structure is: generic, then resource, then specific resource type.
     *
     * @return \SearchSolr\Api\Representation\SolrMapRepresentation[]
     */
    public function mapsOrderedByStructure(): array
    {
        static $maps;

        if ($maps === null) {
            $maps = $this->mapsByResourceName();
            foreach ($maps as &$mapss) {
                usort($mapss, fn ($a, $b) => $a->fieldName() <=> $b->fieldName());
            }
            if ($maps) {
                $maps = array_merge(...array_values($maps));
            }
        }

        return $maps;
    }

    /**
     * Get the solr / omeka mappings by resource type.
     *
     * @param string $resourceName
     * @return \SearchSolr\Api\Representation\SolrMapRepresentation[]
     */
    public function mapsByResourceName($resourceName = null): array
    {
        static $maps;

        if ($maps === null) {
            $maps = [
                'generic' => [],
                'resources' => [],
            ];
            $mapAdapter = $this->getAdapter('solr_maps');
            /** @var \SearchSolr\Entity\SolrMap $mapEntity */
            foreach ($this->resource->getMaps() as $mapEntity) {
                $maps[$mapEntity->getResourceName()][] = $mapAdapter->getRepresentation($mapEntity);
            }
            $maps = array_filter($maps);
        }

        if (!$resourceName) {
            return $maps;
        }

        if ($resourceName === 'generic') {
            return $maps['generic'] ?? [];
        }

        if (!in_array($resourceName, ['items', 'item_sets', 'media'])) {
            return array_merge(
                $maps['generic'] ?? [],
                $maps[$resourceName] ?? []
            );
        }

        return array_merge(
            $maps['generic'] ?? [],
            $maps['resources'] ?? [],
            $maps[$resourceName] ?? []
        );
    }

    /**
     * Get the solr maps by field name and optionnaly by resource name.
     *
     * @return \SearchSolr\Api\Representation\SolrMapRepresentation[]
     */
    public function mapsByFieldName(?string $fieldName = null, ?string $resourceName = null): array
    {
        $result = [];

        $maps = $resourceName
            ? $this->mapsByResourceName($resourceName)
            : $this->maps();

        if ($fieldName) {
            foreach ($maps as $map) {
                if ($map->fieldName() === $fieldName) {
                    $result[] = $map;
                }
            }
            return $result;
        }

        foreach ($maps as $map) {
            $result[$map->fieldName()][] = $map;
        }

        return $result;
    }

    /**
     * Get the solr maps by source and optionnaly by resource name.
     *
     * Warning: multiple maps can have the same source for various usage.
     *
     * @return \SearchSolr\Api\Representation\SolrMapRepresentation[]
     */
    public function mapsBySource(string $source, $resourceName = null): array
    {
        $result = [];
        $maps = $resourceName
            ? $this->mapsByResourceName($resourceName)
            : $this->maps();
        foreach ($maps as $map) {
            if ($map->source() === $source) {
                $result[] = $map;
            }
        }
        return $result;
    }

    public function queryDocuments(string $resourceName, array $ids): array
    {
        $ids = array_map('intval', $ids);
        if (!$resourceName || !$ids) {
            return [];
        }

        // Init solarium.
        $this->solariumClient();

        $resourceTypeField = $this->mapsBySource('resource_name', 'generic');
        $resourceTypeField = $resourceTypeField ? (reset($resourceTypeField))->fieldName() : null;
        if (!$resourceTypeField) {
            return [];
        }

        $resourceIdField = $this->mapsBySource('o:id', 'generic');
        $resourceIdField = $resourceIdField ? (reset($resourceIdField))->fieldName() : null;
        if (!$resourceIdField) {
            return [];
        }

        /** @var \Solarium\QueryType\Select\Query\Query $query */
        $query = $this->solariumClient->createSelect();
        $query
            ->addFilterQuery([
                'key' => $resourceTypeField,
                'query' => "$resourceTypeField:$resourceName",
            ])
            // When index is not ready, output is wrong.
            ->addFilterQuery([
                'key' => $resourceIdField,
                'query' => $resourceIdField . ':' . implode(' OR ', $ids),
            ])
            ->addSort($resourceIdField, SolariumQuery::SORT_ASC)
            // Rows is 10 by default and 0 or -1 are not working.
            ->setRows(1000000000);
        $resultSet = $this->solariumClient->select($query);
        $data = $resultSet->getData();
        $docs = $data['response']['docs'] ?? [];

        return $docs;

        /*
        // TODO Reorder by ids? Check for duplicate resources first.
        // Order by the original ids, but there may be multiple documents with
        // the same id, in particular with a bad indexation or when documents
        // are not cleaned.
        if (count($docs) <= 1) {
            return $docs;
        }

        $result = [];
        foreach ($docs as $doc) {
            $result[$doc[$resourceIdField]] = $doc;
        }

        return array_values(array_replace(array_fill_keys($ids, []), $result));
        */
    }

    public function queryResourceTitles(?string $resourceName): array
    {
        if (!$resourceName) {
            return [];
        }

        // Init solarium.
        $this->solariumClient();

        $resourceTypeField = $this->mapsBySource('resource_name', 'generic');
        $resourceTypeField = $resourceTypeField ? (reset($resourceTypeField))->fieldName() : null;
        if (!$resourceTypeField) {
            return [];
        }

        $resourceIdField = $this->mapsBySource('o:id', 'generic');
        $resourceIdField = $resourceIdField ? (reset($resourceIdField))->fieldName() : null;
        if (!$resourceIdField) {
            return [];
        }

        /** @var \Solarium\QueryType\Select\Query\Query $query */
        $query = $this->solariumClient->createSelect();
        $query
            ->addFilterQuery([
                'key' => $resourceTypeField,
                'query' => "$resourceTypeField:$resourceName",
            ])
            // When index is not ready, output is wrong.
            ->addFilterQuery([
                'key' => $resourceIdField,
                'query' => "$resourceIdField:*",
            ])
            ->setFields([$resourceIdField, $resourceTypeField])
            ->addSort($resourceIdField, SolariumQuery::SORT_ASC)
            // Rows is 10 by default and 0 or -1 are not working.
            ->setRows(1000000000);
        $resultSet = $this->solariumClient->select($query);
        $data = $resultSet->getData();
        return isset($data['response']['docs'])
            ? array_column($data['response']['docs'], $resourceTypeField, $resourceIdField)
            : [];
    }

    /**
     * Warning: unlike in querier, the field isn't an alias but a real index.
     *
     * @todo Merge queryValues() of SolariumQuerier with SolrRepresentation.
     *
     * Adapted:
     * @see \SearchSolr\Api\Representation\SolrCoreRepresentation::queryValues()
     * @see \SearchSolr\Querier\SolariumQuerier::queryValues()
     *
     * @see \SearchSolr\Api\Representation\SolrCoreRepresentation::queryValuesCount()
     *
     * {@inheritDoc}
     * @see \AdvancedSearch\Querier\AbstractQuerier::queryValues()
     */
    public function queryValues(?string $field): array
    {
        if (!$field) {
            return [];
        }

        // Init solarium.
        $this->solariumClient();

        $fields = [$field];

        $query = $this->solariumClient->createTerms();
        $query
            ->setFields($fields)
            ->setSort(\Solarium\Component\Facet\JsonTerms::SORT_INDEX_ASC)
            ->setLimit(-1)
            // Only used values. Anyway, by default there is no predefined list.
            ->setMinCount(1);
        $resultSet = $this->solariumClient->terms($query);
        $terms = $resultSet->getTerms($field);
        return array_keys($terms);
    }

    /**
     * Warning: unlike queryValues, the field isn't an alias but a real index.
     *
     * @todo Merge queryValuesCount() of SolariumQuerier with SolrRepresentation.
     *
     * Adapted:
     * @see \SearchSolr\Api\Representation\SolrCoreRepresentation::queryValuesCount()
     * @see \SearchSolr\Querier\SolariumQuerier::queryValuesCount()
     */
    public function queryValuesCount(?string $field, ?string $sort = 'index asc'): array
    {
        if (!$field) {
            return [];
        }

        // Init solarium.
        $this->solariumClient();

        $fields = [$field];

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
        $terms = $resultSet->getTerms($field);

        // TODO The sort does not seem to work, so for now resort locally.
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

        return $terms;
    }

    /**
     * Get all search indexes related to the core, indexed by id.
     *
     * @return \AdvancedSearch\Api\Representation\SearchEngineRepresentation[]
     */
    public function searchEngines(): array
    {
        // TODO Use entity manager to simplify search of indexes from core.
        $result = [];
        /** @var \AdvancedSearch\Api\Representation\SearchEngineRepresentation[] $searchEngines */
        $searchEngines = $this->getServiceLocator()->get('Omeka\ApiManager')
            ->search('search_engines', ['adapter' => 'solarium'])->getContent();
        $id = $this->id();
        foreach ($searchEngines as $searchEngine) {
            if ($searchEngine->settingEngineAdapter('solr_core_id') == $id) {
                $result[$searchEngine->id()] = $searchEngine;
            }
        }
        return $result;
    }

    /**
     * Find all search pages related to the core, indexed by id.
     *
     * @return \AdvancedSearch\Api\Representation\SearchConfigRepresentation[]
     */
    public function searchConfigs(): array
    {
        // TODO Use entity manager to simplify search of pages from core.
        $result = [];
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach (array_keys($this->searchEngines()) as $searchEngineId) {
            $searchConfigs = $api->search('search_configs', ['engine_id' => $searchEngineId])->getContent();
            foreach ($searchConfigs as $searchConfig) {
                $result[$searchConfig->id()] = $searchConfig;
            }
        }
        return $result;
    }

    /**
     * Check if all required maps are managed by the core.
     *
     * List of fields, adapted:
     * @see \SearchSolr\Api\Representation\SolrCoreRepresentation::missingRequiredMaps()
     * @see \SearchSolr\Job\ReduceSolrFields::perform()
     */
    public function missingRequiredMaps(): ?array
    {
        // Check if the specified fields are available.
        // Value is "is required", but not used for now.
        $fields = [
            // In fact, only resource name and id are really required.
            'resource_name' => true,
            'o:id' => true,
            // Public, owner and site are used in many cases.
            'is_public' => true,
            'owner/o:id' => true,
            'site/o:id' => true,
            // 'search_index' => false,
        ];

        $unavailableFields = [];
        foreach (array_keys($fields) as $source) {
            /** @var \SearchSolr\Api\Representation\SolrMapRepresentation[] $maps */
            $maps = $this->mapsBySource($source);
            if (!count($maps)) {
                $unavailableFields[] = $source;
            }
        }

        // TODO Warning: use the source name, not a static index name.
        // Name is not really required, but simplify investigation.
        $fields = [
            'name_s' => true,
            'ss_name' => true,
        ];
        $checks = [];
        foreach (array_keys($fields) as $fieldName) {
            /** @var \SearchSolr\Api\Representation\SolrMapRepresentation[] $maps */
            $maps = $this->mapsByFieldName($fieldName);
            if (!count($maps)) {
                $checks[] = $fieldName;
            }
        }
        if (count($checks) > 1) {
            // TODO Drupal info for ss_name.
            $unavailableFields[] = 'name_s';
        }

        // TODO Required map or alias for item_set_id (in particular for page item set redirected to search).

        return $unavailableFields ?: null;
    }

    /**
     * Check if a suggester exists in Solr config.
     */
    public function hasSuggester(string $suggesterName): bool
    {
        $config = $this->getSolrConfig();
        if (!$config) {
            return false;
        }

        // Check in searchComponent definitions.
        $searchComponents = $config['config']['searchComponent'] ?? [];
        foreach ($searchComponents as $component) {
            if (($component['class'] ?? '') === 'solr.SuggestComponent') {
                $suggesters = $component['suggester'] ?? [];
                // Can be a single suggester or an array of suggesters.
                if (!is_array($suggesters) || isset($suggesters['name'])) {
                    $suggesters = [$suggesters];
                }
                foreach ($suggesters as $suggester) {
                    if (($suggester['name'] ?? '') === $suggesterName) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Create or update a single Solr SuggestComponent with multiple suggesters.
     *
     * Solr requires all suggesters to live in one searchComponent so that the
     * `suggest.dictionary` parameter can reach any of them.
     * Furthermore, creating separate components per field slows the start of
     * solr because all fields should be rebuilt.
     *
     * @param array $suggesters List of suggester definitions, each with keys:
     *   - name: suggester/dictionary name
     *   - field: Solr field name
     *   - lookupImpl: (optional) defaults to AnalyzingInfixLookupFactory
     *   - suggestAnalyzerFieldType: (optional) defaults to text_general
     *   - buildOnCommit: (optional) defaults to "false"
     * @param string $componentName Name of the single searchComponent.
     * @return bool|string True on success, error message on failure.
     */
    public function updateSuggestComponent(
        array $suggesters,
        string $componentName = 'omeka_suggest'
    ) {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $configUrl = $this->clientUrl() . '/config';

        // Normalize each suggester definition.
        $suggesterDefs = [];
        foreach ($suggesters as $suggester) {
            $name = $suggester['name'];
            $suggesterDefs[] = [
                'name' => $name,
                'lookupImpl' => $suggester['lookupImpl']
                    ?? 'AnalyzingInfixLookupFactory',
                'field' => $suggester['field'],
                'suggestAnalyzerFieldType' => $suggester['suggestAnalyzerFieldType']
                    ?? 'text_suggest',
                // Solr Config API requires booleans as strings.
                'buildOnCommit' => !empty($suggester['buildOnCommit'])
                    ? 'true' : 'false',
            ];
        }

        $component = [
            'name' => $componentName,
            'class' => 'solr.SuggestComponent',
            'suggester' => count($suggesterDefs) === 1
                ? reset($suggesterDefs)
                : $suggesterDefs,
        ];

        // Ensure the text_suggest field type exists in schema.
        if (!$this->ensureSuggestFieldType()) {
            $logger->err(
                'SearchSolr: Failed to create text_suggest field type.' // @translate
            );
            return 'Failed to create text_suggest field type';
        }

        // Delete old suggest components from the overlay.
        $this->deleteOverlaySuggestComponents($componentName);

        // Reload core to release old IndexWriter locks held by
        // AnalyzingInfixSuggesters on the default directory.
        $this->reloadCore();
        if (!$this->waitForCoreReady()) {
            $logger->warn(
                'SearchSolr: Core not ready after reload, continuing anyway.' // @translate
            );
        }

        // Create the component fresh.
        $payload = json_encode([
            'add-searchcomponent' => $component,
        ]);
        $result = $this->postToSolrConfig($configUrl, $payload);
        if ($result !== true) {
            $logger->err(
                'SearchSolr: Failed to create suggest component: {error}', // @translate
                ['error' => is_string($result) ? $result : 'unknown']
            );
            return $result;
        }

        if (!$this->waitForCoreReady()) {
            $logger->warn(
                'SearchSolr: Core not ready after creating component.' // @translate
            );
        }

        return true;
    }

    /**
     * Delete all suggest-related searchComponents from the config overlay.
     *
     * Use a single http request with duplicate json keys (Solr's Noggit parser
     * supports this).
     */
    protected function deleteOverlaySuggestComponents(
        string $currentComponentName
    ): void {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $configUrl = $this->clientUrl() . '/config';

        // Read only the overlay to avoid trying to delete
        // components defined in solrconfig.xml.
        $overlay = $this->getSolrConfigOverlay();
        $components = $overlay['searchComponent'] ?? [];
        $toDelete = [];
        foreach ($components as $name => $comp) {
            $class = $comp['class'] ?? '';
            if ($class === 'solr.SuggestComponent') {
                $toDelete[] = $name;
            }
        }

        if (empty($toDelete)) {
            return;
        }

        $logger->info(
            'SearchSolr: Deleting {count} old suggest components.', // @translate
            ['count' => count($toDelete)]
        );

        $parts = [];
        foreach ($toDelete as $name) {
            $parts[] = '"delete-searchcomponent":'
                . json_encode($name);
        }
        $payload = '{' . implode(',', $parts) . '}';
        $this->postToSolrConfig($configUrl, $payload);
    }

    /**
     * Update the /suggest handler to reference a single suggest component.
     *
     * @return bool|string True on success, error message on failure.
     */
    public function updateSuggestHandler(
        string $componentName = 'omeka_suggest'
    ) {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $configUrl = $this->clientUrl() . '/config';

        $handler = [
            'name' => '/suggest',
            'class' => 'solr.SearchHandler',
            'startup' => 'lazy',
            'defaults' => [
                'suggest' => 'true',
                'suggest.count' => '10',
            ],
            'components' => [$componentName],
        ];

        $payload = json_encode(['add-requesthandler' => $handler]);
        $result = $this->postToSolrConfig($configUrl, $payload);
        if ($result !== true) {
            $payload = json_encode(['update-requesthandler' => $handler]);
            $result = $this->postToSolrConfig($configUrl, $payload);
            if ($result !== true) {
                $logger->warn(
                    'SearchSolr: Failed to create/update suggest handler: {error}', // @translate
                    ['error' => is_string($result) ? $result : 'unknown']
                );
                return $result;
            }
        }

        return true;
    }

    /**
     * Build/rebuild suggester dictionaries.
     *
     * Uses a direct http post to the /suggest handler. Solr builds all
     * specified dictionaries sequentially in a single request (no lock
     * conflicts between suggesters).
     *
     * @param array $names Dictionary names to build. If empty, builds the
     *   "default" dictionary only.
     */
    public function buildSuggester(array $names = []): bool
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        $params = 'suggest.build=true&suggest.q=_';
        foreach ($names as $name) {
            $params .= '&suggest.dictionary='
                . urlencode($name);
        }

        $url = $this->clientUrl() . '/suggest';
        $headers = 'Content-Type: application/x-www-form-urlencoded';
        $headers .= $this->basicAuthHeader();
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $params,
                // Building many dictionaries may take a long time.
                'timeout' => 3600,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $logger->err(
                'SearchSolr: No response from suggest handler.' // @translate
            );
            return false;
        }
        $result = json_decode($response, true);
        if (isset($result['error'])) {
            $logger->err(
                'SearchSolr: Failed to build suggester: {error}', // @translate
                ['error' => $result['error']['msg'] ?? 'unknown']
            );
            return false;
        }
        return true;
    }

    /**
     * Reload the Solr core to release orphaned locks.
     *
     * Uses the CoreAdmin API which releases internal write locks left by
     * crashed or interrupted processes.
     */
    public function reloadCore(): bool
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        $settings = $this->clientSettings();
        $coreName = $settings['core'] ?? null;
        if (!$coreName) {
            $logger->err('SearchSolr: Cannot reload: no core name.'); // @translate
            return false;
        }
        $adminUrl = $settings['scheme'] . '://'
            . $settings['host'] . ':' . $settings['port']
            . '/solr/admin/cores?action=RELOAD&core='
            . urlencode($coreName);
        $authHeader = $this->basicAuthHeader();
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $authHeader ? trim($authHeader) : null,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($adminUrl, false, $context);
        if ($response === false) {
            $logger->err(
                'SearchSolr: Core reload failed: no response from {url}.', // @translate
                ['url' => preg_replace('~://[^@]+@~', '://***@', $adminUrl)]
            );
            return false;
        }
        $result = json_decode($response, true);
        if (!empty($result['error'])) {
            $logger->err(
                'SearchSolr: Core reload error: {error}', // @translate
                ['error' => $result['error']['msg'] ?? 'unknown']
            );
            return false;
        }
        $logger->info(
            'SearchSolr: Core "{core}" reloaded successfully.', // @translate
            ['core' => $coreName]
        );
        return true;
    }

    /**
     * Restart the core via UNLOAD + CREATE (Core Admin API).
     *
     * More thorough than reloadCore(): fully closes the core, releasing all
     * IndexWriter locks (e.g. from AnalyzingInfix-Suggester), before
     * re-registering it. Falls back to reloadCore() on error.
     */
    public function restartCore(): bool
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        $settings = $this->clientSettings();
        $coreName = $settings['core'] ?? null;
        if (!$coreName) {
            $logger->err('SearchSolr: Cannot restart: no core name.'); // @translate
            return false;
        }

        $baseAdminUrl = $settings['scheme'] . '://'
            . $settings['host'] . ':' . $settings['port']
            . '/solr/admin/cores';

        $authHeader = $this->basicAuthHeader();
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $authHeader ? trim($authHeader) : null,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);

        // Get core status to find instanceDir.
        $statusUrl = $baseAdminUrl
            . '?action=STATUS&core=' . urlencode($coreName);
        $response = @file_get_contents(
            $statusUrl, false, $context
        );
        if ($response === false) {
            $logger->warn('SearchSolr: Cannot get core status, falling back to reload.'); // @translate
            return $this->reloadCore();
        }
        $result = json_decode($response, true);
        $instanceDir = $result['status'][$coreName]['instanceDir']
            ?? null;
        if (!$instanceDir) {
            $logger->warn('SearchSolr: Cannot find instanceDir, falling back to reload.'); // @translate
            return $this->reloadCore();
        }

        // UNLOAD the core (releases all IndexWriter locks).
        $unloadUrl = $baseAdminUrl
            . '?action=UNLOAD&core=' . urlencode($coreName);
        $response = @file_get_contents(
            $unloadUrl, false, $context
        );
        if ($response === false
            || !empty(json_decode($response, true)['error'])
        ) {
            $logger->warn('SearchSolr: Core unload failed, falling back to reload.'); // @translate
            return $this->reloadCore();
        }
        $logger->info(
            'SearchSolr: Core "{core}" unloaded.', // @translate
            ['core' => $coreName]
        );

        // Recreate the core from its instanceDir.
        $createUrl = $baseAdminUrl
            . '?action=CREATE&name=' . urlencode($coreName)
            . '&instanceDir=' . urlencode($instanceDir);
        $response = @file_get_contents(
            $createUrl, false, $context
        );
        if ($response === false
            || !empty(json_decode($response, true)['error'])
        ) {
            // Retry once after a short wait.
            sleep(2);
            $response = @file_get_contents(
                $createUrl, false, $context
            );
            if ($response === false
                || !empty(json_decode($response, true)['error'])
            ) {
                $logger->err('SearchSolr: Core recreate failed after unload. Manual recovery may be needed.'); // @translate
                return false;
            }
        }

        $logger->info(
            'SearchSolr: Core "{core}" restarted successfully.', // @translate
            ['core' => $coreName]
        );
        return true;
    }

    /**
     * Check number of fields in core against the configured maxFields limit.
     *
     * @return array Associative array with keys "numFields", "maxFields" and
     * "exceeded" (bool), or null if unavailable.
     */
    public function fieldLimitStatus(): ?array
    {
        $url = $this->clientUrl();

        // Get current field count via luke api.
        $lukeUrl = $url . '/admin/luke?numTerms=0';
        $authHeader = $this->basicAuthHeader();
        $lukeResponse = @file_get_contents($lukeUrl, false,
            stream_context_create(['http' => [
                'timeout' => 10,
                'header' => $authHeader ? trim($authHeader) : null,
            ]]));
        if ($lukeResponse === false) {
            return null;
        }
        $luke = json_decode($lukeResponse, true);
        $numFields = is_array($luke) && isset($luke['fields'])
            ? count($luke['fields'])
            : null;
        if ($numFields === null) {
            return null;
        }

        // Get maxFields from solr config api.
        $maxFields = null;
        $config = $this->getSolrConfig();
        if ($config) {
            $processors = $config['config']['updateProcessor'] ?? [];
            foreach ($processors as $proc) {
                if (($proc['class'] ?? '') === 'solr.NumFieldLimitingUpdateRequestProcessorFactory') {
                    $maxFields = (int) ($proc['maxFields'] ?? 0) ?: null;
                    break;
                }
            }
        }

        return [
            'numFields' => $numFields,
            'maxFields' => $maxFields,
            'exceeded' => $maxFields !== null
                && $numFields > $maxFields,
        ];
    }

    /**
     * Get Solr config via API.
     */
    protected function getSolrConfig(): ?array
    {
        return $this->getSolrConfigEndpoint('/config');
    }

    /**
     * Get Solr config overlay (only user-added entries).
     */
    protected function getSolrConfigOverlay(): array
    {
        $data = $this->getSolrConfigEndpoint('/config/overlay');
        return $data['overlay'] ?? [];
    }

    protected function getSolrConfigEndpoint(string $path): ?array
    {
        $url = $this->clientUrl() . $path;

        $headers = 'Content-Type: application/json';
        $headers .= $this->basicAuthHeader();
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Poll the core until it responds to a ping or a timeout is reached.
     *
     * Used after reload or config changes to ensure the core is fully
     * initialized before the next operation.
     *
     * @param int $maxWait Maximum seconds to wait.
     * @param int $interval Seconds between polls.
     * @return bool True if the core is ready, false on timeout.
     */
    public function waitForCoreReady(
        int $maxWait = 300,
        int $interval = 3
    ): bool {
        $client = $this->solariumClient();
        if (!$client) {
            return false;
        }
        $ping = $client->createPing();

        $deadline = time() + $maxWait;
        while (time() < $deadline) {
            try {
                $client->ping($ping);
                return true;
            } catch (\Throwable $e) {
                // Core not ready yet.
            }
            sleep($interval);
        }

        return false;
    }

    /**
     * Build an Authorization header for Solr BasicAuth, if configured.
     *
     * Returns an empty string when no credentials are set, or a string like
     * "\r\nAuthorization: Basic ..." ready to append to an existing header
     * value.
     */
    protected function basicAuthHeader(): string
    {
        $settings = $this->clientSettings();
        if (empty($settings['username'])) {
            return '';
        }
        $credentials = $settings['username']
            . ':' . ($settings['password'] ?? '');
        return "\r\nAuthorization: Basic "
            . base64_encode($credentials);
    }

    /**
     * Post to Solr Config API.
     *
     * @return bool|string True on success, error message on failure.
     */
    protected function postToSolrConfig(string $url, string $payload)
    {
        // The Config API triggers an internal core reload after each
        // change. Use waitForCoreReady() afterwards for readiness.
        $timeout = strlen($payload) > 100000 ? 120 : 30;
        $headers = 'Content-Type: application/json';
        $headers .= $this->basicAuthHeader();
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $payload,
                'timeout' => $timeout,
                // Allow reading response body on HTTP errors (4xx, 5xx).
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return 'Connection failed';
        }

        $result = json_decode($response, true);
        if (isset($result['error'])) {
            return $result['error']['msg'] ?? 'Unknown error';
        }

        return true;
    }

    /**
     * Ensure the "text_suggest" field type exists in the Solr schema.
     *
     * Replaces apostrophes (straight and curly) with spaces so that StandardTokenizer
     * splits "l'exception" into [l, exception], making "exception" matchable by
     * AnalyzingInfixLookupFactory. Identifiers like "123.4567.890" are still
     * preserved.
     */
    public function ensureSuggestFieldType(): bool
    {
        try {
            $schema = $this->schema();
            $types = $schema->getSchema()['fieldTypes'] ?? [];
            foreach ($types as $type) {
                if (($type['name'] ?? '') === 'text_suggest') {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Schema not readable; try to create the type anyway.
        }

        $schemaUrl = $this->clientUrl() . '/schema';
        $analyzer = [
            'charFilters' => [
                [
                    'class' => 'solr.PatternReplaceCharFilterFactory',
                    // Single quote, standard apostrophe, inverted.
                    'pattern' => "['’‘]",
                    'replacement' => ' ',
                ],
            ],
            'tokenizer' => ['class' => 'solr.StandardTokenizerFactory'],
            'filters' => [
                ['class' => 'solr.LowerCaseFilterFactory'],
            ],
        ];

        // Try add first; if it already exists, try replace.
        $fieldTypeDef = [
            'name' => 'text_suggest',
            'class' => 'solr.TextField',
            'positionIncrementGap' => '100',
            'indexAnalyzer' => $analyzer,
            'queryAnalyzer' => $analyzer,
        ];
        $result = $this->postToSolrConfig(
            $schemaUrl,
            json_encode(['add-field-type' => $fieldTypeDef])
        );
        if ($result === true) {
            return true;
        }

        // "already exists" → try replace.
        if (is_string($result)
            && stripos($result, 'already exists') !== false
        ) {
            $result = $this->postToSolrConfig(
                $schemaUrl,
                json_encode(['replace-field-type' => $fieldTypeDef])
            );
            return $result === true;
        }

        $logger = $this->getServiceLocator()
            ->get('Omeka\Logger');
        $logger->err(
            'SearchSolr: Cannot create text_suggest: {error}', // @translate
            ['error' => $result]
        );
        return false;
    }

    /**
     * Ensure the "suggest_txt" field exists in the Solr schema.
     *
     * Creates the field and copyField directives from _txt mapped fields.
     * By default, long-value properties listed in metadata_text.php
     * (descriptions, OCR, etc.) are excluded.
     *
     * @param bool $includeLongTexts Include long-value properties (OCR,
     *   descriptions, etc.) in the suggest field.
     * @return bool|string True on success, error message on failure.
     */
    public function ensureSuggestField(
        bool $includeLongTexts = false
    ) {
        $skipTermTexts = $includeLongTexts
            ? []
            : (include dirname(__DIR__, 3) . '/config/metadata_text.php');

        $sourceFields = [];
        foreach ($this->maps() as $map) {
            $fieldName = $map->fieldName();
            if (!str_ends_with($fieldName, '_txt')) {
                continue;
            }
            if ($skipTermTexts
                && in_array($map->source(), $skipTermTexts)
            ) {
                continue;
            }
            $sourceFields[] = $fieldName;
        }
        $sourceFields = array_unique($sourceFields);

        if (empty($sourceFields)) {
            return 'No _txt maps found.';
        }

        $schemaUrl = $this->clientUrl() . '/schema';
        $schema = $this->schema();

        // Remove existing field and its copyFields if recreating.
        if (isset($schema->getFieldsByName()['suggest_txt'])) {
            // Delete copyFields targeting suggest_txt first.
            $copyFields = $schema->getSchema()['copyFields'] ?? [];
            $deletes = [];
            foreach ($copyFields as $cf) {
                if (($cf['dest'] ?? '') === 'suggest_txt') {
                    $deletes[] = [
                        'source' => $cf['source'],
                        'dest' => 'suggest_txt',
                    ];
                }
            }
            if ($deletes) {
                $this->postToSolrConfig($schemaUrl, json_encode([
                    'delete-copy-field' => $deletes,
                ]));
            }
            $result = $this->postToSolrConfig(
                $schemaUrl,
                json_encode([
                    'delete-field' => ['name' => 'suggest_txt'],
                ])
            );
            if ($result !== true) {
                return 'Failed to delete existing suggest_txt: '
                    . (is_string($result) ? $result : 'unknown');
            }
        }

        // Create the field.
        $result = $this->postToSolrConfig($schemaUrl, json_encode([
            'add-field' => [
                'name' => 'suggest_txt',
                'type' => 'text_general',
                'stored' => true,
                'indexed' => true,
                'multiValued' => true,
            ],
        ]));
        if ($result !== true) {
            return 'Failed to create suggest_txt field: '
                . (is_string($result) ? $result : 'unknown');
        }

        // Create copyFields from each source _txt field.
        $copyFields = [];
        foreach ($sourceFields as $field) {
            $copyFields[] = [
                'source' => $field,
                'dest' => 'suggest_txt',
            ];
        }
        $result = $this->postToSolrConfig($schemaUrl, json_encode([
            'add-copy-field' => $copyFields,
        ]));
        if ($result !== true) {
            return 'Field created but copyFields failed: '
                . (is_string($result) ? $result : 'unknown');
        }

        return true;
    }
}
