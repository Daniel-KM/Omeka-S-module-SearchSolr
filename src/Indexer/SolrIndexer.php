<?php

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2017-2018
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

namespace Solr\Indexer;

use Omeka\Entity\Resource;
use Search\Indexer\AbstractIndexer;
use Solr\Api\Representation\SolrNodeRepresentation;
use SolrClient;
use SolrInputDocument;
use SolrServerException;

class SolrIndexer extends AbstractIndexer
{
    /**
     * @var SolrNodeRepresentation
     */
    protected $solrNode;

    /**
     * @var SolrClient
     */
    protected $client;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Omeka\Api\Adapter\Manager
     */
    protected $apiAdapters;

    /**
     * @var \Doctrine\ORM\EntityManager $entityManager
     */
    protected $entityManager;

    /**
     * @var \Solr\ValueExtractor\Manager
     */
    protected $valueExtractorManager;

    /**
     * @var \Solr\ValueFormatter\Manager
     */
    protected $valueFormatterManager;

    /**
     * @var array Array of SolrMappingRepresentation by resource name.
     */
    protected $solrMappings;
    /**
     * @var int[]
     */
    protected $siteIds;

    public function canIndex($resourceName)
    {
        $services = $this->getServiceLocator();
        $valueExtractorManager = $services->get('Solr\ValueExtractorManager');
        /** @var \Solr\ValueExtractor\ValueExtractorInterface $valueExtractor */
        $valueExtractor = $valueExtractorManager->get($resourceName);
        return isset($valueExtractor);
    }

    public function clearIndex()
    {
        $client = $this->getClient();
        $client->deleteByQuery('*:*');
        $client->commit();
    }

    public function indexResource(Resource $resource)
    {
        if (empty($this->api)) {
            $this->init();
        }
        $this->addResource($resource);
        $this->commit();
    }

