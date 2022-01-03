<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2018-2021
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
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\AbstractResourceRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
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

    public function __construct(ApiManager $api, LoggerInterface $logger, $baseFilepath)
    {
        $this->api = $api;
        $this->logger = $logger;
        $this->baseFilepath = $baseFilepath;
    }

    abstract public function getLabel(): string;

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
                    'item_set' => 'Item: Item set', // @translate
                    'media' => 'Item: Media', // @translate
                    'content' => 'Media: Content (html or extracted text)', // @translate
                    // May be used internally in admin board.
                    'is_open' => 'Item set: Is open', // @translate
                    // Specific values.
                    'o:term' => 'Property or class term', // @translate
                    'o:label' => 'Label ', // @translate
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
        $field = $solrMap->firstSource();

        if ($field === '') {
            if (method_exists($resource, 'displayTitle')) {
                $title = $resource->displayTitle('');
            } elseif (method_exists($resource, 'title')) {
                $title = $resource->title();
            } elseif (method_exists($resource, 'label')) {
                $title = $resource->label();
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
            return $resource instanceof AbstractResourceEntityRepresentation
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
                ? $this->extractResourceClassValues($resource, $solrMap)
                : [];
        }

        if ($field === 'resource_template') {
            return $resource instanceof AbstractResourceEntityRepresentation
                ? $this->extractResourceTemplateValues($resource, $solrMap)
                : [];
        }

        if ($field === 'media') {
            return $resource instanceof ItemRepresentation
                ? $this->extractItemMediasValue($resource, $solrMap)
                : [];
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

        if ($field === 'is_open') {
            return $resource instanceof ItemSetRepresentation
                ? [$resource->isOpen()]
                : [];
        }

        if ($field === 'o:term') {
            return method_exists($resource, 'term')
                ? [$resource->term()]
                : [];
        }

        if ($field === 'o:title') {
            return method_exists($resource, 'title')
                ? [$resource->title()]
                : [];
        }

        if ($field === 'o:label') {
            return method_exists($resource, 'label')
                ? [$resource->label()]
                : [];
        }

        if (strpos($field, ':')) {
            return $this->extractPropertyValue($resource, $solrMap);
        }

        return [];
    }

    protected function extractOwnerValues(
        AbstractResourceEntityRepresentation $resource,
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
        $extractedValues = [];

        /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
        $values = $resource->value($solrMap->firstSource(), ['all' => true, 'type' => $solrMap->pool('data_types')]);

        // It's not possible to exclude a type via value.
        $excludedDataTypes = $solrMap->pool('data_types_exclude');
        $hasExcludedDataTypes = !empty($excludedDataTypes);

        // Only value resources are managed here: other types are managed with
        // the formatter.
        foreach ($values as $value) {
            if ($hasExcludedDataTypes && in_array($value->type(), $excludedDataTypes)) {
                continue;
            }
            // A value resource may be set for multiple types, included a custom
            // vocab with a resource.
            $vr = $value->valueResource();
            if ($vr) {
                $resourceExtractedValues = $this->extractValue($vr, $solrMap->subMap());
                $extractedValues = array_merge($extractedValues, $resourceExtractedValues);
            } else {
                $extractedValues[] = $value;
            }
        }

        return $extractedValues;
    }

    protected function extractMediaContent(MediaRepresentation $media): array
    {
        if ($media->ingester() === 'html') {
            return [$media->mediaData()['html']];
        }
        if (strtok((string) $media->mediaType(), '/') === 'text') {
            $filePath = $this->baseFilepath . '/original/' . $media->filename();
            return [file_get_contents($filePath)];
        }
        return [];
    }
}
