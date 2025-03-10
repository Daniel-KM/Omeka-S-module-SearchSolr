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
        $thesaurusRelations = [
            'scheme',
            'tops',
            'top',
            'self',
            'broader',
            'narrowers',
            'relateds',
            'siblings',
            'ascendants',
            'descendants',
            'branch',
        ];

        $resourcesToExtract = $this->settings['thesaurus_resources'] ?? null;
        if (!in_array($resourcesToExtract, $thesaurusRelations)) {
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

        $thesaurusMethods = [
            'scheme' => 'scheme',
            'tops' => 'tops',
            'top' => 'top',
            'self' => 'selfItem',
            'broader' => 'broader',
            'broader_or_self' => 'broaderOrSelf',
            'narrowers' => 'narrowers',
            'narrowers_or_self' => 'narrowersOrSelf',
            'relateds' => 'relateds',
            'relateds_or_self' => 'relatedsOrSelf',
            'siblings' => 'siblings',
            'siblings_or_self' => 'siblingsOrSelf',
            // From self.
            'ascendants' => 'ascendants',
            'ascendants_or_self' => 'ascendantsOrSelf' ,
            'descendants' => 'descendants',
            'descendants_or_self' => 'descendantsOrSelf',
            'branch' => 'flatBranch',
        ];

        // Use a direct method when possible.
        $alreadyWithSelf = [
            'self',
            'branch',
        ];
        $withSelf = [
            // 'broader',
            'narrowers',
            'relateds',
            'siblings',
            'ascendants',
            'descendants',
        ];
        if (method_exists($thesaurus, 'broaderOrSelf')) {
            $withSelf[] = 'broader';
        }
        $includeSelf = !empty($this->settings['thesaurus_self'])
            && !in_array($resourcesToExtract, $alreadyWithSelf);
        if ($includeSelf && in_array($resourcesToExtract, $withSelf)) {
            $resourcesToExtract .= '_or_self';
            $includeSelf = false;
        }

        $method = $thesaurusMethods[$resourcesToExtract];
        $resources = $thesaurus->$method();

        // Scheme is always a resource, so get data for it.
        if ($resourcesToExtract === 'scheme') {
            $resources = $thesaurus->itemToData($resources);
        }

        $singles = [
            'scheme',
            'top',
            // "self" is not a single with method selfItem().
            'broader',
        ];
        if (in_array($resourcesToExtract, $singles)) {
            if ($resources) {
                $resources = [$resources];
            }
        }

        if (!count($resources) && !$includeSelf) {
            return [];
        }

        if ($includeSelf) {
            $self = $thesaurus->selfItem();
            $resources[$self['id'] ?? $self['self']['id']] = $self;
        }

        $results = [];
        foreach ($resources as $itemData) foreach ($thesaurusMetadata as $metadata) {
            if ($metadata === 'o:id') {
                $id = $itemData['id'] ?? $itemData['self']['id'] ?? null;
                if ($id) {
                    $results[] = $id;
                }
            } else {
                $resource = $thesaurus->itemFromData($itemData);
                if ($resource) {
                    foreach ($resource->value($metadata, ['all' => true]) as $value) {
                        $results[] = $value->value();
                    }
                }
            }
        }

        $this->returnPostFormatter($results);
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
