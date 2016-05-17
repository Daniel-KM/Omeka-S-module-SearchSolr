<?php
return [
    'controllers' => [
        'invokables' => [
            'Solr\Controller\Admin\Index' => 'Solr\Controller\Admin\IndexController',
            'Solr\Controller\Admin\Field' => 'Solr\Controller\Admin\FieldController',
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
        ],
    ],
    'navigation' => [
        'AdminGlobal' => [
            [
                'label' => 'Solr',
                'route' => 'admin/solr',
                'resource' => 'Solr\Controller\Admin\Index',
                'privilege' => 'browse',
                'class' => 'o-icon-search',
            ],
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
                                'controller' => 'Index',
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'field' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/field/:action',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Field',
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
                        ],
                    ],
                ],
            ],
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
];
