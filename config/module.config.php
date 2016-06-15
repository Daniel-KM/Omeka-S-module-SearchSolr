<?php
return [
    'controllers' => [
        'invokables' => [
            'Solr\Controller\Admin\Node' => 'Solr\Controller\Admin\NodeController',
            'Solr\Controller\Admin\Field' => 'Solr\Controller\Admin\FieldController',
            'Solr\Controller\Admin\Profile' => 'Solr\Controller\Admin\ProfileController',
            'Solr\Controller\Admin\ProfileRule' => 'Solr\Controller\Admin\ProfileRuleController',
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            __DIR__ . '/../src/Entity',
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            'solr_fields' => 'Solr\Api\Adapter\SolrFieldAdapter',
            'solr_nodes' => 'Solr\Api\Adapter\SolrNodeAdapter',
            'solr_profiles' => 'Solr\Api\Adapter\SolrProfileAdapter',
            'solr_profile_rules' => 'Solr\Api\Adapter\SolrProfileRuleAdapter',
        ],
    ],
    'navigation' => [
        'AdminGlobal' => [
            [
                'label' => 'Solr',
                'route' => 'admin/solr',
                'resource' => 'Solr\Controller\Admin\Node',
                'privilege' => 'browse',
                'class' => 'o-icon-search',
            ],
        ],
    ],
    'form_elements' => [
        'factories' => [
            'Solr\Form\Admin\SolrFieldForm' => 'Solr\Service\Form\SolrFieldFormFactory',
            'Solr\Form\Admin\SolrNodeForm' => 'Solr\Service\Form\SolrNodeFormFactory',
            'Solr\Form\Admin\SolrProfileForm' => 'Solr\Service\Form\SolrProfileFormFactory',
            'Solr\Form\Admin\SolrProfileRuleForm' => 'Solr\Service\Form\SolrProfileRuleFormFactory',
        ],
    ],
    'router' =>[
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'solr' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/solr',
                            'defaults' => [
                                '__NAMESPACE__' => 'Solr\Controller\Admin',
                                'controller' => 'Node',
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'node' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/node[/:action]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Node',
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                            'node-id' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/node/:id[/:action]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Node',
                                        'action' => 'show',
                                    ],
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                ],
                            ],
                            'node-id-field' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/node/:id/field[/:action]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Field',
                                        'action' => 'browse',
                                    ],
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                ],
                            ],
                            'field-id' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/field/:id[/:action]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Field',
                                        'action' => 'show',
                                    ],
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                ],
                            ],
                            'node-id-profile' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/node/:id/profile[/:action]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Profile',
                                        'action' => 'browse',
                                    ],
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                ],
                            ],
                            'profile-id' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/profile/:id[/:action]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Profile',
                                        'action' => 'show',
                                    ],
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                ],
                            ],
                            'profile-id-rule' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/profile/:id/rule[/:action]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'ProfileRule',
                                        'action' => 'browse',
                                    ],
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                ],
                            ],
                            'rule-id' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/rule/:id[/:action]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'ProfileRule',
                                        'action' => 'show',
                                    ],
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            'Solr\ValueExtractorManager' => 'Solr\Service\ValueExtractorManagerFactory',
            'Solr\ValueFormatterManager' => 'Solr\Service\ValueFormatterManagerFactory',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
    'search' => [
        'adapters' => [
            'solr' => 'Solr\Adapter',
        ],
    ],
    'solr' => [
        'value_extractors' => [
            'items' => 'Solr\ValueExtractor\ItemValueExtractor',
            'item_sets' => 'Solr\ValueExtractor\ItemSetValueExtractor',
        ],
        'value_formatters' => [
            'date_range' => 'Solr\ValueFormatter\DateRange',
        ],
    ],
];
