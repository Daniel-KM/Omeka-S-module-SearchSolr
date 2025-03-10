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
        if (!$val || !is_array($val)) {
            return [];
        }

        $mode = $this->settings['place_mode'] ?? null;
        switch ($mode) {
            case 'country':
                $result = [$val['country'] ?? null];
                break;

            case 'toponym':
                $result = [$val['toponym'] ?? null];
                break;

            case 'country_and_toponym':
                $result = [
                    $val['country'] ?? null,
                    $val['toponym'] ?? null,
                ];
                break;

            case 'toponym_and_country':
                $result = [
                    $val['toponym'] ?? null,
                    $val['country'] ?? null,
                ];
                break;

            case 'coordinates':
                if (array_key_exists('latitude', $val)
                    && $val['latitude'] !== ''
                    && array_key_exists('longitude', $val)
                    && $val['longitude'] !== ''
                ) {
                    $result = [sprintf('[%1$s/%2$s]', $val['latitude'], $val['longitude'])];
                }
                break;

            case 'latitude':
                $result = [$val['latitude'] ?? null];
                break;

            case 'longitude':
                $result = [$val['longitude'] ?? null];
                break;

            case 'country_toponym':
                $result = trim((empty($val['country']) ? '' : $val['country'])
                    . (empty($val['toponym']) ? '' : ' (' . $val['toponym'] . ')'));
                break;

            case 'toponym_country':
                $result = trim((empty($val['toponym']) ? '' : $val['toponym'])
                    . (empty($val['country']) ? '' : ' (' . $val['country'] . ')'));
                break;

            case 'html':
                $result = (empty($val['toponym']) ? '' : $val['toponym'])
                    . (empty($val['country']) ? '' : ' (' . $val['country'] . ')')
                    . (isset($val['latitude']) && $val['latitude'] !== '' && isset($val['longitude']) && $val['longitude'] !== ''
                        ? sprintf(' [%1$s/%2$s]', $val['latitude'], $val['longitude'])
                        : '');
                break;

            case 'array':
            default:
                $result = [
                    $val['country'] ?? null,
                    $val['toponym'] ?? null,
                    $val['latitude'] ?? null,
                    $val['longitude'] ?? null,
                ];
                break;
        }

        return array_filter($result, fn ($v) => $v !== '' && $v !== [] && $v !== null);
    }
}
