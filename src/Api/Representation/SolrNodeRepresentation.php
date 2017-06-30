<?php

/*
 * Copyright BibLibre, 2016-2017
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

namespace Solr\Api\Representation;

use SolrClient;
use SolrClientException;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Solr\Schema;

class SolrNodeRepresentation extends AbstractEntityRepresentation
{
    /**
     * {@inheritDoc}
     */
    public function getJsonLdType()
    {
        return 'o:SolrNode';
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

        return $url('admin/solr/node-id', $params, $options);
    }

    public function name()
    {
        return $this->resource->getName();
    }

    public function settings()
    {
        return $this->resource->getSettings();
    }

    public function clientSettings()
    {
        $settings = $this->settings();
        return (array) $settings['client'];
    }

    public function clientUrl()
    {
        $clientSettings = $this->clientSettings();
        $hostname = $clientSettings['hostname'];
        $port = $clientSettings['port'];
        $path = $clientSettings['path'];
        return sprintf('%s:%s/%s', $hostname, $port, $path);
    }

    public function status()
    {
        $solrClient = new SolrClient($this->clientSettings());

        try {
            $solrPingResponse = $solrClient->ping();
        } catch (SolrClientException $e) {
            $messages = explode("\n", $e->getMessage());
            return reset($messages);
        }

        return 'OK';
    }

    public function mappingUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        $params = [
            'action' => $action,
            'nodeId' => $this->id(),
        ];
        $options = [
            'force_canonical' => $canonical,
        ];

        return $url('admin/solr/node-id-mapping', $params, $options);
    }

    public function resourceMappingUrl($resourceName, $action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        $params = [
            'action' => $action,
            'nodeId' => $this->id(),
            'resourceName' => $resourceName,
        ];
        $options = [
            'force_canonical' => $canonical,
        ];

        return $url('admin/solr/node-id-mapping-resource', $params, $options);
    }

    public function schema()
    {
        $services = $this->getServiceLocator();

        return $services->build('Solr\Schema', ['solr_node' => $this]);
    }
}
