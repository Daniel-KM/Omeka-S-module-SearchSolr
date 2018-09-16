<?php
/*
 * Copyright Daniel Berthereau, 2018
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

namespace Solr\ValueExtractor;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

trait ValueExtractorTrait
{
    /**
     * Extract the values of the given property of the given item.
     * If a value is a resource, then this method is called recursively with
     * the source part after the slash as $source.
     *
     * @param AbstractResourceEntityRepresentation $representation
     * @param string $source Property (RDF term).
     * @return string[] Human-readable values.
     */
    protected function extractPropertyValue(
        AbstractResourceEntityRepresentation $representation,
        $source
    ) {
        // $subField may be NULL.
        @list($field, $subField) = explode('/', $source, 2);

        switch ($field) {
            // Item_set or media may have been set without field.
            case '':
                return [$representation->displayTitle()];

            case 'o:id':
                return [$representation->id()];

            case 'item_set':
                if (!$representation instanceof ItemRepresentation) {
                    $this->logger->warn('Tried to get item_set of non item resource.'); // @translate
                    return [];
                }
                return $this->extractItemSetValue($representation, $subField);

            case 'media':
                if (!$representation instanceof ItemRepresentation) {
                    $this->logger->warn('Tried to get media of non item resource.'); // @translate
                    return [];
                }
                return $this->extractMediaValue($representation, $subField);
        }

        $extractedValues = [];
        /* @var ValueRepresentation[] $values */
        $values = $representation->value($field, ['all' => true, 'default' => []]);
        foreach ($values as $value) {
            // Manage standard types and special types from modules RdfDatatype,
            // CustomVocab, ValueSuggest, etc.
            $mainType = explode(':', $value->type())[0];
            if ($mainType === 'resource') {
                $this->extractPropertyResourceValue($extractedValues, $value, $subField);
            } else {
                $extractedValues[] = (string) $value;
            }
        }

        return $extractedValues;
    }

    /**
     * Extracts value(s) from resource-type value and adds them to already
     * extracted values (passed by reference).
     *
     * @param array $extractedValues Already extracted values.
     * @param ValueRepresentation $value Resource-type value from which to
     * extract searched values.
     * @param string|null $property RDF term representing the property to
     * extract. If null, get the displayTitle() value.
     */
    protected function extractPropertyResourceValue(
        array &$extractedValues,
        ValueRepresentation $value,
        $property
    ) {
        if (isset($property)) {
            $extractedValues = array_merge(
                $extractedValues,
                $this->extractValue($value->valueResource(), $property)
            );
        } else {
            $resourceTitle = $value->valueResource()->displayTitle('');
            if (!empty($resourceTitle)) {
                $extractedValues[] = $resourceTitle;
            }
        }
    }
}
