<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau 2018-2021
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

use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Stdlib\Message;
use SearchSolr\Schema;
use Solarium\Client as SolariumClient;
use Solarium\Core\Client\Adapter\Http as SolariumAdapter;
use Solarium\Exception\HttpException as SolariumException;
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
                // Core and collection have same meaning on a standard solr.
                'collection' => null,
                'core' => null,
                // For Solr Cloud.
                // 'leader' => false,
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
     * @todo Add a true status check and use it for status message.
     */
    public function status(): bool
    {
        return $this->statusMessage() === 'OK';
    }

    /**
     * Check if Solr is working.
     */
    public function statusMessage(): string
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        if (!file_exists(dirname(__DIR__, 3) . '/vendor/solarium/solarium/src/Client.php')) {
            $message = new Message('The composer library "%s" is not installed. See readme.', 'Solarium'); // @translate
            $logger->err($message);
            return (string) $message;
        }

        $clientSettings = $this->clientSettings();
        $client = $this->solariumClient();

        if (!$client) {
            $message = new Message('Solr core #%d: incorrect or incomplete configuration.', $this->id()); // @translate
            $logger->err($message);
            return (string) $message;
        }

        try {
            // Create a ping query.
            $query = $client->createPing();
            // Execute the ping query. Result is not checked, bug use exception.
            @$client->ping($query);
        } catch (SolariumException $e) {
            if ($e->getCode() === 404) {
                $message = new Message('Solr core not found. Check your url.'); // @translate
                $logger->err($message);
                return (string) $message;
            }
            if ($e->getCode() === 401) {
                $message = new Message('Solr core not found or unauthorized. Check your url and your credentials.'); // @translate
                $logger->err($message);
                return (string) $message;
            }
            $message = new Message('Solr core #%d: %s', $this->id(), $e->getMessage()); // @translate
            $logger->err($message);
            return $e->getMessage();
        } catch (\Exception $e) {
            $message = new Message('Solr core #%d: %s', $this->id(), $e->getMessage()); // @translate
            $logger->err($message);
            return $e->getMessage();
        }

        // Check the schema too, in particular when there are credentials, but
        // the certificate is expired or incomplete.
        try {
            $this->schema()->getSchema();
        } catch (SolariumException $e) {
            $message = new Message('Solr core #%d enpoint: %s', $this->id(), $e->getMessage()); // @translate
            $logger->err($message);
            return $e->getMessage();
        } catch (\Exception $e) {
            $message = new Message('Solr core #%d: %s', $this->id(), $e->getMessage()); // @translate
            $logger->err($message);
            return $e->getMessage();
        }

        // Check if the config bypass certificate check.
        if (!empty($clientSettings['secure']) && !empty($clientSettings['bypass_certificate_check'])) {
            $logger->warn('Solr: the config bypasses the check of the certificate.'); // @translate
            return 'OK (warning: check of certificate disabled)'; // @translate
        }

        return 'OK'; // @translate
    }

    public function mapUrl(?string $action = null, $canonical = false): string
    {
        $url = $this->getViewHelper('Url');
        $params = [
            'action' => $action,
            'coreId' => $this->id(),
        ];
        $options = [
            'force_canonical' => $canonical,
        ];
        return $url('admin/search/solr/core-id-map', $params, $options);
    }

    public function resourceMapUrl(?string $resourceName, ?string $action = null, $canonical = false): string
    {
        $url = $this->getViewHelper('Url');
        $params = [
            'action' => $action,
            'coreId' => $this->id(),
            'resourceName' => $resourceName,
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
     * Get the solr mappings by id.
     *
     * @return \SearchSolr\Api\Representation\SolrMapRepresentation[]
     */
    public function maps()
    {
        $maps = [];
        $mapAdapter = $this->getAdapter('solr_maps');
        /** @var \SearchSolr\Entity\SolrMap $mapEntity */
        foreach ($this->resource->getMaps() as $mapEntity) {
            $maps[$mapEntity->getId()] = $mapAdapter->getRepresentation($mapEntity);
        }
        return $maps;
    }

    /**
     * Get the solr mappings by resource type.
     *
     * @param string $resourceName
     * @return \SearchSolr\Api\Representation\SolrMapRepresentation[]
     */
    public function mapsByResourceName($resourceName = null)
    {
        static $maps = [];

        $id = $this->id();
        if (!isset($maps[$id])) {
            $maps[$id] = [];
            $mapAdapter = $this->getAdapter('solr_maps');
            /** @var \SearchSolr\Entity\SolrMap $mapEntity */
            foreach ($this->resource->getMaps() as $mapEntity) {
                $maps[$id][$mapEntity->getResourceName()][] = $mapAdapter->getRepresentation($mapEntity);
            }
        }

        return $resourceName
            ? $maps[$id][$resourceName] ?? []
            : $maps[$id];
    }

    /**
     * Get all search indexes related to the core, indexed by id.
     *
     * @return \AdvancedSearch\Api\Representation\SearchEngineRepresentation[]
     */
    public function searchEngines()
    {
        // TODO Use entity manager to simplify search of indexes from core.
        $result = [];
        /** @var \AdvancedSearch\Api\Representation\SearchEngineRepresentation[] $searchEngines */
        $searchEngines = $this->getServiceLocator()->get('Omeka\ApiManager')->search('search_engines', ['adapter' => 'solarium'])->getContent();
        $id = $this->id();
        foreach ($searchEngines as $searchEngine) {
            if ($searchEngine->settingAdapter('solr_core_id') == $id) {
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
    public function searchConfigs()
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
}
