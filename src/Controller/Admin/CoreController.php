<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016
 * Copyright Daniel Berthereau, 2017-2025
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

use AdvancedSearch\Api\Representation\SearchConfigRepresentation;
use Common\Stdlib\PsrMessage;
use finfo;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use SearchSolr\Form\Admin\SolrCoreForm;
use SearchSolr\Form\Admin\SolrCoreMappingImportForm;

class CoreController extends AbstractActionController
{
    /**
     * @var array
     */
    protected $mappingHeaders = [
        'resource_name',
        'field_name',
        'source',
        'pool:filter_values',
        'pool:filter_uris',
        'pool:filter_resources',
        'pool:filter_value_resources',
        'pool:data_types',
        'pool:data_types_exclude',
        'pool:filter_languages',
        'pool:filter_visibility',
        'settings:label',
        'settings:formatter',
    ];

    public function browseAction()
    {
        $response = $this->api()->search('solr_cores');
        $solrCores = $response->getContent();
        return new ViewModel([
            'solrCores' => $solrCores,
        ]);
    }

    public function addAction()
    {
        /** @var \SearchSolr\Form\Admin\SolrCoreForm $form */
        $form = $this->getForm(SolrCoreForm::class, [
            'server_id' => $this->settings()->get('searchsolr_server_id'),
        ]);
        $form->remove('o:settings');

        if (!$this->checkPostAndValidForm($form)) {
            return new ViewModel([
                'form' => $form,
            ]);
        }

        $data = $form->getData();
        $data['o:settings'] = [
            'client' => [
                'scheme' => 'http',
                'host' => 'localhost',
                'port' => '8983',
                'path' => '/',
                'secure' => '0',
            ],
            'server_id' => '',
        ];
        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $this->api()->create('solr_cores', $data)->getContent();
        $this->messenger()->addSuccess(new PsrMessage(
            'Solr core "{solr_core_name}" created.', // @translate
            ['solr_core_name' => $solrCore->name()]
        ));
        return $this->redirect()->toRoute('admin/search/solr/core-id', ['id' => $solrCore->id(), 'action' => 'edit']);
    }

    public function editAction()
    {
        $id = $this->params('id');
        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $this->api()->read('solr_cores', $id)->getContent();

        /** @var \SearchSolr\Form\Admin\SolrCoreForm $form */
        $form = $this->getForm(SolrCoreForm::class, [
            'server_id' => $this->settings()->get('searchsolr_server_id'),
        ]);
        $data = $solrCore->jsonSerialize();

        // The setting "filter_resources" should be a string.
        $data['o:settings']['filter_resources'] = empty($data['o:settings']['filter_resources'])
            ? ''
            : http_build_query($data['o:settings']['filter_resources']);

        $form->setData($data);

        if (!$this->checkPostAndValidForm($form)) {
            return new ViewModel([
                'solrCore' => $solrCore,
                'form' => $form,
            ]);
        }

        $data = $form->getData();
        $clearFullIndex = !empty($data['o:settings']['clear_full_index']);

        // Store query as array to simplify process.
        $filterResources = [];
        parse_str($data['o:settings']['filter_resources'] ?? '', $filterResources);
        $data['o:settings']['filter_resources'] = $filterResources ?: null;

        // SolrClient requires a boolean for the option "secure".
        $data['o:settings']['client']['secure'] = !empty($data['o:settings']['client']['secure']);
        $data['o:settings']['client']['host'] = preg_replace('(^https?://)', '', $data['o:settings']['client']['host']);
        $data['o:settings']['resource_languages'] = implode(' ', array_unique(array_filter(explode(' ', $data['o:settings']['resource_languages']))));
        unset($data['o:settings']['clear_full_index']);
        $this->api()->update('solr_cores', $id, $data);

        $this->messenger()->addSuccess(new PsrMessage(
            'Solr core "{solr_core_name}" updated.', // @translate
            ['solr_core_name' => $solrCore->name()]
        ));

        $missingMaps = $solrCore->missingRequiredMaps();
        if ($missingMaps) {
            $this->messenger()->addError(new PsrMessage(
                'Some required fields are missing or not available in the core: {list}. Update the generic or the resource mappings.', // @translate
                ['list' => implode(', ', array_unique($missingMaps))]
            ));
        }

        if (!empty($data['o:settings']['support'])) {
            $supportFields = $solrCore->schemaSupport($data['o:settings']['support']);
            $unsupportedFields = array_filter($supportFields, fn ($v) => empty($v));
            if (count($unsupportedFields)) {
                $this->messenger()->addError(new PsrMessage(
                    'Some specific static or dynamic fields are missing or not available for "{value}" in the core: {list}.', // @translate
                    ['value' => $data['o:settings']['support'], 'list' => implode(', ', array_keys($unsupportedFields))]
                ));
            }
            $this->messenger()->addWarning('Don’t forget to reindex this core with external indexers.'); // @translate
        } else {
            $this->messenger()->addWarning('Don’t forget to reindex the resources and to check the mapping of the search pages that use this core.'); // @translate
        }

        if ($clearFullIndex) {
            $this->clearFullIndex($solrCore);
            $this->messenger()->addWarning(new PsrMessage(
                'All indexes of core "{solr_core_name}" were deleted.', // @translate
                ['solr_core_name' => $solrCore->name()]
            ));
        }

        return $this->redirect()->toRoute('admin/search/solr');
    }

