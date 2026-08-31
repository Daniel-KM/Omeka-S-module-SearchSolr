<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016
 * Copyright Daniel Berthereau, 2017-2026
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
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SearchSolr\Api\Adapter\TraitArrayFilterRecursiveEmptyValue;
use SearchSolr\Stdlib\SolrCore as SolrCoreRepresentation;
use SearchSolr\Form\Admin\SolrCoreForm;
use SearchSolr\Form\Admin\SolrCoreMappingImportForm;
use SearchSolr\ValueExtractor\Manager as ValueExtractorManager;

class CoreController extends AbstractActionController
{
    use TraitArrayFilterRecursiveEmptyValue;
    use TraitSolrController;

    /**
     * The structure should be the same in import and export.
     *
     * @see self::importSolrMapping()
     * @see self::exportSolrMapping()
     *
     * @var array
     */
    protected $mappingHeaders = [
        'resource_name',
        'field_name',
        'alias',
        'source',
        // Pool.
        'pool:filter_values',
        'pool:filter_uris',
        'pool:filter_resources',
        'pool:filter_value_resources',
        'pool:data_types',
        'pool:data_types_exclude',
        'pool:filter_languages',
        'pool:filter_visibility',
        // Settings.
        'settings:label',
        'settings:parts',
        'settings:formatter',
        'settings:normalization',
        'settings:max_length',
        'settings:place_mode',
        'settings:table',
        'settings:table_mode',
        'settings:table_index_original',
        'settings:table_index_strict',
        'settings:thesaurus_resources',
        'settings:thesaurus_self',
        'settings:thesaurus_metadata',
        'settings:finalization',
    ];

    /**
     * @var \SearchSolr\ValueExtractor\Manager
     */
    protected $valueExtractorManager;

    public function __construct(ValueExtractorManager $valueExtractorManager)
    {
        $this->valueExtractorManager = $valueExtractorManager;
    }

    public function browseAction()
    {
        // The cores are listed in the table of the search engines of the search
        // manager.
        return $this->redirect()->toRoute('admin/search-manager');
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
        // A core is a facet of a solarium engine: adding a core creates the
        // engine, with a default connection to edit next.
        $engine = $this->api()->create('search_engines', [
            'o:name' => $data['o:name'],
            'o:engine_adapter' => 'solarium',
            'o:settings' => [
                'resource_types' => ['items', 'item_sets'],
                'solr' => [
                    'client' => [
                        'scheme' => 'http',
                        'host' => 'localhost',
                        'port' => '8983',
                        'path' => '/',
                        'secure' => '0',
                    ],
                    'server_id' => '',
                ],
            ],
        ])->getContent();
        $this->messenger()->addSuccess(new PsrMessage(
            'Solr core "{solr_core_name}" created.', // @translate
            ['solr_core_name' => $engine->name()]
        ));
        return $this->redirect()->toRoute('admin/search-manager/solr/core-id', ['id' => $engine->id(), 'action' => 'edit']);
    }

    public function editAction()
    {
        /**
         * @var \SearchSolr\Stdlib\SolrCore $solrCore
         */
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        // Detect copy field and its type for informational message in form.
        $copyFieldInfo = null;
        try {
            $schema = $solrCore->schema();
            $hasDefaultField = $schema->checkDefaultField();
            if ($hasDefaultField) {
                $textFieldType = $schema->getFieldsByName()['_text_']['type'] ?? null;
                $copyFieldInfo = [
                    'has_copy_field' => true,
                    'field_type' => $textFieldType,
                ];
            } else {
                $copyFieldInfo = [
                    'has_copy_field' => false,
                    'field_type' => null,
                ];
            }
        } catch (\Throwable $e) {
            // Schema not accessible.
        }

        /** @var \SearchSolr\Form\Admin\SolrCoreForm $form */
        $form = $this->getForm(SolrCoreForm::class, [
            'server_id' => $this->settings()->get('searchsolr_server_id'),
            'copy_field_info' => $copyFieldInfo,
            // The name belongs to the engine and is edited on its form.
            'skip_name' => true,
        ]);
        $data = [
            'o:settings' => $solrCore->settings(),
        ];

        // Secret fields (password, admin_password) are never prefilled: the
        // Secret view helper blanks them and the adapter keeps the stored value
        // on empty submission, so they are neither echoed nor cleared on edit.

        // The setting "filter_resources" should be a string.
        $data['o:settings']['filter_resources'] = empty($data['o:settings']['filter_resources'])
            ? ''
            : http_build_query($data['o:settings']['filter_resources']);

        $form->setData($data);

        // Flag the Secret fields that already hold a value, so the helper shows
        // the "set" icon and the control to remove the saved password.
        $clientFieldset = $form->get('o:settings')->get('client');
        $storedClient = (array) $solrCore->setting('client', []);
        foreach (['password', 'admin_password'] as $key) {
            if (!empty($storedClient[$key])) {
                $clientFieldset->get($key)->setOption('has_value', true);
            }
        }

        if (!$this->checkPostAndValidForm($form)) {
            return new ViewModel([
                'solrCore' => $solrCore,
                'form' => $form,
            ]);
        }

        $data = $form->getData();

        // The Secret "remove" checkboxes are raw markup, not form elements, so
        // they are absent from getData(): carry them over from the raw post so
        // the adapter can clear the stored password.
        $postClient = $this->params()->fromPost('o:settings')['client'] ?? [];
        foreach (['password_remove', 'admin_password_remove'] as $key) {
            if (!empty($postClient[$key])) {
                $data['o:settings']['client'][$key] = $postClient[$key];
            }
        }

        // Store query as array to simplify process.
        $filterResources = [];
        parse_str($data['o:settings']['filter_resources'] ?? '', $filterResources);
        $data['o:settings']['filter_resources'] = $filterResources ?: null;

        // SolrClient requires a boolean for the option "secure".
        $data['o:settings']['client']['secure'] = !empty($data['o:settings']['client']['secure']);
        $data['o:settings']['client']['host'] = preg_replace('(^https?://)', '', $data['o:settings']['client']['host']);
        $data['o:settings']['resource_languages'] = implode(' ', array_unique(array_filter(explode(' ', $data['o:settings']['resource_languages']))));
        $data['o:settings']['field_boost'] = $this->prepareFieldsBoost($solrCore);

        // Query relevance settings (minimum_match, tie_breaker) are edited on
        // the show page, not in this form: preserve them so a full save here
        // does not wipe them.
        $existingQuery = $solrCore->settings()['query'] ?? [];
        foreach (['minimum_match', 'tie_breaker'] as $key) {
            if (isset($existingQuery[$key]) && $existingQuery[$key] !== '') {
                $data['o:settings']['query'][$key] = $existingQuery[$key];
            }
        }

        // Passwords are stored encrypted at rest; an empty submission keeps
        // the stored value and the "remove" checkbox clears it (logic moved
        // from the former core adapter).
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $cipher = $services->get('Omeka\Cipher');
        $storedClient = (array) ($solrCore->settings()['client'] ?? []);
        foreach (['password', 'admin_password'] as $key) {
            $remove = !empty($data['o:settings']['client'][$key . '_remove']);
            unset($data['o:settings']['client'][$key . '_remove']);
            if ($remove) {
                unset($data['o:settings']['client'][$key]);
            } elseif (!empty($data['o:settings']['client'][$key])) {
                $data['o:settings']['client'][$key] = $cipher->encrypt((string) $data['o:settings']['client'][$key]);
            } elseif (!empty($storedClient[$key])) {
                $data['o:settings']['client'][$key] = $storedClient[$key];
            }
        }

        // Keep the solr settings managed outside of this form, in particular
        // the snapshots of the maps (backup_maps).
        $data['o:settings'] = array_replace($solrCore->settings(), $data['o:settings']);

        $this->updateSolrSettings((int) $id, $data['o:settings']);

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

        $maps = $solrCore->maps();
        if (count($maps) > 1024) {
            $this->messenger()->addNotice(new PsrMessage(
                'The core "{solr_core_name}" has {count} maps. Some queries are not possible with more than 1024 indexes. You may remove indexes used to sort ("_s") or useless indexes, or group them.', // @translate
                ['solr_core_name' => $solrCore->name(), 'count' => count($maps)]
            ));
        }

        return $this->redirect()->toRoute('admin/search-manager');
    }

    public function showAction()
    {
        /**
         * @var \SearchSolr\Stdlib\SolrCore $solrCore
         */
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        $missingMaps = $solrCore->missingRequiredMaps();
        if ($missingMaps) {
            $this->messenger()->addError(new PsrMessage(
                'Some required fields are missing or not available in the core: {fields}. Update the generic or the resource mappings.', // @translate
                ['fields' => implode(', ', array_unique($missingMaps))]
            ));
        }

        $fieldStatus = $solrCore->fieldLimitStatus();
        if ($fieldStatus && $fieldStatus['maxFields']) {
            if ($fieldStatus['exceeded']) {
                $this->messenger()->addError(new PsrMessage(
                    'The Solr core has {numFields} fields, exceeding the configured limit of {maxFields}. Indexing will be refused. To fix, either reduce or group field maps, or increase "maxFields" in solrconfig.xml and restart Solr.', // @translate
                    [
                        'numFields' => $fieldStatus['numFields'],
                        'maxFields' => $fieldStatus['maxFields'],
                    ]
                ));
            } elseif ($fieldStatus['numFields'] > $fieldStatus['maxFields'] * 0.9) {
                $this->messenger()->addWarning(new PsrMessage(
                    'The Solr core has {numFields} fields, approaching the configured limit of {maxFields} ({percentage}%). It is recommended either to reduce or to group field maps, or to increase "maxFields" in solrconfig.xml and restart Solr.', // @translate
                    [
                        'numFields' => $fieldStatus['numFields'],
                        'maxFields' => $fieldStatus['maxFields'],
                        'percentage' => round($fieldStatus['numFields'] / $fieldStatus['maxFields'] * 100),
                    ]
                ));
            }
        }

        return new ViewModel([
            'solrCore' => $solrCore,
            'resource' => $solrCore,
        ]);
    }

    public function showIndexingStatsAction()
    {
        /**
         * @var \SearchSolr\Stdlib\SolrCore $solrCore
         */
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        $counts = $this->getIndexedResourceCounts($solrCore);

        $missingMaps = $solrCore->missingRequiredMaps();
        if ($missingMaps) {
            $this->messenger()->addError(new PsrMessage(
                'Some required fields are missing or not available in the core: {fields}. Update the generic or the resource mappings.', // @translate
                ['fields' => implode(', ', array_unique($missingMaps))]
            ));
        }

        return new ViewModel([
            'solrCore' => $solrCore,
            'resource' => $solrCore,
            'counts' => $counts,
        ]);
    }

