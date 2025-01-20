<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Default ValueFormatter to get a string from any data type.
 *
 * Values with a resource are already converted via display title.
 */
class StandardWithoutUri extends AbstractValueFormatter
{
    public function getLabel(): string
    {
        return 'Standard without uri'; // @translate
    }

    public function format($value): array
    {
        if (is_object($value)) {
            if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                if ($value->type() === 'place') {
                    $val = json_decode($value->value(), true);
                    $value = (empty($val['country']) ? '' : ' (' . $val['country'] . ')')
                        . (array_key_exists('latitude', $val) && array_key_exists('longitude', $val) ? sprintf(' [%1$s/%2$s]', $val['latitude'], $val['longitude']) : '');
                } else {
                    $value = trim((string) $value->value());
                }
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
