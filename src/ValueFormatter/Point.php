<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

use Omeka\Api\Representation\ValueRepresentation;

class Point implements ValueFormatterInterface
{
    public function getLabel(): string
    {
        return 'Point'; // @translate
    }

    public function format($value): array
    {
        if ($value instanceof ValueRepresentation) {
            switch ($value->type()) {
                // Less check, because it is already formatted.
                case 'geometry:geography':
                    $val = (string) $value;
                    return strpos($val, 'POINT(') === 0
                        ? [preg_replace('~[^\d.]~', ',', $val)]
                        : [];

                case 'place':
                    $val = json_decode($value->value(), true);
                    if (!$val || !is_array($val) || !array_key_exists('latitude', $val) || !array_key_exists('longitude', $val)) {
                        return [];
                    }
                    return [$val['latitude'] . ',' . $val['longitude']];

                case 'geometry:geometry':
                    // Geometry should be checked as any other data, because
                    // only latitude and longitude are managed by Solr point.
                default:
                    $value = (string) $value;
                    break;
            }
        }

        $val = array_filter(explode(' ', preg_replace('~[^\d.]~', ' ', $value)));
        return count($val) === 2
            && is_numeric($val[0])
            && is_numeric($val[1])
            && $val[0] >= -90
            && $val[0] <= 90
            && $val[1] >= -180
            && $val[1] <= 180
            ? [$val[0] . ',' . $val[1]]
            : [];
    }
}
