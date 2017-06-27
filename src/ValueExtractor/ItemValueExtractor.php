<?php

/*
 * Copyright BibLibre, 2016-2017
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
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\AbstractResourceRepresentation;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class ItemValueExtractor implements ValueExtractorInterface
{
    protected $api;

    public function setApiManager(ApiManager $api)
    {
        $this->api = $api;
    }

    public function getLabel()
    {
        return 'Item';
    }

    public function getAvailableFields()
    {
        $fields = [
            'created' => [
                'label' => 'Created',
            ],
            'modified' => [
                'label' => 'Modified',
            ],
            'is_public' => [
                'label' => 'Is public',
            ],
            'resource_class' => [
                'label' => 'Resource class',
            ],
            'resource_template' => [
                'label' => 'Resource template',
            ],
        ];

        $properties = $this->api->search('properties')->getContent();
        foreach ($properties as $property) {
            $term = $property->term();
            $fields[$term]['label'] = $term;
        }

        $fields['item_set'] = [
            'label' => 'Item set',
            'children' => [
                'id' => [
                    'label' => 'Internal identifier',
                ],
            ],
        ];
        $fields['media']['label'] = 'Media';

        foreach ($properties as $property) {
            $term = $property->term();
            $fields['item_set']['children'][$term]['label'] = $term;
            $fields['media']['children'][$term]['label'] = $term;
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

        if (preg_match('/^media\/(.*)/', $field, $matches)) {
            $mediaField = $matches[1];
            return $this->extractMediaValue($item, $mediaField);
        }

        if (preg_match('/^item_set\/(.*)/', $field, $matches)) {
            $itemSetField = $matches[1];
            return $this->extractItemSetValue($item, $itemSetField);
        }

        return $this->extractPropertyValue($item, $field);
    }

    protected function extractMediaValue(ItemRepresentation $item, $field)
    {
        $extractedValue = [];

        foreach ($item->media() as $media) {
            $mediaExtractedValue = $this->extractPropertyValue($media, $field);
            $extractedValue = array_merge($extractedValue, $mediaExtractedValue);
        }

        return $extractedValue;
    }

    protected function extractItemSetValue(ItemRepresentation $item, $field)
    {
        $extractedValue = [];

        foreach ($item->itemSets() as $itemSet) {
            if ($field == 'id') {
                $extractedValue[] = $itemSet->id();
            } else {
                $itemSetExtractedValue = $this->extractPropertyValue($itemSet, $field);
                $extractedValue = array_merge($extractedValue, $itemSetExtractedValue);
            }
        }

        return $extractedValue;
    }

    protected function extractPropertyValue(AbstractResourceEntityRepresentation $representation, $field)
    {
        $extractedValue = [];
        $values = $representation->value($field, ['all' => true, 'default' => []]);
        foreach ($values as $i => $value) {
            $type = $value->type();
            if ($type === 'literal' || $type == 'uri') {
                $extractedValue[] = (string) $value;
            }
        }

        return $extractedValue;
    }
}
