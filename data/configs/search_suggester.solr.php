<?php declare(strict_types=1);

/**
 * Generic config for the Solr suggester.
 *
 * When created, it can be modified in the admin board.
 *
 * The default Solr field is "_text_" which is the standard Solr catchall
 * copy field that aggregates all indexed content. If "_text_" doesn't exist,
 * it can be created via the Solr core admin page or changed to another field.
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
        // Use _text_ catchall copy field (standard Solr field).
        // Can be an array for multiple fields: ['dcterms_title_txt', 'dcterms_creator_txt']
        'solr_fields' => ['_text_'],
        'solr_lookup_impl' => 'AnalyzingInfixLookupFactory',
        'solr_build_on_commit' => true,
    ],
];
