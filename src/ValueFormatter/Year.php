<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

class Year extends \SearchSolr\ValueFormatter\Date
{
    protected $label = 'Year'; // @translate

    protected $comment = 'Index only year from a date, in particular for filters an facets'; // @translate

    public function format($value): array
    {
        $value = parent::format($value);
        foreach ($value as $k => $v) {
            $value[$k] = substr($v, 0, 1) === '-'
                ? '-' . strtok(substr($v, 1), '-')
                : strtok($v, '-');
        }
        return $value;
    }
}
