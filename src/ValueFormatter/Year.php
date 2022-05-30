<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

class Year extends \SearchSolr\ValueFormatter\Date
{
    public function getLabel(): string
    {
        return 'Year'; // @translate
    }

    public function format($value): array
    {
        $value = parent::format($value);
        if (count($value)) {
            $year = reset($value);
            return substr($year, 0, 1) === '-'
                ? ['-' . strtok(substr($year, 1), '-')]
                : [strtok($year, '-')];
        }
        return $value;
    }
}
