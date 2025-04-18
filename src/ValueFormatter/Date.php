<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

use DateTime;
use DateTimeZone;
use Omeka\Api\Representation\ValueRepresentation;

class Date extends AbstractValueFormatter
{
    protected $label = 'Date'; // @translate

    protected $comment = 'Check and get date and time'; // @translate

    public function format($value): array
    {
        // Ideally, value should be an interval or a timestamp. Else, check the string early.

        if ($value instanceof ValueRepresentation && $value->type() === 'numeric:interval') {
            $value = strtok((string) $value, '/');
        } elseif (!($value instanceof ValueRepresentation && $value->type() === 'numeric:timestamp')) {
            // A value that is not managed as timestamp.
            $value = trim((string) $value);
            // Manage the common case where the date is uncertain and wrapped
            // with "[]" or "()" or "{}". Wrap may be on part of the date only.
            $value = str_replace(['[', ']', '(', ')', '{', '}', '?', '!'], '', $value);
            $matches = [];
            // Check for another format than ISO 8601 (partial or full) too.
            // Of course, garbage in, garbage out.
            if (preg_match('~^([+-]?)(\d+)$~', $value, $matches)) {
                // A single year, but without leading 0. "0" is not a year.
                $val = (int) $matches[2];
                if (!$val) {
                    return [];
                }
                // The year should be at least 4 digits for next process.
                $value = str_replace('+', '', $matches[1]) . sprintf('%04s', $val);
                $value = $this->fillFullDate($value);
            } elseif (strpos($value, '/') > 0) {
                // Manage "1914/1918" via a recursive call.
                // To be improved to avoid American dates.
                return $this->format($value = trim(strtok($value, '/')));
            } elseif (preg_match('~^([+-]?\d+)\s*-\s*-?\s*(?:[\D]+|[+-]?\s*\d\d\d+|[a-zA-Z].*)\s*\??$~', $value, $matches)) {
                // Manage the common but unstandard case "1914-1918" that should
                // not be the allowed "1918-11".
                $value = $this->fillFullDate($matches[1]);
            } elseif (preg_match('~^[+-]?\d+-\d\d-\d\d[ T]\d\d:\d\d:\d\dZ?$~', $value, $matches)) {
                // This is a mysql date.
                $value = str_replace(' ', 'T', $value);
            } elseif (preg_match('~^([+-]?)(\d+:\d\d:\d\d) (\d\d:\d\d:\d\d)Z?$~', $value, $matches)) {
                // This is an old exif date.
                $value = $matches[1] . str_replace(':', '-', $matches[2]) . 'T' . $matches[3];
            }
        } elseif (!(is_scalar($value) || (is_object($value) && method_exists($value, '__toString')))) {
            return [];
        }

        $value = (string) $this->getDateTimeFromValue((string) $value);

        return strlen($value)
            ? [$value]
            : [];
    }

    /**
     * Fill the full date time from a partial checked string.
     */
    protected function fillFullDate(string $value): string
    {
        // If the time is not set, it will be the current date or time.
        if (substr($value, 0, 1) === '-' && strlen($value) < 21) {
            $value = substr_replace('-0000-01-01T00:00:00Z', $value, 0, strlen($value) - 21);
        } elseif (substr($value, 0, 1) !== '-' && strlen($value) < 20) {
            $value = substr_replace('0000-01-01T00:00:00Z', $value, 0, strlen($value) - 20);
        }
        return (new DateTime($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Convert into a standard DateTime. Manage some badly formatted values.
     *
     * Adapted from module NumericDataType.
     * @see \NumericDataTypes\DataType\AbstractDateTimeDataType::getDateTimeFromValue()
     *
     * @param string $value
     * @return string|null
     */
    protected function getDateTimeFromValue($value)
    {
        $yearMin = -292277022656;
        $yearMax = 292277026595;
        $patternIso8601 = '^(?<date>(?<year>-?\d{4,})(-(?<month>\d{1,2}))?(-(?<day>\d{1,2}))?)(?<time>(T(?<hour>\d{1,2}))?(:(?<minute>\d{1,2}))?(:(?<second>\d{1,2}))?)(?<offset>((?<offset_hour>[+-]\d{1,2})?(:?(?<offset_minute>\d{1,2}))?)|Z?)$';
        static $dateTimes = [];

        $value = ltrim($value, '+');

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
            'time_value' => $matches['time'] ?? null,
            'offset_value' => $matches['offset'] ?? null,
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
        $dateTime['month_normalized'] = $dateTime['month'] ?? 1;
        // The last day takes special handling, as it depends on year/month.
        $dateTime['day_normalized'] = $dateTime['day'] ?? 1;
        $dateTime['hour_normalized'] = $dateTime['hour'] ?? 0;
        $dateTime['minute_normalized'] = $dateTime['minute'] ?? 0;
        $dateTime['second_normalized'] = $dateTime['second'] ?? 0;
        $dateTime['offset_hour_normalized'] = $dateTime['offset_hour'] ?? 0;
        $dateTime['offset_minute_normalized'] = $dateTime['offset_minute'] ?? 0;
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

        // Adding the DateTime object here to reduce code duplication. To ensure
        // consistency, use Coordinated Universal Time (UTC) if no offset is
        // provided. This avoids automatic adjustments based on the server's
        // default timezone.
        // With strict type, "now" is required.
        $dateTime['date'] = new DateTime('now', new DateTimeZone($dateTime['offset_normalized']));
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
