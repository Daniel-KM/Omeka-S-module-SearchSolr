<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * ValueFormatter to get text with options.
 */
class Text extends AbstractValueFormatter
{
    protected $label = 'Text'; // @translate

    protected $comment = 'Get the value and normalize'; // @translate

    public function format($value): array
    {
        if (is_object($value)) {
            if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                $value = trim((string) $value->value());
            } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                $value = trim((string) $value->altText());
            } elseif (method_exists('__toString', $value)) {
                $value = trim((string) $value);
            } else {
                return [];
            }
        } else {
            $value = trim((string) $value);
        }
        return strlen($value)
            ? [$value]
            : [];
    }
}
