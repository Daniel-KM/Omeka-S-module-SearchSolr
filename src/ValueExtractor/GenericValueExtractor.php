<?php declare(strict_types=1);

namespace SearchSolr\ValueExtractor;

/**
 * @todo For now, generic is a subclass of resource.
 */
class GenericValueExtractor extends AbstractResourceEntityValueExtractor
{
    public function getLabel(): string
    {
        return 'Generic'; // @translate
    }
}
