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

    protected $comment = 'Store any value as text (deprecated: use Text without settings)'; // @translate

    public function format($value): array
    {
        $value = trim((string) $value);
        return strlen($value)
            ? $this->postFormatter([$value])
            : [];
    }
}
