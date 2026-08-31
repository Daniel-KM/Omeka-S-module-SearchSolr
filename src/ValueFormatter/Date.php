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
 * Format a date, a date time or an extended date (EDTF) for Solr indexing.
 *
 * Parses the value with the edtf library and returns ISO 8601 strings
 * compatible with Solr date fields, or years, or a Solr date range. Solr date
 * fields support the full historical range, including BCE with negative years
 * (e.g. "-4500-01-01T00:00:00Z").
 *
 * Common legacy patterns are normalized before parsing: unstandard intervals
 * ("1914-1918", "1914-18"), mysql date times ("1914-06-28 10:30:00"), old exif
 * dates ("1914:06:28 10:30:00") and wrapping parens or braces. The full EDTF
 * (seasons, uncertainty…) requires the module Data Type EDTF; a minimal
 * fallback parser manages the common patterns without it.
 *
 * The settings control the output:
 * - "date_out": precision of the output.
 *   - "datetime" (default): ISO 8601 date time ("1975-04-17T12:15:00Z").
 *   - "date": ISO 8601 date time with the time dropped (00:00:00Z).
 *   - "year": signed integer year, for integer or long fields.
 * - "date_mode":
 *   - "single" (default): one or two plain values, according to "part".
 *   - "interval": a single Solr date range "[min TO max]", for "_dr" fields.
 * - "part" (single mode): "min" (default), "max", or "range" for both.
 *
 * Examples of output (datetime):
 * - "1975"            → min 1975-01-01T00:00:00Z, max 1975-12-31T23:59:59Z
 * - "1975-04-17"      → min 1975-04-17T00:00:00Z, max 1975-04-17T23:59:59Z
 * - "1914/1918"       → min 1914-01-01T00:00:00Z, max 1918-12-31T23:59:59Z
 * - "-4500"           → min -4500-01-01T00:00:00Z, max -4500-12-31T23:59:59Z
 *
 * @see https://www.loc.gov/standards/datetime/
 * @see https://solr.apache.org/guide/solr/latest/indexing-guide/date-formatting-math.html
 */
class Date extends AbstractValueFormatter
{
    protected $label = 'Date'; // @translate

    protected $comment = 'Parse a date, a date time or an extended date (EDTF) and return it as date, year or interval. Common patterns are managed natively; the full EDTF (seasons, uncertainty…) requires the module Data Type EDTF.'; // @translate

    public function format($value): array
    {
        if ($value instanceof ValueRepresentation) {
            $dateString = trim((string) $value->value());
        } else {
            $dateString = trim((string) $value);
        }
        if ($dateString === '') {
            return [];
        }

        $dateString = $this->normalizeDateString($dateString);

        $out = $this->settings['date_out'] ?? 'datetime';
        $dateOnly = $out === 'date';

        [$min, $max] = $this->bounds($dateString, $dateOnly);
        if ($min === null && $max === null) {
            return [];
        }

        $mode = $this->settings['date_mode'] ?? 'single';
        if ($mode === 'interval') {
            // A Solr date range field rejects an inverted range, and the whole
            // document fails to index.
            $start = $this->outValue($min ?? $max, $out);
            $end = $this->outValue($max ?? $min, $out);
            if ($start === null || $end === null) {
                return [];
            }
            if ($this->compareBounds($start, $end) > 0) {
                [$start, $end] = [$end, $start];
            }
            return ["[$start TO $end]"];
        }

        $part = $this->settings['part'] ?? 'min';
        $values = [];
        if (($part === 'min' || $part === 'range') && $min !== null) {
            $values[] = $this->outValue($min, $out);
        }
        if (($part === 'max' || $part === 'range') && $max !== null) {
            $values[] = $this->outValue($max, $out);
        }
        return array_values(array_filter($values, fn ($v) => $v !== null));
    }

