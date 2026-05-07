<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Format an EDTF value for Solr indexing, keeping only the date part.
 *
 * Same as {@see Edtf} but drops the time component. Times from
 * ExtDateTime values are forced to 00:00:00Z. A value like
 * "1975-04-17T12:15:00" yields "1975-04-17T00:00:00Z" for both min
 * and max. A year "1975" yields "1975-01-01T00:00:00Z" (min) and
 * "1975-12-31T00:00:00Z" (max).
 *
 * Useful when time precision is irrelevant (historical/archaeological
 * data) and when facets should only show date values.
 */
class EdtfDate extends Edtf
{
    protected $label = 'EDTF (date only)'; // @translate

    protected $comment = 'Parse EDTF values, return ISO 8601 date (time dropped).'; // @translate

    protected bool $dateOnly = true;
}
