<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2018-2025
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

namespace SearchSolr\ValueExtractor;

use Laminas\Log\LoggerInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\AbstractRepresentation;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\AbstractResourceRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use SearchSolr\Api\Representation\SolrMapRepresentation;

abstract class AbstractResourceEntityValueExtractor implements ValueExtractorInterface
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var LoggerInterface Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $baseFilepath;

    protected $label;

    public function __construct(ApiManager $api, LoggerInterface $logger, $baseFilepath)
    {
        $this->api = $api;
        $this->logger = $logger;
        $this->baseFilepath = $baseFilepath;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getMapFields(): array
    {
        // To allow to select any data recursively in case of value resources,
        // all possible fields are included here.
        // TODO Use ajax to improve select of sub fields.

        $fields = [
            'generic' => [
                'label' => 'Generic', // @translate
                'options' => [
                    'resource_name' => 'Resource type', // @translate
                    'o:id' => 'Internal id', // @translate
                    'owner' => 'Owner', // @translate
                    'site' => 'Site', // @translate
                    'is_public' => 'Is public', // @translate
                    'created' => 'Created', // @translate
                    'modified' => 'Modified', // @translate
                    'resource_class' => 'Resource class', // @translate
                    'resource_template' => 'Resource template', // @translate
                    'asset' => 'Asset (attached thumbnail)', // @translate
                    'item_set' => 'Item: Item set', // @translate
                    'item_sets_tree' => 'Item: Item sets tree', // @translate
                    'media' => 'Item: Media', // @translate
                    'has_media' => 'Item: Has media', // @translate
                    'content' => 'Media: Content (from html or extractable text from file, included alto)', // @translate
                    'is_open' => 'Item set: Is open', // @translate
                    'value' => 'Value itself (in particular for module Thesaurus)', // @translate
                    'access_level' => 'Access level (module Access)', // @translate
                    // Urls.
                    'url_api' => 'Api url', // @translate
                    'url_admin' => 'Admin url', // @translate
                    'url_site' => 'Site url (default or first site only)', // @translate
                    'url_asset' => 'Asset/Thumbnail: file url', // @translate
                    'url_original' => 'Primary media: original file url', // @translate
                    //  TODO Manage all thumbnail types (here only standard ones).
                    'url_thumbnail_large' => 'Primary media: large thumbnail url', // @translate
                    'url_thumbnail_medium' => 'Primary media: medium thumbnail url', // @translate
                    'url_thumbnail_square' => 'Primary media: square thumbnail url', // @translate
                    'url_thumbnail_display_large' => 'Representative image (asset if any, else primary media large thumbnail)', // @translate,
                    'url_thumbnail_display_medium' => 'Representative image (asset if any, else primary media medium thumbnail)', // @translate
                    'url_thumbnail_display_square' => 'Representative image (asset if any, else primary media square thumbnail)', // @translate
                    // Specific values.
                    'o:label' => 'Label', // @translate
                    'o:name' => 'Name', // @translate
                    'o:title' => 'Title', // @translate
                    'o:lang' => 'Media language', // @translate
                    'o:ingester' => 'Media ingester', // @translate
                    'o:renderer' => 'Media renderer', // @translate
                    'o:size' => 'Media size', // @translate
                    'o:source' => 'Media source', // @translate
                    'o:media_type' => 'Media type', // @translate
                    'o:filename' => 'File name', // @translate
                    'o:alt_text' => 'Alternative text', // @translate
                    'o:asset_url' => 'Asset url', // @translate
                    'o:original_url' => 'Original url', // @translate
                    'o:thumbnail' => 'Thumbnail (asset)', // @translate
                    'o:term' => 'Property or class term', // @translate
                    'property_values' => 'All property values', // @translate
                ],
            ],
            // Set dcterms first.
            'dcterms' => [],
        ];

        $properties = $this->api->search('properties')->getContent();
        foreach ($properties as $property) {
            $prefix = $property->vocabulary()->prefix();
            if (empty($fields[$prefix]['label'])) {
                $fields[$prefix] = [
                    'label' => $property->vocabulary()->label(),
                    'options' => [],
                ];
            }
            $fields[$prefix]['options'][$property->term()] = $property->label();
        }

        return $fields;
    }

    /**
     * If a value is a linked resource, then this method is called recursively.
     *
     * {@inheritDoc}
     * @see \SearchSolr\ValueExtractor\ValueExtractorInterface::extractValue()
     */
    public function extractValue(
        AbstractResourceRepresentation $resource,
        SolrMapRepresentation $solrMap
    ): array {
        static $defaultSiteSlug;

        if ($this->excludeResourceViaQueryFilter($resource, $solrMap, 'filter_resources')) {
            return [];
        }

        $field = $solrMap->firstSource();

        if ($field === '') {
            if (method_exists($resource, 'displayTitle')) {
                $title = $resource->displayTitle('');
            } elseif (method_exists($resource, 'title')) {
                $title = $resource->title();
            } elseif (method_exists($resource, 'label')) {
                $title = $resource->label();
            } elseif (method_exists($resource, 'name')) {
                $title = $resource->name();
            } else {
                return [];
            }
            return mb_strlen($title) ? [$title] : [];
        }

        if ($field === 'o:id') {
            return [$resource->id()];
        }

        if ($field === 'is_public') {
            return method_exists($resource, 'isPublic')
                ? [$resource->isPublic()]
                : [true];
        }

        if ($field === 'owner') {
            return method_exists($resource, 'owner')
                ? $this->extractOwnerValues($resource, $solrMap)
                : [];
        }

        if ($field === 'site') {
            return $resource instanceof AbstractResourceEntityRepresentation
                ? $this->extractSitesValues($resource, $solrMap)
                : [];
        }

        if ($field === 'created') {
            return method_exists($resource, 'created')
                ? [$resource->created()->format('Y-m-d\TH:i:s\Z')]
                : [];
        }

        if ($field === 'modified') {
            if (!method_exists($resource, 'modified')) {
                return [];
            }
            $modified = $resource->modified();
            return $modified
                ? [$modified->format('Y-m-d\TH:i:s\Z')]
                : [];
        }

        if ($field === 'resource_class') {
            return $resource instanceof AbstractResourceEntityRepresentation
                // May fix a issue when a resource has no class but trying to
                // get the term.
                && $resource->resourceClass()
                ? $this->extractResourceClassValues($resource, $solrMap)
                : [];
        }

        if ($field === 'resource_template') {
            return $resource instanceof AbstractResourceEntityRepresentation
                // Prevent possible issue like resource class.
                && $resource->resourceTemplate()
                ? $this->extractResourceTemplateValues($resource, $solrMap)
                : [];
        }

        if ($field === 'asset') {
            return method_exists($resource, 'thumbnail')
                ? $this->extractAssetValues($resource, $solrMap)
                : [];
        }

        if ($field === 'media') {
            return $resource instanceof ItemRepresentation
                ? $this->extractItemMediasValue($resource, $solrMap)
                : [];
        }

        if ($field === 'has_media') {
            return $resource instanceof ItemRepresentation
                ? [count($resource->media()) > 0]
                : [false];
        }

        if ($field === 'content') {
            return $resource instanceof MediaRepresentation
                ? $this->extractMediaContent($resource)
                : [];
        }

        if ($field === 'item_set') {
            return $resource instanceof ItemRepresentation
                ? $this->extractItemItemSetsValue($resource, $solrMap)
                : [];
        }

        if ($field === 'item_sets_tree') {
            return $resource instanceof ItemRepresentation
                ? $this->extractItemItemSetsTreeValue($resource, $solrMap)
                : [];
        }

        if ($field === 'is_open') {
            return $resource instanceof ItemSetRepresentation
                ? [$resource->isOpen()]
                : [];
        }

        // See below when extracting value resource.
        if ($field === 'value') {
            // Here, the resource is never a value.
            return $resource instanceof ValueRepresentation
                ? [$resource]
                : [];
        }

        if ($field === 'access_level') {
            return $resource instanceof AbstractResourceEntityRepresentation
                ? $this->accessLevel($resource, $solrMap)
                : [];
        }

        if ($field === 'url_site') {
            if ($defaultSiteSlug === null) {
                /** @var \Common\View\Helper\DefaultSite $defaultSite */
                $defaultSite = $resource->getServiceLocator()->get('ViewHelperManager')->get('defaultSite');
                $defaultSiteSlug = $defaultSite('slug') ?: false;
            }
            if ($defaultSiteSlug === false || !method_exists($resource, 'siteUrl')) {
                return [];
            }
            // Some resources like assets have the method, but no data.
            $url = $resource->siteUrl($defaultSiteSlug, true);
            return $url ? [$url] : [];
        }

        if ($field === 'url_asset') {
            if (!method_exists($resource, 'thumbnail')) {
                return [];
            }
            $asset = $resource->thumbnail();
            return $asset
                ? [$asset->assetUrl()]
                : [];
        }

        if ($field === 'url_original') {
            $primaryMedia = $resource->primaryMedia();
            if (!$primaryMedia) {
                return [];
            }
            $url = $primaryMedia->originalUrl();
            return $url ? [$url] : [];
        }

        $mediaUrlTypes = [
            'url_thumbnail_large' => 'large',
            'url_thumbnail_medium' => 'medium',
            'url_thumbnail_square' => 'square',
        ];
        if (isset($mediaUrlTypes[$field])) {
            $primaryMedia = $resource->primaryMedia();
            if (!$primaryMedia) {
                return [];
            }
            $url = $primaryMedia->thumbnailUrl($mediaUrlTypes[$field]);
            return $url ? [$url] : [];
        }

        $mediaUrlTypes = [
            'url_thumbnail_display_large' => 'large',
            'url_thumbnail_display_medium' => 'medium',
            'url_thumbnail_display_square' => 'square',
        ];
        if (isset($mediaUrlTypes[$field])) {
            if (!method_exists($resource, 'thumbnailDisplayUrl')) {
                return [];
            }
            $url = $resource->thumbnailDisplayUrl($mediaUrlTypes[$field]);
            return $url ? [$url] : [];
        }

        // TODO Use all available locales to get the title and the description.
        if ($field === 'o:title' && method_exists($resource, 'displayTitle')) {
            $result = $resource->displayTitle();
            return $result === null || $result === '' || $result === []
                ? []
                : [$result];
        }

        if ($field === 'o:description' && method_exists($resource, 'displayDescription')) {
            $result = $resource->displayDescription();
            return $result === null || $result === '' || $result === []
                ? []
                : [$result];
        }

        $specialMetadata = [
            'resource_name' => 'resourceName',
            'url_admin' => 'adminUrl',
            'url_api' => 'apiUrl',
            // Special metadata.
            'o:term' => 'term',
            'o:label' => 'label',
            'o:name' => 'name',
            'o:filename' => 'filename',
            'o:lang' => 'lang',
            'o:ingester' => 'ingester',
            'o:renderer' => 'renderer',
            'o:size' => 'size',
            'o:source' => 'source',
            'o:media_type' => 'mediaType',
            'o:title' => 'title',
            'o:alt_text' => 'altText',
            'o:asset_url' => 'assetUrl',
            'o:original_url' => 'originalUrl',
            'o:thumbnail' => 'thumbnail',
        ];
        if (isset($specialMetadata[$field])) {
            if (!method_exists($resource, $specialMetadata[$field])) {
                return [];
            }
            try {
                $result = $resource->{$specialMetadata[$field]}();
            } catch (\Exception $e) {
                $result = null;
            }
            return $result === null || $result === '' || $result === []
                ? []
                : [$result];
        }

        if ($field === 'property_values') {
            if (!method_exists($resource, 'values')) {
                return [];
            }
            return $this->extractPropertyValues($resource, $solrMap);
        }

        if (strpos($field, ':')) {
            return $this->extractPropertyValue($resource, $solrMap);
        }

        return [];
    }

    protected function excludeResourceViaQueryFilter(
        AbstractResourceRepresentation $resource,
        SolrMapRepresentation $solrMap,
        string $keyQueryFilter
    ): bool {
        static $idsByQueries = [
            'items' => [],
            'item_sets' => [],
            'media' => [],
            'assets' => [],
            'users' => [],
        ];

        $queryFilter = $solrMap->pool($keyQueryFilter);
        if (empty($queryFilter)) {
            return false;
        }

        $resourceNames = [
            \Omeka\Api\Representation\ItemRepresentation::class => 'items',
            \Omeka\Api\Representation\ItemSetRepresentation::class => 'item_sets',
            \Omeka\Api\Representation\MediaRepresentation::class => 'media',
            \Omeka\Api\Representation\AssetRepresentation::class => 'assets',
            \Omeka\Api\Representation\UserRepresentation::class => 'users',
        ];
        if (!isset($resourceNames[get_class($resource)])) {
            return false;
        }

        $resourceName = $resourceNames[get_class($resource)];

        if (!array_key_exists($queryFilter, $idsByQueries[$resourceName])) {
            $services = $resource->getServiceLocator();
            /** @var \Omeka\Api\Manager $api */
            $api = $services->get('Omeka\ApiManager');
            $request = [];
            parse_str($queryFilter, $request);
            if (!$request) {
                $idsByQueries[$resourceName][$queryFilter] = null;
                return false;
            }
            $idsByQueries[$resourceName][$queryFilter] = $api->search($resourceName, $request, ['returnScalar' => 'id'])->getContent();
        }

        return !isset($idsByQueries[$resourceName][$queryFilter][$resource->id()]);
    }

    protected function extractOwnerValues(
        AbstractEntityRepresentation $resource,
        ?SolrMapRepresentation $solrMap
    ): array {
        $user = $resource->owner();
        return $user
            ? $this->extractValue($user, $solrMap->subMap())
            : [];
    }

    protected function extractSitesValues(
        AbstractResourceEntityRepresentation $resource,
        ?SolrMapRepresentation $solrMap
    ): array {
        if ($resource instanceof MediaRepresentation) {
            $resource = $resource->item();
        }
        $extractedValues = [];
        foreach ($resource->sites() as $site) {
            $values = $this->extractValue($site, $solrMap->subMap());
            $extractedValues = array_merge($extractedValues, $values);
        }
        return $extractedValues;
    }

    protected function extractResourceClassValues(
        AbstractResourceEntityRepresentation $resource,
        ?SolrMapRepresentation $solrMap
    ): array {
        $resourceClass = $resource->resourceClass();
        return $resourceClass
            ? $this->extractValue($resourceClass, $solrMap->subMap())
            : [];
    }

    protected function extractResourceTemplateValues(
        AbstractResourceEntityRepresentation $resource,
        ?SolrMapRepresentation $solrMap
    ): array {
        $resourceTemplate = $resource->resourceTemplate();
        return $resourceTemplate
            ? $this->extractValue($resourceTemplate, $solrMap->subMap())
            : [];
    }

    protected function extractAssetValues(
        AbstractRepresentation $resource,
        ?SolrMapRepresentation $solrMap
    ): array {
        $asset = $resource->thumbnail();
        return $asset
            ? $this->extractValue($asset, $solrMap->subMap())
            : [];
    }

    protected function extractItemMediasValue(
        ItemRepresentation $item,
        ?SolrMapRepresentation $solrMap
    ): array {
        $extractedValues = [];
        foreach ($item->media() as $media) {
            $values = $this->extractValue($media, $solrMap->subMap());
            $extractedValues = array_merge($extractedValues, $values);
        }
        return $extractedValues;
    }

    protected function extractItemItemSetsValue(
        ItemRepresentation $item,
        ?SolrMapRepresentation $solrMap
        ): array {
        $extractedValues = [];
        foreach ($item->itemSets() as $itemSet) {
            $values = $this->extractValue($itemSet, $solrMap->subMap());
            $extractedValues = array_merge($extractedValues, $values);
        }
        return $extractedValues;
    }

    protected function extractItemItemSetsTreeValue(
        ItemRepresentation $item,
        ?SolrMapRepresentation $solrMap
    ): array {
        static $itemSetsTreeAncestorsOrSelf;

        if ($itemSetsTreeAncestorsOrSelf === null) {
            $itemSetsTreeAncestorsOrSelf = [];
            $services = $item->getServiceLocator();
            if ($services->has('ItemSetsTree')) {
                $structure = $this->itemSetsTreeQuick($services);
                $itemSetsTreeAncestorsOrSelf = array_column($structure, 'ancestors', 'id');
                foreach ($itemSetsTreeAncestorsOrSelf as $id => &$ancestors) {
                    $ancestors = [$id => $id] + $ancestors;
                }
                unset($ancestors);
            }
        }

        if (!count($itemSetsTreeAncestorsOrSelf)) {
            return [];
        }

        $result = [];
        foreach (array_keys($item->itemSets()) as $itemSetId) {
            $result = array_merge($result, $itemSetsTreeAncestorsOrSelf[$itemSetId] ?? []);
        }
        return array_values(array_unique($result));
    }

    /**
     * Extract the values or all properties of the given resource.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param SolrMapRepresentation|null $solrMap
     * @return mixed[]|\Omeka\Api\Representation\ValueRepresentation[]
     * Null values and empty strings should be skipped.
     */
    protected function extractPropertyValues(
        AbstractResourceEntityRepresentation $resource,
        SolrMapRepresentation $solrMap
    ): array {
        /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
        $values = [];
        foreach (array_keys($resource->values()) as $term) {
            $values = array_merge($values, $resource->value($term, ['all' => true, 'type' => $solrMap->pool('data_types')]));
        }
        return $this->extractPropertyValuesEach($resource, $solrMap, $values);
    }

    /**
     * Extract the property values of the given resource.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param SolrMapRepresentation|null $solrMap
     * @return mixed[]|\Omeka\Api\Representation\ValueRepresentation[]
     * Null values and empty strings should be skipped.
     */
    protected function extractPropertyValue(
        AbstractResourceEntityRepresentation $resource,
        SolrMapRepresentation $solrMap
    ): array {
        /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
        $values = $resource->value($solrMap->firstSource(), [
            'all' => true,
            'type' => $solrMap->pool('data_types'),
            'lang' => $solrMap->pool('filter_languages'),
        ]);
        return $this->extractPropertyValuesEach($resource, $solrMap, $values);
    }

    /**
     * Normalize the extracted values of the given resource.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param SolrMapRepresentation|null $solrMap
     * @return mixed[]|\Omeka\Api\Representation\ValueRepresentation[]
     * Null values and empty strings should be skipped.
     */
    protected function extractPropertyValuesEach(
        AbstractResourceEntityRepresentation $resource,
        SolrMapRepresentation $solrMap,
        array $values
    ): array {
        if (!count($values)) {
            return [];
        }

        $extractedValues = [];

        // Filter values and uris are full regex automatically checked.
        $filterValuesPattern = $solrMap->pool('filter_values') ?: null;
        $filterUrisPattern = $solrMap->pool('filter_uris') ?: null;

        $filterVisibility = $solrMap->pool('filter_visibility') ?: null;
        $publicOnly = $filterVisibility === 'public';
        $privateOnly = $filterVisibility === 'private';

        // It is not possible to exclude a type via value methods.
        $excludedDataTypes = $solrMap->pool('data_types_exclude');
        $hasExcludedDataTypes = !empty($excludedDataTypes);

        // Only value resources are managed here: other types are managed with
        // the formatter.
        foreach ($values as $value) {
            if ($filterVisibility
                && (($privateOnly && $value->isPublic()) || ($publicOnly && !$value->isPublic()))
            ) {
                continue;
            }
            if ($hasExcludedDataTypes && in_array($value->type(), $excludedDataTypes)) {
                continue;
            }
            if ($filterValuesPattern) {
                $val = (string) $value->value();
                if (strlen($val) && !preg_match($filterValuesPattern, $val)) {
                    continue;
                }
            }
            if ($filterUrisPattern) {
                $val = (string) $value->uri();
                if (strlen($val) && !preg_match($filterUrisPattern, $val)) {
                    continue;
                }
            }
            // A value resource may be set for multiple types, included a custom
            // vocab with a resource.
            $vr = $value->valueResource();
            if ($vr) {
                if (!$this->excludeResourceViaQueryFilter($vr, $solrMap, 'filter_value_resources')) {
                    $solrSubMap = $solrMap->subMap();
                    $firstSource = $solrSubMap->firstSource();
                    if (!$firstSource || $firstSource === 'value') {
                        $extractedValues[] = $value;
                    } else {
                        $resourceExtractedValues = $this->extractValue($vr, $solrSubMap);
                        $extractedValues = array_merge($extractedValues, $resourceExtractedValues);
                    }
                }
            } else {
                $extractedValues[] = $value;
            }
        }

        return $extractedValues;
    }

    protected function extractMediaContent(MediaRepresentation $media): array
    {
        if ($media->ingester() === 'html') {
            $output = $media->mediaData()['html'];
            return $output ? [$output] : [];
        }
        $mediaType = $media->mediaType();
        if ($mediaType === 'application/alto+xml') {
            $output = $this->extractContentAlto($media);
            return strlen($output) ? [$output] : [];
        }
        if (strtok((string) $media->mediaType(), '/') === 'text') {
            $filePath = $this->baseFilepath . '/original/' . $media->filename();
            return [file_get_contents($filePath)];
        }
        return [];
    }

    protected function accessLevel(
        AbstractResourceEntityRepresentation $resource,
        ?SolrMapRepresentation $solrMap
    ): array {
        /** @var \Access\Mvc\Controller\Plugin\AccessLevel $accessLevel */
        static $accessLevel;

        if ($accessLevel === null) {
            $plugins = $resource->getServiceLocator()->get('ControllerPluginManager');
            $accessLevel = $plugins->has('accessLevel') ? $plugins->get('accessLevel') : false;
        }

        return $accessLevel
            ? [$accessLevel($resource)]
            : [];
    }

    /**
     * Get flat tree of item sets quickly.
     *
     * Use a quick connection request instead of a long procedure.
     *
     * @see \AdvancedSearch\View\Helper\AbstractFacet::itemsSetsTreeQuick()
     * @see \BlockPlus\View\Helper\Breadcrumbs::itemsSetsTreeQuick()
     * @see \SearchSolr\ValueExtractor\AbstractResourceEntityValueExtractor::itemSetsTreeQuick()
     *
     * @todo Simplify ordering: by sql (for children too) or store.
     *
     * @return array
     */
    protected function itemSetsTreeQuick($services): array
    {
        // Run an api request to check rights.
        $itemSetTitles = $this->api->search('item_sets', [], ['returnScalar' => 'title'])->getContent();
        if (!count($itemSetTitles)) {
            return [];
        }

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        $sortingMethod = $services->get('Omeka\Settings')->get('itemsetstree_sorting_method', 'title') === 'rank' ? 'rank' : 'title';
        $sortingMethodSql = $sortingMethod === 'rank'
            ? 'item_sets_tree_edge.rank'
            : 'resource.title';

        // TODO Use query builder.
        $sql = <<<SQL
            SELECT
                item_sets_tree_edge.item_set_id,
                item_sets_tree_edge.item_set_id AS "id",
                item_sets_tree_edge.parent_item_set_id AS "parent",
                item_sets_tree_edge.rank AS "rank",
                resource.title as "title"
            FROM item_sets_tree_edge
            JOIN resource ON resource.id = item_sets_tree_edge.item_set_id
            WHERE item_sets_tree_edge.item_set_id IN (:ids)
            GROUP BY resource.id
            ORDER BY $sortingMethodSql ASC;
            SQL;
        $flatTree = $connection->executeQuery($sql, ['ids' => array_keys($itemSetTitles)], ['ids' => $connection::PARAM_INT_ARRAY])->fetchAllAssociativeIndexed();

        // Use integers or string to simplify comparaisons.
        foreach ($flatTree as &$node) {
            $node['id'] = (int) $node['id'];
            $node['parent'] = (int) $node['parent'] ?: null;
            $node['rank'] = (int) $node['rank'];
            $node['title'] = (string) $node['title'];
        }
        unset($node);

        $structure = [];
        foreach ($flatTree as $id => $node) {
            $children = [];
            foreach ($flatTree as $subId => $subNode) {
                if ($subNode['parent'] === $id) {
                    $children[$subId] = $subId;
                }
            }
            $ancestors = [];
            $nodeWhile = $node;
            while ($parentId = $nodeWhile['parent']) {
                $ancestors[$parentId] = $parentId;
                $nodeWhile = $flatTree[$parentId] ?? null;
                if (!$nodeWhile) {
                    break;
                }
            }
            $structure[$id] = $node;
            $structure[$id]['children'] = $children;
            $structure[$id]['ancestors'] = $ancestors;
            $structure[$id]['level'] = count($ancestors);
        }

        // Order by sorting method.
        if ($sortingMethod === 'rank') {
            $sortingFunction = fn ($a, $b) => $structure[$a]['rank'] - $structure[$b]['rank'];
        } else {
            $sortingFunction = fn ($a, $b) => strcmp($structure[$a]['title'], $structure[$b]['title']);
        }

        foreach ($structure as &$node) {
            usort($node['children'], $sortingFunction);
        }
        unset($node);

        // Get and order root nodes.
        $roots = [];
        foreach ($structure as $id => $node) {
            if (!$node['level']) {
                $roots[$id] = $node;
            }
        }

        // Root is already ordered via sql.

        // TODO The children are useless here.

        // Reorder whole structure.
        // TODO Use a while loop.
        $result = [];
        foreach ($roots as $id => $root) {
            $result[$id] = $root;
            foreach ($root['children'] ?? [] as $child1) {
                $child1 = $structure[$child1];
                $result[$child1['id']] = $child1;
                foreach ($child1['children'] ?? [] as $child2) {
                    $child2 = $structure[$child2];
                    $result[$child2['id']] = $child2;
                    foreach ($child2['children'] ?? [] as $child3) {
                        $child3 = $structure[$child3];
                        $result[$child3['id']] = $child3;
                        foreach ($child3['children'] ?? [] as $child4) {
                            $child4 = $structure[$child4];
                            $result[$child4['id']] = $child4;
                            foreach ($child4['children'] ?? [] as $child5) {
                                $child5 = $structure[$child5];
                                $result[$child5['id']] = $child5;
                                foreach ($child5['children'] ?? [] as $child6) {
                                    $child6 = $structure[$child6];
                                    $result[$child6['id']] = $child6;
                                    foreach ($child6['children'] ?? [] as $child7) {
                                        $child7 = $structure[$child7];
                                        $result[$child7['id']] = $child7;
                                        foreach ($child7['children'] ?? [] as $child8) {
                                            $child8 = $structure[$child8];
                                            $result[$child8['id']] = $child8;
                                            foreach ($child8['children'] ?? [] as $child9) {
                                                $child9 = $structure[$child9];
                                                $result[$child9['id']] = $child9;
                                                foreach ($child9['children'] ?? [] as $child10) {
                                                    $child10 = $structure[$child10];
                                                    $result[$child10['id']] = $child10;
                                                    foreach ($child10['children'] ?? [] as $child11) {
                                                        $child11 = $structure[$child11];
                                                        $result[$child11['id']] = $child11;
                                                        foreach ($child11['children'] ?? [] as $child12) {
                                                            $child12 = $structure[$child12];
                                                            $result[$child12['id']] = $child12;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        $structure = $result;

        // Append missing item sets.
        foreach (array_diff_key($itemSetTitles, $flatTree) as $id => $title) {
            if (isset($structure[$id])) {
                continue;
            }
            $structure[$id] = [
                'id' => $id,
                'parent' => null,
                'rank' => 0,
                'title' => $title,
                'children' => [],
                'ancestors' => [],
                'level' => 0,
            ];
        }

        return $structure;
    }

    /**
     * Copy:
     * @see \AdvancedSearch\Stdlib\FulltextSearchDelegator::extractText()
     * @see \SearchSolr\ValueExtractor\AbstractResourceEntityValueExtractor::extractContentAlto()
     * @see \IiifServer\View\Helper\IiifAnnotationPageLine2::__invoke()
     */
    protected function extractContentAlto(MediaRepresentation $media): string
    {
        if ($media->mediaType() !== 'application/alto+xml') {
            return '';
        }

        // TODO Manage external storage.
        // Extract text from alto.
        $filePath = $this->baseFilepath . '/original/' . $media->filename();
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return '';
        }

        try {
            $xmlContent = file_get_contents($filePath);
            $xml = @simplexml_load_string($xmlContent);
        } catch (\Exception $e) {
            // No log.
            return '';
        }

        if (!$xml) {
            return '';
        }

        $namespaces = $xml->getDocNamespaces();
        $altoNamespace = $namespaces['alto'] ?? $namespaces[''] ?? 'http://www.loc.gov/standards/alto/ns-v4#';
        $xml->registerXPathNamespace('alto', $altoNamespace);

        $text = '';

        // TODO Use a single xpath or xsl to get the whole in one query.
        foreach ($xml->xpath('/alto:alto/alto:Layout//alto:TextLine') as $xmlTextLine) {
            /** @var \SimpleXMLElement $xmlString */
            foreach ($xmlTextLine->children() as $xmlString) {
                if ($xmlString->getName() === 'String') {
                    $attributes = $xmlString->attributes();
                    $text .= (string) @$attributes->CONTENT . ' ';
                }
            }
        }

        return trim($text);
    }
}
