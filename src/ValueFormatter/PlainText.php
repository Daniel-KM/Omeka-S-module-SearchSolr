<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * ValueFormatter to strip all HTML tags. Useful for HTML media content.
 */
class PlainText extends AbstractValueFormatter
{
    protected $label = 'Plain text'; // @translate

    protected $comment = 'Remove tags from a value (deprecated: use Text and settings)'; // @translate

    public function format($value): array
    {
        $value = strip_tags((string) $value);
        return strlen($value)
            ? $this->postFormatter([$value])
            : [];
    }
}
