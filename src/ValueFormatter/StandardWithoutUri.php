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
        if (is_object($value)) {
            if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                $value = trim((string) $value->value());
            } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                $value = trim((string) $value->altText());
            } else {
                return [];
            }
        } else {
            $value = trim((string) $value);
        }
        return strlen($value) ? [$value] : [];
    }
}
