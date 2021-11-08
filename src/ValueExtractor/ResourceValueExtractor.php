<?php declare(strict_types=1);

namespace SearchSolr\ValueExtractor;

class ResourceValueExtractor extends AbstractResourceEntityValueExtractor
{
    public function getLabel(): string
    {
        return 'Resource'; // @translate
    }
}
