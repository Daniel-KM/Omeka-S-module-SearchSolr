<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

class Integer extends AbstractValueFormatter
{
    protected $label = 'Integer'; // @translate

    protected $comment = 'Convert string into integer'; // @translate

    public function format($value): array
    {
        $value = (string) $value;
        $integer = (int) $value;
        return $integer === 0
                && !(mb_substr($value, 0, 1) === '0' || mb_substr($value, 0, 2) === '-0')
           ? []
           : [$integer];
    }
}
