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

namespace Solr\Controller\Admin;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use Solr\Form\Admin\SolrFieldForm;

class FieldController extends AbstractActionController
{
    public function browseAction()
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');

        $solrNodeId = $this->params('id');
        $solrNode = $api->read('solr_nodes', $solrNodeId)->getContent();

        $response = $api->search('solr_fields', ['solr_node_id' => $solrNodeId]);
        $solrFields = $response->getContent();

        $view = new ViewModel;
        $view->setVariable('solrNode', $solrNode);
        $view->setVariable('solrFields', $solrFields);
        return $view;
    }

    protected function checkPostAndValidForm($form) {
        if (!$this->getRequest()->isPost())
            return false;

        $form->setData($this->params()->fromPost());
        if (!$form->isValid()) {
            $this->messenger()->addError('There was an error during validation');
            return false;
        }
        return true;
    }


    public function addAction()
    {
        $serviceLocator = $this->getServiceLocator();

        $form = $this->getForm(SolrFieldForm::class);
        $solrNodeId = $this->params('id');
        $view = new ViewModel;
        $view->setVariable('form', $form);
        if (!$this->checkPostAndValidForm($form))
            return $view;


        $data = $form->getData();
        $data['o:solr_node']['o:id'] = $solrNodeId;
        $response = $this->api()->create('solr_fields', $data);
        if ($response->isError()) {
            $form->setMessages($response->getErrors());
            return $view;
        }

        $this->messenger()->addSuccess('Solr field created.');
        return $this->redirect()->toRoute('admin/solr/node-id-field', ['action' => 'browse'], true);

    }

    public function editAction()
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');

        $id = $this->params('id');
        $field = $api->read('solr_fields', $id)->getContent();

        $form = $this->getForm(SolrFieldForm::class);
        $data = $field->jsonSerialize();
        $form->setData($data);
        $view = new ViewModel;
        $view->setVariable('form', $form);

        if (!$this->checkPostAndValidForm($form))
            return $view;

        $formData = $form->getData();
        $response = $this->api()->update('solr_fields', $id, $formData, [], true);
        if ($response->isError()) {
            $form->setMessages($response->getErrors());
            return $view;
        }
        $this->messenger()->addSuccess('Solr field updated.');
        return $this->redirect()->toRoute('admin/solr/node-id-field', [
                        'action' => 'browse',
                        'id' => $field->solrNode()->id(),
                    ]);
        return $view;
    }

    public function deleteConfirmAction()
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');
        $id = $this->params('id');
        $response = $api->read('solr_fields', $id);
        $field = $response->getContent();

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate('common/delete-confirm-details');
        $view->setVariable('resourceLabel', 'solr field');
        $view->setVariable('resource', $field);
        return $view;
    }

    public function deleteAction()
    {
        $id = $this->params('id');
        $field = $this->api()->read('solr_fields', $id)->getContent();

        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $response = $this->api()->delete('solr_fields', $id);
                if ($response->isError()) {
                    $this->messenger()->addError('Solr field could not be deleted');
                } else {
                    $this->messenger()->addSuccess('Solr field successfully deleted');
                }
            } else {
                $this->messenger()->addError('Solr field could not be deleted');
            }
        }

        return $this->redirect()->toRoute('admin/solr/node-id-field', [
            'action' => 'browse',
            'id' => $field->solrNode()->id(),
        ]);
    }
}
