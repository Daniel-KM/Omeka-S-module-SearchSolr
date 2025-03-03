<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Default ValueFormatter to get a string from any data type.
 *
 * Manage some special types like uri, where the uri and the label are returned.
 * Values with a resource are already converted via display title.
 */
class StandardWithUri extends AbstractValueFormatter
{
    protected $label = 'Standard with uri'; // @translate

    public function format($value): array
    {
        if (is_object($value)) {
            if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                if ($value->type() === 'place') {
                    $value = (string) $value;
                } else {
                    // Order is the one used in full text.
                    $value = trim($value->uri() . ' ' . $value->value());
                }
            } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                $value = trim($value->assetUrl() . ' ' . $value->altText());
            } else {
                return [];
            }
        } else {
            $value = trim((string) $value);
        }
        return strlen($value) ? [$value] : [];
    }
}
