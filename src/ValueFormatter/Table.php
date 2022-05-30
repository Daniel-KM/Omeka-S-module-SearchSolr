<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * ValueFormatter to get original value.
 *
 * Similar to Standard, but for string value.
 */
class Table extends PlainText
{
    public function getLabel(): string
    {
        return 'Table'; // @translate
    }

    public function format($value): array
    {
        static $table;

        // TODO Use services Config.
        if (is_null($table)) {
            $table = require OMEKA_PATH . '/config/local.config.php';
            $table = $table['searchsolr']['table'] ?? [];
        }

        $values = parent::format($value);
        if (!count($values) || !count($table)) {
            return $values;
        }

        foreach ($values as &$val) {
            $val = $table[$val] ?? $val;
        }
        unset($val);

        return $values;
    }
}
