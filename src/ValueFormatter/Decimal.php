<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

class Decimal extends AbstractValueFormatter
{
    protected $label = 'Decimal'; // @translate

    protected $comment = 'Convert string into decimal'; // @translate

    public function format($value): array
    {
        $value = trim((string) (is_bool($value) ? (int) $value : $value));
        // The comma is a common decimal separator in the values of a database.
        $value = strtr($value, [',' => '.']);
        return is_numeric($value)
            ? [(float) $value]
            : [];
    }
}
