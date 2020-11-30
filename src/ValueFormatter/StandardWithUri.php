<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Default ValueFormatter to get a string from any data type.
 *
 * Manage some special types like uri, where the uri and the label are returned.
 * Values with a resource are already converted via display title.
 */
class StandardWithUri implements ValueFormatterInterface
{
    public function getLabel(): string
    {
        return 'Standard with uri'; // @translate
    }

    public function format($value): array
    {
        $value = is_object($value) && $value instanceof \Omeka\Api\Representation\ValueRepresentation
            // Order is the one used in full text.
            ? trim($value->uri() . ' ' . $value->value())
            : trim((string) $value);
        return strlen($value) ? [$value] : [];
    }
}
