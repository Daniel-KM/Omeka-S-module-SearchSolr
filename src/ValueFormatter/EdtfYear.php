<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Format an EDTF value as a signed integer year.
 *
 * Extends {@see Edtf} and reuses its parsing (intervals, sets, seasons,
 * unspecified digits like 19XX/197X, BCE) but converts the final ISO
 * strings to year numbers. Month, day and time are dropped.
 *
 * Suitable for Solr integer (_i) or long (_l) fields when only
 * year-level precision is needed. Facets show readable years
 * (e.g. "-4500", "1914"), range queries use plain year numbers.
 *
 * The "part" setting controls the output:
 * - "min" (default): start year of the EDTF value
 * - "max": end year of the EDTF value
 * - "range": both start and end years (two values)
 *
 * Examples:
 * - "1975"       → 1975
 * - "1914/1918"  → min 1914, max 1918
 * - "-4500"      → -4500
 * - "19XX"       → min 1900, max 1999
 * - "197X"       → min 1970, max 1979
 *
 * Map to a Solr "_i" field for historical years (year ≥ -2147M) or
 * "_l" for geological scales.
 */
class EdtfYear extends Edtf
{
    protected $label = 'EDTF (year)'; // @translate

    protected $comment = 'Parse EDTF values, return signed integer year.'; // @translate

    public function format($value): array
    {
        // Delegate parsing and bound extraction to the parent,
        // then convert each ISO string to a signed year integer.
        $isoValues = parent::format($value);
        $years = [];
        foreach ($isoValues as $iso) {
            $year = $this->isoToYear((string) $iso);
            if ($year !== null) {
                $years[] = $year;
            }
        }
        return $years;
    }

    /**
     * Extract the signed year from an ISO 8601 string built by the
     * parent, e.g. "1975-01-01T00:00:00Z" or "-4500-12-31T23:59:59Z".
     */
    protected function isoToYear(string $iso): ?int
    {
        if ($iso === '') {
            return null;
        }
        // Match an optional minus sign then the year digits, stop at
        // the first "-" that starts the month (position > 0 for sign
        // or > 4 otherwise).
        if (preg_match('/^(-?)(\d+)-/', $iso, $m)) {
            $year = (int) ($m[1] . $m[2]);
            return $year;
        }
        return null;
    }
}
