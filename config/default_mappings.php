<?php declare(strict_types=1);
// Example of a generic mapping for Solr.
// It should be adapted to specific data, in particular when they are normalized,
// for example for dates.

return [
    // Items.

    // Text general of Dublin Core elements + spatial and temporal coverages.
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_title_txt',
        'source' => 'dcterms:title',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Title'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_creator_txt',
        'source' => 'dcterms:creator',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Creator'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_subject_txt',
        'source' => 'dcterms:subject',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Subject'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_description_txt',
        'source' => 'dcterms:description',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Description'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_publisher_txt',
        'source' => 'dcterms:publisher',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Publisher'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_contributor_txt',
        'source' => 'dcterms:contributor',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Contributor'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_date_txt',
        'source' => 'dcterms:date',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Date'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_type_txt',
        'source' => 'dcterms:type',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Type'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_format_txt',
        'source' => 'dcterms:format',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Format'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_identifier_txt',
        'source' => 'dcterms:identifier',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Identifier'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_source_txt',
        'source' => 'dcterms:source',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Source'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_language_txt',
        'source' => 'dcterms:language',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Language'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_relation_txt',
        'source' => 'dcterms:relation',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Relation'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_coverage_txt',
        'source' => 'dcterms:coverage',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Coverage'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_rights_txt',
        'source' => 'dcterms:rights',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Rights'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_spatial_txt',
        'source' => 'dcterms:spatial',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Spatial coverage'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_temporal_txt',
        'source' => 'dcterms:temporal',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Temporal coverage'],
    ],
    // Dublin Core Terms.
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_spatial_ss',
        'source' => 'dcterms:spatial',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Spatial coverage'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_temporal_dr',
        'source' => 'dcterms:temporal',
        'pool' => [],
        'settings' => ['formatter' => 'date_range', 'label' => 'Temporal coverage'],
    ],

    // Specific fields.
    [
        'resource_name' => 'items',
        'field_name' => 'is_public_b',
        'source' => 'is_public',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Public'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'resource_class_s',
        'source' => 'resource_class',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Resource class'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'item_set_id_is',
        'source' => 'item_set/o:id',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Item set / Internal identifier'],
    ],

    // Fields for facets.
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_type_ss',
        'source' => 'dcterms:type',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Type'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_subject_ss',
        'source' => 'dcterms:subject',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Subject'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_creator_ss',
        'source' => 'dcterms:creator',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Creator'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_publisher_ss',
        'source' => 'dcterms:publisher',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Publisher'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_language_ss',
        'source' => 'dcterms:language',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Language'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_rights_ss',
        'source' => 'dcterms:rights',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Rights'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'item_set_dcterms_title_ss',
        'source' => 'item_set/dcterms:title',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Item Set'],
    ],

    // Fields to sort.
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_title_s',
        'source' => 'dcterms:title',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Title'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_date_s',
        'source' => 'dcterms:date',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Date'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_creator_s',
        'source' => 'dcterms:creator',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Creator'],
    ],

    // Item sets.
    [
        'resource_name' => 'item_sets',
        'field_name' => 'dcterms_title_txt',
        'source' => 'dcterms:title',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Title'],
    ],
    [
        'resource_name' => 'item_sets',
        'field_name' => 'dcterms_description_txt',
        'source' => 'dcterms:description',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Description'],
    ],
    [
        'resource_name' => 'item_sets',
        'field_name' => 'is_public_b',
        'source' => 'is_public',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Public'],
    ],
    [
        'resource_name' => 'item_sets',
        'field_name' => 'dcterms_title_s',
        'source' => 'dcterms:title',
        'pool' => [],
        'settings' => ['formatter' => '', 'label' => 'Title'],
    ],
];
