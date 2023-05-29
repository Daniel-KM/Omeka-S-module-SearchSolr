<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * ValueFormatter to escape all special characters from text.
 *
 * @link https://forum.omeka.org/t/solr-text-field-length/13430
 */
class HtmlEscapedText extends AbstractValueFormatter
{
    public function getLabel(): string
    {
        return 'HTML escaped text'; // @translate
    }

    public function format($value): array
    {
        // New default for php 8.1.
        $value = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
        return strlen($value) ? [$value] : [];
    }
}
