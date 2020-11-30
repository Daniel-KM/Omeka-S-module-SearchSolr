<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Default ValueFormatter to get a string from any data type.
 *
 * Values with a resource are already converted via display title.
 */
class StandardWithoutUri implements ValueFormatterInterface
{
    public function getLabel(): string
    {
        return 'Standard without uri'; // @translate
    }

    public function format($value): array
    {
        $value = is_object($value) && $value instanceof \Omeka\Api\Representation\ValueRepresentation
            ? trim((string) $value->value())
            : trim((string) $value);
        return strlen($value) ? [$value] : $value;
    }
}