    /**
     * Convert an ISO bound to the output precision.
     *
     * @return string|int|null
     */
    protected function outValue(?string $iso, string $out)
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        if ($out === 'year') {
            return preg_match('/^(-?)(\d+)-/', $iso, $matches)
                ? (int) ($matches[1] . $matches[2])
                : null;
        }
        return $iso;
    }

    /**
     * Compare two bounds numerically by year first: a lexicographic
     * comparison is wrong for negative years ("-4500" vs "-0100").
     *
     * @param string|int $a
     * @param string|int $b
     */
    protected function compareBounds($a, $b): int
    {
        $year = function ($v): int {
            if (is_int($v)) {
                return $v;
            }
            return preg_match('/^(-?)(\d+)/', (string) $v, $matches)
                ? (int) ($matches[1] . $matches[2])
                : 0;
        };
        $yearA = $year($a);
        $yearB = $year($b);
        return $yearA !== $yearB
            ? $yearA <=> $yearB
            : strcmp((string) $a, (string) $b);
    }

    /**
     * Normalize the common legacy patterns into a parsable EDTF string.
     */
    protected function normalizeDateString(string $value): string
    {
        // Uncertainty wrappers: parens and braces are not EDTF; brackets are a
        // valid EDTF set, so they are kept.
        $value = trim(strtr($value, ['(' => '', ')' => '', '{' => '', '}' => '', '!' => '']));

        $matches = [];
        // The common but unstandard interval "1914-1918", that should not be
        // the valid year-month "1918-11", and the abbreviated "1914-18"
        // (completed with the leading digits of the start).
        if (preg_match('~^(-?\d{3,})\s*-\s*(\d+)\s*\??$~', $value, $matches)
            && ($matches[2] > 12 || strlen($matches[2]) > 2)
        ) {
            $start = $matches[1];
            $end = $matches[2];
            if (strlen($end) < strlen(ltrim($start, '-'))) {
                $startAbs = ltrim($start, '-');
                $end = substr($startAbs, 0, strlen($startAbs) - strlen($end)) . $end;
            }
            // An inverted interval is invalid for the edtf parser.
            if ((int) $end < (int) $start) {
                [$start, $end] = [$end, $start];
            }
            return "$start/$end";
        }
        // A mysql date time ("1914-06-28 10:30:00").
        if (preg_match('~^[+-]?\d+-\d\d-\d\d \d\d:\d\d:\d\d$~', $value)) {
            return strtr($value, ' ', 'T');
        }
        // An old exif date ("1914:06:28 10:30:00").
        if (preg_match('~^([+-]?)(\d+:\d\d:\d\d) (\d\d:\d\d:\d\d)Z?$~', $value, $matches)) {
            return $matches[1] . strtr($matches[2], [':' => '-']) . 'T' . $matches[3];
        }
        return $value;
    }

    /**
     * Parse an EDTF string once and return its [minIso, maxIso] bounds,
     * memoized per string and precision.
     *
     * The same date is indexed by several maps (year start/end/range, date
     * start/end): the EDTF parse is the costly step and is identical for all of
     * them, so parsing once and reusing the bounds removes the per-map
     * repetition. The cache is soft-capped to keep memory bounded during a full
     * reindex.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function bounds(string $edtfString, bool $dateOnly): array
    {
        static $cache = [];

        $key = ($dateOnly ? '1|' : '0|') . $edtfString;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $result = [null, null];
        if (class_exists(EdtfFactory::class)) {
            try {
                $parsed = EdtfFactory::newParser()->parse($edtfString);
                if ($parsed->isValid()) {
                    $edtf = $parsed->getEdtfValue();
                    // For intervals, use explicit start/end dates; otherwise
                    // build min/max from the single value's precision.
                    if ($edtf instanceof Interval) {
                        $min = $edtf->hasStartDate()
                            ? $this->toIso($edtf->getStartDate(), false, $dateOnly)
                            : null;
                        $max = $edtf->hasEndDate()
                            ? $this->toIso($edtf->getEndDate(), true, $dateOnly)
                            : null;
                    } else {
                        $min = $this->toIso($edtf, false, $dateOnly);
                        $max = $this->toIso($edtf, true, $dateOnly);
                    }
                    $result = [$min, $max];
                }
            } catch (\Throwable $e) {
                // Invalid EDTF: cache the null bounds to avoid re-parsing.
            }
        } else {
            // The edtf-php library is not installed: minimal fallback for the
            // common patterns (year, year-month, year-month-day, negative
            // years, interval with open bounds), so the year and date indexes
            // keep working without the dependency. Full EDTF (seasons,
            // uncertainty…) needs the library.
            $result = $this->boundsFallback($edtfString);
        }

        // Within a resource the same date repeats across maps (cache hit);
        // across the whole index distinct dates accumulate, so reset when large
        // rather than growing unbounded.
        if (count($cache) >= 20000) {
            $cache = [];
        }

        return $cache[$key] = $result;
    }

    /**
     * Minimal bounds parser used when the edtf-php library is missing.
     *
     * @return array [min, max], as iso date strings or nulls.
     */
    protected function boundsFallback(string $edtfString): array
    {
        $parsePart = function (string $part, bool $isMax): ?string {
            $part = trim($part);
            if ($part === '' || $part === '..') {
                return null;
            }
            if (!preg_match('~^(-?\d{1,6})(?:-(\d{2}))?(?:-(\d{2}))?$~', $part, $m)) {
                return null;
            }
            $year = (int) $m[1];
            $month = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            $day = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
            if ($month !== null && ($month < 1 || $month > 12)) {
                return null;
            }
            if ($month === null) {
                $month = $isMax ? 12 : 1;
                $day = $isMax ? 31 : 1;
            } elseif ($day === null) {
                $day = $isMax ? (int) date('t', mktime(0, 0, 0, $month, 1, abs($year) ?: 2000)) : 1;
            } elseif ($day < 1 || $day > 31) {
                return null;
            }
            return sprintf('%s%04d-%02d-%02d', $year < 0 ? '-' : '', abs($year), $month, $day);
        };

        $parts = explode('/', $edtfString, 2);
        if (count($parts) === 2) {
            return [$parsePart($parts[0], false), $parsePart($parts[1], true)];
        }
        return [$parsePart($edtfString, false), $parsePart($edtfString, true)];
    }

    /**
     * Build an ISO 8601 string from an EDTF value at its native precision.
     *
     * $isMax = false: fill missing components with 01/01/00:00:00. $isMax =
     * true: fill with the last valid value (12/31, last day of the month,
     * 23:59:59).
     */
    protected function toIso($edtf, bool $isMax, bool $dateOnly): ?string
    {
        if ($edtf instanceof Set) {
            $members = $edtf->getDates();
            if (!$members) {
                return null;
            }
            // Use the first (min) or last (max) member of the set.
            return $this->toIso(
                $isMax ? end($members) : reset($members),
                $isMax,
                $dateOnly
            );
        }

        if ($edtf instanceof Season) {
            $year = $edtf->getYear();
            if ($year === null) {
                return null;
            }
            // Seasons: approximate to full year bounds.
            return $isMax
                ? $this->formatIso($year, 12, 31, 23, 59, 59, $dateOnly)
                : $this->formatIso($year, 1, 1, 0, 0, 0, $dateOnly);
        }

        if ($edtf instanceof ExtDateTime) {
            $inner = $edtf->getDate();
            $year = $inner->getYear();
            if ($year === null) {
                return null;
            }
            // Time is always precise in ExtDateTime (no partial time supported
            // by the library), so min == max for the time.
            return $this->formatIso(
                $year,
                $inner->getMonth() ?? ($isMax ? 12 : 1),
                $inner->getDay() ?? ($isMax ? $this->lastDay(
                    $year, $inner->getMonth() ?? 12
                ) : 1),
                $edtf->getHour(),
                $edtf->getMinute(),
                $edtf->getSecond(),
                $dateOnly
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
                $isMax ? 59 : 0,
                $dateOnly
            );
        }

        return null;
    }

    /**
     * Build an ISO 8601 UTC date-time string. Supports negative years
     * (BCE) with a leading "-" and zero-padded 4-digit year.
     *
     * When $dateOnly is true, time is forced to 00:00:00Z.
     */
    protected function formatIso(
        int $year, int $month, int $day,
        int $hour = 0, int $minute = 0, int $second = 0,
        bool $dateOnly = false
    ): ?string {
        // Reject years outside Solr DatePointField range
        // (~±292 million years).
        if (abs($year) > 292000000) {
            return null;
        }
        if ($dateOnly) {
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
     * Last day of a Gregorian month, handling leap years. Works for any year
     * including BCE (astronomical year numbering).
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
