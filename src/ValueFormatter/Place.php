<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

use Omeka\Api\Representation\ValueRepresentation;

/**
 * Place Formatter to index in the same field the toponym and the country.
 */
class Place extends AbstractValueFormatter
{
    protected $label = 'Place'; // @translate

    protected $comment = 'Store country and toponym from a value of type place (module Data Type Place)'; // @translate

    public function format($value): array
    {
        if (!$value instanceof ValueRepresentation) {
            return [];
        }

        if ($value->type() !== 'place') {
            return [];
        }

        // TODO Remove json_decode? No, this is the value.
        $val = json_decode($value->value(), true);
        if (!$val || !is_array($val) || !array_key_exists('toponym', $val) || !array_key_exists('country', $val)) {
            return [];
        }

        $mode = $this->settings['place_mode'] ?? null;
        switch ($mode) {
            case 'toponym':
                $result = [$val['toponym']];
                break;
            case 'country':
                $result = [$val['country']];
                break;
            case 'toponym_and_country':
                $result = [
                    $val['toponym'],
                    $val['country'],
                ];
                break;
            case 'country_and_toponym':
            default:
                $result = [
                    $val['country'],
                    $val['toponym'],
                ];
                break;
        }

        return $this->postFormatter($result);
    }
}
