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
                if (is_array($value)) {
                    $array[$key] = $arrayFilterRecursiveEmpty($value);
                }
                if ($array[$key] === '' || $array[$key] === null || $array[$key] === []) {
                    unset($array[$key]);
                }
            }
            return $array;
        };
        $arrayFilterRecursiveEmpty($array);
        return $array;
    }
}
