<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2017
 * Copyright Daniel Berthereau, 2017-2026
 * Copyright Paul Sarrassat, 2018
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
use Doctrine\DBAL\Connection;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use SearchSolr\Api\Adapter\TraitArrayFilterRecursiveEmptyValue;
use SearchSolr\Api\Representation\SolrMapRepresentation;
use SearchSolr\Form\Admin\SolrMapForm;
use SearchSolr\ValueExtractor\Manager as ValueExtractorManager;

class MapController extends AbstractActionController
{
    use TraitArrayFilterRecursiveEmptyValue;
    use TraitSolrController;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var ValueExtractorManager
     */
    protected $valueExtractorManager;

    public function __construct(
        Connection $connection,
        ValueExtractorManager $valueExtractorManager
    ) {
        $this->connection = $connection;
        $this->valueExtractorManager = $valueExtractorManager;
    }

    /**
     * Redirect to the core show page. The dedicated browse-resource
     * page has been removed. Kept for backward compatibility of
     * bookmarks and external links.
     */
    public function browseResourceAction()
    {
        $solrCoreId = $this->params('core-id');
        $resourceName = $this->params('resource-name');
        $url = $this->url()->fromRoute(
            'admin/search-manager/solr/core-id',
            ['id' => $solrCoreId]
        );
        return $this->redirect()
            ->toUrl($url . '?resource_type=' . urlencode($resourceName));
    }

