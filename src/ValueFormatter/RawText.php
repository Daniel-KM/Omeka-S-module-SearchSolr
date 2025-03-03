<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * ValueFormatter to get original value.
 *
 * Similar to Standard, but for string value.
 */
class RawText extends AbstractValueFormatter
{
    protected $label = 'Raw text'; // @translate

    public function format($value): array
    {
        $value = trim((string) $value);
        return strlen($value) ? [$value] : [];
    }
}
