<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Formatter that includes only uris, mainly for value with data type uri.
 */
class Uri extends AbstractValueFormatter
{
    public function getLabel(): string
    {
        return 'Uri'; // @translate
    }

    public function format($value): array
    {
        if (is_object($value)) {
            if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                $value = trim((string) $value->uri());
            } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                $value = trim((string) $value->assetUrl());
            } else {
                return [];
            }
        } else {
            $value = trim((string) $value);
        }
        return filter_var($value, FILTER_VALIDATE_URL)
            ? [$value]
            : [];
    }
}
