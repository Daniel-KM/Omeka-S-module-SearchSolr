<?php declare(strict_types=1);

namespace SearchSolr\Api\Adapter;

trait TraitArrayFilterRecursiveEmptyValue
{
    /**
     * Remove empty values ("", [] and null) recursively.
     *
     * 0 and "0" are valid values and kept.
     */
    public function arrayFilterRecursiveEmptyValue(array $array): array
    {
        $arrayFilterRecursiveEmpty = null;
        $arrayFilterRecursiveEmpty = function (array &$array) use (&$arrayFilterRecursiveEmpty): array {
            foreach ($array as $key => $value) {
                if (is_array($value) && $value) {
                    $array[$key] = $arrayFilterRecursiveEmpty($value);
                }
                if (in_array($array[$key], ['', null, []], true)) {
                    unset($array[$key]);
                }
            }
            return $array;
        };
        $arrayFilterRecursiveEmpty($array);
        return $array;
    }
}
