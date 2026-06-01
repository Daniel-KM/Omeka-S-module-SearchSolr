<?php declare(strict_types=1);

namespace SearchSolr\Stdlib;

class SuggesterFields
{
    /**
     * Reduce explicitly selected suggester fields to a single catchall when one
     * is present.
     *
     * A catchall already aggregates all fields, so a single suggester built on
     * it is enough. The dedicated suggestion catchall "suggest_txt" has
     * priority over the search catchall "_text_".
     *
     * This rule must be applied identically when building the suggesters
     * (CreateSolrSuggesters) and when querying them (SolariumQuerier), so the
     * suggester names stay consistent.
     *
     * @param string[] $solrFields
     * @return string[]
     */
    public static function reduceToCatchall(array $solrFields): array
    {
        foreach (['suggest_txt', '_text_'] as $catchall) {
            if (in_array($catchall, $solrFields, true)) {
                return [$catchall];
            }
        }
        return $solrFields;
    }
}
