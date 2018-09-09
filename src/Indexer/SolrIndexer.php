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
     * @var SolrClient
     */
    protected $client;

    /**
     * @var SolrNodeRepresentation
     */
    protected $solrNode;

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
        $this->addResource($resource);
        $this->commit();
    }

    public function indexResources(array $resources)
    {
        if (empty($resources)) {
            return;
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

    protected function getDocumentId($resourceName, $resourceId)
    {
        return sprintf('%s:%s', $resourceName, $resourceId);
    }

    protected function addResource(Resource $resource)
    {
        // TODO Prepare the services one time outside of this method.
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $apiAdapters = $services->get('Omeka\ApiAdapterManager');
        $valueExtractorManager = $services->get('Solr\ValueExtractorManager');
        $valueFormatterManager = $services->get('Solr\ValueFormatterManager');
        $logger = $this->getLogger();

        /** @var \Omeka\Api\Representation\AbstractResourceRepresentation $resource */
        $resourceName = $resource->getResourceName();
        $resourceId = $resource->getId();
        $logger->info(sprintf('Indexing resource #%1$s (%2$s)', $resourceId, $resourceName));

        $adapter = $apiAdapters->get($resourceName);
        $resource = $adapter->getRepresentation($resource);

        $client = $this->getClient();
        $solrNode = $this->getSolrNode();
        $solrNodeSettings = $solrNode->settings();

        $document = new SolrInputDocument;

        $id = $this->getDocumentId($resourceName, $resourceId);
        $document->addField('id', $id);

        // Force the indexation of "is_public" even if not selected in mapping.
        $isPublicField = $solrNodeSettings['is_public_field'];
        $document->addField($isPublicField, $resource->isPublic());

        $resourceNameField = $solrNodeSettings['resource_name_field'];
        $document->addField($resourceNameField, $resourceName);

        // TODO To be removed and replaced by the standard mapping.
        $sitesField = $solrNodeSettings['sites_field'];
        if ($sitesField) {
            switch ($resourceName) {
                case 'items':
                    $sites = $api->search('sites')->getContent();
                    foreach ($sites as $site) {
                        $query = ['id' => $resourceId, 'site_id' => $site->id()];
                        $res = $api->search('items', $query)->getContent();
                        if (!empty($res)) {
                            $document->addField($sitesField, $site->id());
                        }
                    }
                    break;

                case 'item_sets':
                    /** @var \Doctrine\ORM\EntityManager $entityManager */
                    $entityManager = $services->get('Omeka\EntityManager');
                    $qb = $entityManager->createQueryBuilder();
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
        }

        /** @var \Solr\Api\Representation\SolrMappingRepresentation[] $solrMappings */
        $solrMappings = $api->search('solr_mappings', [
            'resource_name' => $resourceName,
            'solr_node_id' => $solrNode->id(),
        ])->getContent();

        $schema = $solrNode->schema();

        /** @var \Solr\ValueExtractor\ValueExtractorInterface $valueExtractor */
        $valueExtractor = $valueExtractorManager->get($resourceName);
        foreach ($solrMappings as $solrMapping) {
            $solrField = $solrMapping->fieldName();
            $source = $solrMapping->source();
            // Index "is_public" one time only, except if the admin wants a
            // different to store it in a different field.
            if ($source === 'is_public' && $solrField === $isPublicField) {
                continue;
            }
            $values = $valueExtractor->extractValue($resource, $source);

            if (!is_array($values)) {
                $values = (array) $values;
            }

            $schemaField = $schema->getField($solrField);
            if ($schemaField && !$schemaField->isMultivalued()) {
                $values = array_slice($values, 0, 1);
            }

            $solrMappingSettings = $solrMapping->settings();
            $formatter = $solrMappingSettings['formatter'];
            if ($formatter) {
                $valueFormatter = $valueFormatterManager->get($formatter);
            }
            foreach ($values as $value) {
                if ($formatter && $valueFormatter) {
                    $value = $valueFormatter->format($value);
                }
                $document->addField($solrField, $value);
            }
        }

        try {
            $client->addDocument($document);
        } catch (SolrServerException $e) {
            $logger->err($e);
            $logger->err(sprintf('Indexing of resource %s failed', $resourceId));
        }
    }

    protected function commit()
    {
        $this->getLogger()->info('Commit index in Solr.');
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
            $solrNodeId = $this->getAdapterSetting('solr_node_id');
            if ($solrNodeId) {
                $api = $this->getServiceLocator()->get('Omeka\ApiManager');
                $this->solrNode = $api->read('solr_nodes', $solrNodeId)->getContent();
            }
        }

        return $this->solrNode;
    }
}
