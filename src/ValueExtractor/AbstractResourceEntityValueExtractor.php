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
            'o:id' => [
                'label' => 'Internal identifier', // @translate
            ],
            'created' => [
                'label' => 'Created', // @translate
            ],
            'modified' => [
                'label' => 'Modified', // @translate
            ],
            'is_public' => [
                'label' => 'Is public', // @translate
            ],
            'resource_class' => [
                'label' => 'Resource class', // @translate
            ],
            'resource_template' => [
                'label' => 'Resource template', // @translate
            ],
            'item_set' => [
                'label' => 'Item: Item set', // @translate
            ],
            'media' => [
                'label' => 'Item: Media', // @translate
            ],
            'content' => [
                'label' => 'Media: Content (html or extracted text)', // @translate
            ],
            // May be used internally in admin board.
            'is_open' => [
                'label' => 'Item set: Is open', // @translate
            ],
        ];

        $properties = $this->api->search('properties')->getContent();
        foreach ($properties as $property) {
            $term = $property->term();
            $fields[$term]['label'] = $term;
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
            $title = $resource->displayTitle('');
            return mb_strlen($title) ? [$title] : [];
        }

        if ($field === 'o:id') {
            return [$resource->id()];
        }

        if ($field === 'created') {
            return [$resource->created()->format('Y-m-d H:i:s')];
        }

        if ($field === 'modified') {
            $modified = $resource->modified();
            return $modified
                ? [$modified->format('Y-m-d H:i:s')]
                : [];
        }

        if ($field === 'is_public') {
            return [$resource->isPublic()];
        }

        if ($field === 'resource_class') {
            $resourceClass = $resource->resourceClass();
            return $resourceClass
                ? [$resourceClass->term()]
                : [];
        }

        if ($field === 'resource_template') {
            $resourceTemplate = $resource->resourceTemplate();
            return $resourceTemplate
                ? [$resourceTemplate->label()]
                : [];
        }

        if ($field === 'media') {
            return $resource instanceof ItemRepresentation
                ? $this->extractItemMediasValue($resource, $solrMap)
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

        if ($field === 'content') {
            return $resource instanceof MediaRepresentation
                ? $this->extractMediaContent($resource)
                : [];
        }

        if (strpos($field, ':')) {
            return $this->extractPropertyValue($resource, $solrMap);
        }

        return [];
    }

    protected function extractItemMediasValue(
        ItemRepresentation $item,
        ?SolrMapRepresentation $solrMap
    ): array {
        $extractedValues = [];
        foreach ($item->media() as $media) {
            $mediaExtractedValues = $this->extractValue($media, $solrMap->subMap());
            $extractedValues = array_merge($extractedValues, $mediaExtractedValues);
        }
        return $extractedValues;
    }

    protected function extractItemItemSetsValue(
        ItemRepresentation $item,
        ?SolrMapRepresentation $solrMap
    ): array {
        $extractedValues = [];
        foreach ($item->itemSets() as $itemSet) {
            $itemSetExtractedValues = $this->extractValue($itemSet, $solrMap->subMap());
            $extractedValues = array_merge($extractedValues, $itemSetExtractedValues);
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
