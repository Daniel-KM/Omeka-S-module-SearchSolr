<?php declare(strict_types=1);

/**
 * Generic mapping for Solr.
 *
 * It should be adapted to specific data, in particular when they are normalized,
 * for example for dates.
 *
 * They can be updated in admin board.
 *
 * The aliases use the omeka names.
 * For properties, the alias is used dynamically with priority to "_ss".
 *
 * For compatibility with Drupal, use the file default_mappings_drupal.php.
 */

return [
    // Resources.

    // Required specific fields for any type of resource.
    [
        // Api name.
        'resource_name' => 'generic',
        'field_name' => 'resource_name_s',
        'alias' => 'resource_name',
        'source' => 'resource_name',
        'pool' => [],
        'settings' => ['label' => 'Resource type'],
    ],
    [
        'resource_name' => 'generic',
        'field_name' => 'id_i',
        'alias' => 'id',
        'source' => 'o:id',
        'pool' => [],
        'settings' => ['label' => 'Internal id'],
    ],
    [
        'resource_name' => 'generic',
        'field_name' => 'is_public_i',
        'alias' => 'is_public',
        'source' => 'is_public',
        'pool' => [],
        'settings' => ['label' => 'Public'],
    ],
    [
        // The generic name of the resource: may be main title, label or name.
        'resource_name' => 'generic',
        'field_name' => 'name_s',
        'alias' => 'name',
        'source' => 'o:title',
        'pool' => [],
        'settings' => ['label' => 'Name'],
    ],
    [
        'resource_name' => 'generic',
        'field_name' => 'owner_id_i',
        'alias' => 'owner_id',
        'source' => 'owner/o:id',
        'pool' => [],
        'settings' => ['label' => 'Owner'],
    ],
    [
        'resource_name' => 'generic',
        'field_name' => 'site_id_is',
        'alias' => 'site_id',
        'source' => 'site/o:id',
        'pool' => [],
        'settings' => ['label' => 'Site'],
    ],

    // Not required specific fields.

    // Resources.

    // Specific fields.
    [
        'resource_name' => 'resources',
        'field_name' => 'resource_class_s',
        'alias' => 'resource_class_term',
        'source' => 'resource_class/o:term',
        'pool' => [],
        'settings' => ['label' => 'Resource class'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'resource_template_s',
        'alias' => 'resource_template_label',
        'source' => 'resource_template/o:label',
        'pool' => [],
        'settings' => ['label' => 'Resource template'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'title_s',
        'alias' => 'title',
        'source' => 'o:title',
        'pool' => [],
        'settings' => ['label' => 'Title'],
    ],

    // Properties.
    // Text general of Dublin Core elements + spatial and temporal coverages.
    // The alias is on "_ss" (below), not with "_txt".
    // TODO Remove default mapping for properties and build them automatically.
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_title_txt',
        'alias' => '',
        'source' => 'dcterms:title',
        'pool' => [],
        'settings' => ['label' => 'Title'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_creator_txt',
        'alias' => '',
        'source' => 'dcterms:creator',
        'pool' => [],
        'settings' => ['label' => 'Creator'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_subject_txt',
        'alias' => '',
        'source' => 'dcterms:subject',
        'pool' => [],
        'settings' => ['label' => 'Subject'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_description_txt',
        'alias' => '',
        'source' => 'dcterms:description',
        'pool' => [],
        'settings' => ['label' => 'Description'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_publisher_txt',
        'alias' => '',
        'source' => 'dcterms:publisher',
        'pool' => [],
        'settings' => ['label' => 'Publisher'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'dcterms_contributor_txt',
        'alias' => '',
        'source' => 'dcterms:contributor',
        'pool' => [],
        'settings' => ['label' => 'Contributor'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_date_txt',
        'alias' => '',
        'source' => 'dcterms:date',
        'pool' => [],
        'settings' => ['label' => 'Date'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_type_txt',
        'alias' => '',
        'source' => 'dcterms:type',
        'pool' => [],
        'settings' => ['label' => 'Type'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_format_txt',
        'alias' => '',
        'source' => 'dcterms:format',
        'pool' => [],
        'settings' => ['label' => 'Format'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_identifier_txt',
        'alias' => '',
        'source' => 'dcterms:identifier',
        'pool' => [],
        'settings' => ['label' => 'Identifier'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_source_txt',
        'alias' => '',
        'source' => 'dcterms:source',
        'pool' => [],
        'settings' => ['label' => 'Source'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_language_txt',
        'alias' => '',
        'source' => 'dcterms:language',
        'pool' => [],
        'settings' => ['label' => 'Language'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_relation_txt',
        'alias' => '',
        'source' => 'dcterms:relation',
        'pool' => [],
        'settings' => ['label' => 'Relation'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_coverage_txt',
        'alias' => '',
        'source' => 'dcterms:coverage',
        'pool' => [],
        'settings' => ['label' => 'Coverage'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_rights_txt',
        'alias' => '',
        'source' => 'dcterms:rights',
        'pool' => [],
        'settings' => ['label' => 'Rights'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_spatial_txt',
        'alias' => '',
        'source' => 'dcterms:spatial',
        'pool' => [],
        'settings' => ['label' => 'Spatial coverage'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_temporal_txt',
        'alias' => '',
        'source' => 'dcterms:temporal',
        'pool' => [],
        'settings' => ['label' => 'Temporal coverage'],
    ],
    // Dublin Core Terms.
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_spatial_ss',
        'alias' => '',
        'source' => 'dcterms:spatial',
        'pool' => [],
        'settings' => ['label' => 'Spatial coverage'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_temporal_dr',
        'alias' => '',
        'source' => 'dcterms:temporal',
        'pool' => [],
        'settings' => ['formatter' => 'date_range', 'label' => 'Temporal coverage'],
    ],

    // Fields for filters and facets.
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_type_ss',
        'alias' => '',
        'source' => 'dcterms:type',
        'pool' => [],
        'settings' => ['label' => 'Type'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_subject_ss',
        'alias' => '',
        'source' => 'dcterms:subject',
        'pool' => [],
        'settings' => ['label' => 'Subject'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_creator_ss',
        'alias' => '',
        'source' => 'dcterms:creator',
        'pool' => [],
        'settings' => ['label' => 'Creator'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_publisher_ss',
        'alias' => '',
        'source' => 'dcterms:publisher',
        'pool' => [],
        'settings' => ['label' => 'Publisher'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_language_ss',
        'alias' => '',
        'source' => 'dcterms:language',
        'pool' => [],
        'settings' => ['label' => 'Language'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_rights_ss',
        'alias' => '',
        'source' => 'dcterms:rights',
        'pool' => [],
        'settings' => ['label' => 'Rights'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'item_set_title_ss',
        'alias' => 'item_set_title',
        'source' => 'item_set/o:title',
        'pool' => [],
        'settings' => ['label' => 'Item Set'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'property_values_txt',
        'alias' => '',
        'source' => 'property_values',
        'pool' => [],
        'settings' => ['formatter' => 'alphanumeric', 'label' => 'Values'],
    ],

    // Fields to sort.
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_title_s',
        'alias' => '',
        'source' => 'dcterms:title',
        'pool' => [],
        'settings' => ['label' => 'Title'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_date_s',
        'alias' => '',
        'source' => 'dcterms:date',
        'pool' => [],
        'settings' => ['label' => 'Date'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'dcterms_creator_s',
        'alias' => '',
        'source' => 'dcterms:creator',
        'pool' => [],
        'settings' => ['label' => 'Creator'],
    ],

    // Items.

    // Required fields.
    [
        'resource_name' => 'items',
        'field_name' => 'item_set_id_is',
        'alias' => 'item_set_id',
        'source' => 'item_set/o:id',
        'pool' => [],
        'settings' => ['label' => 'Item set id'],
    ],

    // Item sets.
    // Nothing specific.
];
