<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2017
 * Copyright Daniel Berthereau, 2017-2025
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

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var ValueExtractorManager
     */
    protected $valueExtractorManager;

    /**
     * @var array
     */
    protected $solrLangs = [
        'cjk' => 'cjk',
        'zh' => 'cjk',
        'zho' => 'cjk',
        'chi' => 'cjk',
        // 'ja' => 'cjk',
        // 'jpn' => 'cjk',
        // 'ko' => 'cjk',
        // 'kor' => 'cjk',
        'en' => 'en',
        'eng' => 'en',
        'ar' => 'ar',
        'ara' => 'ar',
        'bg' => 'bg',
        'bul' => 'bg',
        'ca' => 'ca',
        'cat' => 'ca',
        'cz' => 'cz',
        'ces' => 'cz',
        'cze' => 'cz',
        'da' => 'da',
        'dan' => 'da',
        'de' => 'de',
        'deu' => 'de',
        'ger' => 'de',
        'el' => 'el',
        'ell' => 'el',
        'gre' => 'el',
        'es' => 'es',
        'spa' => 'es',
        'et' => 'et',
        'est' => 'et',
        'eu' => 'eu',
        'eus' => 'eu',
        'bas' => 'eu',
        'fa' => 'fa',
        'fas' => 'fa',
        'per' => 'fa',
        'fi' => 'fi',
        'fin' => 'fi',
        'fr' => 'fr',
        'fra' => 'fr',
        'fre' => 'fr',
        'ga' => 'ga',
        'gle' => 'ga',
        'gl' => 'gl',
        'glg' => 'gl',
        'hi' => 'hi',
        'hin' => 'hi',
        'hu' => 'hu',
        'hun' => 'hu',
        'hy' => 'hy',
        'hye' => 'hy',
        'arm' => 'hy',
        'id' => 'id',
        'ind' => 'id',
        'it' => 'it',
        'ita' => 'it',
        'ja' => 'ja',
        'jpn' => 'ja',
        'ko' => 'ko',
        'kor' => 'ko',
        'lv' => 'lv',
        'lav' => 'lv',
        'nl' => 'nl',
        'nld' => 'nl',
        'dut' => 'nl',
        'no' => 'no',
        'nor' => 'no',
        'pt' => 'pt',
        'por' => 'pt',
        'ro' => 'ro',
        'ron' => 'ro',
        'rum' => 'ro',
        'ru' => 'ru',
        'rus' => 'ru',
        'sv' => 'sv',
        'swe' => 'sv',
        'th' => 'th',
        'tha' => 'th',
        'tr' => 'tr',
        'tur' => 'tr',
    ];

    public function __construct(
        Connection $connection,
        ValueExtractorManager $valueExtractorManager
    ) {
        $this->connection = $connection;
        $this->valueExtractorManager = $valueExtractorManager;
    }

    public function browseResourceAction()
    {
        $solrCoreId = $this->params('core-id');
        $resourceName = $this->params('resource-name');

        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();
        $maps = $solrCore->mapsByResourceName($resourceName);

        if (!$solrCore->schema()->checkDefaultField()) {
            $this->messenger()->addWarning(
                'This core seems to have no default field. If there are no results to a default query, add the copy field "_text_" with source "*".' // @translate
            );
        }

        return new ViewModel([
            'solrCore' => $solrCore,
            'resourceName' => $resourceName,
            'maps' => $maps,
        ]);
    }

    public function completeAction()
    {
        // TODO Complete for all resources names.

        $solrCoreId = $this->params('core-id');
        $resourceName = $this->params('resource-name') ?: 'items';

        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

        $api = $this->api();

        // Get all existing indexed properties and keep only field names.
        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation[] $maps */
        $maps = $solrCore->mapsByResourceName($resourceName);
        $maps = array_map(fn ($v) => $v->fieldName(), $maps);

        $skipTermTexts = include dirname(__DIR__, 3) . '/config/metadata_text.php';

        // Prepare the indexes by languages.
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'CONCAT(`vocabulary`.`prefix`, ":", `property`.`local_name`) AS term',
                '`value`.`lang` AS lang',
                // Required for mysql, but useless.
                '`property`.`id` AS prop'
            )
            ->distinct()
            ->from('`value`', 'value')
            ->innerJoin('value', 'property', 'property', '`property`.`id` = `value`.`property_id`')
            ->innerJoin('property', 'vocabulary', 'vocabulary', '`property`.`vocabulary_id` = `vocabulary`.`id`')
            ->where('`value`.`lang` IS NOT NULL')
            ->andWhere('`value`.`lang` != ""')
            ->orderBy('`property`.`id`', 'asc')
            ->addOrderBy('`value`.`lang`', 'asc')
        ;
        $result = $this->connection->executeQuery($qb)->fetchAllAssociative();
        $langsByProperties = [];
        foreach ($result as $propLang) {
            $langsByProperties[$propLang['term']][] = $propLang['lang'];
        }

        if (empty($langsByProperties)) {
            $this->messenger()->addSuccess(new PsrMessage(
                'No values have a language. The indexes will use a generic language (_txt).' // @translate
            ));
        } else {
            $this->messenger()->addSuccess(new PsrMessage(
                'The values use the following languages: {json}.', // @translate
                ['json' => json_encode($langsByProperties, 320)]
            ));
        }

        // TODO Use language from the settings to prepare the maps?
        // $langs = $this->settings('value_languages') ?: [];

        $createMap = function (string $name, string $term, ?string $alias, array $pool, array $settings)
            use ($api, $solrCoreId, $resourceName, &$maps): ?SolrMapRepresentation
        {
            if (in_array($name, $maps)) {
                return null;
            }
            $data = [];
            $data['o:solr_core']['o:id'] = $solrCoreId;
            $data['o:resource_name'] = $resourceName;
            $data['o:field_name'] = $name;
            $data['o:alias'] = $alias;
            $data['o:source'] = $term;
            $data['o:pool'] = $pool;
            $data['o:settings'] = $settings;
            $result = $api->create('solr_maps', $data)->getContent();
            $maps[] = $name;
            return $result;
        };

        // Add all missing maps with a generic multivalued text field.
        // Don't add a map if it exists at a upper level.
        $newMaps = [];
        $properties = $api->search('properties')->getContent();
        $usedPropertyIds = $this->listUsedPropertyIds($resourceName);
        foreach ($properties as $property) {
            // Skip property that are not used.
            if (!in_array($property->id(), $usedPropertyIds)) {
                continue;
            }

            $term = $property->term();

            // For full text search (_t = single value, _txt = multivalued).
            $name = str_replace(':', '_', $term) . '_txt';
            $result = $createMap($name, $term, null, [], ['formatter' => '', 'label' => $property->label()]);
            if ($result) {
                $newMaps[] = $name;
            }

            // For full text search with language managed by solr.
            foreach ($langsByProperties[$term] ?? [] as $language) {
                if (!isset($this->solrLangs[$language])) {
                    continue;
                }
                $name = str_replace(':', '_', $term) . '_txt_' . $this->solrLangs[$language];
                $result = $createMap(
                    $name,
                    $term,
                    null,
                    ['filter_languages' => array_keys($this->solrLangs, $this->solrLangs[$language])],
                    ['formatter' => '', 'label' => $property->label()]
                );
                if ($result) {
                    $newMaps[] = $name;
                }
            }

            if (!in_array($term, $skipTermTexts)) {
                // For filters and facets.
                $name = str_replace(':', '_', $term) . '_ss';
                $result = $createMap($name, $term, $term, [], ['formatter' => '', 'label' => $property->label()]);
                if ($result) {
                    $newMaps[] = $name;
                }

                // For sort.
                $name = str_replace(':', '_', $term) . '_s';
                $result = $createMap($name, $term, null, [], ['formatter' => '', 'label' => $property->label()]);
                if ($result) {
                    $newMaps[] = $name;
                }
            }
        }

        if ($newMaps) {
            $this->messenger()->addSuccess(new PsrMessage(
                '{count} new maps successfully created: {list}.', // @translate
                ['count' => count($newMaps), 'list' => implode(', ', $newMaps)]
            ));
            $this->messenger()->addWarning('Check all new maps and remove useless ones.'); // @translate
            $this->messenger()->addWarning('Don’t forget to run the indexation of the core.'); // @translate
        } else {
            $this->messenger()->addWarning('No new maps added.'); // @translate
        }

        return $this->redirect()->toRoute('admin/search/solr/core-id', ['id' => $solrCoreId]);
    }

    public function cleanAction()
    {
        $solrCoreId = $this->params('core-id');
        $resourceName = $this->params('resource-name');
        $api = $this->api();

        /** @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore */
        $solrCore = $api->read('solr_cores', $solrCoreId)->getContent();

        // Get all existing indexed properties.
        $maps = $solrCore->mapsByResourceName($resourceName);

        // Map as associative array by map id and keep only the source.
        $mapList = [];
        foreach ($maps as $map) {
            // Only the maps with the current resource name are removed.
            if ($map->resourceName() === $resourceName) {
                $mapList[$map->id()] = $map->source();
            }
        }

        // Add all missing maps.
        $result = [];
        $properties = $api->search('properties')->getContent();
        $usedPropertyIds = $this->listUsedPropertyIds($resourceName);
        foreach ($properties as $property) {
            // Skip property that are used.
            if (in_array($property->id(), $usedPropertyIds)) {
                continue;
            }

            // Skip property that are not mapped.
            $term = $property->term();
            if (!in_array($term, $mapList)) {
                continue;
            }

            // There may be multiple maps with the same term.
            $ids = array_keys(array_filter($mapList, fn ($v) => $v === $term));
            $api->batchDelete('solr_maps', $ids);

            $result[] = $term;
        }

        if ($result) {
            $this->messenger()->addSuccess(new PsrMessage(
                '{count} maps successfully deleted: {list}.', // @translate
                ['count' => count($result), 'list' => implode(', ', $result)]
            ));
            $this->messenger()->addNotice('Don’t forget to run the indexation of the core.'); // @translate
        } else {
            $this->messenger()->addWarning('No maps deleted.'); // @translate
        }

        return $this->redirect()->toRoute('admin/search/solr/core-id', ['id' => $solrCoreId]);
    }

    public function addAction()
    {
        $solrCoreId = $this->params('core-id');
        $resourceName = $this->params('resource-name');

        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

        $form = $this->getForm(SolrMapForm::class, [
            'solr_core_id' => $solrCoreId,
            'resource_name' => $resourceName,
        ]);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $data = $form->getData();
                $data = $this->arrayFilterRecursiveEmptyValue($data);
                $data['o:source'] = $this->sourceArrayToString($data['o:source']);
                $data['o:solr_core']['o:id'] = $solrCoreId;
                $data['o:resource_name'] = $resourceName;
                $this->api()->create('solr_maps', $data);

                $this->messenger()->addSuccess(new PsrMessage(
                    'Solr map created: {solr_map_name}.', // @translate
                    ['solr_map_name' => $data['o:field_name']]
                ));

                return $this->redirect()->toRoute('admin/search/solr/core-id', [
                    'id' => $solrCoreId,
                    // TODO Add a filter on resource name.
                    // 'resource-name' => $resourceName,
                ]);
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
         * @var \SearchSolr\Api\Representation\SolrCoreRepresentation $solrCore
         * @var \SearchSolr\Api\Representation\SolrMapRepresentation $map
         */
        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

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
                $data['o:source'] = $this->sourceArrayToString($data['o:source']);
                $data['o:solr_core']['o:id'] = $solrCoreId;
                $data['o:resource_name'] = $resourceName;
                $this->api()->update('solr_maps', $id, $data);

                $this->messenger()->addSuccess(new PsrMessage(
                    'Solr map modified: {solr_map_name}.', // @translate
                    ['solr_map_name' => $data['o:field_name']]
                ));

                $this->messenger()->addWarning('Don’t forget to check search pages that use this map.'); // @translate

                return $this->redirect()->toRoute('admin/search/solr/core-id', [
                    'id' => $solrCoreId,
                    // TODO Add a filter on resource name.
                    // 'resource-name' => $resourceName,
                ]);
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
                $this->api()->delete('solr_maps', $id);
                $this->messenger()->addSuccess('Solr map successfully deleted'); // @translate
                $this->messenger()->addWarning('Don’t forget to check search pages that used this map.'); // @translate
            } else {
                $this->messenger()->addError('Solr map could not be deleted'); // @translate
            }
        }

        return $this->redirect()->toRoute('admin/search/solr/core-id', ['id' => $map->solrCore()->id()]);
    }

    protected function getSolrSchema($solrCoreId)
    {
        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();
        return $solrCore->schema()->getSchema();
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
     * @param string $resourceName
     * @return \Omeka\Api\Representation\PropertyRepresentation[]
     */
    protected function listUsedPropertyIds($resourceName): array
    {
        $resourceTypes = [
            'resources' => \Omeka\Entity\Resource::class,
            'items' => \Omeka\Entity\Item::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
            'media' => \Omeka\Entity\Media::class,
            'value_annotations' => \Omeka\Entity\ValueAnnotation::class,
            'annotations' => \Annotate\Entity\Annotation::class,
        ];

        // Manage "generic" type.
        if (!isset($resourceTypes[$resourceName])) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder()
            ->select('DISTINCT value.property_id')
            ->from('value', 'value')
            ->innerJoin('value', 'resource', 'resource', 'resource.id = value.resource_id')
            ->orderBy('value.property_id', 'ASC');
        if ($resourceName !== 'resources') {
            $qb
                ->where('resource.resource_type = :resource_type')
                ->setParameter('resource_type', $resourceTypes[$resourceName]);
        }

        return $this->connection
            ->executeQuery($qb, $qb->getParameters())
            ->fetchFirstColumn();
    }

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
        return implode('/', array_map(fn ($v) => $v['source'], $source));
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
}
