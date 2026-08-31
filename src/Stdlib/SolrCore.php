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

namespace SearchSolr\Stdlib;

use AdvancedSearch\Api\Representation\SearchEngineRepresentation;
use Common\Stdlib\PsrMessage;
use Laminas\ServiceManager\ServiceLocatorInterface;
use SearchSolr\Schema\Schema;
use Solarium\Client as SolariumClient;
use Solarium\Exception\HttpException as SolariumException;
use Solarium\QueryType\Select\Query\Query as SolariumQuery;

/**
 * The Solr core of a search engine.
 *
 * An engine is a real backend: the core (connection, schema, maps, snapshots)
 * is a facet of the solarium engine, stored in its settings under "solr" and
 * in the solr_map table by engine. This class keeps the method surface of the
 * former SolrCoreRepresentation, so the queriers, indexers, jobs and views are
 * mostly unchanged.
 */
class SolrCore
{
    /**
     * The sources of the maps required by the module to work at all: without
     * them, a resource cannot be identified, filtered by visibility, owner or
     * site.
     *
     * @var string[]
     */
    const REQUIRED_SOURCES = [
        'resource_name',
        'o:id',
        'is_public',
        'owner/o:id',
        'site/o:id',
    ];

    /**
     * The sources of the system maps: the metadata indexes created and managed
     * by the module (identity, visibility, dates, typing, structure, contents,
     * urls…), used by the queriers and the views even when no search page
     * references them. They are never removed by the maps sync. A composed
     * source ("owner/o:id") is matched by its root.
     *
     * @var string[]
     */
    const SYSTEM_SOURCES = [
        // Identity.
        'resource_name',
        'o:id',
        'o:title',
        // Visibility and ownership.
        'is_public',
        'owner',
        'site',
        'access_level',
        'group_id',
        // Dates.
        'created',
        'modified',
        // Typing.
        'resource_class',
        'resource_template',
        // Structure.
        'item_set',
        'item_sets_tree',
        'item',
        'media',
        'has_media',
        'has_original',
        'has_thumbnails',
        'o:media_type',
        'asset',
        'is_open',
        // Contents.
        'content',
        'value',
        'property_values',
        'annotation',
        'value_annotations',
        // Selections.
        'selection_id',
        'selection_public_id',
        // Urls.
        'url_api',
        'url_admin',
        'url_site',
        'url_asset',
        'url_original',
        'url_thumbnail_large',
        'url_thumbnail_medium',
        'url_thumbnail_square',
    ];

    /**
     * @var SearchEngineRepresentation
     */
    protected $engine;

    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var SolariumClient
     */
    protected $solariumClient;

    /**
     * @var Schema
     */
    protected $schema;

    public function __construct(SearchEngineRepresentation $engine, ServiceLocatorInterface $services)
    {
        $this->engine = $engine;
        $this->services = $services;
    }

    /**
     * The id of the core is the id of its engine.
     */
    public function id(): int
    {
        return $this->engine->id();
    }

    public function searchEngine(): SearchEngineRepresentation
    {
        return $this->engine;
    }

    protected function getServiceLocator(): ServiceLocatorInterface
    {
        return $this->services;
    }

    protected function getViewHelper($name)
    {
        return $this->services->get('ViewHelperManager')->get($name);
    }

    protected function getAdapter($name)
    {
        return $this->services->get('Omeka\ApiAdapterManager')->get($name);
    }

    /**
     * Whether the user may operate the core: delegated to its engine.
     */
    public function userIsAllowed($privilege): bool
    {
        return $this->engine->userIsAllowed($privilege);
    }

    /**
     * The admin url of an action on the core page (route on the engine id).
     */
    public function url($action = null, $canonical = false)
    {
        return $this->adminUrl($action, $canonical);
    }

