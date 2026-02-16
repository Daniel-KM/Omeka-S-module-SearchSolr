<?php declare(strict_types=1);

/**
 * Generic config for the Solr engine.
 *
 * When created, it can be modified in the admin board.
 *
 * @var array
 */
return [
    '@context' => null,
    '@id' => null,
    '@type' => 'o:SearchEngine',
    'o:id' => null,
    'o:name' => 'Solr',
    'o:engine_adapter' => 'solarium',
    'o:settings' => [
        'resource_types' => [
            'items',
        ],
        'engine_adapter' => [
            // Filled during install with solr_core_id.
        ],
    ],
    'o:created' => null,
    'o:modified' => null,
];
