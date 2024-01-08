<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

use Omeka\Api\Representation\ValueRepresentation;

/**
 * Place Formatter to index in the same field the toponym and the country.
 */
class Place extends AbstractValueFormatter
{
    public function getLabel(): string
    {
        return 'Place'; // @translate
    }

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
        switch($mode){
            case 'toponym':
                return [$val['toponym']];
            case 'country':
                return [$val['country']];
            case 'toponym_and_country':
            default:
                return [
                    $val['toponym'],
                    $val['country'],
                ];
        }
    }
}