    public function showAction()
    {
        $solrCoreId = $this->params('id');
        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

        $resourceTypeField = $solrCore->mapsBySource('resource_name', 'generic');
        $resourceTypeField = $resourceTypeField ? (reset($resourceTypeField))->fieldName() : null;

        $counts = $resourceTypeField
            ? $solrCore->queryValuesCount('resource_name_s')
            : [];

        return new ViewModel([
            'solrCore' => $solrCore,
            'resource' => $solrCore,
            'counts' => $counts,
        ]);
    }

    public function deleteConfirmAction()
    {
        $id = $this->params('id');
        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $response = $this->api()->read('solr_cores', $id);
        $solrCore = $response->getContent();

        $searchEngines = $solrCore->searchEngines();
        $searchConfigs = $solrCore->searchConfigs();
        $solrMaps = $solrCore->maps();

        $view = new ViewModel([
            'resource' => $solrCore,
            'resourceLabel' => 'Solr core', // @translate
            'partialPath' => 'common/solr-core-delete-confirm-details',
            'totalSearchEngines' => count($searchEngines),
            'totalSearchConfigs' => count($searchConfigs),
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

    public function importAction()
    {
        $solrCoreId = $this->params('id');
        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

        /** @var \SearchSolr\Form\Admin\SolrCoreMappingImportForm $form */
        $form = $this->getForm(SolrCoreMappingImportForm::class);
        $form->init();

        $view = new ViewModel([
            'solrCore' => $solrCore,
            'form' => $form,
        ]);

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $view;
        }

        $files = $request->getFiles()->toArray();
        if (empty($files)) {
            $this->messenger()->addError('Missing file.'); // @translate
            return $view;
        }

        $post = $this->params()->fromPost();
        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addError('Wrong request for file.'); // @translate
            return $view;
        }

        $file = $files['source'];
        $fileCheck = $this->checkFile($file);
        if (!empty($file['error'])) {
            $this->messenger()->addError('An error occurred when uploading the file.'); // @translate
        } elseif ($fileCheck === false) {
            $this->messenger()->addError(new PsrMessage(
                'Wrong media type ({media_type}) for file.', // @translate
                ['media_type' => $file['type']]
            ));
        } elseif (empty($file['size'])) {
            $this->messenger()->addError('The file is empty.'); // @translate
        } else {
            $data = $form->getData();
            $file = $fileCheck;
            $delimiter = $data['delimiter'] ?? ',';
            $delimiter = $delimiter === 'tabulation' ? "\t" : $delimiter;
            $enclosure = $data['enclosure'] ?? '"';
            $enclosure = $enclosure === 'empty' ? chr(0) : $enclosure;
            $result = $this->importSolrMapping($solrCore, $file['tmp_name'], [
                'type' => $file['type'],
                'delimiter' => $delimiter,
                'enclosure' => $enclosure,
            ]);
            // Messages are already appended.
            if ($result) {
                return $this->redirect()->toRoute('admin/search/solr/core-id-map', ['core-id' => $solrCoreId]);
            }
        }

        return $view;
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

        // @see \Laminas\Http\Headers
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

    public function listDocumentsAction()
    {
        /**
         * @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore
         */
        $id = $this->params('id');
        $solrCore = $this->api()->read('solr_cores', $id)->getContent();

        $resourceName = $this->params()->fromQuery('resource_name') ?: '';

        $ids = $this->params()->fromQuery('id') ?: [];
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        $documents = $solrCore->queryDocuments($resourceName, $ids);

        return (new JsonModel($documents))
            ->setOption('prettyPrint', true);
    }

    public function listResourcesAction()
    {
        $solrCoreId = $this->params('id');
        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

        // The search config is useless here.
        $searchConfig = $this->getSearchConfigAdmin();
        $resourceName = $this->params()->fromQuery('resource_name');
        $missing = (bool) $this->params()->fromQuery('missing');

        $resourceTitles = $solrCore->queryResourceTitles($resourceName);
        if ($missing) {
            // TODO Add a resource filter "not id".
            $resourceTitlesExisting = $this->api()->search($resourceName, [], ['returnScalar' => 'title'])->getContent();
            $resourceTitles = array_diff_key($resourceTitlesExisting, $resourceTitles);
        }

        return (new ViewModel([
            'solrCore' => $solrCore,
            'searchConfig' => $searchConfig,
            'resourceName' => $resourceName,
            'missing' => $missing,
            'resourceTitles' => $resourceTitles,
        ]))->setTerminal(true);
    }

    public function listValuesAction()
    {
        $solrCoreId = $this->params('id');
        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

        $searchConfig = $this->getSearchConfigAdmin();
        $fieldName = $this->params()->fromQuery('fieldname');
        $sort = $this->params()->fromQuery('sort');

        return (new ViewModel([
            'solrCore' => $solrCore,
            'searchConfig' => $searchConfig,
            'fieldName' => $fieldName,
            'sort' => $sort,
        ]))->setTerminal(true);
    }

    protected function checkPostAndValidForm(\Laminas\Form\Form $form)
    {
        if (!$this->getRequest()->isPost()) {
            return false;
        }

        $post = $this->params()->fromPost();
        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addError('There was an error during validation'); // @translate
            return false;
        }
        return true;
    }

    protected function clearFullIndex(SolrCoreRepresentation $solrCore): void
    {
        $solariumClient = $solrCore->solariumClient();
        $update = $solariumClient
            ->createUpdate()
            ->addDeleteQuery('*:*')
            ->addCommit();
        $solariumClient->update($update);
    }

    protected function importSolrMapping(SolrCoreRepresentation $solrCore, $filepath, array $options)
    {
        $rows = $this->extractRows($filepath, $options);
        if (empty($rows)) {
            $this->messenger()->addError(
                'The file does not contain any row.' // @translate
            );
            return false;
        }

        $rows = array_values($rows);
        if (array_values($rows[0]) !== array_values($this->mappingHeaders)) {
            $this->messenger()->addError(
                'The headers of the file are not the default ones. Or the delimiter is not the good one according to the media-type or extension.' // @translate
            );
            return false;
        }
        unset($rows[0]);

        // First loop to check input.
        $result = [];
        foreach ($rows as $key => $row) {
            $current = array_filter($row);
            if (empty($current)) {
                unset($rows[$key]);
            }
            if (empty($row['resource_name'])
                || empty($row['field_name'])
                || empty($row['source'])
            ) {
                $this->messenger()->addWarning(new PsrMessage(
                    'The row #{index} does not contain required data.', // @translate
                    ['index' => $key + 1]
                ));
                unset($rows[$key]);
            } elseif (!in_array($row['resource_name'], ['generic', 'resources', 'items', 'item_sets', 'media'])) {
                $this->messenger()->addWarning(new PsrMessage(
                    'The row #{index} does not manage resource "{resource_name}".', // @translate
                    ['index' => $key + 1, 'resource_name' => $row['resource_name']]
                ));
            } else {
                $result[] = [
                    'o:solr_core' => ['o:id' => $solrCore->id()],
                    'o:resource_name' => $row['resource_name'],
                    'o:field_name' => $row['field_name'],
                    'o:source' => $row['source'],
                    'o:pool' => [
                        'filter_values' => empty($row['pool:filter_values']) ? null : trim($row['pool:filter_values']),
                        'filter_uris' => empty($row['pool:filter_uris']) ? null : trim($row['pool:filter_uris']),
                        'filter_resources' => empty($row['pool:filter_resources']) ? null : trim($row['pool:filter_resources']),
                        'filter_value_resources' => empty($row['pool:filter_value_resources']) ? null : trim($row['pool:filter_value_resources']),
                        'data_types' => empty($row['pool:data_types']) ? [] : array_filter(array_map('trim', explode('|', $row['pool:data_types']))),
                        'data_types_exclude' => empty($row['pool:data_types_exclude']) ? [] : array_filter(array_map('trim', explode('|', $row['pool:data_types_exclude']))),
                        // Don't filter array to keep values without language.
                        'filter_languages' => empty($row['pool:filter_languages']) ? [] : array_unique(array_map('trim', explode('|', $row['pool:filter_languages']))),
                        'filter_visibility' => empty($row['pool:filter_visibility']) || !in_array($row['pool:filter_visibility'], ['public', 'private']) ? null : $row['pool:filter_visibility'],
                    ],
                    'o:settings' => [
                        'formatter' => $row['settings:formatter'],
                        'label' => $row['settings:label'],
                    ],
                ];
            }
        }
        if (!count($result)) {
            $this->messenger()->addError(
                'The file does not contain any valid data.' // @translate
            );
            return false;
        }

        // Second loop to import data, after removing existing mapping.
        /** @var \Omeka\Mvc\Controller\Plugin\Api $api */
        $api = $this->api();
        $maps = $solrCore->maps();
        if (count($maps)) {
            $api->batchDelete('solr_maps', array_keys($maps));
            $this->messenger()->addNotice(new PsrMessage(
                'The existing mapping of core "{solr_core_name}" (#{solr_core_id}) has been deleted.', // @translate
                ['solr_core_name' => $solrCore->name(), 'solr_core_id' => $solrCore->id()]
            ));
        }

        $response = $api->batchCreate('solr_maps', $result);
        if (!$response) {
            $this->messenger()->addError(new PsrMessage(
                'An error has occurred during import of the mapping for core "{solr_core_name}" (#{solr_core_id}).', // @translate
                ['solr_core_name' => $solrCore->name(), 'solr_core_id' => $solrCore->id()]
            ));
            return false;
        }

        $this->messenger()->addSuccess(new PsrMessage(
            '{count} fields have been mapped for core "{solr_core_name}" (#{solr_core_id}).', // @translate
            ['count' => count($result), 'solr_core_name' => $solrCore->name(), 'solr_core_id' => $solrCore->id()]
        ));

        return true;
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

        // Prepend the utf-8 bom to support Windows.
        fwrite($stream, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $this->appendTsvRow($stream, $this->mappingHeaders);

        foreach ($solrCore->mapsByResourceName() as $resourceName => $maps) {
            /** @var \SearchSolr\Api\Representation\SolrMapRepresentation $map */
            foreach ($maps as $map) {
                $mapping = [
                    $resourceName,
                    $map->fieldName(),
                    $map->source(),
                    (string) $map->pool('filter_values'),
                    (string) $map->pool('filter_uris'),
                    (string) $map->pool('filter_resources'),
                    (string) $map->pool('filter_value_resources'),
                    implode(' | ', $map->pool('data_types')),
                    implode(' | ', $map->pool('data_types_exclude')),
                    implode(' | ', $map->pool('filter_languages')),
                    (string) $map->pool('filter_visibility'),
                    $map->setting('label', ''),
                    $map->setting('formatter', ''),
                ];
                $this->appendTsvRow($stream, $mapping);
            }
        }

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        return $output;
    }

    protected function appendTsvRow($stream, array $fields): void
    {
        fputcsv($stream, $fields, "\t", chr(0), chr(0));
    }

    protected function extractRows(string $filepath, array $options = []): array
    {
        $options += [
            'type' => 'text/csv',
            'delimiter' => ',',
            'enclosure' => '"',
        ];
        if ($options['type'] === 'text/tab-separated-values') {
            $options['delimiter'] = "\t";
        }
        $delimiter = $options['delimiter'];
        $enclosure = $options['enclosure'];

        // fgetcsv is not used to avoid issues with bom.
        $content = file_get_contents($filepath);
        $content = mb_convert_encoding($content, 'UTF-8');
        if (substr($content, 0, 3) === chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            $content = substr($content, 3);
        }
        if (empty($content)) {
            return [];
        }

        $countHeaders = count($this->mappingHeaders);
        $rows = array_map(fn ($v) => str_getcsv($v, $delimiter, $enclosure), array_map('trim', explode("\n", $content)));
        foreach ($rows as $key => $row) {
            if (empty(array_filter($row))) {
                unset($rows[$key]);
                continue;
            }
            if (count($row) < $countHeaders) {
                $row = array_slice(array_merge($row, array_fill(0, $countHeaders, '')), 0, $countHeaders);
            } elseif (count($row) > $countHeaders) {
                $row = array_slice($row, 0, $countHeaders);
            }
            $rows[$key] = array_combine($this->mappingHeaders, array_map('trim', $row));
        }

        $rows = array_values(array_filter($rows));
        if (!isset($rows[0]['resource_name'])) {
            return [];
        }

        return $rows;
    }

    /**
     * Check the file, according to its media type.
     *
     * @todo Use the class TempFile before.
     *
     * @param array $fileData
     *            File data from a post ($_FILES).
     * @return array|bool
     */
    protected function checkFile(array $fileData)
    {
        if (empty($fileData) || empty($fileData['tmp_name'])) {
            return false;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($fileData['tmp_name']);
        $extension = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
        $fileData['extension'] = $extension;

        // Manage an exception for a very common format, undetected by fileinfo.
        if ($mediaType === 'text/plain' || $mediaType === 'application/octet-stream') {
            $extensions = [
                'txt' => 'text/plain',
                'csv' => 'text/csv',
                'tab' => 'text/tab-separated-values',
                'tsv' => 'text/tab-separated-values',
            ];
            if (isset($extensions[$extension])) {
                $mediaType = $extensions[$extension];
                $fileData['type'] = $mediaType;
            }
        }

        $supporteds = [
            // 'application/vnd.oasis.opendocument.spreadsheet' => true,
            'text/plain' => true,
            'text/tab-separated-values' => true,
            'application/csv' => true,
        ];
        if (!isset($supporteds[$mediaType])) {
            return false;
        }

        return $fileData;
    }

    protected function getSearchConfigAdmin(): ?SearchConfigRepresentation
    {
        $searchConfig = $this->settings()->get('advancedsearch_main_config');
        if (!$searchConfig) {
            return null;
        }

        try {
            return $this->api()->read('search_configs', [is_numeric($searchConfig) ? 'id' : 'slug' => $searchConfig])->getContent();
        } catch (\Exception $e) {
            return null;
        }
    }
}
