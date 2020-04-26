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
     * @return array
     */
    public function clientSettings()
    {
        $settings = $this->settings();
        return (array) $settings['client'];
    }

    /**
     * @return string
     */
    public function clientUrl()
    {
        $settings = $this->clientSettings();
        $user = isset($settings['login']) ? $settings['login'] : '';
        $pass = isset($settings['password']) ? ':' . $settings['password'] : '';
        $pass = ($user || $pass) ? $pass . '@' : '';
        return (empty($settings['secure']) ? 'http://' : 'https://')
            . $user . $pass . $settings['hostname'] . ':' . $settings['port'] . '/' . $settings['path'];
    }

    /**
     * Get the url to the core without credentials.
     *
     * @return string
     */
    public function clientUrlAdmin()
    {
        $settings = $this->clientSettings();
        return (empty($settings['secure']) ? 'http://' : 'https://')
            . $settings['hostname'] . ':' . $settings['port'] . '/' . $settings['path'];
    }

    /**
     * @return string
     */
    public function clientUrlAdminBoard()
    {
        $settings = $this->clientSettings();
        // Remove first part of the string ("solr/").
        $path = mb_substr($settings['path'], (mb_strrpos($settings['path'], '/') ?: -1) + 1);
        $url = (empty($settings['secure']) ? 'http://' : 'https://')
            . $settings['hostname'] . ':' . $settings['port'] . '/solr/#/' . $path;
        return $url;
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

        if (!file_exists(dirname(dirname(dirname(__DIR__))) . '/vendor/solarium/solarium/library/Solarium/Autoloader.php')) {
            $message = new Message('The composer library "%s" is not installed. See readme.', 'Solarium'); // @translate
            $logger->err($message);
            return $message;
        }

        $clientSettings = $this->clientSettings();
        $solariumClient = new SolariumClient($clientSettings);
        try {
            // Create a ping query.
            $query = $solariumClient->createPing();
            // Execute the ping query.
            $solariumClient->ping($query);
        } catch (SolariumException $e) {
            if ($e->getCode() === 404) {
                $message = new Message('The core is available, but the index is not found. Check if it is created.'); // @translate
                $logger->err($message);
                return $message;
            }
            $logger->err($e);
            $messages = explode("\n", $e->getMessage());

            return reset($messages);
        }

        // Check the schema too, in particular when there are credentials, but
        // the certificate is expired or incomplete.
        try {
            $this->schema()->getSchema();
        } catch (SolariumException $e) {
            $logger->err($e);
            $messages = explode("\n", $e->getMessage());
            return reset($messages);
        }

        // Check if the config bypass certificate check.
        if (!empty($clientSettings['secure'])) {
            if (!empty($services->get('Config')['searchsolr']['config']['searchsolr_bypass_certificate_check'])) {
                $logger->warn('Solr: the config bypasses the check of the certificate.'); // @translate
                return 'OK (warning: check of certificate disabled)'; // @translate
            }
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
}
