<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Formatter that includes only uris, mainly for value with data type uri.
 */
class Uri implements ValueFormatterInterface
{
    public function getLabel(): string
    {
        return 'Uri'; // @translate
    }

    public function format($value): array
    {
        $value = is_object($value) && $value instanceof \Omeka\Api\Representation\ValueRepresentation
            ? (string) $value->uri()
            : (string) $value;
        return filter_var($value, FILTER_VALIDATE_URL)
            ? [$value]
            : [];
    }
}
