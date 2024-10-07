<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * ValueFormatter to make a value first character uppercase.
 *
 * @link https://forum.omeka.org/t/solr-text-field-length/13430
 */
class UcFirstText extends AbstractValueFormatter
{
    public function getLabel(): string
    {
        return 'First character uppercase'; // @translate
    }

    public function format($value): array
    {
        $value = ucfirst(mb_strtolower((string) $value));
        return strlen($value) ? [$value] : [];
    }
}
