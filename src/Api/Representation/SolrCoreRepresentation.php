<?php

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau 2018-2020
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
use Solarium\Exception\HttpException as SolariumException;

class SolrCoreRepresentation extends AbstractEntityRepresentation
{
    /**
     * @var SolariumClient
     */
    protected $solariumClient;

    /**
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

    /**
     * @return string
     */
    public function name()
    {
        return $this->resource->getName();
    }

    /**
     * @return array
     */
    public function settings()
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

    /**
     * @return array
     */
    public function clientSettings()
    {
        $settings = $this->resource->getSettings();
        // Currently, the keys from the old module Solr are kept.
        // TODO Convert settings during from old module Solr before saving.
        $clientSettings = (array) $settings['client'];
        $clientSettings['endpoint'] = $this->endpoint();
        return $clientSettings;
    }

    /**
     * @see \Solarium\Core\Client\Endpoint
     * @return array
     */
    public function endpoint()
    {
        $settings = $this->resource->getSettings();
        $clientSettings = (array) $settings['client'];
        return [
            $clientSettings['host'] => array_intersect_key(
                $clientSettings, [
                    'scheme' => null,
                    'host' => null,
                    'port' => null,
                    'path' => '/',
                    // Core and collection have same meaning on a standard solr.
                    // 'collection' => null,
                    'core' => null,
                    'username' => null,
                    'password' => null,
                ]
            ),
        ];
    }

    /**
     * @return \Solarium\Client
     */
    public function solariumClient()
    {
        if (!isset($this->solariumClient)) {
            $this->solariumClient = new SolariumClient(['endpoint' => $this->endpoint()]);
        }
        return $this->solariumClient;
    }

    /**
     * @return string
     */
    public function clientUrl()
    {
        $settings = $this->clientSettings();
        $user = empty($settings['username']) ? '' : $settings['username'];
        $pass = empty($settings['password']) ? '' : ':' . $settings['password'];
        $credentials = ($user || $pass) ? $user . $pass . '@' : '';
        return $settings['scheme'] . '://' . $credentials . $settings['host'] . ':' . $settings['port'] . '/solr/' . $settings['core'];
    }

    /**
     * Get the url to the core without credentials.
     *
     * @return string
     */
    public function clientUrlAdmin()
    {
        $settings = $this->clientSettings();
        return $settings['scheme'] . '://' . $settings['host'] . ':' . $settings['port'] . '/solr/' . $settings['core'];
    }

    /**
     * @return string
     */
    public function clientUrlAdminBoard()
    {
        $settings = $this->clientSettings();
        return $settings['scheme'] . '://' . $settings['host'] . ':' . $settings['port'] . '/solr/#/' . $settings['core'];
    }

    /**
     * Check if Solr is working.
     *
     * @return string
     */
    public function status()
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        if (!file_exists(dirname(dirname(dirname(__DIR__))) . '/vendor/solarium/solarium/src/Client.php')) {
            $message = new Message('The composer library "%s" is not installed. See readme.', 'Solarium'); // @translate
            $logger->err($message);
            return $message;
        }

        $clientSettings = $this->clientSettings();
        $solariumClient = $this->solariumClient();

        try {
            // Create a ping query.
            $query = $solariumClient->createPing();
            // Execute the ping query. Result is not checked, bug use exception.
            $solariumClient->ping($query);
        } catch (SolariumException $e) {
            if ($e->getCode() === 404) {
                $message = new Message('Solr core not found. Check your url.'); // @translate
                $logger->err($message);
                return $message;
            }
            if ($e->getCode() === 401) {
                $message = new Message('Solr core not found or unauthorized. Check your url and your credentials.'); // @translate
                $logger->err($message);
                return $message;
            }
            $logger->err($e);
            return $e->getMessage();
        }
        // Check the schema too, in particular when there are credentials, but
        // the certificate is expired or incomplete.
        try {
            $this->schema()->getSchema();
        } catch (SolariumException $e) {
            $logger->err($e);
            return $e->getMessage();
        }

        // Check if the config bypass certificate check.
        if (!empty($clientSettings['secure']) && !empty($clientSettings['bypass_certificate_check'])) {
            $logger->warn('Solr: the config bypasses the check of the certificate.'); // @translate
            return 'OK (warning: check of certificate disabled)'; // @translate
        }

        return 'OK'; // @translate
    }

    /**
     * @param string $action
     * @param bool $canonical
     * @return string
     */
    public function mapUrl($action = null, $canonical = false)
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

    /**
     * @param string $resourceName
     * @param string $action
     * @param bool $canonical
     * @return string
     */
    public function resourceMapUrl($resourceName, $action = null, $canonical = false)
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
     *
     * @return \SearchSolr\Schema\Schema
     */
    public function schema()
    {
        $services = $this->getServiceLocator();
        return $services->build(Schema::class, ['solr_core' => $this]);
    }

    public function getSchemaField($field)
    {
        return $this->schema()->getField($field);
    }
}