    public function indexResources(array $resources)
    {
        if (empty($resources)) {
            return;
        }
        if (empty($this->api)) {
            $this->init();
        }
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

    /**
     * Initialize the indexer.
     *
     * @todo Create/use a full service manager factory.
     */
    protected function init()
    {
        $services = $this->getServiceLocator();
        $this->api = $services->get('Omeka\ApiManager');
        $this->apiAdapters = $services->get('Omeka\ApiAdapterManager');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->valueExtractorManager = $services->get('Solr\ValueExtractorManager');
        $this->valueFormatterManager = $services->get('Solr\ValueFormatterManager');
        $this->siteIds = $this->api->search('sites', [], ['returnScalar' => 'id'])->getContent();
    }

    protected function getDocumentId($resourceName, $resourceId)
    {
        return sprintf('%s:%s', $resourceName, $resourceId);
    }

    protected function addResource(Resource $resource)
    {
        $resourceName = $resource->getResourceName();
        $resourceId = $resource->getId();
        $this->getLogger()->info(sprintf('Indexing resource #%1$s (%2$s)', $resourceId, $resourceName));

        $client = $this->getClient();
        $solrNode = $this->getSolrNode();
        $solrNodeSettings = $solrNode->settings();
        $solrMappings = $this->getSolrMappings($resourceName);
        $schema = $solrNode->schema();
        /** @var \Solr\ValueExtractor\ValueExtractorInterface $valueExtractor */
        $valueExtractor = $this->valueExtractorManager->get($resourceName);

        /** @var \Omeka\Api\Representation\AbstractResourceRepresentation $representation */
        $adapter = $this->apiAdapters->get($resourceName);
        $representation = $adapter->getRepresentation($resource);

        $document = new SolrInputDocument;

        $id = $this->getDocumentId($resourceName, $resourceId);
        $document->addField('id', $id);

        // Force the indexation of visibility, resource type and sites, even if
        // not selected in mapping, because they are the base of Omeka.

        $isPublicField = $solrNodeSettings['is_public_field'];
        $document->addField($isPublicField, $resource->isPublic());

        $resourceNameField = $solrNodeSettings['resource_name_field'];
        $document->addField($resourceNameField, $resourceName);

        $sitesField = $solrNodeSettings['sites_field'];
        switch ($resourceName) {
            case 'items':
                // There is no method to get the list of sites of an item.
                foreach ($this->siteIds as $siteId) {
                    $query = ['id' => $resourceId, 'site_id' => $siteId];
                    $res = $this->api->search('items', $query, ['returnScalar' => 'id'])->getContent();
                    if (!empty($res)) {
                        $document->addField($sitesField, $siteId);
                    }
                }
                break;

            case 'item_sets':
                // There is no method to get the list of sites of an item set.
                $qb = $this->entityManager->createQueryBuilder();
                $qb->select('siteItemSet')
                    ->from(\Omeka\Entity\SiteItemSet::class, 'siteItemSet')
                    ->innerJoin('siteItemSet.itemSet', 'itemSet')
                    ->where($qb->expr()->eq('itemSet.id', $resourceId));
                $siteItemSets = $qb->getQuery()->getResult();
                foreach ($siteItemSets as $siteItemSet) {
                    $document->addField($sitesField, $siteItemSet->getSite()->getId());
                }
                break;
        }

        foreach ($solrMappings as $solrMapping) {
            $solrField = $solrMapping->fieldName();
            $source = $solrMapping->source();

            // Index the required fields one time only except if the admin wants
            // to store it in a different field too.
            if ($source === 'is_public' && $solrField === $isPublicField) {
                continue;
            }
            // The admin can‘t modify this parameter via the standard interface.
            if ($source === 'resource_name' && $solrField === $sitesField) {
                continue;
            }
            // The admin can‘t modify this parameter via the standard interface.
            if ($source === 'site/o:id' && $solrField === $sitesField) {
                continue;
            }

            $values = $valueExtractor->extractValue($representation, $source);

            if (!is_array($values)) {
                $values = (array) $values;
            }

            $schemaField = $schema->getField($solrField);
            if ($schemaField && !$schemaField->isMultivalued()) {
                $values = array_slice($values, 0, 1);
            }

            $solrMappingSettings = $solrMapping->settings();
            $formatter = $solrMappingSettings['formatter'];
            $valueFormatter = $formatter
                ? $this->valueFormatterManager->get($formatter)
                : null;
            foreach ($values as $value) {
                if ($valueFormatter) {
                    $value = $valueFormatter->format($value);
                }
                $document->addField($solrField, $value);
            }
        }

        try {
            $client->addDocument($document);
        } catch (SolrServerException $e) {
            $this->getLogger()->err(sprintf('Indexing of resource %s failed', $resourceId));
            $this->getLogger()->err($e);
        }
    }

    protected function commit()
    {
        $this->getLogger()->info('Commit index in Solr.');
        $this->getClient()->commit();
    }

    /**
     * @return \Solr\Api\Representation\SolrNodeRepresentation
     */
    protected function getSolrNode()
    {
        if (!isset($this->solrNode)) {
            $solrNodeId = $this->getAdapterSetting('solr_node_id');
            if ($solrNodeId) {
                $api = $this->getServiceLocator()->get('Omeka\ApiManager');
                $this->solrNode = $api->read('solr_nodes', $solrNodeId)->getContent();
            }
        }
        return $this->solrNode;
    }

    /**
     * @return SolrClient
     */
    protected function getClient()
    {
        if (!isset($this->client)) {
            $solrNode = $this->getSolrNode();
            $this->client = new SolrClient($solrNode->clientSettings());
        }
        return $this->client;
    }

    /**
     * Get the solr mappings for a resource type.
     *
     * @param string $resourceName
     * @return \Solr\Api\Representation\SolrMappingRepresentation[]
     */
    protected function getSolrMappings($resourceName)
    {
        if (!isset($this->solrMappings[$resourceName])) {
            $solrNode = $this->getSolrNode();
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            $this->solrMappings[$resourceName] = $api->search('solr_mappings', [
                'resource_name' => $resourceName,
                'solr_node_id' => $solrNode->id(),
            ])->getContent();
        }
        return $this->solrMappings[$resourceName];
    }
}
