<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * ValueFormatter to strip all HTML tags. Useful for HTML media content.
 */
class PlainText implements ValueFormatterInterface
{
    public function getLabel(): string
    {
        return 'Plain text'; // @translate
    }

    public function format($value): array
    {
        $value = strip_tags((string) $value);
        return strlen($value) ? [$value] : [];
    }
}
