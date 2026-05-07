<?php declare(strict_types=1);

/**
 * Generic config for the Solr suggester.
 *
 * When created, it can be modified in the admin board.
 *
 * Use stored fields for suggestions: "_text_" is not stored by default and
 * uses EdgeNGram, resulting in character-level fragments instead of real values.
 * Specific "_txt" fields (text_general, stored) return complete values.
 *
 * @var array
 */
return [
    '@context' => null,
    '@id' => null,
    '@type' => 'o:SearchSuggester',
    'o:id' => null,
    'o:name' => 'Solr',
    'o:search_engine' => ['o:id' => null], // Filled during install.
    'o:settings' => [
        // Solr-specific settings.
        'solr_suggester_name' => 'omeka_suggester',
        // "suggest_txt" is a unified field aggregating all short-value
        // _txt fields via copyField (created via admin action).
        // "auto" uses all stored text and string fields individually.
        // Or specify fields: ['dcterms_title_txt', 'dcterms_creator_txt'].
        'solr_fields' => ['suggest_txt'],
        'solr_lookup_implementation' => 'AnalyzingInfixLookupFactory',
        'solr_skip_build_on_commit' => false,
    ],
];
