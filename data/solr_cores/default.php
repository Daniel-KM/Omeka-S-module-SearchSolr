<?php declare(strict_types=1);

/**
 * Generic config for a solr core.
 *
 * When created, it can be modified in the admin board.
 *
 * @var array
 */
return [
    '@context' => null,
    '@id' => null,
    '@type' => 'o:SolrCore',
    'o:id' => null,
    'o:name' => 'Default',
    'o:settings' => [
        'client' => [
            'scheme' => 'http',
            'host' => 'localhost',
            'port' => 8983,
            'path' => '/',
            // 'collection' => null,
            'core' => 'omeka',
            'secure' => false,
            'username' => null,
            'password' => null,
            'bypass_certificate_check' => false,
        ],
        'support' => '',
        'server_id' => '',
        'resource_languages' => '',
        'query' => [
            'minimum_match' => '',
            'tie_breaker' => '',
        ],
    ],
];
