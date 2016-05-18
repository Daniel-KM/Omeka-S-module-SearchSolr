<?php

/*
 * Copyright BibLibre, 2016
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

namespace Solr;

use SolrClient;
use SolrInputDocument;
use SolrServerException;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Search\Indexer\AbstractIndexer;

class Indexer extends AbstractIndexer
{
    protected $client;

    public function clearIndex()
    {
        $client = $this->getClient();
        $client->deleteByQuery('*:*');
        $client->commit();
    }

    public function indexResource(AbstractResourceEntityRepresentation $resource)
    {
        $this->addResource($resource);
        $this->commit();
    }

    public function indexResources(array $resources)
    {
        foreach ($resources as $resource) {
            $this->addResource($resource);
        }
        $this->commit();
    }

    public function deleteResource($id)
    {
        $this->getClient()->deleteById($id);
        $this->commit();
    }

    protected function addResource(AbstractResourceEntityRepresentation $resource)
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');
        $settings = $serviceLocator->get('Omeka\Settings');

        $client = $this->getClient();
        $resource_name_field = $settings->get('solr_resource_name_field', Module::DEFAULT_RESOURCE_NAME_FIELD);

        $document = new SolrInputDocument;
        $document->addField('id', $resource->id());
        $document->addField($resource_name_field, $resource->resourceName());

        $fields = $api->search('solr_fields', ['is_indexed' => 1])->getContent();
        foreach ($fields as $field) {
            $values = $resource->value($field->property()->term(), ['all' => true, 'default' => []]);

            // TODO: Provide something to be able to index all types of value
            $values = array_values(array_filter($values, function($value) {
                $type = $value->type();
                return ($type == 'literal' || $type == 'uri');
            }));

            if (!$field->isMultivalued()) {
                $values = array_slice($values, 0, 1);
            }

            foreach ($values as $value) {
                $document->addField($field->name(), $value);
            }
        }
        $this->getLogger()->info(sprintf('Indexing resource %1$s (%2$s)', $resource->id(), $resource->resourceName()));

        try {
            $client->addDocument($document);
        } catch (SolrServerException $e) {
            $this->getLogger()->err($e);
            $this->getLogger()->err(sprintf('Indexing of resource %s failed', $resource->id()));
        }
    }

    protected function commit()
    {
        $this->getLogger()->info('Commit');
        $this->getClient()->commit();
    }

    protected function getClient()
    {
        if (!isset($this->client)) {
            $this->client = new SolrClient([
                'hostname' => $this->getAdapterSetting('hostname'),
                'port' => $this->getAdapterSetting('port'),
                'path' => $this->getAdapterSetting('path'),
            ]);
        }

        return $this->client;
    }
}
