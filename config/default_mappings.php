<?php
// Example of a generic mapping for Solr.
// It should be adapted to specific data, in particular when they are normalized,
// for example for dates.

return [
    // Items.

    // Text general of Dublin Core elements + spatial and temporal coverages.
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_title_t',
        'source' => 'dcterms:title',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_creator_t',
        'source' => 'dcterms:creator',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_subject_t',
        'source' => 'dcterms:subject',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_description_t',
        'source' => 'dcterms:description',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_publisher_t',
        'source' => 'dcterms:publisher',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_contributor_t',
        'source' => 'dcterms:contributor',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_date_t',
        'source' => 'dcterms:date',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_type_t',
        'source' => 'dcterms:type',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_format_t',
        'source' => 'dcterms:format',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_identifier_t',
        'source' => 'dcterms:identifier',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_source_t',
        'source' => 'dcterms:source',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_language_t',
        'source' => 'dcterms:language',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_relation_t',
        'source' => 'dcterms:relation',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_coverage_t',
        'source' => 'dcterms:coverage',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_rights_t',
        'source' => 'dcterms:rights',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_spatial_t',
        'source' => 'dcterms:spatial',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_temporal_t',
        'source' => 'dcterms:temporal',
        'settings' => ['formatter' => ''],
    ],
    // Dublin Core Terms.
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_spatial_ss',
        'source' => 'dcterms:spatial',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_temporal_dr',
        'source' => 'dcterms:temporal',
        'settings' => ['formatter' => 'date_range'],
    ],

    // Specific fields.
    [
        'resource_name' => 'items',
        'field_name' => 'is_public_b',
        'source' => 'is_public',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'resource_class_s',
        'source' => 'resource_class',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'item_set_id_is',
        'source' => 'item_set/id',
        'settings' => ['formatter' => ''],
    ],

    // Fields for facets.
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_type_ss',
        'source' => 'dcterms:type',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_subject_ss',
        'source' => 'dcterms:subject',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_creator_ss',
        'source' => 'dcterms:creator',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_publisher_ss',
        'source' => 'dcterms:publisher',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_language_ss',
        'source' => 'dcterms:language',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_rights_ss',
        'source' => 'dcterms:rights',
        'settings' => ['formatter' => ''],
    ],

    // Fields to sort.
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_title_s',
        'source' => 'dcterms:title',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_date_s',
        'source' => 'dcterms:date',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_creator_s',
        'source' => 'dcterms:creator',
        'settings' => ['formatter' => ''],
    ],

    // Item sets.
    [
        'resource_name' => 'item_sets',
        'field_name' => 'dcterms_title_t',
        'source' => 'dcterms:title',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'item_sets',
        'field_name' => 'dcterms_description_t',
        'source' => 'dcterms:description',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'item_sets',
        'field_name' => 'is_public_b',
        'source' => 'is_public',
        'settings' => ['formatter' => ''],
    ],
    [
        'resource_name' => 'item_sets',
        'field_name' => 'dcterms_title_s',
        'source' => 'dcterms:title',
        'settings' => ['formatter' => ''],
    ],
];
