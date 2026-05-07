<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

use EDTF\EdtfFactory;
use EDTF\Model\ExtDate;
use EDTF\Model\ExtDateTime;
use EDTF\Model\Interval;
use EDTF\Model\Season;
use EDTF\Model\Set;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Format an EDTF (Extended Date/Time Format) value for Solr indexing.
 *
 * Parses the EDTF string with the edtf library and returns ISO 8601
 * date-time strings compatible with Solr date fields. Solr date fields
 * support the full historical range, including BCE with negative years
 * (e.g. "-4500-01-01T00:00:00Z").
 *
 * This formatter is the complete version: it preserves the time
 * component when present. Use {@see EdtfDate} to index only the date
 * part (time forced to 00:00:00Z).
 *
 * The "part" setting controls the output:
 * - "min" (default): earliest moment of the EDTF value
 * - "max": latest moment of the EDTF value
 * - "range": both min and max as an array (two values)
 *
 * Examples of output:
 * - "1975"            → min 1975-01-01T00:00:00Z, max 1975-12-31T23:59:59Z
 * - "1975-04-17"      → min 1975-04-17T00:00:00Z, max 1975-04-17T23:59:59Z
 * - "1975-04-17T12:15:00" → min = max = 1975-04-17T12:15:00Z
 * - "1914/1918"       → min 1914-01-01T00:00:00Z, max 1918-12-31T23:59:59Z
 * - "-4500"           → min -4500-01-01T00:00:00Z, max -4500-12-31T23:59:59Z
 *
 * Requires module DataTypeEdtf (which provides the edtf library).
 *
 * @see https://www.loc.gov/standards/datetime/
 * @see https://solr.apache.org/guide/solr/latest/indexing-guide/date-formatting-math.html
 */
class Edtf extends AbstractValueFormatter
{
    protected $label = 'EDTF (date + time)'; // @translate

    protected $comment = 'Parse EDTF values, return ISO 8601 date-time (min, max, or both).'; // @translate

    /**
     * When true, time components are dropped (date-only indexing).
     */
    protected bool $dateOnly = false;

    public function format($value): array
    {
        $edtfString = $value instanceof ValueRepresentation
            ? trim((string) $value->value())
            : trim((string) $value);

        if ($edtfString === '') {
            return [];
        }

        if (!class_exists(EdtfFactory::class)) {
            return [];
        }

        try {
            $result = EdtfFactory::newParser()->parse($edtfString);
        } catch (\Throwable $e) {
            return [];
        }

        if (!$result->isValid()) {
            return [];
        }

        $edtf = $result->getEdtfValue();

        // Compute bounds. For intervals, use explicit start/end dates;
        // otherwise build min/max from the single value's precision.
        if ($edtf instanceof Interval) {
            $min = $edtf->hasStartDate()
                ? $this->toIso($edtf->getStartDate(), false)
                : null;
            $max = $edtf->hasEndDate()
                ? $this->toIso($edtf->getEndDate(), true)
                : null;
        } else {
            $min = $this->toIso($edtf, false);
            $max = $this->toIso($edtf, true);
        }

        $part = $this->settings['part'] ?? 'min';
        $values = [];
        if (($part === 'min' || $part === 'range') && $min !== null) {
            $values[] = $min;
        }
        if (($part === 'max' || $part === 'range') && $max !== null) {
            $values[] = $max;
        }
        return $values;
    }

    /**
     * Build an ISO 8601 string from an EDTF value at its native precision.
     *
     * $isMax = false: fill missing components with 01/01/00:00:00.
     * $isMax = true: fill with the last valid value (12/31, last day of
     * the month, 23:59:59).
     */
    protected function toIso($edtf, bool $isMax): ?string
    {
        if ($edtf instanceof Set) {
            $members = $edtf->getDates();
            if (!$members) {
                return null;
            }
            // Use the first (min) or last (max) member of the set.
            return $this->toIso(
                $isMax ? end($members) : reset($members),
                $isMax
            );
        }

        if ($edtf instanceof Season) {
            $year = $edtf->getYear();
            if ($year === null) {
                return null;
            }
            // Seasons: approximate to full year bounds.
            return $isMax
                ? $this->formatIso($year, 12, 31, 23, 59, 59)
                : $this->formatIso($year, 1, 1, 0, 0, 0);
        }

        if ($edtf instanceof ExtDateTime) {
            $inner = $edtf->getDate();
            $year = $inner->getYear();
            if ($year === null) {
                return null;
            }
            // Time is always precise in ExtDateTime (no partial time
            // supported by the library), so min == max for the time.
            return $this->formatIso(
                $year,
                $inner->getMonth() ?? ($isMax ? 12 : 1),
                $inner->getDay() ?? ($isMax ? $this->lastDay(
                    $year, $inner->getMonth() ?? 12
                ) : 1),
                $edtf->getHour(),
                $edtf->getMinute(),
                $edtf->getSecond()
            );
        }

        if ($edtf instanceof ExtDate) {
            $year = $edtf->getYear();
            if ($year === null) {
                return null;
            }
            $month = $edtf->getMonth();
            $day = $edtf->getDay();
            return $this->formatIso(
                $year,
                $month ?? ($isMax ? 12 : 1),
                $day ?? ($isMax ? $this->lastDay(
                    $year, $month ?? 12
                ) : 1),
                $isMax ? 23 : 0,
                $isMax ? 59 : 0,
                $isMax ? 59 : 0
            );
        }

        return null;
    }

    /**
     * Build an ISO 8601 UTC date-time string. Supports negative years
     * (BCE) with a leading "-" and zero-padded 4-digit year.
     *
     * When $this->dateOnly is true, time is forced to 00:00:00Z.
     */
    protected function formatIso(
        int $year, int $month, int $day,
        int $hour = 0, int $minute = 0, int $second = 0
    ): ?string {
        // Reject years outside Solr DatePointField range
        // (~±292 million years).
        if (abs($year) > 292000000) {
            return null;
        }
        if ($this->dateOnly) {
            $hour = $minute = $second = 0;
        }
        $sign = $year < 0 ? '-' : '';
        $yAbs = str_pad(
            (string) abs($year), 4, '0', STR_PAD_LEFT
        );
        return sprintf(
            '%s%s-%02d-%02dT%02d:%02d:%02dZ',
            $sign, $yAbs, $month, $day, $hour, $minute, $second
        );
    }

    /**
     * Last day of a Gregorian month, handling leap years. Works for
     * any year including BCE (astronomical year numbering).
     */
    protected function lastDay(int $year, int $month): int
    {
        $daysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if ($month < 1 || $month > 12) {
            return 31;
        }
        if ($month !== 2) {
            return $daysInMonth[$month - 1];
        }
        // Leap year check (proleptic Gregorian).
        $isLeap = ($year % 4 === 0 && $year % 100 !== 0)
            || $year % 400 === 0;
        return $isLeap ? 29 : 28;
    }
}