    public function addAction()
    {
        $solrCoreId = $this->params('core-id');
        $resourceName = $this->params('resource-name');

        $solrCore = $this->solrCore((int) $solrCoreId);

        $form = $this->getForm(SolrMapForm::class, [
            'solr_core_id' => $solrCoreId,
            'resource_name' => $resourceName,
        ]);

        // Prefill the source when given, e.g. from the "+" of the lists of the
        // maps of the core page.
        $sourceQuery = (string) $this->params()->fromQuery('source');
        if ($sourceQuery !== '' && !$this->getRequest()->isPost()) {
            $form->setData(['o:source' => $this->sourceStringToArray($sourceQuery)]);
        }

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $data = $form->getData();
                $data = $this->arrayFilterRecursiveEmptyValue($data);
                $data = $this->cleanMapSettings($data);
                $data = $this->arrayFilterRecursiveEmptyValue($data);
                $data['o:source'] = $this->sourceArrayToString($data['o:source'] ?? []);
                $data['o:solr_core']['o:id'] = $solrCoreId;
                // The scope is chosen in the form ("Scope" radio); the route
                // resource-name is only the default, so it is the fallback.
                $data['o:resource_name'] = $data['o:resource_name'] ?? $resourceName;
                // Explicit provenance: a map created by hand is never removed
                // by the maps sync.
                $data['o:settings']['origin'] = 'manual';
                $this->api()->create('solr_maps', $data);

                // Ideally, the update of the core should be done via event.
                $this->updateFieldsBoost($solrCore);

                $this->messenger()->addSuccess(new PsrMessage(
                    'Solr map created: {solr_map_name}.', // @translate
                    ['solr_map_name' => $data['o:field_name']]
                ));

                return $this->redirect()->toUrl(
                    $this->url()->fromRoute(
                        'admin/search-manager/solr/core-id',
                        ['id' => $solrCoreId]
                    ) . '?resource_type=' . urlencode(
                        $data['o:resource_name'] ?? $resourceName
                    )
                );
            } else {
                $messages = $form->getMessages();
                if (isset($messages['csrf'])) {
                    $this->messenger()->addError(
                        'Invalid or missing CSRF token' // @translate
                    );
                } else {
                    $this->messenger()->addError(
                        'There was an error during validation' // @translate
                    );
                }
            }
        }

        return new ViewModel([
            'solrCore' => $solrCore,
            'resourceName' => $resourceName,
            'form' => $form,
            'schema' => $this->getSolrSchema($solrCoreId),
            'sourceLabels' => $this->getSourceLabels(),
        ]);
    }

    public function editAction()
    {
        $solrCoreId = $this->params('core-id');
        $resourceName = $this->params('resource-name');
        $id = $this->params('id');

        /**
         * @var \SearchSolr\Stdlib\SolrCore $solrCore
         * @var \SearchSolr\Api\Representation\SolrMapRepresentation $map
         */
        $solrCore = $this->solrCore((int) $solrCoreId);

        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation $map */
        $map = $this->api()->read('solr_maps', $id)->getContent();

        $form = $this->getForm(SolrMapForm::class, [
            'solr_core_id' => $solrCoreId,
            'resource_name' => $resourceName,
        ]);
        $mapData = $map->jsonSerialize();
        $mapData['o:source'] = $this->sourceStringToArray($mapData['o:source']);
        $form->setData($mapData);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $data = $form->getData();
                $data = $this->arrayFilterRecursiveEmptyValue($data);
                $data = $this->cleanMapSettings($data);
                $data = $this->arrayFilterRecursiveEmptyValue($data);
                $data['o:source'] = $this->sourceArrayToString($data['o:source'] ?? []);
                $data['o:solr_core']['o:id'] = $solrCoreId;
                $data['o:resource_name'] = $resourceName;
                // A map edited by hand becomes manual, whatever its origin.
                $data['o:settings']['origin'] = 'manual';
                $this->api()->update('solr_maps', $id, $data);

                // Ideally, the update of the core should be done via an event.
                $this->updateFieldsBoost($solrCore);

                $this->messenger()->addSuccess(new PsrMessage(
                    'Solr map modified: {solr_map_name}.', // @translate
                    ['solr_map_name' => $data['o:field_name']]
                ));

                $this->messenger()->addWarning('Don’t forget to check search pages that use this map.'); // @translate

                return $this->redirect()->toUrl(
                    $this->url()->fromRoute(
                        'admin/search-manager/solr/core-id',
                        ['id' => $solrCoreId]
                    ) . '?resource_type=' . urlencode(
                        $data['o:resource_name'] ?? $resourceName
                    )
                );
            } else {
                $messages = $form->getMessages();
                if (isset($messages['csrf'])) {
                    $this->messenger()->addError('Invalid or missing CSRF token'); // @translate
                } else {
                    $this->messenger()->addError('There was an error during validation'); // @translate
                }
            }
        }

        return new ViewModel([
            'solrCore' => $solrCore,
            'resourceName' => $resourceName,
            'map' => $map,
            'form' => $form,
            'schema' => $this->getSolrSchema($solrCoreId),
            'sourceLabels' => $this->getSourceLabels(),
        ]);
    }

    public function deleteConfirmAction()
    {
        /**
         * @var \SearchSolr\Api\Representation\SolrMapRepresentation $map
         */
        $id = $this->params('id');
        $response = $this->api()->read('solr_maps', $id);
        $map = $response->getContent();

        $searchConfigs = $map->solrCore()->searchConfigs();
        $searchConfigsUsingMap = [];
        foreach ($searchConfigs as $searchConfig) {
            if ($this->doesSearchConfigUseMap($searchConfig, $map)) {
                $searchConfigsUsingMap[] = $searchConfig;
            }
        }

        $view = new ViewModel([
            'resourceLabel' => 'Solr map', // @translate
            'resource' => $map,
            'partialPath' => 'common/solr-map-delete-confirm-details',
            'totalSearchConfigs' => count($searchConfigs),
            'totalSearchConfigsUsingMap' => count($searchConfigsUsingMap),
        ]);
        return $view
            ->setTerminal(true)
            ->setTemplate('common/delete-confirm-details');
    }

    public function deleteAction()
    {
        /**
         * @var \SearchSolr\Api\Representation\SolrMapRepresentation $map
         */
        $id = $this->params('id');
        $map = $this->api()->read('solr_maps', $id)->getContent();

        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $solrCore = $map->solrCore();
                $this->api()->delete('solr_maps', $id);
                // Ideally, the update of the core should be done via event.
                $this->updateFieldsBoost($solrCore);
                $this->messenger()->addSuccess('Solr map successfully deleted'); // @translate
                $this->messenger()->addWarning('Don’t forget to check search pages that used this map.'); // @translate
            } else {
                $this->messenger()->addError('Solr map could not be deleted'); // @translate
            }
        }

        return $this->redirect()->toRoute('admin/search-manager/solr/core-id', ['id' => $map->solrCore()->id()]);
    }

    protected function getSolrSchema($solrCoreId)
    {
        $solrCore = $this->solrCore((int) $solrCoreId);
        try {
            return $solrCore->schema()->getSchema();
        } catch (\Exception $e) {
            // Solr unreachable: the form still works without the schema hints.
            $this->messenger()->addWarning(
                'The Solr server is not reachable, so the schema hints are unavailable.' // @translate
            );
            return null;
        }
    }

    protected function getSourceLabels()
    {
        $sourceLabels = [
            'resource_name' => 'Resource type', // @translate
            'id' => 'Internal id', // @translate
            'is_public' => 'Public', // @translate
            'is_open' => 'Is open', // @translate
            'site' => 'Site', // @translate
            'owner' => 'Owner', // @translate
            'created' => 'Created', // @translate
            'modified' => 'Modified', // @translate
            'resource_class' => 'Resource class', // @translate
            'resource_template' => 'Resource template', // @translate
            'item_set' => 'Item set', // @translate
            'item' => 'Item', // @translate
            'media' => 'Media', // @translate
            'digital_object' => 'Digital object', // @translate
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
     * Check if a search page use a map enabled as facet or sort field.
     *
     * @param SearchConfigRepresentation $searchConfig
     * @param SolrMapRepresentation $solrMap
     * @return bool
     */
    protected function doesSearchConfigUseMap(
        SearchConfigRepresentation $searchConfig,
        SolrMapRepresentation $solrMap
    ) {
        $searchConfigSettings = $searchConfig->settings();
        $fieldName = $solrMap->fieldName();
        foreach ($searchConfigSettings as $value) {
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

    /**
     * Get all used properties for a resource.
     *
     * @todo Use EasyMeta (but filtered by resource).
     *
    /**
     * Convert an array of sources into a string of sources separated by "/".
     *
     * @example
     * Turns:
     * <code>
     * [
     *     0 => ['source' => "foo"],
     *     1 => ['source' => "bar"],
     * ]
     * </code>
     * into:
     * <code>
     * "foo/bar"
     * </code>
     *
     * @param array $source
     */
    protected function sourceArrayToString($source)
    {
        return implode('/', array_map(fn ($v) => $v['source'] ?? '', $source ?: []));
    }

    /**
     * Convert a string of sources separated by "/" into an array of sources.
     *
     * @see self::sourceArrayToString()
     *
     * @param array $source
     */
    protected function sourceStringToArray($source)
    {
        return array_map(fn ($v) => ['source' => $v], explode('/', $source));
    }

    /**
     * Remove the settings specific to another formatter or normalization,
     * so a map stores only what applies to it.
     */
    protected function cleanMapSettings(array $data): array
    {
        $formatter = $data['o:settings']['formatter'] ?? '';
        $specificByFormatter = [
            'date' => ['date_mode', 'date_out'],
            'place' => ['place_mode'],
            'thesaurus' => ['thesaurus_resources', 'thesaurus_self', 'thesaurus_metadata'],
        ];
        foreach ($specificByFormatter as $formatterName => $keys) {
            if ($formatter !== $formatterName) {
                foreach ($keys as $key) {
                    unset($data['o:settings'][$key]);
                }
            }
        }
        // The unchecked checkboxes post "0": store nothing.
        foreach (['include_digital_object', 'thesaurus_self', 'table_index_original', 'table_check_strict'] as $key) {
            if (empty($data['o:settings'][$key])) {
                unset($data['o:settings'][$key]);
            }
        }
        if (empty($data['o:pool']['filter_languages_no_lang'])) {
            unset($data['o:pool']['filter_languages_no_lang']);
        }
        $normalizations = $data['o:settings']['normalization'] ?? [];
        $specificByNormalization = [
            'max_length' => ['max_length'],
            'truncate' => ['truncate_at'],
            'table' => ['table', 'table_mode', 'table_index_original', 'table_check_strict'],
        ];
        foreach ($specificByNormalization as $normalization => $keys) {
            if (!in_array($normalization, $normalizations, true)) {
                foreach ($keys as $key) {
                    unset($data['o:settings'][$key]);
                }
            }
        }
        return $data;
    }
}
