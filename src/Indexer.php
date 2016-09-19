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
use Omeka\Api\Representation\AbstractResourceRepresentation;
use Omeka\Api\Representation\ResourceReference;
use Search\Indexer\AbstractIndexer;

class Indexer extends AbstractIndexer
{
    protected $client;
    protected $solrNode;
    protected $solrProfiles;

    public function canIndex($resourceName)
    {
        $apiAdapterManager = $this->getServiceLocator()->get('Omeka\ApiAdapterManager');

        $solrProfile = $this->getSolrProfile($resourceName);
        if ($solrProfile) {
            return true;
        }

        return false;
    }

    public function clearIndex()
    {
        $client = $this->getClient();
        $client->deleteByQuery('*:*');
        $client->commit();
    }

    public function indexResource(AbstractResourceRepresentation $resource)
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

    public function deleteResource($resourceName, $resourceId)
    {
        $id = $this->getDocumentId($resourceName, $resourceId);
        $this->getClient()->deleteById($id);
        $this->commit();
    }

    protected function getDocumentId($resourceName, $resourceId)
    {
        return sprintf('%s:%s', $resourceName, $resourceId);
    }

    protected function addResource(AbstractResourceRepresentation $resource)
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');
        $settings = $serviceLocator->get('Omeka\Settings');
        $valueExtractorManager = $serviceLocator->get('Solr\ValueExtractorManager');
        $valueFormatterManager = $serviceLocator->get('Solr\ValueFormatterManager');

        if ($resource instanceof ResourceReference) {
            $resource = $resource->getRepresentation();
        }

        $client = $this->getClient();

        $resourceName = $resource->resourceName();
        $id = $this->getDocumentId($resourceName, $resource->id());

        $solrNode = $this->getSolrNode();
        $solrNodeSettings = $solrNode->settings();
        $solrProfile = $this->getSolrProfile($resourceName);

        $document = new SolrInputDocument;
        $document->addField('id', $id);
        $resource_name_field = $solrNodeSettings['resource_name_field'];
        $document->addField($resource_name_field, $resourceName);

        $solrProfileRules = $api->search('solr_profile_rules', [
            'solr_profile_id' => $solrProfile->id(),
        ])->getContent();

        $valueExtractor = $valueExtractorManager->get($resourceName);
        foreach ($solrProfileRules as $solrProfileRule) {
            $solrField = $solrProfileRule->solrField();
            $source = $solrProfileRule->source();
            $values = $valueExtractor->extractValue($resource, $source);

            if (!is_array($values)) {
                $values = (array) $values;
            }

            if (!$solrField->isMultivalued()) {
                $values = array_slice($values, 0, 1);
            }

            $solrProfileRuleSettings = $solrProfileRule->settings();
            $formatter = $solrProfileRuleSettings['formatter'];
            $valueFormatter = $valueFormatterManager->get($formatter);
            foreach ($values as $value) {
                if ($valueFormatter) {
                    $value = $valueFormatter->format($value);
                }
                $document->addField($solrField->name(), $value);
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
            $solrNode = $this->getSolrNode();
            $this->client = new SolrClient($solrNode->clientSettings());
        }

        return $this->client;
    }

    protected function getSolrNode()
    {
        if (!isset($this->solrNode)) {
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');

            $solrNodeId = $this->getAdapterSetting('solr_node_id');
            if ($solrNodeId) {
                $response = $api->read('solr_nodes', $solrNodeId);
                $this->solrNode = $response->getContent();
            }
        }

        return $this->solrNode;
    }

    protected function getSolrProfile($resourceName)
    {
        if (!isset($this->solrProfiles)) {
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            $solrNode = $this->getSolrNode();
            if ($solrNode) {
                $solrProfiles = $api->search('solr_profiles', [
                    'solr_node_id' => $solrNode->id(),
                ])->getContent();

                foreach ($solrProfiles as $solrProfile) {
                    $this->solrProfiles[$solrProfile->resourceName()] = $solrProfile;
                }
            }
        }

        return $this->solrProfiles[$resourceName];
    }
}
