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
use Solr\Form\Admin\SolrProfileForm;

class ProfileController extends AbstractActionController
{
    public function browseAction()
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');

        $solrNodeId = $this->params('id');
        $solrNode = $api->read('solr_nodes', $solrNodeId)->getContent();

        $response = $api->search('solr_profiles', ['solr_node_id' => $solrNodeId]);
        $solrProfiles = $response->getContent();

        $view = new ViewModel;
        $view->setVariable('solrNode', $solrNode);
        $view->setVariable('solrProfiles', $solrProfiles);
        return $view;
    }

    public function addAction()
    {
        $serviceLocator = $this->getServiceLocator();

        $form = $this->getForm(SolrProfileForm::class);
        $solrNodeId = $this->params('id');

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                $data = $form->getData();
                $data['o:solr_node']['o:id'] = $solrNodeId;
                $response = $this->api()->create('solr_profiles', $data);
                if ($response->isError()) {
                    $form->setMessages($response->getErrors());
                } else {
                    $this->messenger()->addSuccess('Solr profile created.');
                    return $this->redirect()->toRoute('admin/solr/node-id-profile', [
                        'action' => 'browse',
                        'id' => $solrNodeId,
                    ]);
                }
            } else {
                $this->messenger()->addError('There was an error during validation');
            }
        }

        $view = new ViewModel;
        $view->setVariable('form', $form);
        return $view;
    }

    public function editAction()
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');

        $id = $this->params('id');
        $profile = $api->read('solr_profiles', $id)->getContent();

        $form = new SolrProfileForm($serviceLocator);
        $data = $profile->jsonSerialize();
        $form->setData($data);

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                $formData = $form->getData();
                $response = $this->api()->update('solr_profiles', $id, $formData, [], true);
                if ($response->isError()) {
                    $form->setMessages($response->getErrors());
                } else {
                    $this->messenger()->addSuccess('Solr profile updated.');
                    return $this->redirect()->toRoute('admin/solr/node-id-profile', [
                        'action' => 'browse',
                        'id' => $profile->solrNode()->id(),
                    ]);
                }
            } else {
                $this->messenger()->addError('There was an error during validation');
            }
        }

        $view = new ViewModel;
        $view->setVariable('form', $form);
        return $view;
    }

    public function deleteConfirmAction()
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');
        $id = $this->params('id');
        $response = $api->read('solr_profiles', $id);
        $profile = $response->getContent();

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate('common/delete-confirm-details');
        $view->setVariable('resourceLabel', 'solr profile');
        $view->setVariable('resource', $profile);
        return $view;
    }

    public function deleteAction()
    {
        $id = $this->params('id');
        $profile = $this->api()->read('solr_profiles', $id)->getContent();
        $solrNode = $profile->solrNode();

        if ($this->getRequest()->isPost()) {
            $form = new ConfirmForm($this->getServiceLocator());
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $response = $this->api()->delete('solr_profiles', $id);
                if ($response->isError()) {
                    $this->messenger()->addError('Solr profile could not be deleted');
                } else {
                    $this->messenger()->addSuccess('Solr profile successfully deleted');
                }
            } else {
                $this->messenger()->addError('Solr profile could not be deleted');
            }
        }
        return $this->redirect()->toRoute('admin/solr/node-id-profile', [
            'action' => 'browse',
            'id' => $solrNode->id(),
        ]);
    }
}