    /**
     * An html link to an action of the core page, like a representation link.
     */
    public function link(string $text, $action = null, array $attributes = [])
    {
        $hyperlink = $this->getViewHelper('hyperlink');
        return $hyperlink($text, $this->adminUrl($action), $attributes);
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

        return $url('admin/search-manager/solr/core-id', $params, $options);
    }

    public function name(): string
    {
        return $this->engine->name();
    }

    public function settings(): array
    {
        $settings = $this->engine->setting('solr');
        return is_array($settings) ? $settings : [];
    }

    public function backupMaps(): ?array
    {
        $backups = $this->settings()['backup_maps'] ?? null;
        return is_array($backups) ? $backups : null;
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function setting($name, $default = null)
    {
        $settings = $this->settings();
        return $settings[$name] ?? $default;
    }

    /**
     * Decrypt the Solr passwords stored encrypted at rest. A no-op for legacy
     * clear values or when no encryption key is configured.
     */
    protected function decryptClientPasswords(array $client): array
    {
        $cipher = $this->getServiceLocator()->get('Omeka\Cipher');
        foreach (['password', 'admin_password'] as $key) {
            if (!empty($client[$key])) {
                $client[$key] = $cipher->decrypt((string) $client[$key]);
            }
        }
        return $client;
    }

    public function clientSettings(): array
    {
        // Currently, the keys from the old module Solr are kept.
        // TODO Convert settings during from old module Solr before saving.
        $clientSettings = $this->decryptClientPasswords((array) $this->setting('client', []));
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
        $clientSettings = $this->decryptClientPasswords($clientSettings);
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

        if (!file_exists(dirname(__DIR__, 2) . '/vendor/solarium/solarium/src/Client.php')) {
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
        return $url('admin/search-manager/solr/core-id-map-resource', $params, $options);
    }

    /**
     * Get the schema for the core.
     */
    public function schema(): Schema
    {
        // Memoized: the schema instance caches the remote fetch, so the many
        // checks of a page do a single request.
        return $this->schema ??= $this->getServiceLocator()
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
            foreach ($this->mapEntities() as $mapEntity) {
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
            foreach ($this->mapEntities() as $mapEntity) {
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

        // The specific resource types of modules are resources too, so they use
        // the maps defined for all resources.
        if (!in_array($resourceName, ['items', 'item_sets', 'media', 'digital_objects', 'concepts'])) {
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

    /**
     * Get all the resource ids indexed for a resource type.
     *
     * Only the id field is fetched, so the query stays cheap: about 80 ms for
     * 15000 documents. On a shared core, the documents of the other indexes are
     * excluded through the index name, like the indexer does.
     *
     * @param bool $withIndexedAt Fetch the date of indexation of each document
     * too, when the index has the field.
     * @return array|null Dates of indexation by resource id, the date being
     * null when it is not fetched or not indexed; null when the core cannot be
     * queried.
     */
    public function queryIndexedIds(string $resourceName, bool $withIndexedAt = false): ?array
    {
        $client = $this->solariumClient();
        if (!$client) {
            return null;
        }

        $resourceTypeField = $this->mapsBySource('resource_name', 'generic');
        $resourceTypeField = $resourceTypeField ? (reset($resourceTypeField))->fieldName() : null;
        $resourceIdField = $this->mapsBySource('o:id', 'generic');
        $resourceIdField = $resourceIdField ? (reset($resourceIdField))->fieldName() : null;
        if (!$resourceTypeField || !$resourceIdField) {
            return null;
        }

        // The date of indexation is available only when the map exists.
        $indexedAtField = null;
        if ($withIndexedAt) {
            $indexedAtFields = $this->mapsBySource('indexed_at', 'generic');
            $indexedAtField = $indexedAtFields ? (reset($indexedAtFields))->fieldName() : null;
        }

        /** @var \Solarium\QueryType\Select\Query\Query $query */
        $query = $client->createSelect();
        $query
            ->addFilterQuery([
                'key' => 'res_type',
                'query' => "$resourceTypeField:$resourceName",
            ])
            ->setFields($indexedAtField ? [$resourceIdField, $indexedAtField] : [$resourceIdField])
            // Rows is 10 by default and 0 or -1 are not working.
            ->setRows(1000000000);

        // Shared core: keep the documents of this index only.
        $indexName = $this->searchEngine()->settingEngineAdapter('index_name');
        $indexFields = $this->mapsBySource('search_index', 'generic');
        $indexField = $indexFields ? (reset($indexFields))->fieldName() : null;
        if ($indexName && $indexField) {
            $query->addFilterQuery([
                'key' => 'index_name',
                'query' => "$indexField:$indexName",
            ]);
        }

        try {
            $resultSet = $client->select($query);
        } catch (\Exception $e) {
            return null;
        }

        $first = fn ($value) => is_array($value) ? reset($value) : $value;

        $ids = [];
        foreach ($resultSet->getData()['response']['docs'] ?? [] as $doc) {
            if (!isset($doc[$resourceIdField])) {
                continue;
            }
            // A multivalued field is returned as an array.
            $id = (int) $first($doc[$resourceIdField]);
            // The ids are always the keys, even without the date, else the
            // caller cannot tell a list of ids from a list of dates by id.
            $ids[$id] = $indexedAtField && isset($doc[$indexedAtField])
                ? (string) $first($doc[$indexedAtField])
                : null;
        }
        return $ids;
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
     * @see \SearchSolr\Stdlib\SolrCore::queryValues()
     * @see \SearchSolr\Querier\SolariumQuerier::queryValues()
     *
     * @see \SearchSolr\Stdlib\SolrCore::queryValuesCount()
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
     * List the real usages of the indexes of the core.
     *
     * The same sources as the maps sync are scanned, but the nature and the
     * place of each usage are kept, for display.
     *
     * @return array Field name => usage (facet, filter, sort, query,
     * suggester, settings) => list of places.
     */
    public function listFieldUsages(): array
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $engineId = $this->searchEngine()->id();

        $existingFields = [];
        foreach ($this->maps() as $map) {
            $existingFields[$map->fieldName()] = true;
        }

        $usages = [];
        $mark = function ($value, array $suffixes, string $usage, string $place) use (&$usages, $existingFields): void {
            $value = (string) $value;
            if ($value === '') {
                return;
            }
            if (isset($existingFields[$value])) {
                $usages[$value][$usage][$place] = true;
                return;
            }
            if (strpos($value, ':') === false && strpos($value, '/') === false) {
                return;
            }
            $base = strtr($value, [':' => '_', '/' => '_']);
            foreach ($suffixes as $suffix) {
                if (isset($existingFields[$base . $suffix])) {
                    $usages[$base . $suffix][$usage][$place] = true;
                }
            }
        };

        /** @var \AdvancedSearch\Api\Representation\SearchConfigRepresentation $config */
        foreach ($api->search('search_configs')->getContent() as $config) {
            $configEngine = $config->searchEngine();
            if (!$configEngine || $configEngine->id() !== $engineId) {
                continue;
            }
            $place = $config->name();
            foreach ($config->subSetting('facet', 'facets', []) as $f) {
                $mark($f['field'] ?? '', ['_ss', '_i'], 'facet', $place);
                $mark($f['field_end'] ?? '', ['_ss', '_i'], 'facet', $place);
            }
            foreach ($config->subSetting('form', 'filters', []) as $f) {
                $mark($f['field'] ?? '', ['_ss', '_i'], 'filter', $place);
                $mark($f['field_end'] ?? '', ['_ss', '_i'], 'filter', $place);
            }
            // The sort selector is a flat list "name => label".
            foreach (array_keys($config->subSetting('results', 'sort_list', [])) as $sortName) {
                $mark(strtok((string) $sortName, ' '), ['_s', '_fold_s'], 'sort', $place);
            }
            foreach ($config->subSetting('engine', 'field_boosts', []) as $fieldName => $boost) {
                if ((float) $boost > 0 && (float) $boost !== 1.0) {
                    $mark((string) $fieldName, ['_txt'], 'query', $place);
                }
            }
            foreach ($config->subSetting('index', 'aliases', []) as $alias) {
                foreach ($alias['fields'] ?? [] as $aliasField) {
                    $mark($aliasField, ['_txt'], 'query', $place);
                }
            }
            $advanced = $config->advancedFilterSettings();
            foreach ($advanced['fields'] ?? [] as $f) {
                $mark($f['value'] ?? ($f['field'] ?? ''), ['_txt', '_ss'], 'filter', $place);
            }
            foreach ($config->subSetting('request', 'hidden_query_filters', []) as $fieldName => $value) {
                if (is_string($fieldName)) {
                    $mark($fieldName, ['_ss'], 'filter', $place);
                }
            }
        }

        // Boosts set on the core itself apply to every config using it.
        // A neutral boost (1) is not a real usage.
        $corePlace = $this->name();
        foreach ($this->setting('field_boost') ?: [] as $fieldName => $boost) {
            if ((float) $boost > 0 && (float) $boost !== 1.0) {
                $mark((string) $fieldName, ['_txt'], 'query', $corePlace);
            }
        }

        /** @var \AdvancedSearch\Api\Representation\SearchSuggesterRepresentation $suggester */
        foreach ($api->search('search_suggesters')->getContent() as $suggester) {
            $suggesterEngine = $suggester->searchEngine();
            if (!$suggesterEngine || $suggesterEngine->id() !== $engineId) {
                continue;
            }
            foreach ($suggester->settings()['fields'] ?? [] as $suggesterField) {
                $mark($suggesterField, ['_txt'], 'suggester', $suggester->name());
            }
        }

        // The resource link indexes come from the bounce links of the main or
        // site settings and from the pivot query types.
        foreach (array_keys($existingFields) as $fieldName) {
            if (substr($fieldName, -8) === '_link_ss') {
                $usages[$fieldName]['settings']['bounce links'] = true;
            } elseif (substr($fieldName, -8) === '_link_is') {
                $usages[$fieldName]['query']['resource query'] = true;
            }
        }

        // The system maps are used by the module itself, even when no page
        // references them: flag them by provenance or by source; the required
        // ones are flagged apart.
        foreach ($this->maps() as $map) {
            $source = $map->source();
            $rootSource = strtok($source, '/');
            if (in_array($source, self::REQUIRED_SOURCES, true)) {
                $usages[$map->fieldName()]['required']['module'] = true;
            } elseif ($map->setting('origin') === 'system'
                || in_array($source, self::SYSTEM_SOURCES, true)
                || in_array($rootSource, self::SYSTEM_SOURCES, true)
            ) {
                $usages[$map->fieldName()]['system']['module'] = true;
            }
            // A map created or edited by hand is never removed by the sync.
            if ($map->setting('origin') === 'manual') {
                $usages[$map->fieldName()]['manual']['user'] = true;
            }
            // A language index serves the facets and filters of the sites of
            // its locale, created by the multilingual option of the sync.
            $languages = $map->pool('filter_languages');
            if ($languages) {
                $usages[$map->fieldName()]['language'][implode(', ', (array) $languages)] = true;
            }
        }

        return array_map(fn ($fieldUsages) => array_map('array_keys', $fieldUsages), $usages);
    }

    /**
     * List the maps that serve nothing: referenced by no usage (facet, filter,
     * sort, query, suggester, settings), neither required nor system, and not
     * manual nor customized. They can be removed safely.
     *
     * @return \SearchSolr\Api\Representation\SolrMapRepresentation[] By id.
     */
    public function listUnusedMaps(): array
    {
        $usages = $this->listFieldUsages();
        $unused = [];
        foreach ($this->maps() as $map) {
            if (isset($usages[$map->fieldName()])) {
                continue;
            }
            // Only the property maps: a metadata map is a system one anyway.
            $source = $map->source();
            if (strpos($source, ':') === false || strpos($source, '/') !== false) {
                continue;
            }
            if ($this->isCustomizedMap($map)) {
                continue;
            }
            $unused[$map->id()] = $map;
        }
        return $unused;
    }

    /**
     * Whether a map was customized by hand: a formatter other than text, a
     * normalization, a boost, a pool filter, a visibility, or a renamed field.
     * Such a map is never removed automatically.
     */
    public function isCustomizedMap(\SearchSolr\Api\Representation\SolrMapRepresentation $map): bool
    {
        $settings = $map->settings();
        $pool = $map->pool() ?? [];
        // An explicit provenance wins over the heuristic below, that only
        // guesses the provenance of the maps that have none: a map given back
        // to the automatic management is managed by the alignment, whatever
        // its settings, else the flag could never be cleared.
        $origin = $map->setting('origin');
        if ($origin === 'manual') {
            return true;
        }
        if ($origin === 'sync' || $origin === 'system') {
            return false;
        }
        $formatter = $settings['formatter'] ?? '';
        if ($formatter !== '' && $formatter !== 'text') {
            return true;
        }
        if (!empty($settings['normalization'])) {
            return true;
        }
        if (!empty($settings['boost']) && (float) $settings['boost'] !== 1.0) {
            return true;
        }
        if (!empty($pool['filter_values'])
            || !empty($pool['filter_uris'])
            || !empty($pool['filter_resources'])
            || !empty($pool['filter_value_resources'])
            || !empty($pool['data_types'])
            || !empty($pool['data_types_exclude'])
            || !empty($pool['filter_languages'])
        ) {
            return true;
        }
        $visibility = $pool['filter_visibility'] ?? '';
        if ($visibility !== '' && $visibility !== 'default') {
            return true;
        }
        // A field name that does not follow the pattern derived from the
        // source was renamed by hand.
        $source = $map->source();
        if (strpos($source, ':') !== false) {
            $expectedPrefix = strtr($source, ':', '_') . '_';
            if (strpos($map->fieldName(), $expectedPrefix) !== 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Warning: unlike queryValues, the field isn't an alias but a real index.
     *
     * @todo Merge queryValuesCount() of SolariumQuerier with SolrRepresentation.
     *
     * Adapted:
     * @see \SearchSolr\Stdlib\SolrCore::queryValuesCount()
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
        // One engine per core: the engine of this core.
        return [$this->engine->id() => $this->engine];
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
     * @see \SearchSolr\Stdlib\SolrCore::missingRequiredMaps()
     * @see \SearchSolr\Job\ReduceSolrFields::perform()
     */
    public function missingRequiredMaps(): ?array
    {
        $unavailableFields = [];
        foreach (self::REQUIRED_SOURCES as $source) {
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
    /**
     * The lookup implementations that store their index in a directory.
     *
     * They need a distinct "indexPath", else they share the same write lock.
     *
     * @link https://solr.apache.org/guide/solr/latest/query-guide/suggester.html
     */
    const SUGGESTER_LOOKUPS_WITH_INDEX = [
        'AnalyzingInfixLookupFactory',
        'BlendedInfixLookupFactory',
    ];

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
            $lookupImpl = $suggester['lookupImpl'] ?? 'AnalyzingInfixLookupFactory';
            $def = [
                'name' => $name,
                'lookupImpl' => $lookupImpl,
                'field' => $suggester['field'],
                'suggestAnalyzerFieldType' => $suggester['suggestAnalyzerFieldType']
                    ?? 'text_suggest',
                // Solr Config API requires booleans as strings.
                'buildOnCommit' => !empty($suggester['buildOnCommit'])
                    ? 'true' : 'false',
            ];

            // The lookups that store their index on disk use the same default
            // directory, so they share the same write lock and all of them but
            // one fail to build ("Lock held by this virtual machine"). So each
            // suggester gets its own directory.
            if (in_array($lookupImpl, self::SUGGESTER_LOOKUPS_WITH_INDEX, true)) {
                $def['indexPath'] = $suggester['indexPath'] ?? ('suggester_' . $name);
            }

            $suggesterDefs[] = $def;
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
     * Number of documents holding a field, via the luke api.
     *
     * Returns null when the core is unreachable or the field is absent, so the
     * caller can tell "not populated yet" (0) from "cannot check" (null).
     */
    public function fieldDocCount(string $field): ?int
    {
        $lukeUrl = $this->clientUrl() . '/admin/luke?numTerms=0&fl=' . urlencode($field);
        $authHeader = $this->basicAuthHeader();
        $response = @file_get_contents($lukeUrl, false,
            stream_context_create(['http' => [
                'timeout' => 10,
                'header' => $authHeader ? trim($authHeader) : null,
            ]]));
        if ($response === false) {
            return null;
        }
        $luke = json_decode($response, true);
        if (!is_array($luke) || !isset($luke['fields'])) {
            return null;
        }
        return (int) ($luke['fields'][$field]['docs'] ?? 0);
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
     * Ensure the "string_folded" field type exists in the Solr schema.
     *
     * A single-term type (KeywordTokenizer) lowercased and ascii-folded, so
     * sorts and alphabetical comparisons on "*_fold_s" fields follow the
     * database collation (case and diacritics insensitive) instead of the byte
     * order of a plain string field.
     */
    public function ensureFoldedFieldType(): bool
    {
        try {
            $types = $this->schema()->getSchema()['fieldTypes'] ?? [];
            foreach ($types as $type) {
                if (($type['name'] ?? '') === 'string_folded') {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Schema not readable; try to create the type anyway.
        }

        $fieldTypeDef = [
            'name' => 'string_folded',
            'class' => 'solr.TextField',
            'sortMissingLast' => true,
            'analyzer' => [
                'tokenizer' => ['class' => 'solr.KeywordTokenizerFactory'],
                'filters' => [
                    ['class' => 'solr.LowerCaseFilterFactory'],
                    ['class' => 'solr.ASCIIFoldingFilterFactory'],
                ],
            ],
        ];
        $result = $this->postToSolrConfig(
            $this->clientUrl() . '/schema',
            json_encode(['add-field-type' => $fieldTypeDef])
        );
        if ($result === true || (is_string($result) && stripos($result, 'already exists') !== false)) {
            return true;
        }

        $this->getServiceLocator()->get('Omeka\Logger')->err(
            'SearchSolr: Cannot create string_folded: {error}', // @translate
            ['error' => $result]
        );
        return false;
    }

    /**
     * Ensure the dynamic field "*_fold_s" exists in the Solr schema.
     *
     * The field is a single string_folded term, indexed only. TextField has no
     * docValues, so sorting requires "uninvertible" (checked on Solr 10).
     */
    /**
     * Ensure the dynamic field "*_ps" exists in the Solr schema.
     *
     * A location is not multivalued by default and there is no plural type for
     * it, unlike "strings" for a string, so the field is declared multivalued:
     * a resource may have many places.
     */
    public function ensurePointDynamicField(): bool
    {
        try {
            $dynamicFields = $this->schema()->getSchema()['dynamicFields'] ?? [];
            foreach ($dynamicFields as $dynamicField) {
                if (($dynamicField['name'] ?? '') === '*_ps') {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Schema not readable; try to create the field anyway.
        }

        $fieldDef = [
            'name' => '*_ps',
            'type' => 'location',
            'indexed' => true,
            'stored' => true,
            'multiValued' => true,
        ];
        $result = $this->postToSolrConfig(
            $this->clientUrl() . '/schema',
            json_encode(['add-dynamic-field' => $fieldDef])
        );
        if ($result === true || (is_string($result) && stripos($result, 'already exists') !== false)) {
            return true;
        }

        $this->getServiceLocator()->get('Omeka\Logger')->err(
            'SearchSolr: Cannot create the dynamic field *_ps: {error}', // @translate
            ['error' => $result]
        );
        return false;
    }

    public function ensureFoldedDynamicField(): bool
    {
        try {
            $dynamicFields = $this->schema()->getSchema()['dynamicFields'] ?? [];
            foreach ($dynamicFields as $dynamicField) {
                if (($dynamicField['name'] ?? '') === '*_fold_s') {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Schema not readable; try to create the field anyway.
        }

        $fieldDef = [
            'name' => '*_fold_s',
            'type' => 'string_folded',
            'indexed' => true,
            'stored' => false,
            'multiValued' => false,
            'uninvertible' => true,
        ];
        $result = $this->postToSolrConfig(
            $this->clientUrl() . '/schema',
            json_encode(['add-dynamic-field' => $fieldDef])
        );
        if ($result === true || (is_string($result) && stripos($result, 'already exists') !== false)) {
            return true;
        }

        $this->getServiceLocator()->get('Omeka\Logger')->err(
            'SearchSolr: Cannot create the dynamic field *_fold_s: {error}', // @translate
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
    /**
     * Ensure a language-neutral suggest field type with diacritics folding.
     *
     * Creates (or replaces) a "text_suggest_folded" type using
     * StandardTokenizer + LowerCaseFilter + ASCIIFoldingFilter
     * (preserveOriginal=true). No language-specific stemming, so it works
     * across all languages indexed in the same core.
     */
    public function ensureSuggestFoldedFieldType(): bool
    {
        $analyzer = [
            'tokenizer' => ['class' => 'solr.StandardTokenizerFactory'],
            'filters' => [
                ['class' => 'solr.LowerCaseFilterFactory'],
                [
                    'class' => 'solr.ASCIIFoldingFilterFactory',
                    'preserveOriginal' => 'true',
                ],
            ],
        ];
        $fieldTypeDef = [
            'name' => 'text_suggest_folded',
            'class' => 'solr.TextField',
            'positionIncrementGap' => '100',
            'indexAnalyzer' => $analyzer,
            'queryAnalyzer' => $analyzer,
        ];
        $schemaUrl = $this->clientUrl() . '/schema';
        $result = $this->postToSolrConfig(
            $schemaUrl,
            json_encode(['add-field-type' => $fieldTypeDef])
        );
        if ($result === true) {
            return true;
        }
        if (is_string($result)
            && stripos($result, 'already exists') !== false
        ) {
            $result = $this->postToSolrConfig(
                $schemaUrl,
                json_encode(['replace-field-type' => $fieldTypeDef])
            );
            return $result === true;
        }
        $this->getServiceLocator()->get('Omeka\Logger')->err(
            'SearchSolr: Cannot create text_suggest_folded: {error}', // @translate
            ['error' => is_string($result) ? $result : 'unknown']
        );
        return false;
    }

    public function ensureSuggestField(
        bool $includeLongTexts = false,
        ?string $fieldType = null
    ) {
        if ($fieldType === null) {
            if (!$this->ensureSuggestFoldedFieldType()) {
                return 'Failed to create language-neutral folded type.';
            }
            $fieldType = 'text_suggest_folded';
        }

        $skipTermTexts = $includeLongTexts
            ? []
            : (include dirname(__DIR__, 2) . '/config/metadata_text.php');

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
                'type' => $fieldType,
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

    /**
     * The map entities of the engine, source of the map representations.
     *
     * @return \SearchSolr\Entity\SolrMap[]
     */
    protected function mapEntities(): array
    {
        return $this->services->get('Omeka\EntityManager')
            ->getRepository(\SearchSolr\Entity\SolrMap::class)
            ->findBy(['engine' => $this->engine->id()]);
    }
}
