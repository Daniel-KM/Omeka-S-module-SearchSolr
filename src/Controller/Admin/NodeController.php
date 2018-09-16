<?php

/*
 * Copyright BibLibre, 2016
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
use Solr\Form\Admin\SolrNodeForm;
use Solr\Api\Representation\SolrNodeRepresentation;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class NodeController extends AbstractActionController
{
    public function browseAction()
    {
        $response = $this->api()->search('solr_nodes');
        $nodes = $response->getContent();

        $view = new ViewModel;
        $view->setVariable('nodes', $nodes);
        return $view;
    }

    protected function checkPostAndValidForm($form)
    {
        if (!$this->getRequest()->isPost()) {
            return false;
        }

        $form->setData($this->params()->fromPost());
        if (!$form->isValid()) {
            $this->messenger()->addError('There was an error during validation'); // @translate
            return false;
        }
        return true;
    }

    public function addAction()
    {
        $form = $this->getForm(SolrNodeForm::class);

        $view = new ViewModel;
        $view->setVariable('form', $form);

        if (!$this->checkPostAndValidForm($form)) {
            return $view;
        }

        $data = $form->getData();
        $this->api()->create('solr_nodes', $data);
        $this->messenger()->addSuccess('Solr node created.'); // @translate
        $this->messenger()->addWarning('Don’t forget to index the resources before usiing it.'); // @translate
        return $this->redirect()->toRoute('admin/solr');
    }

    public function editAction()
    {
        $id = $this->params('id');
        $node = $this->api()->read('solr_nodes', $id)->getContent();

        $form = $this->getForm(SolrNodeForm::class);
        $data = $node->jsonSerialize();
        $form->setData($data);

        $view = new ViewModel;
        $view->setVariable('form', $form);

        if (!$this->checkPostAndValidForm($form)) {
            return $view;
        }

        $formData = $form->getData();
        $this->api()->update('solr_nodes', $id, $formData);

        $this->messenger()->addSuccess('Solr node updated.'); // @translate
        $this->messenger()->addWarning('Don’t forget to reindex the resources and to check the mapping of the search pages that use this node.'); // @translate

        return $this->redirect()->toRoute('admin/solr');
    }

    public function deleteConfirmAction()
    {
        $id = $this->params('id');
        $response = $this->api()->read('solr_nodes', $id);
        $node = $response->getContent();

        $searchIndexes = $this->searchSearchIndexes($node);
        $searchPages = $this->searchSearchPages($node);
        $solrMappings = $this->api()->search('solr_mappings', ['solr_node_id' => $node->id()])->getContent();

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate('common/delete-confirm-details');
        $view->setVariable('resourceLabel', 'Solr node'); // @translate
        $view->setVariable('resource', $node);
        $view->setVariable('partialPath', 'common/solr-node-delete-confirm-details');
        $view->setVariable('totalSearchIndexes', count($searchIndexes));
        $view->setVariable('totalSearchPages', count($searchPages));
        $view->setVariable('totalSolrMappings', count($solrMappings));
        return $view;
    }

    public function deleteAction()
    {
        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $this->api()->delete('solr_nodes', $this->params('id'));
                $this->messenger()->addSuccess('Solr node successfully deleted'); // @translate
            } else {
                $this->messenger()->addError('Solr node could not be deleted'); // @translate
            }
        }
        return $this->redirect()->toRoute('admin/solr');
    }

    /**
     * Find all search indexes related to a specific solr node.
     *
     * @todo Factorize with MappingController::searchSearchIndexes()
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
     * @todo Factorize with MappingController::searchSearchPages()
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
}
