<?php

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2018
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

namespace Solr\ValueExtractor;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Zend\Log\LoggerInterface;

class ItemValueExtractor implements ValueExtractorInterface
{
    use ValueExtractorTrait;

    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var string
     */
    protected $baseFilepath;

    /**
     * @var LoggerInterface Logger
     */
    protected $logger;

    /**
     * @param ApiManager $api
     */
    public function setApiManager(ApiManager $api)
    {
        $this->api = $api;
    }

    /**
     * @param ApiManager $api
     */
    public function setBaseFilepath($baseFilepath)
    {
        $this->baseFilepath = $baseFilepath;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getLabel()
    {
        return 'Item'; // @translate
    }

    public function getAvailableFields()
    {
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
                'label' => 'Item set', // @translate
            ],
            'media' => [
                'label' => 'Media', // @translate
            ],
            // TODO Move media content to subproperty of media only.
            'content' => [
                'label' => 'Media content (html or extracted text)', // @translate
            ],
        ];

        $properties = $this->api->search('properties')->getContent();
        foreach ($properties as $property) {
            $term = $property->term();
            $fields[$term]['label'] = $term;
        }

        return $fields;
    }

    public function extractValue(AbstractResourceRepresentation $item, $field)
    {
        if ($field === 'created') {
            return $item->created();
        }

        if ($field === 'modified') {
            return $item->modified();
        }

        if ($field === 'is_public') {
            return $item->isPublic();
        }

        if ($field === 'resource_class') {
            $resourceClass = $item->resourceClass();
            return $resourceClass ? $resourceClass->term() : null;
        }

        if ($field === 'resource_template') {
            $resourceTemplate = $item->resourceTemplate();
            return $resourceTemplate ? $resourceTemplate->label() : null;
        }

        // TODO Clarify the use of this method for subfields (may be used by media? Only?).

        if ($item instanceof ItemRepresentation) {
            $matches = [];
            if (preg_match('~^media/(.*)|^media$~', $field, $matches)
                || preg_match('~^item_set/(.*)|^item_set$~', $field, $matches)
            ) {
                $fieldName = $matches[0];
                $subFieldName = $matches[1];
                switch ($fieldName) {
                    case 'media':
                        return $this->extractMediaValue($item, $subFieldName);
                    case 'item_set':
                        return $this->extractItemSetValue($item, $subFieldName);
                }
            }
        }

        return $this->extractPropertyValue($item, $field);
    }

    protected function extractMediaValue(ItemRepresentation $item, $field)
    {
        $extractedValue = [];

        foreach ($item->media() as $media) {
            if ($field === 'content') {
                if ($media->ingester() === 'html') {
                    $mediaExtractedValue = [$media->mediaData()['html']];
                } elseif (strtok($media->mediaType(), '/') === 'text') {
                    $filePath = $this->baseFilepath . '/original/' . $media->filename();
                    $mediaExtractedValue = [file_get_contents($filePath)];
                } else {
                    continue;
                }
            } else {
                $mediaExtractedValue = $this->extractPropertyValue($media, $field);
            }
            $extractedValue = array_merge($extractedValue, $mediaExtractedValue);
        }

        return $extractedValue;
    }

    protected function extractItemSetValue(ItemRepresentation $item, $field)
    {
        $extractedValue = [];

        foreach ($item->itemSets() as $itemSet) {
            $itemSetExtractedValue = $this->extractPropertyValue($itemSet, $field);
            $extractedValue = array_merge($extractedValue, $itemSetExtractedValue);
        }

        return $extractedValue;
    }
}
