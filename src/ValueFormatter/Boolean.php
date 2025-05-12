<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

class Boolean extends AbstractValueFormatter
{
    protected $label = 'Boolean'; // @translate

    protected $comment = 'Convert value into boolean true or false'; // @translate

    public function format($value): array
    {
        $boolean = is_bool($value) ? $value : (bool) (string) $value;
        return [$boolean ? 1 : 0];
    }
}
