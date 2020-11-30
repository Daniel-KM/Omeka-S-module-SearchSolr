<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Default ValueFormatter to get a string from any data type.
 *
 * Manage some special types like uri, where the uri and the label are returned.
 * Values with a resource are already converted via display title.
 */
class Standard implements ValueFormatterInterface
{
    public function getLabel(): string
    {
        return 'Standard'; // @translate
    }

    public function format($value): array
    {
        if (is_object($value) && $value instanceof \Omeka\Api\Representation\ValueRepresentation) {
            $result = trim((string) $value->uri() . ' ' . (string) $value);
            return strlen($result) ? [$result] : [];
        }
        $value = trim((string) $value);
        return strlen($value) ? [$value] : [];
    }
}
