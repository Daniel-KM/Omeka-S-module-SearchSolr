<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Default ValueFormatter to get an ascii string from any data type, without
 * symbols except "-".
 *
 * Manage some special types like uri, where the uri and the label are returned.
 * Values with a resource are already converted via display title.
 *
 * It is useful only when the solr search engine is not configured to manage
 * non-alphanumeric characters automatically, in particular with fields with
 * "txt" or "t".
 */
class Alphanumeric extends Standard
{
    protected $label = 'Alphanumeric'; // @translate

    public function format($value): array
    {
        $result = parent::format($value);
        foreach ($result as $key => $val) {
            $v = trim(str_replace('  ', ' ', preg_replace('~[^\p{L}\p{N}-]++~u', ' ', $val)));
            if (strlen($v)) {
                $result[$key] = $v;
            } else {
                unset($result[$key]);
            }
        }
        return $result;
    }
}
