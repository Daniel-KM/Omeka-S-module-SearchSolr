<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

use Omeka\Api\Representation\ValueRepresentation;

class Point extends AbstractValueFormatter
{
    protected $label = 'Point'; // @translate

    protected $comment = 'Create a geographic point from data types geometry, geography and place (modules Data Type Geometry and Data Type Place)'; // @translate

    public function format($value): array
    {
        if ($value instanceof ValueRepresentation) {
            // The value of a place is a json with the coordinates apart.
            if ($value->type() === 'place') {
                $val = json_decode((string) $value->value(), true);
                return is_array($val)
                    && isset($val['latitude'])
                    && isset($val['longitude'])
                    ? $this->point((string) $val['latitude'], (string) $val['longitude'])
                    : [];
            }
            // A geometry is checked like any other value: only a point can be
            // indexed as a location by Solr.
            $value = (string) $value;
        }

        $value = trim((string) $value);

        // A well-known text orders the coordinates as "longitude latitude",
        // unlike a location of Solr.
        if (preg_match('~^POINT\s*\(\s*(-?[\d.]+)[\s,]+(-?[\d.]+)\s*\)$~i', $value, $matches)) {
            return $this->point($matches[2], $matches[1]);
        }

        $val = array_values(array_filter(
            preg_split('~[^-\d.]+~', $value) ?: [],
            fn ($v) => $v !== '' && is_numeric($v)
        ));

        return count($val) === 2
            ? $this->point($val[0], $val[1])
            : [];
    }

    /**
     * Format a latitude and a longitude as a location of Solr, when valid.
     */
    protected function point(string $latitude, string $longitude): array
    {
        return is_numeric($latitude)
            && is_numeric($longitude)
            && $latitude >= -90
            && $latitude <= 90
            && $longitude >= -180
            && $longitude <= 180
            ? [$latitude . ',' . $longitude]
            : [];
    }
}
