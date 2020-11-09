<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Default ValueFormatter to get a string from any data type.
 *
 * Values with a resource are already converted via display title.
 */
class StandardNoUri implements ValueFormatterInterface
{
    public function getLabel(): string
    {
        return 'Standard (no uri)'; // @translate
    }

    public function format($value)
    {
        return (string) $value;
    }
}
