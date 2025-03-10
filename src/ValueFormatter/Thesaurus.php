<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

use Omeka\Api\Representation\ItemRepresentation;
use Thesaurus\Stdlib\Thesaurus as TThesaurus;

/**
 * Default ValueFormatter to get terms or term ids from thesaurus.
 */
class Thesaurus extends AbstractValueFormatter
{
    protected $label = 'Thesaurus'; // @translate

    protected $comment = 'Get the resource ids or labels from thesaurus (module Thesaurus)'; // @translate

    public function format($value): array
    {
        $thesaurusResources = [
            'scheme' => 'scheme',
            'tops' => 'tops',
            'top' => 'top',
            'self' => 'selfItem',
            'broader' => 'broader',
            'narrowers' => 'narrowers',
            'narrowers_or_self' => 'narrowersOrSelf',
            'relateds' => 'relateds',
            'relateds_or_self' => 'relatedsOrSelf',
            'siblings' => 'siblings',
            'siblings_or_self' => 'siblingsOrSelf',
            'ascendants' => 'ascendants',
            'ascendants_or_self' => 'ascendantsOrSelf' ,
            'descendants' => 'descendants',
            'descendants_or_self' => 'descendantsOrSelf',
        ];

        $resourcesToExtract = $this->settings['thesaurus_resources'] ?? null;
        if (!isset($thesaurusResources[$resourcesToExtract])) {
            return [];
        }

        $thesaurusMetadata = $this->settings['thesaurus_metadata'] ?? [];
        if (!$thesaurusMetadata) {
            return [];
        }

        if (!is_object($value) || !$value instanceof \Omeka\Api\Representation\ValueRepresentation) {
            return [];
        }

        $vr = $value->valueResource();
        if (!$vr || !$vr instanceof ItemRepresentation) {
            return [];
        }

        $thesaurus = $this->getThesaurus($vr);
        if (!$thesaurus) {
            return [];
        }

        $method = $thesaurusResources[$resourcesToExtract];
        $resources = $thesaurus->$method();

        $singles = [
            'scheme',
            'top',
            'self',
        ];
        if (in_array($resourcesToExtract, $singles)) {
            if (!$resources) {
                return [];
            }
            $resources = [$resources];
        } elseif (!count($resources)) {
            return [];
        }

        $result = [];
        foreach ($resources as $itemData) foreach ($thesaurusMetadata as $metadata) {
            if ($metadata === 'o:id') {
                $id = $itemData['id'] ?? $itemData['self']['id'] ?? null;
                if ($id) {
                    $result[] = $id;
                }
            } else {
                $resource = $thesaurus->itemFromData($itemData);
                if ($resource) {
                    foreach ($resource->value($metadata, ['all' => true]) as $value) {
                        $result[] = $value->value();
                    }
                }
            }
        }

        return $result;
    }

    protected function getThesaurus(ItemRepresentation $item): ?TThesaurus
    {
        /** @var \Thesaurus\Stdlib\Thesaurus $thesaurus */
        $thesaurus = $this->services->get('Thesaurus\Thesaurus');
        $thesaurus->setItem($item);
        return $thesaurus->isSkos()
            ? $thesaurus
            : null;
    }
}
