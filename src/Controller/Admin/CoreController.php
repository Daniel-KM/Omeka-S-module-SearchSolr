<?php

/*
 * Copyright BibLibre, 2016
 * Copyright Daniel Berthereau, 2017-2020
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

namespace SearchSolr\Controller\Admin;

use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\Message;
use Search\Api\Representation\SearchIndexRepresentation;
use Search\Api\Representation\SearchPageRepresentation;
use SearchSolr\Form\Admin\SolrCoreForm;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class CoreController extends AbstractActionController
{
    public function browseAction()
    {
        $response = $this->api()->search('solr_cores');
        $cores = $response->getContent();
        return new ViewModel([
            'cores' => $cores,
        ]);
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
        $form = $this->getForm(SolrCoreForm::class);
        if (!$this->checkPostAndValidForm($form)) {
            return new ViewModel([
                'form' => $form,
            ]);
        }

        $data = $form->getData();
        // SolrClient requires a boolean for the option "secure".
        $data['o:settings']['client']['secure'] = !empty($data['o:settings']['client']['secure']);
        $data['o:settings']['client']['host'] = preg_replace('(^https?://)', '', $data['o:settings']['client']['host']);
        $core = $this->api()->create('solr_cores', $data)->getContent();
        $this->messenger()->addSuccess(new Message('Solr core "%s" created.', $core->name())); // @translate
        $this->messenger()->addWarning('Don’t forget to index the resources before usiing it.'); // @translate
        return $this->redirect()->toRoute('admin/search/solr');
    }

    public function editAction()
    {
        $id = $this->params('id');
        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $core */
        $core = $this->api()->read('solr_cores', $id)->getContent();

        $form = $this->getForm(SolrCoreForm::class);
        $data = $core->jsonSerialize();
        $form->setData($data);

        if (!$this->checkPostAndValidForm($form)) {
            return new ViewModel([
                'form' => $form,
            ]);
        }

        $data = $form->getData();
        // SolrClient requires a boolean for the option "secure".
        $data['o:settings']['client']['secure'] = !empty($data['o:settings']['client']['secure']);
        $data['o:settings']['client']['host'] = preg_replace('(^https?://)', '', $data['o:settings']['client']['host']);
        $this->api()->update('solr_cores', $id, $data);

        $this->messenger()->addSuccess(new Message('Solr core "%s" updated.', $core->name())); // @translate
        $this->messenger()->addWarning('Don’t forget to reindex the resources and to check the mapping of the search pages that use this core.'); // @translate

        return $this->redirect()->toRoute('admin/search/solr');
    }

    public function deleteConfirmAction()
    {
        $id = $this->params('id');
        $response = $this->api()->read('solr_cores', $id);
        $core = $response->getContent();

        $searchIndexes = $this->searchSearchIndexes($core);
        $searchPages = $this->searchSearchPages($core);
        $solrMaps = $this->api()->search('solr_maps', ['solr_core_id' => $core->id()])->getContent();

        $view = new ViewModel([
            'resourceLabel' => 'Solr core', // @translate
            'resource' => $core,
            'partialPath' => 'common/solr-core-delete-confirm-details',
            'totalSearchIndexes' => count($searchIndexes),
            'totalSearchPages' => count($searchPages),
            'totalSolrMaps' => count($solrMaps),
        ]);
        return $view
            ->setTerminal(true)
            ->setTemplate('common/delete-confirm-details');
    }

    public function deleteAction()
    {
        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $this->api()->delete('solr_cores', $this->params('id'));
                $this->messenger()->addSuccess('Solr core successfully deleted'); // @translate
            } else {
                $this->messenger()->addError('Solr core could not be deleted'); // @translate
            }
        }
        return $this->redirect()->toRoute('admin/search/solr');
    }

    public function exportAction()
    {
        $solrCoreId = $this->params('id');
        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

        // Export all maps even empty, so the user will have the headers.
        $filename = $this->exportFilename($solrCore);
        $content = $this->exportSolrMapping($solrCore);

        $response = $this->getResponse();
        $response->setContent($content);

        // @see \Zend\Http\Headers
        $response
            ->getHeaders()
            ->addHeaderLine('Content-Disposition: attachment; filename=' . $filename)
            ->addHeaderLine('Content-Type: text/tab-separated-values')
            // This is the strlen as bytes, not as character.
            ->addHeaderLine('Content-length: ' . strlen($content))
            // When forcing the download of a file over SSL, IE8 and lower
            // browsers fail if the Cache-Control and Pragma headers are not set.
            // @see http://support.microsoft.com/KB/323308
            ->addHeaderLine('Cache-Control: max-age=0')
            ->addHeaderLine('Expires: 0')
            ->addHeaderLine('Pragma: public');

        return $response;
    }

    /**
     * Find all search indexes related to a specific solr core.
     *
     * @todo Factorize with MapController::searchSearchIndexes()
     * @param SolrCoreRepresentation $solrCore
     * @return SearchIndexRepresentation[] Result is indexed by id.
     */
    protected function searchSearchIndexes(SolrCoreRepresentation $solrCore)
    {
        $result = [];
        $api = $this->api();
        $searchIndexes = $api->search('search_indexes', ['adapter' => 'solarium'])->getContent();
        foreach ($searchIndexes as $searchIndex) {
            $searchIndexSettings = $searchIndex->settings();
            if ($solrCore->id() == $searchIndexSettings['adapter']['solr_core_id']) {
                $result[$searchIndex->id()] = $searchIndex;
            }
        }
        return $result;
    }

    /**
     * Find all search pages related to a specific solr core.
     *
     * @todo Factorize with MapController::searchSearchPages()
     * @param SolrCoreRepresentation $solrCore
     * @return SearchPageRepresentation[] Result is indexed by id.
     */
    protected function searchSearchPages(SolrCoreRepresentation $solrCore)
    {
        // TODO Use entity manager to simplify search of pages from core.
        $result = [];
        $api = $this->api();
        $searchIndexes = $this->searchSearchIndexes($solrCore);
        foreach ($searchIndexes as $searchIndex) {
            $searchPages = $api->search('search_pages', ['index_id' => $searchIndex->id()])->getContent();
            foreach ($searchPages as $searchPage) {
                $result[$searchPage->id()] = $searchPage;
            }
        }
        return $result;
    }

    protected function exportFilename(SolrCoreRepresentation $solrCore)
    {
        $base = preg_replace('/[^A-Za-z0-9]/', '_', $solrCore->name());
        $base = $base ? preg_replace('/_+/', '_', $base) . '-' : '';
        $base .= $solrCore->id() . '-';
        $base .= (new \DateTime())->format('Ymd-His');
        $base .= '.tsv';
        return $base;
    }

    protected function exportSolrMapping(SolrCoreRepresentation $solrCore)
    {
        // Because the output is always small, create it in memory in realtime.
        $stream = fopen('php://temp', 'w+');

        $headers = [
            'resource_name',
            'field_name',
            'source',
            'settings:label',
            'settings:formatter',
        ];

        $this->appendTsvRow($stream, $headers);

        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation[] $maps */
        $maps = $this->api()
            ->search('solr_maps', [
                'solr_core_id' => $solrCore->id(),
                'sort_by' => 'source',
                'sort_order' => 'asc',
            ])
            ->getContent();

        foreach ($maps as $map) {
            $settings = $map->settings();
            $mapping = [
                $map->resourceName(),
                $map->fieldName(),
                $map->source(),
                $settings['label'],
                $settings['formatter'],
            ];
            $this->appendTsvRow($stream, $mapping);
        }

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        return $output;
    }

    protected function appendTsvRow($stream, array $fields)
    {
        fputcsv($stream, $fields, "\t", chr(0), chr(0));
    }
}
