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
use Solr\Form\Admin\SolrProfileRuleForm;

class ProfileRuleController extends AbstractActionController
{
    public function browseAction()
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');

        $solrProfileId = $this->params('id');
        $solrProfile = $api->read('solr_profiles', $solrProfileId)->getContent();

        $response = $api->search('solr_profile_rules', [
            'solr_profile_id' => $solrProfileId,
        ]);
        $solrProfileRules = $response->getContent();

        $view = new ViewModel;
        $view->setVariable('solrProfile', $solrProfile);
        $view->setVariable('solrProfileRules', $solrProfileRules);
        return $view;
    }

    public function addAction()
    {
        $serviceLocator = $this->getServiceLocator();

        $solrProfileId = $this->params('id');
        $form = $this->getForm(SolrProfileRuleForm::class, [
            'solr_profile_id' => $solrProfileId,
        ]);

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                $data = $form->getData();
                $data['o:solr_profile']['o:id'] = $solrProfileId;
                $response = $this->api()->create('solr_profile_rules', $data);
                if ($response->isError()) {
                    $form->setMessages($response->getErrors());
                } else {
                    $this->messenger()->addSuccess('Solr profile rule created.');
                    return $this->redirect()->toRoute(
                        'admin/solr/profile-id-rule',
                        [
                            'id' => $solrProfileId,
                            'action' => 'browse',
                        ]
                    );
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

        $solrProfileRuleId = $this->params('id');
        $response = $api->read('solr_profile_rules', $solrProfileRuleId);
        $solrProfileRule = $response->getContent();
        $form = $this->getForm(SolrProfileRuleForm::class, [
            'solr_profile_id' => $solrProfileRule->solrProfile()->id(),
        ]);

        $data = $solrProfileRule->jsonSerialize();
        $data['o:solr_field'] = $data['o:solr_field']->jsonSerialize();
        $form->setData($data);

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                $formData = $form->getData();
                $response = $this->api()->update('solr_profile_rules', $solrProfileRuleId, $formData, [], true);
                if ($response->isError()) {
                    $form->setMessages($response->getErrors());
                } else {
                    $this->messenger()->addSuccess('Solr profile rule updated.');
                    return $this->redirect()->toRoute('admin/solr/profile-id-rule', [
                        'id' => $solrProfileRule->solrProfile()->id(),
                        'action' => 'browse',
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
        $solrProfileRuleId = $this->params('id');
        $response = $api->read('solr_profile_rules', $solrProfileRuleId);
        $solrProfileRule = $response->getContent();

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate('common/delete-confirm-details');
        $view->setVariable('resourceLabel', 'solr profile rule');
        $view->setVariable('resource', $solrProfileRule);
        return $view;
    }

    public function deleteAction()
    {
        $solrProfileRuleId = $this->params('id');
        $response = $this->api()->read('solr_profile_rules', $solrProfileRuleId);
        $solrProfileRule = $response->getContent();

        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $response = $this->api()->delete('solr_profile_rules', $solrProfileRuleId);
                if ($response->isError()) {
                    $this->messenger()->addError('Solr profile rule could not be deleted');
                } else {
                    $this->messenger()->addSuccess('Solr profile rule successfully deleted');
                }
            } else {
                $this->messenger()->addError('Solr profile rule could not be deleted');
            }
        }

        return $this->redirect()->toRoute('admin/solr/profile-id-rule', [
            'id' => $solrProfileRule->solrProfile()->id(),
            'action' => 'browse',
        ]);
    }
}
