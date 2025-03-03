<?php declare(strict_types=1);

/**
 * List the metadata that should not be indexed as content string.
 *
 * A content string is used for filters, facets, sort, so it should be fixed.
 *
 * This list is used to autocreate the mapping.
 */

return [
    'dcterms:description',
    'dcterms:abstract',
    'dcterms:tableOfContents',

    'bibo:content',

    'bio:biography',
    'bio:olb',

    'curation:data',
    'curation:note',

    'extracttext:extracted_text',

    'skos:changeNote',
    'skos:definition',
    'skos:editorialNote',
    'skos:note',
    'skos:scopeNote',
];