    public function deleteConfirmAction()
    {
        /**
         * @var \SearchSolr\Stdlib\SolrCore $solrCore
         */
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

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
                // A core is a facet of its engine: deleting it deletes the
                // engine, with its maps, search pages and suggesters.
                $this->api()->delete('search_engines', $this->params('id'));
                $this->messenger()->addSuccess('Solr core successfully deleted'); // @translate
            } else {
                $this->messenger()->addError('Solr core could not be deleted'); // @translate
            }
        }
        return $this->redirect()->toRoute('admin/search-manager');
    }

    public function importAction()
    {
        /**
         * @var \SearchSolr\Stdlib\SolrCore $solrCore
         */
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

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
            $enclosure = $enclosure === 'empty' ? "\0" : $enclosure;
            $result = $this->importSolrMapping($solrCore, $file['tmp_name'], [
                'type' => $file['type'],
                'delimiter' => $delimiter,
                'enclosure' => $enclosure,
            ]);
            // Messages are already appended.
            if ($result) {
                return $this->redirect()->toRoute('admin/search-manager/solr/core-id', ['id' => $id]);
            }
        }

        return $view;
    }

    public function exportAction()
    {
        /**
         * @var \SearchSolr\Stdlib\SolrCore $solrCore
         */
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

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
            // browsers fail if the Cache-Control and Pragma headers aren't set.
            // @see http://support.microsoft.com/KB/323308
            ->addHeaderLine('Cache-Control: max-age=0')
            ->addHeaderLine('Expires: 0')
            ->addHeaderLine('Pragma: public');

        return $response;
    }

    public function listDocumentsAction()
    {
        /**
         * @var \SearchSolr\Stdlib\SolrCore $solrCore
         */
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

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
        /**
         * @var \SearchSolr\Stdlib\SolrCore $solrCore
         */
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

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
        /**
         * @var \SearchSolr\Stdlib\SolrCore $solrCore
         */
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

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

        $cleanArray = fn (string $v): array => array_values(array_unique(array_filter(explode(' ', str_replace(['/', ',', '|'], ' ', $v)))));

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
            } elseif (!in_array($row['resource_name'], ['generic', 'resources', 'items', 'item_sets', 'media', 'digital_objects', 'concepts'])) {
                $this->messenger()->addWarning(new PsrMessage(
                    'The row #{index} does not manage resource "{resource_name}".', // @translate
                    ['index' => $key + 1, 'resource_name' => $row['resource_name']]
                ));
            } else {
                // The structure should be the same in import and export.
                $result[] = [
                    'o:solr_core' => ['o:id' => $solrCore->id()],
                    'o:resource_name' => $row['resource_name'],
                    'o:field_name' => $row['field_name'],
                    'o:alias' => $row['alias'] ?? '',
                    'o:source' => $row['source'],
                    'o:pool' => $this->arrayFilterRecursiveEmptyValue([
                        'filter_values' => empty($row['pool:filter_values']) ? null : trim($row['pool:filter_values']),
                        'filter_uris' => empty($row['pool:filter_uris']) ? null : trim($row['pool:filter_uris']),
                        'filter_resources' => empty($row['pool:filter_resources']) ? null : trim($row['pool:filter_resources']),
                        'filter_value_resources' => empty($row['pool:filter_value_resources']) ? null : trim($row['pool:filter_value_resources']),
                        'data_types' => empty($row['pool:data_types']) ? [] : array_filter(array_map('trim', explode('|', $row['pool:data_types']))),
                        'data_types_exclude' => empty($row['pool:data_types_exclude']) ? [] : $cleanArray($row['pool:data_types_exclude']),
                        // Don't filter array to keep values without language.
                        'filter_languages' => empty($row['pool:filter_languages']) ? [] : $cleanArray($row['pool:filter_languages']),
                        'filter_visibility' => empty($row['pool:filter_visibility']) || !in_array($row['pool:filter_visibility'], ['public', 'private']) ? null : $row['pool:filter_visibility'],
                    ]),
                    'o:settings' => $this->arrayFilterRecursiveEmptyValue([
                        'label' => $row['settings:label'],
                        'parts' => empty($row['settings:parts']) ? [] : $cleanArray($row['settings:parts']),
                        'formatter' => $row['settings:formatter'],
                        'normalization' => empty($row['settings:normalization']) ? [] : $cleanArray($row['settings:normalization']),
                        'max_length' => empty($row['settings:max_length']) ? null : (int) $row['settings:max_length'],
                        'place_mode' => empty($row['settings:place_mode']) ? null : trim($row['settings:place_mode']),
                        'table' => empty($row['settings:table']) ? null : trim($row['settings:table']),
                        'table_mode' => empty($row['settings:table_mode']) ? null : trim($row['settings:table_mode']),
                        'table_index_original' => !empty($row['settings:table_index_original']) ?: null,
                        'table_index_strict' => !empty($row['settings:table_index_strict']) ?: null,
                        'thesaurus_resources' => empty($row['settings:thesaurus_resources']) ? null : $row['settings:thesaurus_resources'],
                        'thesaurus_self' => !empty($row['settings:thesaurus_self']) ?: null,
                        'thesaurus_metadata' => empty($row['settings:thesaurus_metadata']) ? [] : $cleanArray($row['settings:thesaurus_metadata']),
                        'finalization' => empty($row['settings:finalization']) ? [] : $cleanArray($row['settings:finalization']),
                    ]),
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
            $this->messenger()->addSuccess(new PsrMessage(
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
                // The structure should be the same in import and export.
                $mapping = [
                    $resourceName,
                    $map->fieldName(),
                    (string) $map->alias(),
                    $map->source(),
                    // Pool.
                    (string) $map->pool('filter_values'),
                    (string) $map->pool('filter_uris'),
                    (string) $map->pool('filter_resources'),
                    (string) $map->pool('filter_value_resources'),
                    implode(' | ', $map->pool('data_types', [])),
                    implode(' | ', $map->pool('data_types_exclude', [])),
                    implode(' | ', $map->pool('filter_languages', [])),
                    (string) $map->pool('filter_visibility'),
                    // Settings.
                    (string) $map->setting('label', ''),
                    implode(' | ', $map->setting('parts', [])),
                    (string) $map->setting('formatter', ''),
                    implode(' | ', $map->setting('normalization', [])),
                    (string) $map->setting('max_length', ''),
                    (string) $map->setting('place_mode', ''),
                    (string) $map->setting('table', ''),
                    (string) $map->setting('table_mode', ''),
                    (string) $map->setting('table_index_original', ''),
                    (string) $map->setting('table_index_strict', ''),
                    (string) $map->setting('thesaurus_resources', ''),
                    (string) $map->setting('thesaurus_self', ''),
                    implode(' | ', $map->setting('thesaurus_metadata', [])),
                    implode(' | ', $map->setting('finalization', [])),
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
        fputcsv($stream, $fields, "\t", "\0", "\0");
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
     * @todo Use OpenSpount (see module Locate).
     *
     * @param array $fileData File data from a post ($_FILES).
     * @return array|bool
     */
    protected function checkFile(array $fileData)
    {
        if (empty($fileData)
            || empty($fileData['tmp_name'])
            || empty($fileData['type'])
        ) {
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
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Create the catchall copyField "_text_" in Solr for full-text search.
     */
    public function createCatchallAction()
    {
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        $schema = $solrCore->schema();

        // Check if _text_ already exists.
        if ($schema->checkDefaultField()) {
            $this->messenger()->addWarning(new PsrMessage(
                'The catchall field "_text_" already exists in core "{solr_core_name}".', // @translate
                ['solr_core_name' => $solrCore->name()]
            ));
            return $this->redirect()->toRoute('admin/search-manager/solr/core-id', [
                'id' => $id,
                'action' => 'show',
            ]);
        }

        // Create the copyField via Solr API.
        try {
            $solariumClient = $solrCore->solariumClient();
            $endpoint = $solariumClient->getEndpoint();
            $url = $endpoint->getBaseUri() . 'schema';

            $data = json_encode([
                'add-copy-field' => [
                    'source' => '*',
                    'dest' => '_text_',
                ],
            ]);

            $httpClient = new \Laminas\Http\Client($url, [
                'timeout' => 30,
            ]);
            $httpClient->setMethod('POST');
            $httpClient->setHeaders(['Content-Type' => 'application/json']);
            $httpClient->setRawBody($data);
            $response = $httpClient->send();

            if ($response->isSuccess()) {
                $this->messenger()->addSuccess(new PsrMessage(
                    'Catchall field "_text_" created in core "{solr_core_name}". Reindex required.', // @translate
                    ['solr_core_name' => $solrCore->name()]
                ));
            } else {
                $body = json_decode($response->getBody(), true);
                $error = $body['error']['msg'] ?? $response->getReasonPhrase();
                $this->messenger()->addError(new PsrMessage(
                    'Failed to create catchall field: {error}', // @translate
                    ['error' => $error]
                ));
            }
        } catch (\Throwable $e) {
            $this->messenger()->addError(new PsrMessage(
                'Error creating catchall field: {error}', // @translate
                ['error' => $e->getMessage()]
            ));
        }

        return $this->redirect()->toRoute('admin/search-manager/solr/core-id', [
            'id' => $id,
            'action' => 'show',
        ]);
    }

    /**
     * Delete the catchall copyField "_text_" in Solr (inverse of create).
     */
    public function deleteCatchallAction()
    {
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        $schema = $solrCore->schema();

        // Check if _text_ copyField exists.
        if (!$schema->checkDefaultField()) {
            $this->messenger()->addWarning(new PsrMessage(
                'The catchall field "_text_" does not exist in core "{solr_core_name}".', // @translate
                ['solr_core_name' => $solrCore->name()]
            ));
            return $this->redirect()->toRoute('admin/search-manager/solr/core-id', [
                'id' => $id,
                'action' => 'show',
            ]);
        }

        // Delete the copyField via Solr API.
        try {
            $solariumClient = $solrCore->solariumClient();
            $endpoint = $solariumClient->getEndpoint();
            $url = $endpoint->getBaseUri() . 'schema';

            $data = json_encode([
                'delete-copy-field' => [
                    'source' => '*',
                    'dest' => '_text_',
                ],
            ]);

            $httpClient = new \Laminas\Http\Client($url, [
                'timeout' => 30,
            ]);
            $httpClient->setMethod('POST');
            $httpClient->setHeaders(['Content-Type' => 'application/json']);
            $httpClient->setRawBody($data);
            $response = $httpClient->send();

            if ($response->isSuccess()) {
                $this->messenger()->addSuccess(new PsrMessage(
                    'Catchall field "_text_" deleted in core "{solr_core_name}".', // @translate
                    ['solr_core_name' => $solrCore->name()]
                ));
            } else {
                $body = json_decode($response->getBody(), true);
                $error = $body['error']['msg'] ?? $response->getReasonPhrase();
                $this->messenger()->addError(new PsrMessage(
                    'Failed to delete catchall field: {error}', // @translate
                    ['error' => $error]
                ));
            }
        } catch (\Throwable $e) {
            $this->messenger()->addError(new PsrMessage(
                'Error deleting catchall field: {error}', // @translate
                ['error' => $e->getMessage()]
            ));
        }

        return $this->redirect()->toRoute('admin/search-manager/solr/core-id', [
            'id' => $id,
            'action' => 'show',
        ]);
    }

    /**
     * Disable the data-driven (schemaless) mode of the "_default" config set.
     */
    public function disableDataDrivenAction()
    {
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        try {
            $solariumClient = $solrCore->solariumClient();
            $endpoint = $solariumClient->getEndpoint();
            $url = $endpoint->getBaseUri() . 'config';

            $data = json_encode([
                'set-user-property' => [
                    'update.autoCreateFields' => 'false',
                ],
            ]);

            $httpClient = new \Laminas\Http\Client($url, [
                'timeout' => 30,
            ]);
            $httpClient->setMethod('POST');
            $httpClient->setHeaders(['Content-Type' => 'application/json']);
            $httpClient->setRawBody($data);
            $response = $httpClient->send();

            if ($response->isSuccess()) {
                $this->messenger()->addSuccess(new PsrMessage(
                    'Data-driven schema disabled (update.autoCreateFields = false) on core "{solr_core_name}".', // @translate
                    ['solr_core_name' => $solrCore->name()]
                ));
            } else {
                $body = json_decode($response->getBody(), true);
                $error = $body['error']['msg'] ?? $response->getReasonPhrase();
                $this->messenger()->addError(new PsrMessage(
                    'Failed to disable data-driven schema: {error}', // @translate
                    ['error' => $error]
                ));
            }
        } catch (\Throwable $e) {
            $this->messenger()->addError(new PsrMessage(
                'Error disabling data-driven schema: {error}', // @translate
                ['error' => $e->getMessage()]
            ));
        }

        return $this->redirect()->toRoute('admin/search-manager/solr/core-id', [
            'id' => $id,
            'action' => 'show',
        ]);
    }

    public function optimizeAction()
    {
        $id = $this->params('id');

        $job = $this->jobDispatcher()->dispatch(
            \SearchSolr\Job\OptimizeSolrIndex::class,
            ['solr_core_id' => (int) $id]
        );

        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Optimizing index in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlPlugin->fromRoute(
                        'admin/id',
                        ['controller' => 'job', 'id' => $job->getId()]
                    ))
                ),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf(
                        '<a href="%1$s">',
                        htmlspecialchars($urlPlugin->fromRoute(
                            'admin/default',
                            ['controller' => 'log'],
                            ['query' => ['job_id' => $job->getId()]]
                        ))
                    )
                    : sprintf(
                        '<a href="%1$s" target="_blank" rel="noopener noreferrer">',
                        htmlspecialchars($urlPlugin->fromRoute(
                            'admin/id',
                            ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]
                        ))
                    ),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute(
            'admin/search-manager/solr/core-id',
            ['id' => $id]
        );
    }

    public function createCoreOnServerAction()
    {
        $id = $this->params('id');
        /** @var \SearchSolr\Stdlib\SolrCore $solrCore */
        $solrCore = $this->solrCore((int) $id);

        $connection = $solrCore->clientSettings();
        $coreName = (string) ($connection['core'] ?? '');
        $configSet = trim((string) ($connection['config_set'] ?? '')) ?: '_default';
        $logger = $solrCore->getServiceLocator()->get('Omeka\Logger');
        $coreAdmin = new \SearchSolr\Solr\CoreAdmin($logger);

        // A core created manually on the server is reused without creation.
        if ($coreAdmin->coreExists($connection, $coreName)) {
            $this->messenger()->addWarning(new PsrMessage(
                'The core "{core}" already exists on the server; it is used as is, nothing was created.', // @translate
                ['core' => $coreName]
            ));
            return $this->redirect()->toRoute('admin/search-manager/solr/core-id', ['id' => $id]);
        }

        if (!$coreAdmin->createCore($connection, $coreName, $configSet)) {
            $this->messenger()->addError(new PsrMessage(
                'Failed to create the core "{core}" on the Solr server. Check the logs: the config set "{config_set}" must exist in SOLR_HOME/configsets on the server.', // @translate
                ['core' => $coreName, 'config_set' => $configSet]
            ));
            return $this->redirect()->toRoute('admin/search-manager/solr/core-id', ['id' => $id]);
        }

        // Provision the schema field types and field used for suggestions.
        $solrCore->ensureSuggestFieldType();
        $solrCore->ensureSuggestFoldedFieldType();
        $solrCore->ensureSuggestField();

        $this->messenger()->addSuccess(new PsrMessage(
            'The core "{core}" was created on the Solr server and its schema initialized. Default maps are being created; reindex afterwards.', // @translate
            ['core' => $coreName]
        ));

        // Provision the default and required maps in background.
        return $this->dispatchCompleteMapsJob('complete');
    }

    public function deleteCoreOnServerAction()
    {
        $id = $this->params('id');
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/search-manager/solr/core-id', ['id' => $id]);
        }

        /** @var \SearchSolr\Stdlib\SolrCore $solrCore */
        $solrCore = $this->solrCore((int) $id);
        $connection = $solrCore->clientSettings();
        $coreName = (string) ($connection['core'] ?? '');

        // Refuse to delete a core shared through the setting "index_name", as
        // its documents may belong to another index or third party.
        if ($solrCore->setting('index_name')) {
            $this->messenger()->addError(new PsrMessage(
                'The core "{core}" is shared (the engine sets index_name); deletion on the server is refused.', // @translate
                ['core' => $coreName]
            ));
            return $this->redirect()->toRoute('admin/search-manager/solr/core-id', ['id' => $id]);
        }

        $logger = $solrCore->getServiceLocator()->get('Omeka\Logger');
        $coreAdmin = new \SearchSolr\Solr\CoreAdmin($logger);
        if ($coreAdmin->deleteCore($connection, $coreName)) {
            $this->messenger()->addSuccess(new PsrMessage(
                'The core "{core}" was deleted on the Solr server. The connection is still registered in Omeka.', // @translate
                ['core' => $coreName]
            ));
        } else {
            $this->messenger()->addError(new PsrMessage(
                'Failed to delete the core "{core}" on the Solr server. Check the logs.', // @translate
                ['core' => $coreName]
            ));
        }
        return $this->redirect()->toRoute('admin/search-manager/solr/core-id', ['id' => $id]);
    }

    public function dataTypesMapsAction()
    {
        return $this->dispatchCompleteMapsJob('datatypes');
    }

    public function recommendedMapsAction()
    {
        return $this->dispatchCompleteMapsJob('recommended');
    }

    public function completeMapsAction()
    {
        return $this->dispatchCompleteMapsJob('complete');
    }

    protected function dispatchCompleteMapsJob(string $mode)
    {
        $id = $this->params('id');

        $job = $this->jobDispatcher()->dispatch(
            \SearchSolr\Job\CompleteSolrMaps::class,
            [
                'solr_core_id' => (int) $id,
                'resource_name' => 'items',
                'mode' => $mode,
            ]
        );

        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Map creation in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlPlugin->fromRoute(
                        'admin/id',
                        ['controller' => 'job', 'id' => $job->getId()]
                    ))
                ),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf(
                        '<a href="%1$s">',
                        htmlspecialchars($urlPlugin->fromRoute(
                            'admin/default',
                            ['controller' => 'log'],
                            ['query' => ['job_id' => $job->getId()]]
                        ))
                    )
                    : sprintf(
                        '<a href="%1$s" target="_blank" rel="noopener noreferrer">',
                        htmlspecialchars($urlPlugin->fromRoute(
                            'admin/id',
                            ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]
                        ))
                    ),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute(
            'admin/search-manager/solr/core-id',
            ['id' => $id]
        );
    }

    public function cleanMapsAction()
    {
        $api = $this->api();
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        $maps = $solrCore->maps();
        $mapList = [];
        foreach ($maps as $map) {
            $mapList[$map->id()] = $map->source();
        }

        $result = [];
        $properties = $api->search('properties')->getContent();
        $connection = $this->getEvent()->getApplication()
            ->getServiceManager()->get('Omeka\Connection');
        $usedPropertyIds = $connection
            ->executeQuery(
                'SELECT DISTINCT property_id FROM value'
            )
            ->fetchFirstColumn();

        foreach ($properties as $property) {
            if (in_array($property->id(), $usedPropertyIds)) {
                continue;
            }
            $term = $property->term();
            if (!in_array($term, $mapList)) {
                continue;
            }
            $ids = array_keys(
                array_filter($mapList, fn ($v) => $v === $term)
            );
            $api->batchDelete('solr_maps', $ids);
            $result[] = $term;
        }

        if ($result) {
            $this->updateFieldsBoost($solrCore);
            $this->messenger()->addSuccess(new PsrMessage(
                '{count} maps deleted: {list}.', // @translate
                [
                    'count' => count($result),
                    'list' => implode(', ', $result),
                ]
            ));
        } else {
            $this->messenger()->addWarning(
                'No maps deleted.' // @translate
            );
        }

        return $this->redirect()->toRoute(
            'admin/search-manager/solr/core-id', ['id' => $id]
        );
    }

    public function reduceMapsAction()
    {
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        $fieldStatus = $solrCore->fieldLimitStatus();
        if (!$fieldStatus || !$fieldStatus['maxFields']) {
            $this->messenger()->addError(
                'Unable to determine the Solr maxFields limit.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/search-manager/solr/core-id', ['id' => $id]
            );
        }

        $this->jobDispatcher()->dispatch(
            \SearchSolr\Job\ReduceSolrFields::class,
            ['solr_core_id' => (int) $id]
        );

        $this->messenger()->addSuccess(
            'Reduction job started. Check the logs.' // @translate
        );

        return $this->redirect()->toRoute(
            'admin/search-manager/solr/core-id', ['id' => $id]
        );
    }

    public function addAnnotationMapsAction()
    {
        $api = $this->api();
        $id = (int) $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        $existingFields = array_map(
            fn ($m) => $m->fieldName(), $solrCore->maps()
        );

        $connection = $this->getEvent()->getApplication()
            ->getServiceManager()->get('Omeka\Connection');

        $sql = <<<'SQL'
            SELECT DISTINCT CONCAT(v.prefix, ':', p.local_name) AS term
            FROM value_annotation va
            JOIN value av ON av.resource_id = va.id
            JOIN property p ON av.property_id = p.id
            JOIN vocabulary v ON p.vocabulary_id = v.id
            ORDER BY term
            SQL;
        $annotationTerms = $connection->executeQuery($sql)
            ->fetchFirstColumn();

        $newMaps = [];

        $fieldName = 'value_annotations_txt';
        if (!in_array($fieldName, $existingFields)) {
            $api->create('solr_maps', [
                'o:solr_core' => ['o:id' => $id],
                'o:resource_name' => 'resources',
                'o:field_name' => $fieldName,
                'o:source' => 'value_annotations',
                'o:settings' => [
                    'formatter' => '',
                    'label' => 'Value annotations (all)',
                ],
            ]);
            $newMaps[] = $fieldName;
        }

        foreach ($annotationTerms as $term) {
            $base = 'ann_' . strtr($term, ':', '_');
            $source = 'value_annotations/' . $term;

            $fieldName = $base . '_txt';
            if (!in_array($fieldName, $existingFields)) {
                $api->create('solr_maps', [
                    'o:solr_core' => ['o:id' => $id],
                    'o:resource_name' => 'resources',
                    'o:field_name' => $fieldName,
                    'o:source' => $source,
                    'o:settings' => [
                        'formatter' => '',
                        'label' => $term . ' (annotation)',
                    ],
                ]);
                $newMaps[] = $fieldName;
            }

            $fieldName = $base . '_ss';
            if (!in_array($fieldName, $existingFields)) {
                $api->create('solr_maps', [
                    'o:solr_core' => ['o:id' => $id],
                    'o:resource_name' => 'resources',
                    'o:field_name' => $fieldName,
                    'o:source' => $source,
                    'o:settings' => [
                        'formatter' => '',
                        'parts' => ['main'],
                        'label' => $term . ' (annotation)',
                    ],
                ]);
                $newMaps[] = $fieldName;
            }
        }

        if ($newMaps) {
            $this->messenger()->addSuccess(new PsrMessage(
                '{count} annotation maps created: {list}.', // @translate
                [
                    'count' => count($newMaps),
                    'list' => implode(', ', $newMaps),
                ]
            ));
        } else {
            $this->messenger()->addSuccess(
                'All annotation maps already exist.' // @translate
            );
        }

        return $this->redirect()->toRoute(
            'admin/search-manager/solr/core-id', ['id' => $id]
        );
    }

    /**
     * Sync Solr maps with search configs.
     *
     * Create missing maps for properties used in facets, filters, sorts,
     * boosts, suggesters, bounce links, etc; remove property maps not
     * referenced by any config. System maps and value_annotations are kept.
     *
     * The query arg "scope" widens the collection beyond the configs, since the
     * query pivot lets any property be queried like in the internal engine: -
     * "configs" (default): properties referenced by the search configs; -
     * "used": plus every property holding at least one value; - "templates":
     * plus the properties of the resource templates (curated
     *   middle ground between configs and all used properties).
     */
    public function syncMapsAction()
    {
        $api = $this->api();
        $id = (int) $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        // On a plain access, show the form to choose the sources to align. The
        // sync runs on submit only, so the coverage is an explicit choice.
        $form = $this->getForm(\SearchSolr\Form\Admin\SolrCoreSyncForm::class);
        if (!$this->getRequest()->isPost()) {
            // Back-compat: the old preset links passed the scope as a query and
            // ran immediately. Honour them, else render the form.
            $scopeQuery = $this->params()->fromQuery('scope');
            if ($scopeQuery === null) {
                // The form only makes sense in the core page sidebar (xhr); a
                // direct visit just goes back to the core page.
                if (!$this->getRequest()->isXmlHttpRequest()) {
                    return $this->redirect()->toRoute('admin/search-manager/solr/core-id', ['id' => $id]);
                }
                return (new ViewModel([
                    'solrCore' => $solrCore,
                    'form' => $form,
                ]))
                    ->setTerminal(true)
                    ->setTemplate('search-solr/admin/core/sync-maps-sidebar');
            }
            $sources = ['configs', 'settings', 'site_settings'];
            if ($scopeQuery === 'templates') {
                $sources[] = 'templates';
            } elseif ($scopeQuery === 'used') {
                $sources[] = 'used';
            }
            $clean = (bool) $this->params()->fromQuery('clean');
            $multilingual = (bool) $this->params()->fromQuery('multilingual', true);
        } else {
            $form->setData($this->params()->fromPost());
            if (!$form->isValid()) {
                $this->messenger()->addFormErrors($form);
                return $this->redirect()->toRoute('admin/search-manager/solr/core-id', ['id' => $id]);
            }
            $data = $form->getData();
            $sources = $data['sync_sources'] ?: ['configs'];
            $clean = (bool) ($data['clean'] ?? false);
            $multilingual = (bool) ($data['multilingual'] ?? false);
        }
        if (in_array('all', $sources, true)) {
            $sources = ['configs', 'settings', 'site_settings', 'templates', 'used'];
        }
        $wantConfigs = in_array('configs', $sources, true);
        $wantSettings = in_array('settings', $sources, true);
        $wantSiteSettings = in_array('site_settings', $sources, true);
        $wantTemplates = in_array('templates', $sources, true);
        $wantUsed = in_array('used', $sources, true);

        // Check for shared engine (index_name).
        // Sync cannot work reliably when multiple Omeka instances share a core.
        if ($solrCore->setting('index_name')) {
            $this->messenger()->addError(
                'This core is used by a shared engine (index_name is set). Sync is not supported for shared engines.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/search-manager/solr/core-id', ['id' => $id]
            );
        }

        // Sources that must never be deleted.
        $systemSources = [
            'resource_name',
            'o:id',
            'o:title',
            'is_public',
            'owner',
            'site',
            'created',
            'modified',
            'resource_class',
            'resource_template',
            'has_media',
            'asset',
            'content',
            'item_set',
            'item_sets_tree',
            'media',
            'is_open',
            'value',
            'annotation',
            'value_annotations',
            'access_level',
            'property_values',
            'selection_id',
            'selection_public_id',
            'url_api',
            'url_admin',
            'url_site',
            'url_asset',
            'url_original',
            'url_thumbnail_large',
            'url_thumbnail_medium',
            'url_thumbnail_square',
        ];

        $services = $this->getEvent()->getApplication()
            ->getServiceManager();
        $settings = $services->get('Omeka\Settings');
        $siteSettings = $services->get('Omeka\Settings\Site');
        $connection = $services->get('Omeka\Connection');

        // 1. The engine of this core (a core is a facet of its engine).
        $engineIds = [$id];

        // 2. Collect property terms from configs and suggesters, when the
        // "configs" source is checked.
        $usedFields = [];
        $searchConfigs = $wantConfigs
            ? $api->search('search_configs')->getContent()
            : [];
        foreach ($searchConfigs as $config) {
            $configEngine = $config->searchEngine();
            if (!$configEngine
                || !in_array($configEngine->id(), $engineIds)
            ) {
                continue;
            }
            // Facets need _ss (or _i for ranges). For range facets with an
            // interval end ("field_end"), the field names already carry the
            // bound suffix (_min_i / _max_i): the regex in
            // collectFieldAsProperty extracts the suffix from the field name,
            // so passing an empty $suffixes is enough.
            foreach ($config->subSetting('facet', 'facets', []) as $f) {
                $v = $f['field'] ?? null;
                if (!$v) {
                    continue;
                }
                $type = $f['type'] ?? '';
                $isRange = in_array($type, ['RangeDouble', 'SelectRange']);
                $hasEnd = $isRange && !empty($f['field_end']);
                if ($hasEnd) {
                    $this->collectFieldAsProperty($v, $usedFields, []);
                    $this->collectFieldAsProperty($f['field_end'], $usedFields, []);
                } else {
                    $this->collectFieldAsProperty(
                        $v, $usedFields, [$isRange ? '_i' : '_ss']
                    );
                }
            }
            // Filters need _ss. Range filters with an interval end
            // ("field_end") use the suffix carried by the field name (_min_i /
            // _max_i).
            foreach ($config->subSetting('form', 'filters', []) as $f) {
                $v = $f['field'] ?? null;
                if (!$v) {
                    continue;
                }
                $type = $f['type'] ?? '';
                $isRange = in_array($type, ['Range', 'RangeDouble']);
                $hasEnd = $isRange && !empty($f['field_end']);
                if ($hasEnd) {
                    $this->collectFieldAsProperty($v, $usedFields, []);
                    $this->collectFieldAsProperty($f['field_end'], $usedFields, []);
                } else {
                    $this->collectFieldAsProperty(
                        $v, $usedFields, [$isRange ? '_i' : '_ss']
                    );
                }
            }
            // Sorts need _s, plus the folded variant so the order follows
            // the database collation (case and diacritics insensitive).
            foreach ($config->subSetting('results', 'sort_list', []) as $f) {
                $v = strtok($f['name'] ?? '', ' ');
                if ($v) {
                    $this->collectFieldAsProperty(
                        $v, $usedFields, ['_s', '_fold_s']
                    );
                }
            }
            // Boosts are applied to the query fields, so a boost set on a bare
            // property term needs the fulltext index; when the field name
            // carries a suffix, that suffix is used instead.
            foreach ($config->subSetting('index', 'field_boosts', []) as $fieldName => $boost) {
                $this->collectFieldAsProperty(
                    (string) $fieldName,
                    $usedFields,
                    strpos((string) $fieldName, ':') === false ? [] : ['_txt']
                );
            }
            // Aliases need _txt (fulltext search).
            foreach ($config->subSetting('index', 'aliases', []) as $alias) {
                foreach ($alias['fields'] ?? [] as $v) {
                    if (strpos($v, ':') !== false) {
                        $usedFields[$v]['_txt'] = true;
                    }
                }
            }
            // Advanced filter fields need _txt + _ss.
            $advancedFields = $config
                ->subSetting('form', 'advanced', []);
            foreach ($advancedFields['fields'] ?? [] as $f) {
                $v = $f['value'] ?? ($f['field'] ?? null);
                if ($v) {
                    $this->collectFieldAsProperty(
                        $v, $usedFields, ['_txt', '_ss']
                    );
                }
            }
            // Hidden query filters use Solr field names
            // directly (_ss).
            $hiddenFilters = $config
                ->subSetting('request', 'hidden_query_filters', []);
            foreach ($hiddenFilters as $fieldName => $value) {
                if (is_string($fieldName) && $fieldName !== '') {
                    $this->collectFieldAsProperty(
                        $fieldName, $usedFields, ['_ss']
                    );
                }
            }
        }

        // Boosts set on the core itself apply to every config using it, so they
        // are always collected: a boosted field must exist in the index, else
        // Solr rejects the whole query ("is not a valid field name").
        foreach ($solrCore->setting('field_boost') ?: [] as $fieldName => $boost) {
            if (is_string($fieldName) && $fieldName !== '') {
                $this->collectFieldAsProperty(
                    $fieldName,
                    $usedFields,
                    strpos($fieldName, ':') === false ? [] : ['_txt']
                );
            }
        }

        // Suggesters need _txt.
        $suggesters = $wantConfigs
            ? $api->search('search_suggesters')->getContent()
            : [];
        foreach ($suggesters as $suggester) {
            $se = $suggester->searchEngine();
            if (!in_array($se->id(), $engineIds)) {
                continue;
            }
            foreach ($suggester->settings()['fields'] ?? [] as $v) {
                if (strpos($v, ':') !== false) {
                    $usedFields[$v]['_txt'] = true;
                }
            }
        }

        // 3. Bounce links from AdvancedResourceTemplate whitelist/blacklist,
        // from the main settings and/or the site settings when checked.
        $linkFields = ($wantSettings || $wantSiteSettings)
            ? $this->collectBounceProperties(
                $settings, $siteSettings, $connection, $wantSettings, $wantSiteSettings
            )
            : [];
        foreach ($linkFields as $term) {
            if (!isset($usedFields[$term])) {
                $usedFields[$term] = [];
            }
            $usedFields[$term]['_link_ss'] = true;
        }

        // 3b. Widen the collection beyond the configs when requested. Each
        // extra property gets the default value indexes: _txt for word search
        // and _ss for exact filters and facets (_ss is skipped later for long
        // values). Suffixes required by the configs are kept as they are.
        // Only the templates used by at least one resource: the curated set
        // excludes the default "Base resource" and any stale template as long
        // as no resource uses them.
        $extraSql = [];
        if ($wantTemplates) {
            $extraSql[] = 'SELECT DISTINCT CONCAT(vo.prefix, ":", pr.local_name)
                FROM resource_template_property rtp
                INNER JOIN property pr ON pr.id = rtp.property_id
                INNER JOIN vocabulary vo ON vo.id = pr.vocabulary_id
                WHERE EXISTS (
                    SELECT 1 FROM resource r
                    WHERE r.resource_template_id = rtp.resource_template_id
                )';
        }
        if ($wantUsed) {
            $extraSql[] = 'SELECT DISTINCT CONCAT(vo.prefix, ":", pr.local_name)
                FROM value v
                INNER JOIN property pr ON pr.id = v.property_id
                INNER JOIN vocabulary vo ON vo.id = pr.vocabulary_id';
        }
        foreach ($extraSql as $sql) {
            foreach ($connection->fetchFirstColumn($sql) as $term) {
                $usedFields[$term] ??= [];
                $usedFields[$term] += ['_txt' => true, '_ss' => true];
            }
        }

        // 3c. Collected properties holding at least one linked resource get an
        // index of the linked resource ids, required by the query types
        // res/nres of the pivot.
        $linkedTerms = $connection->fetchFirstColumn(
            'SELECT DISTINCT CONCAT(vo.prefix, ":", pr.local_name)
            FROM value v
            INNER JOIN property pr ON pr.id = v.property_id
            INNER JOIN vocabulary vo ON vo.id = pr.vocabulary_id
            WHERE v.value_resource_id IS NOT NULL'
        );
        foreach (array_intersect($linkedTerms, array_keys($usedFields)) as $term) {
            $usedFields[$term]['_link_is'] = true;
        }

        // 4. Get existing maps for this core.
        $existingMaps = $solrCore->maps();
        $existingBySource = [];
        foreach ($existingMaps as $map) {
            $existingBySource[$map->source()][] = $map;
        }

        // 4b. Snapshot the current configuration before any modification, so
        // that it can be restored from the core page if the sync produces an
        // unwanted result. The last 3 snapshots are kept per core.
        $this->snapshotMaps($solrCore, $existingMaps);

        // 5. Delete property maps not referenced by any config, when asked.
        // Keep maps with custom settings (formatter, pool filters,
        // normalization, boost, etc.).
        $deleted = [];
        $kept = [];
        foreach ($clean ? $existingBySource : [] as $source => $maps) {
            if (in_array($source, $systemSources)
                || strpos($source, '/') !== false
                || strpos($source, ':') === false
                || isset($usedFields[$source])
            ) {
                continue;
            }
            foreach ($maps as $map) {
                // Explicit provenance first: a map created or edited by hand
                // is never removed; then the customization heuristic protects
                // the legacy maps without provenance.
                if ($map->setting('origin') === 'manual'
                    || $this->isCustomizedMap($map)
                ) {
                    $kept[] = $map->fieldName();
                    continue;
                }
                $api->delete('solr_maps', $map->id());
                $deleted[] = $map->fieldName();
            }
        }

        // Refresh after deletion.
        $existingFieldNames = [];
        if ($deleted) {
            $solrCore = $this->solrCore((int) $id);
        }
        foreach ($solrCore->maps() as $map) {
            $existingFieldNames[] = $map->fieldName();
        }

        // 6. Create missing maps for used properties.
        // Long-value properties should not get _ss/_s.
        // Long-value properties get only _txt: an exact-value index (_ss/_s)
        // is useless on long texts and Solr rejects the whole document beyond
        // 32766 bytes in a docValues string. The static list is completed by
        // a data-driven detection on the real corpus: any value over the hard
        // byte limit, or an average length beyond an exact-value usefulness.
        // Both thresholds are configurable (["searchsolr"]["config"]).
        $configModule = $services->get('Config')['searchsolr']['config'] ?? [];
        $textOnlyAverage = (int) ($configModule['searchsolr_text_only_average_length'] ?? 100);
        $stringMaxBytes = (int) ($configModule['searchsolr_string_value_max_bytes'] ?? 1000);
        $longValueProperties = include dirname(__DIR__, 3)
            . '/config/metadata_text.php';
        $longValueProperties = array_unique(array_merge(
            $longValueProperties,
            $connection->fetchFirstColumn(
                'SELECT CONCAT(vo.prefix, ":", pr.local_name)
                FROM value v
                INNER JOIN property pr ON pr.id = v.property_id
                INNER JOIN vocabulary vo ON vo.id = pr.vocabulary_id
                WHERE v.value IS NOT NULL
                GROUP BY v.property_id
                HAVING MAX(LENGTH(v.value)) > ' . $stringMaxBytes . '
                    OR AVG(CHAR_LENGTH(v.value)) > ' . $textOnlyAverage
            )
        ));

        // Settings templates per suffix.
        $suffixSettings = [
            '_txt' => ['formatter' => ''],
            '_ss' => ['formatter' => '', 'parts' => ['main']],
            '_s' => ['formatter' => '', 'parts' => ['main']],
            '_i' => ['formatter' => 'integer'],
            // Folded sort/comparison variant (see ensureFoldedFieldType).
            '_fold_s' => ['formatter' => '', 'parts' => ['main']],
            '_link_ss' => [
                'index_for_link' => true,
                'parts' => ['link'],
                'formatter' => '',
            ],
            // Ids of the linked resources, for the query types res/nres: the
            // part "link" yields the linked resource id (or the uri/literal),
            // and the integer formatter drops the non numeric values.
            '_link_is' => [
                'parts' => ['link'],
                'formatter' => 'integer',
            ],
            // Interval lower bound: extract the smallest year from each EDTF
            // value, then aggregate to the smallest year across multivalued
            // sources (e.g. several value annotations).
            '_min_i' => [
                'formatter' => 'edtf_year',
                'parts' => ['main'],
                'part' => 'min',
                'aggregate' => 'min',
            ],
            // Interval upper bound: largest year per value, then largest across
            // multivalued sources.
            '_max_i' => [
                'formatter' => 'edtf_year',
                'parts' => ['main'],
                'part' => 'max',
                'aggregate' => 'max',
            ],
            '_min_l' => [
                'formatter' => 'edtf_year',
                'parts' => ['main'],
                'part' => 'min',
                'aggregate' => 'min',
            ],
            '_max_l' => [
                'formatter' => 'edtf_year',
                'parts' => ['main'],
                'part' => 'max',
                'aggregate' => 'max',
            ],
        ];

        $created = [];
        // Language indexes are added only when the install is really
        // multilingual: at least two site locales and a property with values in
        // at least two of these languages.
        $langsByTerm = $multilingual
            ? $this->multilingualLangsByTerm(array_keys($usedFields))
            : [];
        // The folded fields need their type and dynamic field in the schema;
        // pushed once, on the first folded map to create. On failure the folded
        // maps are skipped: sorts keep using the plain string field.
        $foldedSchemaReady = null;
        foreach ($usedFields as $term => $requiredSuffixes) {
            if (!is_array($requiredSuffixes)
                || empty($requiredSuffixes)
            ) {
                continue;
            }
            $base = strtr($term, ':', '_');
            $isLong = in_array($term, $longValueProperties);

            foreach (array_keys($requiredSuffixes) as $suffix) {
                // Skip _ss/_s for long-value properties.
                if ($isLong
                    && in_array($suffix, ['_ss', '_s', '_fold_s', '_i'])
                ) {
                    continue;
                }
                $fieldName = $base . $suffix;
                if (in_array($fieldName, $existingFieldNames)) {
                    continue;
                }
                if ($suffix === '_fold_s') {
                    $foldedSchemaReady ??= $solrCore->ensureFoldedFieldType()
                        && $solrCore->ensureFoldedDynamicField();
                    if (!$foldedSchemaReady) {
                        continue;
                    }
                }
                $mapSettings = $suffixSettings[$suffix]
                    ?? ['formatter' => ''];
                $api->create('solr_maps', [
                    'o:solr_core' => ['o:id' => $id],
                    'o:resource_name' => 'resources',
                    'o:field_name' => $fieldName,
                    'o:source' => $term,
                    'o:settings' => $mapSettings
                        + ['label' => $term, 'origin' => 'sync'],
                ]);
                $created[] = $fieldName;
                $existingFieldNames[] = $fieldName;
            }

            // Language indexes, for facets and filters by language, so a site
            // displays the values of its own locale. They are built on the
            // exact-value index, skipped for long values like the plain one.
            if ($isLong || !isset($requiredSuffixes['_ss'])) {
                continue;
            }
            foreach ($langsByTerm[$term] ?? [] as $lang => $langCodes) {
                $fieldName = $base . '_' . $lang . '_ss';
                if (in_array($fieldName, $existingFieldNames)) {
                    continue;
                }
                $api->create('solr_maps', [
                    'o:solr_core' => ['o:id' => $id],
                    'o:resource_name' => 'resources',
                    'o:field_name' => $fieldName,
                    'o:source' => $term,
                    'o:pool' => [
                        'filter_languages' => $langCodes,
                        'filter_languages_no_lang' => true,
                    ],
                    'o:settings' => ($suffixSettings['_ss'] ?? ['formatter' => ''])
                        + ['label' => $term . ' (' . $lang . ')', 'origin' => 'sync'],
                ]);
                $created[] = $fieldName;
                $existingFieldNames[] = $fieldName;
            }
        }

        // 8. Ensure required system maps exist. These are the maps needed for
        // Solr to function.
        $requiredMaps = [
            // Generic (all resource types).
            ['generic', 'resource_name_s', 'resource_name', ['label' => 'Resource type']],
            ['generic', 'id_i', 'o:id', ['label' => 'Internal id']],
            ['generic', 'is_public_b', 'is_public', ['parts' => ['main'], 'formatter' => 'boolean', 'label' => 'Public']],
            ['generic', 'name_s', 'o:title', ['label' => 'Name']],
            ['generic', 'owner_id_i', 'owner/o:id', ['label' => 'Owner']],
            ['generic', 'site_id_is', 'site/o:id', ['label' => 'Site']],
            // Resources.
            ['resources', 'resource_class_s', 'resource_class/o:term', ['label' => 'Resource class']],
            ['resources', 'resource_template_s', 'resource_template/o:label', ['label' => 'Resource template']],
            ['resources', 'title_s', 'o:title', ['label' => 'Title']],
            ['resources', 'created_dt', 'created', ['label' => 'Created']],
            ['resources', 'modified_dt', 'modified', ['label' => 'Modified']],
            ['resources', 'property_values_txt', 'property_values', ['label' => 'All property values']],
            ['resources', 'has_original_b', 'has_original', ['formatter' => 'boolean', 'label' => 'Has original file']],
            ['resources', 'has_thumbnails_b', 'has_thumbnails', ['formatter' => 'boolean', 'label' => 'Has thumbnails']],
            ['resources', 'value_annotations_txt', 'value_annotations', ['label' => 'Value annotations (all)']],
            // Items.
            ['items', 'item_set_id_is', 'item_set/o:id', ['label' => 'Item set id']],
            ['items', 'item_set_dcterms_title_ss', 'item_set/dcterms:title', ['label' => 'Item set']],
            ['items', 'has_media_b', 'has_media', ['formatter' => 'boolean', 'label' => 'Has media']],
            ['items', 'media_type_ss', 'media/o:media_type', ['label' => 'Media types']],
            // Media.
            ['media', 'media_type_s', 'o:media_type', ['label' => 'Media type']],
            ['media', 'media_item_id_i', 'item/o:id', ['label' => 'Item id']],
            // Item sets.
            ['item_sets', 'is_open_b', 'is_open', ['formatter' => 'boolean', 'label' => 'Is open']],
        ];

        // Module Group: index the ids of the groups a resource is reserved to,
        // so reserved private content is searchable and faceted for members.
        if (class_exists(\Group\Module::class, false)) {
            $requiredMaps[] = ['generic', 'group_id_is', 'group_id', ['label' => 'Groups (module Group)']];
        }

        // Module Access: index the effective access level, in particular when
        // mode "property" is not used, or to hide records when the filter is
        // used.
        if (class_exists(\Access\Module::class, false)) {
            $requiredMaps[] = ['generic', 'access_level_s', 'access_level', ['label' => 'Access level (module Access)']];
        }

        $existingMapsByField = [];
        $existingGenericSources = [];
        foreach ($existingMaps as $map) {
            $existingMapsByField[$map->fieldName()] = $map;
            if ($map->resourceName() === 'generic') {
                $existingGenericSources[$map->source()] = true;
            }
        }
        foreach ($requiredMaps as [$scope, $fieldName, $source, $mapSettings]) {
            // Do not duplicate the visibility map when it already exists under
            // the legacy field name "is_public_i": keep it to avoid a reindex,
            // so only fresh cores get the boolean "is_public_b".
            if ($source === 'is_public'
                && !isset($existingMapsByField[$fieldName])
                && !empty($existingGenericSources['is_public'])
            ) {
                continue;
            }
            if (isset($existingMapsByField[$fieldName])) {
                $existing = $existingMapsByField[$fieldName];
                if ($existing->resourceName() !== $scope) {
                    $api->update(
                        'solr_maps',
                        $existing->id(),
                        ['o:resource_name' => $scope],
                        [],
                        ['isPartial' => true]
                    );
                    $created[] = $fieldName . ' (fixed scope)';
                }
            } else {
                $api->create('solr_maps', [
                    'o:solr_core' => ['o:id' => $id],
                    'o:resource_name' => $scope,
                    'o:field_name' => $fieldName,
                    'o:source' => $source,
                    'o:settings' => $mapSettings + ['origin' => 'system'],
                ]);
                $created[] = $fieldName;
                $existingFieldNames[] = $fieldName;
            }
        }

        // 9. Ensure selection map if module Selection is active.
        $moduleManager = $services->get('Omeka\ModuleManager');
        $selectionModule = $moduleManager->getModule('Selection');
        if ($selectionModule
            && $selectionModule->getState()
                === \Omeka\Module\Manager::STATE_ACTIVE
            && !in_array('selection_public_is', $existingFieldNames)
        ) {
            $api->create('solr_maps', [
                'o:solr_core' => ['o:id' => $id],
                'o:resource_name' => 'resources',
                'o:field_name' => 'selection_public_is',
                'o:source' => 'selection_public_id',
                'o:settings' => ['label' => 'Public selections'],
            ]);
            $created[] = 'selection_public_is';
            $existingFieldNames[] = 'selection_public_is';
        }

        // 10. Report.
        if ($deleted) {
            $this->updateFieldsBoost($solrCore);
        }

        // Summary line.
        $totalExisting = count($existingMaps);
        $this->messenger()->addSuccess(new PsrMessage(
            'Sync complete (sources: {sources}, cleaning: {cleaning}). Properties collected: {props}. Maps before: {before}, deleted: {deleted}, kept (customized): {kept}, created: {created}.', // @translate
            [
                'sources' => implode(', ', $sources),
                'cleaning' => $clean ? 'on' : 'off',
                'props' => count($usedFields),
                'before' => $totalExisting,
                'deleted' => count($deleted),
                'kept' => count($kept),
                'created' => count($created),
            ]
        ));

        if ($deleted) {
            $this->messenger()->addWarning(new PsrMessage(
                'Deleted: {list}.', // @translate
                ['list' => implode(', ', $deleted)]
            ));
        }
        if ($kept) {
            $this->messenger()->addNotice(new PsrMessage(
                'Kept (customized, not in config): {list}.', // @translate
                ['list' => implode(', ', $kept)]
            ));
        }
        if ($created) {
            $this->messenger()->addSuccess(new PsrMessage(
                'Created: {list}.', // @translate
                ['list' => implode(', ', $created)]
            ));
        }
        if (!$deleted && !$created) {
            $this->messenger()->addSuccess(
                'All maps are in sync with search configs.' // @translate
            );
        }
        if ($deleted || $created) {
            // Actionable reminder: a new map stays empty until reindexing.
            $links = [];
            foreach ($solrCore->searchEngines() as $engine) {
                $links[] = sprintf(
                    '<a href="%s">%s</a>',
                    htmlspecialchars($this->url()->fromRoute(
                        'admin/search-manager/engine-id',
                        ['id' => $engine->id(), 'action' => 'index']
                    )),
                    htmlspecialchars($engine->name())
                );
            }
            $message = new PsrMessage(
                'Reindex required: the changed maps stay empty until then. Reindex: {links}.', // @translate
                ['links' => implode(', ', $links) ?: '—']
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addWarning($message);
        }

        return $this->redirect()->toRoute(
            'admin/search-manager/solr/core-id', ['id' => $id]
        );
    }

    /**
     * List the languages to index separately for each multilingual property.
     *
     * A language index is useful only when the install is really multilingual,
     * so two conditions are required: the sites use at least two distinct
     * locales, and the property has values in at least two of these languages.
     * The languages are compared on their two-letter code, so a site in "en_US"
     * matches the values in "en", "en-GB" and "eng", which are all indexed in
     * the same "en" index.
     *
     * @see \SearchSolr\Stdlib\LanguageCodes
     *
     * @param string[] $terms
     * @return array Language codes to filter on, by term and two-letter code.
     */
    protected function multilingualLangsByTerm(array $terms): array
    {
        if (!$terms) {
            return [];
        }

        $services = $this->getEvent()->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');
        $siteSettings = $services->get('Omeka\Settings\Site');
        $connection = $services->get('Omeka\Connection');

        $toIso1 = fn ($lang): string => \SearchSolr\Stdlib\LanguageCodes::toIso1($lang);

        // Locales of the sites, the global locale being the default one.
        $globalLocale = $toIso1($settings->get('locale'));
        $siteLangs = [];
        foreach ($this->api()->search('sites', [], ['returnScalar' => 'id'])->getContent() as $siteId) {
            $siteSettings->setTargetId($siteId);
            $lang = $toIso1($siteSettings->get('locale')) ?: $globalLocale;
            if ($lang) {
                $siteLangs[$lang] = true;
            }
        }
        if (count($siteLangs) < 2) {
            return [];
        }

        // Languages of the values, by property term.
        $sql = <<<'SQL'
            SELECT CONCAT(vocabulary.prefix, ':', property.local_name) AS term, value.lang AS lang
            FROM value AS value
            JOIN property AS property ON property.id = value.property_id
            JOIN vocabulary AS vocabulary ON vocabulary.id = property.vocabulary_id
            WHERE value.lang IS NOT NULL AND value.lang != ''
            GROUP BY term, value.lang
            SQL;
        $rows = $connection->executeQuery($sql)->fetchAllAssociative();

        $terms = array_fill_keys($terms, true);
        $result = [];
        foreach ($rows as $row) {
            $term = $row['term'];
            if (!isset($terms[$term])) {
                continue;
            }
            $lang = $toIso1($row['lang']);
            if ($lang && isset($siteLangs[$lang])) {
                $result[$term][$lang][] = $row['lang'];
            }
        }

        // A single language is the monolingual case: no language index needed.
        return array_filter($result, fn ($langs): bool => count($langs) > 1);
    }

    /**
     * Check if a map has custom settings that indicate manual configuration.
     *
     * Manual configuration are formatter, pool filters, normalization, boost,
     * etc.: such maps should not be deleted by sync.
     *
     * Indices with specific names are kept too.
     */
    protected function isCustomizedMap(
        \SearchSolr\Api\Representation\SolrMapRepresentation $map
    ): bool {
        $settings = $map->settings();
        $pool = $map->pool() ?? [];
        // Non-empty formatter (other than default empty).
        if (!empty($settings['formatter'])) {
            return true;
        }
        // Any normalization.
        if (!empty($settings['normalization'])) {
            return true;
        }
        // Boost other than default.
        if (!empty($settings['boost']) && (float) $settings['boost'] !== 1.0) {
            return true;
        }
        // Any pool filter.
        if (!empty($pool['filter_values'])
            || !empty($pool['filter_uris'])
            || !empty($pool['filter_resources'])
            || !empty($pool['filter_value_resources'])
            || !empty($pool['data_types'])
            || !empty($pool['data_types_exclude'])
            || !empty($pool['filter_languages'])
        ) {
            return true;
        }
        // Explicit visibility override.
        $vis = $pool['filter_visibility'] ?? '';
        if ($vis !== '' && $vis !== 'default') {
            return true;
        }
        // Non-standard field name: if the field name does not follow the
        // pattern derived from the source, it was renamed manually.
        $source = $map->source();
        if (strpos($source, ':') !== false) {
            $expectedPrefix = strtr($source, ':', '_') . '_';
            if (strpos($map->fieldName(), $expectedPrefix) !== 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add a field reference to the used fields list with its required suffixes.
     *
     * Resolves property terms, Solr field names, and alias names.
     *
     * @param string $value Property term, Solr field name, or alias.
     * @param array $usedFields Accumulator: [term => [suffix => true]].
     * @param string[] $suffixes Required suffixes (e.g. ['_ss', '_s']).
     *   Empty array means just register the term without specific suffix: the
     *   suffix is already in the field name for boosts.
     */
    protected function collectFieldAsProperty(
        string $value,
        array &$usedFields,
        array $suffixes = []
    ): void {
        $term = null;
        if (strpos($value, ':') !== false) {
            $term = $value;
        } elseif (preg_match(
            // Compound interval suffixes (_min_i / _max_i / _min_l / _max_l)
            // are matched before the simple suffixes thanks to the alternation
            // order: longest alternatives first.
            '/^([a-z]+)_(.+?)_(min_i|max_i|min_l|max_l|link_ss|link_is|fold_s|txt|ss|s|dt|is|ls|i|l|b)$/',
            $value,
            $m
        )) {
            $term = $m[1] . ':' . $m[2];
            // The suffix is already known from the field name.
            if (empty($suffixes)) {
                $suffixes = ['_' . $m[3]];
            }
        }
        // Alias names are ignored here: they are resolved via the aliases
        // config which lists their constituent fields.
        if ($term === null) {
            return;
        }
        if (!isset($usedFields[$term])) {
            $usedFields[$term] = [];
        }
        foreach ($suffixes as $suffix) {
            $usedFields[$term][$suffix] = true;
        }
    }

    protected function collectBounceProperties(
        \Omeka\Settings\Settings $settings,
        \Omeka\Settings\SiteSettings $siteSettings,
        \Doctrine\DBAL\Connection $connection,
        bool $includeMain = true,
        bool $includeSites = true
    ): array {
        $keyWl = 'advancedresourcetemplate_properties_as_search_whitelist';
        $keyBl = 'advancedresourcetemplate_properties_as_search_blacklist';

        $whitelists = [];
        $blacklists = [];

        // Main settings.
        if ($includeMain) {
            $wl = $settings->get($keyWl, ['all']);
            $bl = $settings->get($keyBl, []);
            $whitelists[] = is_array($wl) ? $wl : [$wl];
            $blacklists[] = is_array($bl) ? $bl : [$bl];
        }

        // All site settings.
        if ($includeSites) {
            $siteIds = $connection->executeQuery('SELECT id FROM site')
                ->fetchFirstColumn();
            foreach ($siteIds as $siteId) {
                $siteSettings->setTargetId((int) $siteId);
                $wl = $siteSettings->get($keyWl, ['all']);
                $bl = $siteSettings->get($keyBl, []);
                $whitelists[] = is_array($wl) ? $wl : [$wl];
                $blacklists[] = is_array($bl) ? $bl : [$bl];
            }
        }
        if (!$whitelists && !$blacklists) {
            return [];
        }

        // If any source has "all", use all used properties.
        $hasAll = false;
        $specificTerms = [];
        foreach ($whitelists as $wl) {
            if (in_array('all', $wl)) {
                $hasAll = true;
            } else {
                foreach ($wl as $term) {
                    if (strpos($term, ':') !== false) {
                        $specificTerms[$term] = true;
                    }
                }
            }
        }

        $blackTerms = [];
        foreach ($blacklists as $bl) {
            foreach ($bl as $term) {
                $blackTerms[$term] = true;
            }
        }

        if ($hasAll) {
            $sql = <<<'SQL'
                SELECT DISTINCT CONCAT(v.prefix, ':', p.local_name)
                FROM value val
                JOIN property p ON val.property_id = p.id
                JOIN vocabulary v ON p.vocabulary_id = v.id
                SQL;
            $allTerms = $connection->executeQuery($sql)
                ->fetchFirstColumn();
            $result = array_diff($allTerms, array_keys($blackTerms));
        } else {
            $result = array_diff(
                array_keys($specificTerms),
                array_keys($blackTerms)
            );
        }

        return array_values($result);
    }

    /**
     * Reset all maps of this core to "follow engine" visibility.
     *
     * This process removes any explicit "all" override set during upgrade.
     */
    public function resetMapsVisibilityAction()
    {
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        $connection = $this->getEvent()->getApplication()->getServiceManager()
            ->get('Omeka\Connection');
        $sql = <<<'SQL'
            UPDATE solr_map
            SET settings = JSON_SET(
                COALESCE(settings, '{}'),
                '$.pool.filter_visibility', ''
            )
            WHERE solr_core_id = :core_id
              AND JSON_EXTRACT(settings, '$.pool.filter_visibility') = 'all'
            SQL;
        $count = $connection->executeStatement(
            $sql, ['core_id' => $solrCore->id()]
        );

        if ($count) {
            $this->messenger()->addSuccess(new PsrMessage(
                '{count} maps reset to "follow engine" visibility. Reindex required.', // @translate
                ['count' => $count]
            ));
        } else {
            $this->messenger()->addSuccess(
                'All maps already follow the engine visibility.' // @translate
            );
        }

        return $this->redirect()->toRoute(
            'admin/search-manager/solr/core-id',
            ['id' => $id, 'action' => 'show']
        );
    }

    /**
     * Create "suggest_txt" field and selective copyFields for autocompletion.
     */
    public function createSuggestFieldAction()
    {
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        $includeLongTexts = (bool) $this->params()
            ->fromQuery('include_long_texts');
        $keepDiacritics = (bool) $this->params()
            ->fromQuery('keep_diacritics');
        $alreadyExists = (bool) $solrCore->schema()
            ->getField('suggest_txt');
        $fieldType = $keepDiacritics ? 'text_general' : null;
        $result = $solrCore
            ->ensureSuggestField($includeLongTexts, $fieldType);
        if ($result === true) {
            $this->messenger()->addSuccess($alreadyExists
                ? 'Field "suggest_txt" recreated. Reindex required.' // @translate
                : 'Field "suggest_txt" created. Reindex required.' // @translate
            );
        } else {
            $this->messenger()->addError(new PsrMessage(
                'Error creating suggest field: {error}', // @translate
                ['error' => is_string($result) ? $result : 'unknown']
            ));
        }

        return $this->redirect()->toRoute(
            'admin/search-manager/solr/core-id',
            ['id' => $id, 'action' => 'show']
        );
    }

    /**
     * Configure the "_text_" field analyzer for search.
     *
     * Options:
     * - keep: Do nothing
     * - default: Use text_general (strict matching)
     * - optimized: Use text_search with EdgeNGram (Google-like)
     * - linguistic:{lang}: Language-specific stemmer + stopwords
     */
    public function configureSearchAction()
    {
        $id = $this->params('id');
        $solrCore = $this->solrCore((int) $id);

        // Persist query relevance settings, moved here from the core edit form:
        // they tune search behaviour like the catchall analyzer below. Empty
        // values are dropped by the adapter (fallback to solrconfig.xml).
        $settings = $solrCore->settings();
        $settings['query']['minimum_match'] = trim((string) $this->params()->fromPost('minimum_match', ''));
        $settings['query']['tie_breaker'] = trim((string) $this->params()->fromPost('tie_breaker', ''));
        $this->updateSolrSettings((int) $id, $settings);

        $searchConfig = $this->params()->fromPost('search_config', 'keep');

        // Combine linguistic + language into one value.
        if ($searchConfig === 'linguistic') {
            $lang = $this->params()->fromPost('search_language', '');
            $searchConfig = $lang ? 'linguistic:' . $lang : 'keep';
        }

        // Option "keep": only the query relevance settings above were saved;
        // the catchall analyzer schema is left untouched.
        if ($searchConfig === 'keep') {
            $this->messenger()->addSuccess(new PsrMessage(
                'Query relevance settings saved. Catchall analyzer unchanged.' // @translate
            ));
            return $this->redirect()->toRoute('admin/search-manager/solr/core-id', [
                'id' => $id,
                'action' => 'show',
            ]);
        }

        try {
            $solariumClient = $solrCore->solariumClient();
            $endpoint = $solariumClient->getEndpoint();
            $url = $endpoint->getBaseUri() . 'schema';

            $httpClient = new \Laminas\Http\Client($url, [
                'timeout' => 30,
            ]);
            $httpClient->setMethod('POST');
            $httpClient->setHeaders(['Content-Type' => 'application/json']);

            $fieldType = 'text_general';

            if ($searchConfig === 'optimized') {
                $fieldType = 'text_search';
                $fieldTypeDef = [
                    'name' => 'text_search',
                    'class' => 'solr.TextField',
                    'indexAnalyzer' => [
                        'tokenizer' => ['class' => 'solr.StandardTokenizerFactory'],
                        'filters' => [
                            ['class' => 'solr.LowerCaseFilterFactory'],
                            ['class' => 'solr.ASCIIFoldingFilterFactory', 'preserveOriginal' => true],
                            ['class' => 'solr.EdgeNGramFilterFactory', 'minGramSize' => 2, 'maxGramSize' => 20],
                        ],
                    ],
                    'queryAnalyzer' => [
                        'tokenizer' => ['class' => 'solr.StandardTokenizerFactory'],
                        'filters' => [
                            ['class' => 'solr.LowerCaseFilterFactory'],
                            ['class' => 'solr.ASCIIFoldingFilterFactory', 'preserveOriginal' => true],
                        ],
                    ],
                ];
            } elseif (strpos($searchConfig, 'linguistic:') === 0) {
                $lang = substr($searchConfig, 11);
                $languages = include dirname(__DIR__, 3)
                    . '/config/solr_languages.php';
                if (!isset($languages[$lang])) {
                    $this->messenger()->addError(new PsrMessage(
                        'Unsupported language: {lang}', // @translate
                        ['lang' => $lang]
                    ));
                    return $this->redirect()->toRoute(
                        'admin/search-manager/solr/core-id',
                        ['id' => $id, 'action' => 'show']
                    );
                }

                $fieldType = 'text_search_' . $lang;
                $langFilters = $languages[$lang]['filters'];

                // Base filters: lowercase + ASCII folding, then append the
                // language-specific filters.
                $baseFilters = [
                    ['class' => 'solr.LowerCaseFilterFactory'],
                    ['class' => 'solr.ASCIIFoldingFilterFactory', 'preserveOriginal' => true],
                ];
                $allFilters = array_merge(
                    $baseFilters,
                    $langFilters
                );

                $fieldTypeDef = [
                    'name' => $fieldType,
                    'class' => 'solr.TextField',
                    'indexAnalyzer' => [
                        'tokenizer' => ['class' => 'solr.StandardTokenizerFactory'],
                        'filters' => $allFilters,
                    ],
                    'queryAnalyzer' => [
                        'tokenizer' => ['class' => 'solr.StandardTokenizerFactory'],
                        'filters' => $allFilters,
                    ],
                ];
            }

            // Create or replace the custom field type.
            if (isset($fieldTypeDef)) {
                $httpClient->setRawBody(json_encode([
                    'replace-field-type' => $fieldTypeDef,
                ]));
                $response = $httpClient->send();
                if (!$response->isSuccess()) {
                    // Field type may not exist yet: try add.
                    $httpClient->setRawBody(json_encode([
                        'add-field-type' => $fieldTypeDef,
                    ]));
                    $response = $httpClient->send();
                    if (!$response->isSuccess()) {
                        $body = json_decode(
                            $response->getBody(), true
                        );
                        $error = $body['error']['msg']
                            ?? $response->getReasonPhrase();
                        $this->messenger()->addError(new PsrMessage(
                            'Failed to create field type: {error}', // @translate
                            ['error' => $error]
                        ));
                        return $this->redirect()->toRoute(
                            'admin/search-manager/solr/core-id',
                            ['id' => $id, 'action' => 'show']
                        );
                    }
                }
            }

            // Apply the field type to _text_.
            $replaceFieldData = json_encode([
                'replace-field' => [
                    'name' => '_text_',
                    'type' => $fieldType,
                    'multiValued' => true,
                    'indexed' => true,
                    'stored' => false,
                ],
            ]);

            $httpClient->setRawBody($replaceFieldData);
            $response = $httpClient->send();

            if ($response->isSuccess()) {
                if ($searchConfig === 'optimized') {
                    $message = 'Field "_text_" configured for Google-like search in core "{solr_core_name}". Reindex required.'; // @translate
                } elseif (strpos($searchConfig, 'linguistic:') === 0) {
                    $message = 'Field "_text_" configured for linguistic search ({type}) in core "{solr_core_name}". Reindex required.'; // @translate
                } else {
                    $message = 'Field "_text_" configured for strict matching in core "{solr_core_name}". Reindex required.'; // @translate
                }
                $this->messenger()->addSuccess(new PsrMessage(
                    $message,
                    ['type' => $fieldType, 'solr_core_name' => $solrCore->name()]
                ));
            } else {
                $body = json_decode($response->getBody(), true);
                $error = $body['error']['msg'] ?? $response->getReasonPhrase();
                $this->messenger()->addError(new PsrMessage(
                    'Failed to configure _text_ field: {error}', // @translate
                    ['error' => $error]
                ));
            }
        } catch (\Throwable $e) {
            $this->messenger()->addError(new PsrMessage(
                'Error configuring search: {error}', // @translate
                ['error' => $e->getMessage()]
            ));
        }

        return $this->redirect()->toRoute('admin/search-manager/solr/core-id', [
            'id' => $id,
            'action' => 'show',
        ]);
    }

    /**
     * @param SolrCoreRepresentation $solrCore
     * @return array
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    protected function getIndexedResourceCounts(SolrCoreRepresentation $solrCore): array
    {
        // For compatibility with drupal, allow to use the alias.
        $resourceTypeField = $solrCore->mapsBySource('resource_name', 'generic');
        $resourceTypeField = $resourceTypeField ? (reset($resourceTypeField))->fieldName() : null;

        // FIXME Find why the value totals are always different than the count of actual resource ids fetched.
        try {
            $counts = $resourceTypeField
                ? $solrCore->queryValuesCount($resourceTypeField)
                : [];
        } catch (\Throwable $e) {
            $counts = [];
            $this->messenger()->addError(new PsrMessage(
                'Solr issue: {msg}', // @translate
                ['msg' => $e->getMessage()]
            ));
        }

        return $counts;
    }

    /**
     * Store a snapshot of the current Solr maps on the core entity. Keeps the
     * last 3 snapshots in column `solr_core.backup_maps`.
     *
     * @param \SearchSolr\Api\Representation\SolrMapRepresentation[] $existingMaps
     */
    protected function snapshotMaps(
        \SearchSolr\Stdlib\SolrCore $solrCore,
        array $existingMaps
    ): void {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $authService = $services->get('Omeka\AuthenticationService');

        $userId = null;
        $userName = null;
        if ($authService->hasIdentity()) {
            $identity = $authService->getIdentity();
            $userId = $identity->getId();
            $userName = $identity->getName();
        }

        $maps = [];
        foreach ($existingMaps as $map) {
            $maps[] = [
                'resource_name' => $map->resourceName(),
                'field_name' => $map->fieldName(),
                'source' => $map->source(),
                'alias' => $map->alias(),
                'pool' => $map->pool() ?? [],
                'settings' => $map->settings() ?? [],
            ];
        }

        $snapshot = [
            'datetime' => (new \DateTime())->format('c'),
            'user_id' => $userId,
            'user_name' => $userName,
            'count' => count($maps),
            'maps' => $maps,
        ];

        // The snapshots live in the engine settings, under solr.backup_maps.
        $solrSettings = $solrCore->settings();
        $backups = $solrSettings['backup_maps'] ?? ['snapshots' => []];
        if (!isset($backups['snapshots']) || !is_array($backups['snapshots'])) {
            $backups['snapshots'] = [];
        }
        array_unshift($backups['snapshots'], $snapshot);
        $backups['snapshots'] = array_slice($backups['snapshots'], 0, 3);
        $solrSettings['backup_maps'] = $backups;
        $this->updateSolrSettings($solrCore->id(), $solrSettings);
    }

    /**
     * Restore Solr maps from a stored snapshot on the core. Existing maps are
     * deleted and recreated from the snapshot. The current state is itself
     * snapshotted before restore so the operation is reversible.
     */
    public function restoreBackupAction()
    {
        $api = $this->api();
        $id = (int) $this->params('id');
        $index = (int) $this->params()->fromQuery('index', 0);

        $solrCore = $this->solrCore((int) $id);
        $backups = $solrCore->backupMaps() ?? [];
        $snapshots = $backups['snapshots'] ?? [];
        if (!isset($snapshots[$index])) {
            $this->messenger()->addError(
                'Snapshot not found.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/search-manager/solr/core-id', ['id' => $id]
            );
        }

        // Snapshot the current state before restoring.
        $this->snapshotMaps($solrCore, $solrCore->maps());

        // Delete current maps. Use the API to trigger any related logic.
        foreach ($solrCore->maps() as $map) {
            $api->delete('solr_maps', $map->id());
        }

        // Recreate maps from the snapshot.
        $snapshot = $snapshots[$index];
        $created = 0;
        foreach ($snapshot['maps'] ?? [] as $m) {
            $data = [
                'o:solr_core' => ['o:id' => $id],
                'o:resource_name' => $m['resource_name'] ?? 'resources',
                'o:field_name' => $m['field_name'] ?? '',
                'o:source' => $m['source'] ?? '',
                'o:settings' => $m['settings'] ?? [],
            ];
            if (!empty($m['alias'])) {
                $data['o:alias'] = $m['alias'];
            }
            if (!empty($m['pool'])) {
                $data['o:pool'] = $m['pool'];
            }
            if ($data['o:field_name'] === '' || $data['o:source'] === '') {
                continue;
            }
            $api->create('solr_maps', $data);
            ++$created;
        }

        // Refresh boost configuration.
        $solrCore = $this->solrCore((int) $id);
        $this->updateFieldsBoost($solrCore);

        $this->messenger()->addSuccess(new PsrMessage(
            'Restored {count} maps from snapshot of {datetime}.', // @translate
            [
                'count' => $created,
                'datetime' => $snapshot['datetime'] ?? '',
            ]
        ));
        $this->messenger()->addWarning(
            'Reindex required.' // @translate
        );

        return $this->redirect()->toRoute(
            'admin/search-manager/solr/core-id', ['id' => $id]
        );
    }

    /**
     * Delete a stored snapshot from the Solr core.
     */
    public function deleteBackupAction()
    {
        $id = (int) $this->params('id');
        $index = (int) $this->params()->fromQuery('index', -1);

        $solrCore = $this->solrCore((int) $id);
        $solrSettings = $solrCore->settings();
        $backups = $solrSettings['backup_maps'] ?? ['snapshots' => []];
        $snapshots = $backups['snapshots'] ?? [];
        if (!isset($snapshots[$index])) {
            $this->messenger()->addError(
                'Snapshot not found.' // @translate
            );
        } else {
            array_splice($snapshots, $index, 1);
            $backups['snapshots'] = $snapshots;
            if ($snapshots) {
                $solrSettings['backup_maps'] = $backups;
            } else {
                unset($solrSettings['backup_maps']);
            }
            $this->updateSolrSettings((int) $id, $solrSettings);
            $this->messenger()->addSuccess(
                'Snapshot deleted.' // @translate
            );
        }

        return $this->redirect()->toRoute(
            'admin/search-manager/solr/core-id', ['id' => $id]
        );
    }
}
