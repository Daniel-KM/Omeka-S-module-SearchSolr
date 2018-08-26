<?php

/*
 * Copyright BibLibre, 2017
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

namespace Solr\Controller\Admin;

use Omeka\Form\ConfirmForm;
use Search\Api\Representation\SearchIndexRepresentation;
use Search\Api\Representation\SearchPageRepresentation;
use Solr\Api\Representation\SolrNodeRepresentation;
use Solr\Form\Admin\SolrMappingForm;
use Solr\ValueExtractor\Manager as ValueExtractorManager;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Solr\Api\Representation\SolrMappingRepresentation;

class MappingController extends AbstractActionController
{
    protected $valueExtractorManager;

    public function setValueExtractorManager(ValueExtractorManager $valueExtractorManager)
    {
        $this->valueExtractorManager = $valueExtractorManager;
    }

    public function browseAction()
    {
        $solrNodeId = $this->params('nodeId');
        $solrNode = $this->api()->read('solr_nodes', $solrNodeId)->getContent();

        $valueExtractors = [];
        foreach ($this->valueExtractorManager->getRegisteredNames() as $name) {
            $valueExtractors[$name] = $this->valueExtractorManager->get($name);
        }

        $view = new ViewModel;
        $view->setVariable('solrNode', $solrNode);
        $view->setVariable('valueExtractors', $valueExtractors);

        return $view;
    }

    public function browseResourceAction()
    {
        $solrNodeId = $this->params('nodeId');
        $resourceName = $this->params('resourceName');

        $solrNode = $this->api()->read('solr_nodes', $solrNodeId)->getContent();
        $mappings = $this->api()->search('solr_mappings', [
            'solr_node_id' => $solrNode->id(),
            'resource_name' => $resourceName,
        ])->getContent();

        $view = new ViewModel;
        $view->setVariable('solrNode', $solrNode);
        $view->setVariable('resourceName', $resourceName);
        $view->setVariable('mappings', $mappings);
        return $view;
    }

    public function addAction()
    {
        $solrNodeId = $this->params('nodeId');
        $resourceName = $this->params('resourceName');

        $solrNode = $this->api()->read('solr_nodes', $solrNodeId)->getContent();

        $form = $this->getForm(SolrMappingForm::class, [
            'solr_node_id' => $solrNodeId,
            'resource_name' => $resourceName,
        ]);

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                $data = $form->getData();
                $data['o:source'] = implode('/', $data['o:source']);
                $data['o:solr_node']['o:id'] = $solrNodeId;
                $data['o:resource_name'] = $resourceName;
                $this->api()->create('solr_mappings', $data);

                $this->messenger()->addSuccess('Solr mapping created.'); // @translate

                return $this->redirect()->toRoute('admin/solr/node-id-mapping-resource', [
                    'nodeId' => $solrNodeId,
                    'resourceName' => $resourceName,
                ]);
            } else {
                $this->messenger()->addError('There was an error during validation'); // @translate
            }
        }

        $view = new ViewModel;
        $view->setVariable('solrNode', $solrNode);
        $view->setVariable('form', $form);
        $view->setVariable('schema', $this->getSolrSchema($solrNodeId));
        $view->setVariable('sourceLabels', $this->getSourceLabels());
        return $view;
    }

    public function editAction()
    {
        $solrNodeId = $this->params('nodeId');
        $resourceName = $this->params('resourceName');
        $id = $this->params('id');

        $mapping = $this->api()->read('solr_mappings', $id)->getContent();

        $form = $this->getForm(SolrMappingForm::class, [
            'solr_node_id' => $solrNodeId,
            'resource_name' => $resourceName,
        ]);
        $mappingData = $mapping->jsonSerialize();
        $mappingData['o:source'] = explode('/', $mappingData['o:source']);
        $form->setData($mappingData);

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                $data = $form->getData();
                $data['o:solr_node']['o:id'] = $solrNodeId;
                $data['o:resource_name'] = $resourceName;
                $this->api()->update('solr_mappings', $id, $data);

                $this->messenger()->addSuccess('Solr mapping modified.'); // @translate
                $this->messenger()->addWarning('Don’t forget to check search pages that use this mapping.'); // @translate

                return $this->redirect()->toRoute('admin/solr/node-id-mapping-resource', [
                    'nodeId' => $solrNodeId,
                    'resourceName' => $resourceName,
                ]);
            } else {
                $this->messenger()->addError('There was an error during validation'); // @translate
            }
        }

        $view = new ViewModel;
        $view->setVariable('mapping', $mapping);
        $view->setVariable('form', $form);
        $view->setVariable('schema', $this->getSolrSchema($solrNodeId));
        $view->setVariable('sourceLabels', $this->getSourceLabels());
        return $view;
    }

    public function deleteConfirmAction()
    {
        $id = $this->params('id');
        $response = $this->api()->read('solr_mappings', $id);
        $mapping = $response->getContent();

        $searchPages = $this->searchSearchPages($mapping->solrNode());
        $searchPagesUsingMapping = [];
        foreach ($searchPages as $searchPage) {
            if ($this->doesSearchPageUseMapping($searchPage, $mapping)) {
                $searchPagesUsingMapping[] = $searchPage;
            }
        }

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate('common/delete-confirm-details');
        $view->setVariable('resourceLabel', 'Solr mapping'); // @translate
        $view->setVariable('resource', $mapping);
        $view->setVariable('partialPath', 'common/solr-mapping-delete-confirm-details');
        $view->setVariable('totalSearchPages', count($searchPages));
        $view->setVariable('totalSearchPagesUsingMapping', count($searchPagesUsingMapping));
        return $view;
    }

    public function deleteAction()
    {
        $id = $this->params('id');
        $mapping = $this->api()->read('solr_mappings', $id)->getContent();

        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $this->api()->delete('solr_mappings', $id);
                $this->messenger()->addSuccess('Solr mapping successfully deleted'); // @translate
                $this->messenger()->addWarning('Don’t forget to check search pages that used this mapping.'); // @translate
            } else {
                $this->messenger()->addError('Solr mapping could not be deleted'); // @translate
            }
        }

        return $this->redirect()->toRoute('admin/solr/node-id-mapping-resource', [
            'nodeId' => $mapping->solrNode()->id(),
            'resourceName' => $mapping->resourceName(),
        ]);
    }

    protected function getSolrSchema($solrNodeId)
    {
        $solrNode = $this->api()->read('solr_nodes', $solrNodeId)->getContent();
        return $solrNode->schema()->getSchema();
    }

    protected function getSourceLabels()
    {
        $sourceLabels = [
            'id' => 'Internal identifier',
            'is_public' => 'Public', // @translate
            'is_open' => 'Is open', // @translate
            'created' => 'Created', // @translate
            'modified' => 'Modified', // @translate
            'resource_class' => 'Resource class', // @translate
            'resource_template' => 'Resource template', // @translate
            'item_set' => 'Item set', // @translate
            'item' => 'Item', // @translate
            'media' => 'Media', // @translate
        ];

        $propertyLabels = [];
        $result = $this->api()->search('properties')->getContent();
        foreach ($result as $property) {
            $propertyLabels[$property->term()] = ucfirst($property->label());
        }

        $sourceLabels += $propertyLabels;
        return $sourceLabels;
    }

    /**
     * Find all search indexes related to a specific solr node.
     *
     * @todo Factorize with NodeController::searchSearchIndexes()
     * @param SolrNodeRepresentation $solrNode
     * @return SearchIndexRepresentation[] Result is indexed by id.
     */
    protected function searchSearchIndexes(SolrNodeRepresentation $solrNode)
    {
        $result = [];
        $api = $this->api();
        $searchIndexes = $api->search('search_indexes', ['adapter' => 'solr'])->getContent();
        foreach ($searchIndexes as $searchIndex) {
            $searchIndexSettings = $searchIndex->settings();
            if ($solrNode->id() == $searchIndexSettings['adapter']['solr_node_id']) {
                $result[$searchIndex->id()] = $searchIndex;
            }
        }
        return $result;
    }

    /**
     * Find all search pages related to a specific solr node.
     *
     * @todo Factorize with NodeController::searchSearchPages()
     * @param SolrNodeRepresentation $solrNode
     * @return SearchPageRepresentation[] Result is indexed by id.
     */
    protected function searchSearchPages(SolrNodeRepresentation $solrNode)
    {
        // TODO Use entity manager to simplify search of pages from node.
        $result = [];
        $api = $this->api();
        $searchIndexes = $this->searchSearchIndexes($solrNode);
        foreach ($searchIndexes as $searchIndex) {
            $searchPages = $api->search('search_pages', ['index_id' => $searchIndex->id()])->getContent();
            foreach ($searchPages as $searchPage) {
                $result[$searchPage->id()] = $searchPage;
            }
        }
        return $result;
    }

    /**
     * Check if a search page use a mapping enabled as facet or sort field.
     *
     * @param SearchPageRepresentation $searchPage
     * @param SolrMappingRepresentation $solrMapping
     * @return bool
     */
    protected function doesSearchPageUseMapping(
        SearchPageRepresentation $searchPage,
        SolrMappingRepresentation $solrMapping
    ) {
        $searchPageSettings = $searchPage->settings();
        $fieldName = $solrMapping->fieldName();
        foreach ($searchPageSettings as $value) {
            if (is_array($value)) {
                if (!empty($value[$fieldName]['enabled'])
                    || !empty($value[$fieldName . ' asc']['enabled'])
                    || !empty($value[$fieldName . ' desc']['enabled'])
                ) {
                    return true;
                }
            }
        }
        return false;
    }
}
