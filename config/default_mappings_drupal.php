<?php declare(strict_types=1);

/**
 * Generic mapping for Solr adapted for use with Drupal..
 *
 * It should be adapted to specific data, in particular when they are normalized,
 * for example for dates.
 *
 * They can be updated in admin board.
 *
 * @see https://www.drupal.org/project/search_api_solr
 * @see https://github.com/mkalkbrenner/search_api_solr/blob/4.x/solr-conf-templates/9.x/schema.xml#L174-L256
 */

return [
    // Resources.

    // Required specific fields for any type of resource.
    [
        // Api name.
        'resource_name' => 'generic',
        'field_name' => 'ss_resource_name',
        'alias' => 'resource_name',
        'source' => 'resource_name',
        'pool' => [],
        'settings' => ['label' => 'Resource type'],
    ],
    [
        'resource_name' => 'generic',
        'field_name' => 'is_id',
        'alias' => 'id',
        'source' => 'o:id',
        'pool' => [],
        'settings' => ['label' => 'Internal id'],
    ],
    [
        'resource_name' => 'generic',
        'field_name' => 'is_public',
        'alias' => 'is_public',
        'source' => 'is_public',
        'pool' => [],
        'settings' => ['label' => 'Public'],
    ],
    [
        // The generic name of the resource: may be main title, label or name.
        'resource_name' => 'generic',
        'field_name' => 'ss_name',
        'alias' => 'name',
        'source' => 'o:title',
        'pool' => [],
        'settings' => ['label' => 'Name'],
    ],
    [
        'resource_name' => 'generic',
        'field_name' => 'is_owner_id',
        'alias' => 'owner_id',
        'source' => 'owner/o:id',
        'pool' => [],
        'settings' => ['label' => 'Owner'],
    ],
    [
        'resource_name' => 'generic',
        'field_name' => 'im_site_id',
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
        'field_name' => 'ss_resource_class',
        'alias' => 'resource_class_term',
        'source' => 'resource_class/o:term',
        'pool' => [],
        'settings' => ['label' => 'Resource class'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'ss_resource_template',
        'alias' => 'resource_template_label',
        'source' => 'resource_template/o:label',
        'pool' => [],
        'settings' => ['label' => 'Resource template'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'ss_title',
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
        'field_name' => 'twm_dcterms_title',
        'alias' => '',
        'source' => 'dcterms:title',
        'pool' => [],
        'settings' => ['label' => 'Title'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_creator',
        'alias' => '',
        'source' => 'dcterms:creator',
        'pool' => [],
        'settings' => ['label' => 'Creator'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_subject',
        'alias' => '',
        'source' => 'dcterms:subject',
        'pool' => [],
        'settings' => ['label' => 'Subject'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_description',
        'alias' => '',
        'source' => 'dcterms:description',
        'pool' => [],
        'settings' => ['label' => 'Description'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_publisher',
        'alias' => '',
        'source' => 'dcterms:publisher',
        'pool' => [],
        'settings' => ['label' => 'Publisher'],
    ],
    [
        'resource_name' => 'items',
        'field_name' => 'twm_dcterms_contributor',
        'alias' => '',
        'source' => 'dcterms:contributor',
        'pool' => [],
        'settings' => ['label' => 'Contributor'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_date',
        'alias' => '',
        'source' => 'dcterms:date',
        'pool' => [],
        'settings' => ['label' => 'Date'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_type',
        'alias' => '',
        'source' => 'dcterms:type',
        'pool' => [],
        'settings' => ['label' => 'Type'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_format',
        'alias' => '',
        'source' => 'dcterms:format',
        'pool' => [],
        'settings' => ['label' => 'Format'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_identifier',
        'alias' => '',
        'source' => 'dcterms:identifier',
        'pool' => [],
        'settings' => ['label' => 'Identifier'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_source',
        'alias' => '',
        'source' => 'dcterms:source',
        'pool' => [],
        'settings' => ['label' => 'Source'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_language',
        'alias' => '',
        'source' => 'dcterms:language',
        'pool' => [],
        'settings' => ['label' => 'Language'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_relation',
        'alias' => '',
        'source' => 'dcterms:relation',
        'pool' => [],
        'settings' => ['label' => 'Relation'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_coverage',
        'alias' => '',
        'source' => 'dcterms:coverage',
        'pool' => [],
        'settings' => ['label' => 'Coverage'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_rights',
        'alias' => '',
        'source' => 'dcterms:rights',
        'pool' => [],
        'settings' => ['label' => 'Rights'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_spatial',
        'alias' => '',
        'source' => 'dcterms:spatial',
        'pool' => [],
        'settings' => ['label' => 'Spatial coverage'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_dcterms_temporal',
        'alias' => '',
        'source' => 'dcterms:temporal',
        'pool' => [],
        'settings' => ['label' => 'Temporal coverage'],
    ],
    // Dublin Core Terms.
    [
        'resource_name' => 'resources',
        'field_name' => 'sm_dcterms_spatial',
        'alias' => '',
        'source' => 'dcterms:spatial',
        'pool' => [],
        'settings' => ['label' => 'Spatial coverage'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'drs_dcterms_temporal',
        'alias' => '',
        'source' => 'dcterms:temporal',
        'pool' => [],
        'settings' => ['formatter' => 'date_range', 'label' => 'Temporal coverage'],
    ],

    // Fields for filters and facets.
    [
        'resource_name' => 'resources',
        'field_name' => 'sm_dcterms_type',
        'alias' => '',
        'source' => 'dcterms:type',
        'pool' => [],
        'settings' => ['label' => 'Type'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'sm_dcterms_subject',
        'alias' => '',
        'source' => 'dcterms:subject',
        'pool' => [],
        'settings' => ['label' => 'Subject'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'sm_dcterms_creator',
        'alias' => '',
        'source' => 'dcterms:creator',
        'pool' => [],
        'settings' => ['label' => 'Creator'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'sm_dcterms_publisher',
        'alias' => '',
        'source' => 'dcterms:publisher',
        'pool' => [],
        'settings' => ['label' => 'Publisher'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'sm_dcterms_language',
        'alias' => '',
        'source' => 'dcterms:language',
        'pool' => [],
        'settings' => ['label' => 'Language'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'sm_dcterms_rights',
        'alias' => '',
        'source' => 'dcterms:rights',
        'pool' => [],
        'settings' => ['label' => 'Rights'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'sm_item_set_title',
        'alias' => 'item_set_title',
        'source' => 'item_set/o:title',
        'pool' => [],
        'settings' => ['label' => 'Item Set'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'twm_property_values',
        'alias' => '',
        'source' => 'property_values',
        'pool' => [],
        'settings' => ['formatter' => 'alphanumeric', 'label' => 'Values'],
    ],

    // Fields to sort.
    [
        'resource_name' => 'resources',
        'field_name' => 'ss_dcterms_title',
        'alias' => '',
        'source' => 'dcterms:title',
        'pool' => [],
        'settings' => ['label' => 'Title'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'ss_dcterms_date',
        'alias' => '',
        'source' => 'dcterms:date',
        'pool' => [],
        'settings' => ['label' => 'Date'],
    ],
    [
        'resource_name' => 'resources',
        'field_name' => 'ss_dcterms_creator',
        'alias' => '',
        'source' => 'dcterms:creator',
        'pool' => [],
        'settings' => ['label' => 'Creator'],
    ],

    // Items.

    // Required fields.
    [
        'resource_name' => 'items',
        'field_name' => 'im_item_set_id',
        'alias' => 'item_set_id',
        'source' => 'item_set/o:id',
        'pool' => [],
        'settings' => ['label' => 'Item set id'],
    ],

    // Item sets.
    // Nothing specific.
];
