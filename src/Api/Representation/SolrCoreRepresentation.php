<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2018-2025
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
use SearchSolr\Schema;
use Solarium\Client as SolariumClient;
use Solarium\Core\Client\Adapter\Http as SolariumAdapter;
use Solarium\Exception\HttpException as SolariumException;
use Solarium\QueryType\Select\Query\Query as SolariumQuery;
// TODO Use Laminas event manager when #12 will be merged.
// @see https://github.com/laminas/laminas-eventmanager/pull/12
use Symfony\Component\EventDispatcher\EventDispatcher;

class SolrCoreRepresentation extends AbstractEntityRepresentation
{
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
                $this->solariumClient = new SolariumClient(
                    new SolariumAdapter(),
                    new EventDispatcher()
                );
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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
    public function schema():\SearchSolr\Schema\Schema
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
            foreach ($this->resource->getMaps() as $mapEntity) {
                $maps[$mapEntity->getId()] = $mapAdapter->getRepresentation($mapEntity);
            }
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

        /** @var \Solarium\QueryType\Select\Query\Query $query */
        $query = $this->solariumClient->createSelect();
        $query
            ->addFilterQuery([
                'key' => $resourceTypeField,
                'query' => "$resourceTypeField:$resourceName",
            ])
            // When index is not ready, output is wrong.
            ->addFilterQuery([
                'key' => 'is_id_i',
                'query' => 'is_id_i:' . implode(' OR ', $ids),
            ])
            ->addSort('is_id_i', SolariumQuery::SORT_ASC)
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
            $result[$doc['is_id_i']] = $doc;
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

        /** @var \Solarium\QueryType\Select\Query\Query $query */
        $query = $this->solariumClient->createSelect();
        $query
            ->addFilterQuery([
                'key' => $resourceTypeField,
                'query' => "$resourceTypeField:$resourceName",
            ])
            // When index is not ready, output is wrong.
            ->addFilterQuery([
                'key' => 'is_id_i',
                'query' => 'is_id_i:*',
            ])
            ->setFields(['is_id_i', 'ss_name_s'])
            ->addSort('is_id_i', SolariumQuery::SORT_ASC)
            // Rows is 10 by default and 0 or -1 are not working.
            ->setRows(1000000000);
        $resultSet = $this->solariumClient->select($query);
        $data = $resultSet->getData();
        return isset($data['response']['docs'])
            ? array_column($data['response']['docs'], 'ss_name_s', 'is_id_i')
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
     */
    public function missingRequiredMaps(): ?array
    {
        // Check if the specified fields are available.
        // Value is "is required", but not used for now.
        $fields = [
            'resource_name' => true,
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
        $fields = [
            'is_id_i' => true,
            'ss_name_s' => true,
        ];
        foreach (array_keys($fields) as $source) {
            /** @var \SearchSolr\Api\Representation\SolrMapRepresentation[] $maps */
            $maps = $this->mapsByFieldName($source);
            if (!count($maps)) {
                $unavailableFields[] = $source;
            }
        }

        return $unavailableFields ?: null;
    }
}
