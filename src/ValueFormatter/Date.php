<?php

namespace SearchSolr\ValueFormatter;

use DateTime;
use DateTimeZone;
use Omeka\Api\Representation\ValueRepresentation;

class Date implements ValueFormatterInterface
{
    public function getLabel()
    {
        return 'Date'; // @translate
    }

    public function format($value)
    {
        if ($value instanceof ValueRepresentation && $value->type() === 'numeric:interval') {
            $value = strtok((string) $value, '/');
        } else {
            $value =(string) $value;
            // Manage "1914/1918" and the common but unstandard case "1914-1918".
            if (strpos($value, '/') > 0) {
                $value = strtok($value, '/');
            } elseif (strpos($value, '-') > 0) {
                $value = strtok($value, '-');
            }
        }
        return $this->getDateTimeFromValue((string) $value);
    }

    /**
     * Convert into a standard DateTime. Manage some badly formatted values.
     *
     * Adapted from module NumericDataType.
     * @see \NumericDataTypes\DataType\AbstractDateTimeDataType::getDateTimeFromValue()
     *
     * @param string $value
     * @return array
     */
    protected function getDateTimeFromValue($value)
    {
        $yearMin = -292277022656;
        $yearMax = 292277026595;
        $patternIso8601 = '^(?<date>(?<year>-?\d{4,})(-(?<month>\d{2}))?(-(?<day>\d{2}))?)(?<time>(T(?<hour>\d{2}))?(:(?<minute>\d{2}))?(:(?<second>\d{2}))?)(?<offset>((?<offset_hour>[+-]\d{2})?(:(?<offset_minute>\d{2}))?)|Z?)$';
        static $dateTimes = [];

        if (array_key_exists($value, $dateTimes)) {
            return $dateTimes[$value];
        }

        $dateTimes[$value] = null;

        // Match against ISO 8601, allowing for reduced accuracy.
        $matches = [];
        if (!preg_match(sprintf('/%s/', $patternIso8601), $value, $matches)) {
            return null;
        }

        // Remove empty values.
        $matches = array_filter($matches);

        // An hour requires a day.
        if (isset($matches['hour']) && !isset($matches['day'])) {
            return null;
        }

        // An offset requires a time.
        if (isset($matches['offset']) && !isset($matches['time'])) {
            return null;
        }

        // Set the datetime components included in the passed value.
        $dateTime = [
            'value' => $value,
            'date_value' => $matches['date'],
            'time_value' => isset($matches['time']) ? $matches['time'] : null,
            'offset_value' => isset($matches['offset']) ? $matches['offset'] : null,
            'year' => (int) $matches['year'],
            'month' => isset($matches['month']) ? (int) $matches['month'] : null,
            'day' => isset($matches['day']) ? (int) $matches['day'] : null,
            'hour' => isset($matches['hour']) ? (int) $matches['hour'] : null,
            'minute' => isset($matches['minute']) ? (int) $matches['minute'] : null,
            'second' => isset($matches['second']) ? (int) $matches['second'] : null,
            'offset_hour' => isset($matches['offset_hour']) ? (int) $matches['offset_hour'] : null,
            'offset_minute' => isset($matches['offset_minute']) ? (int) $matches['offset_minute'] : null,
        ];

        // Set the normalized datetime components. Each component not included
        // in the passed value is given a default value.
        $dateTime['month_normalized'] = isset($dateTime['month'])
            ? $dateTime['month'] : 1;
        // The last day takes special handling, as it depends on year/month.
        $dateTime['day_normalized'] = isset($dateTime['day'])
            ? $dateTime['day']
            : 1;
        $dateTime['hour_normalized'] = isset($dateTime['hour'])
            ? $dateTime['hour'] : 0;
        $dateTime['minute_normalized'] = isset($dateTime['minute'])
            ? $dateTime['minute'] : 0;
        $dateTime['second_normalized'] = isset($dateTime['second'])
            ? $dateTime['second'] : 0;
        $dateTime['offset_hour_normalized'] = isset($dateTime['offset_hour'])
            ? $dateTime['offset_hour'] : 0;
        $dateTime['offset_minute_normalized'] = isset($dateTime['offset_minute'])
            ? $dateTime['offset_minute'] : 0;
        // Set the UTC offset (+00:00) if no offset is provided.
        $dateTime['offset_normalized'] = isset($dateTime['offset_value'])
            ? ('Z' === $dateTime['offset_value'] ? '+00:00' : $dateTime['offset_value'])
            : '+00:00';

        // Validate ranges of the datetime component.
        if (($yearMin > $dateTime['year']) || ($yearMax < $dateTime['year'])) {
            return null;
        }
        if ((1 > $dateTime['month_normalized']) || (12 < $dateTime['month_normalized'])) {
            return null;
        }
        if ((1 > $dateTime['day_normalized']) || (31 < $dateTime['day_normalized'])) {
            return null;
        }
        if ((0 > $dateTime['hour_normalized']) || (23 < $dateTime['hour_normalized'])) {
            return null;
        }
        if ((0 > $dateTime['minute_normalized']) || (59 < $dateTime['minute_normalized'])) {
            return null;
        }
        if ((0 > $dateTime['second_normalized']) || (59 < $dateTime['second_normalized'])) {
            return null;
        }
        if ((-23 > $dateTime['offset_hour_normalized']) || (23 < $dateTime['offset_hour_normalized'])) {
            return null;
        }
        if ((0 > $dateTime['offset_minute_normalized']) || (59 < $dateTime['offset_minute_normalized'])) {
            return null;
        }

        // Set the ISO 8601 format.
        if (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second']) && isset($dateTime['offset_value'])) {
            $format = 'Y-m-d\TH:i:sP';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['offset_value'])) {
            $format = 'Y-m-d\TH:iP';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['offset_value'])) {
            $format = 'Y-m-d\THP';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second'])) {
            $format = 'Y-m-d\TH:i:s';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute'])) {
            $format = 'Y-m-d\TH:i';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour'])) {
            $format = 'Y-m-d\TH';
        } elseif (isset($dateTime['month']) && isset($dateTime['day'])) {
            $format = 'Y-m-d';
        } elseif (isset($dateTime['month'])) {
            $format = 'Y-m';
        } else {
            $format = 'Y';
        }
        $dateTime['format_iso8601'] = $format;

        // Set the render format.
        if (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second']) && isset($dateTime['offset_value'])) {
            $format = 'F j, Y H:i:s P';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['offset_value'])) {
            $format = 'F j, Y H:i P';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['offset_value'])) {
            $format = 'F j, Y H P';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second'])) {
            $format = 'F j, Y H:i:s';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute'])) {
            $format = 'F j, Y H:i';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour'])) {
            $format = 'F j, Y H';
        } elseif (isset($dateTime['month']) && isset($dateTime['day'])) {
            $format = 'F j, Y';
        } elseif (isset($dateTime['month'])) {
            $format = 'F Y';
        } else {
            $format = 'Y';
        }
        $dateTime['format_render'] = $format;

        // Adding the DateTime object here to reduce code duplication. To ensure
        // consistency, use Coordinated Universal Time (UTC) if no offset is
        // provided. This avoids automatic adjustments based on the server's
        // default timezone.
        $dateTime['date'] = new DateTime(null, new DateTimeZone($dateTime['offset_normalized']));
        $dateTime['date']
            ->setDate(
                $dateTime['year'],
                $dateTime['month_normalized'],
                $dateTime['day_normalized']
            )
            ->setTime(
                $dateTime['hour_normalized'],
                $dateTime['minute_normalized'],
                $dateTime['second_normalized']
            );

        // Cache the date/time.
        $dateTimes[$value] = $dateTime['date']->format('Y-m-d\TH:i:s\Z');
        return $dateTimes[$value];
    }
}
