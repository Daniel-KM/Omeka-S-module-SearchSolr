<?php
return [
    'controllers' => [
        'invokables' => [
            'Solr\Controller\Admin\Node' => 'Solr\Controller\Admin\NodeController',
        ],
        'factories' => [
            'Solr\Controller\Admin\Mapping' => 'Solr\Service\Controller\MappingControllerFactory',
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            __DIR__ . '/../src/Entity',
        ],
        'proxy_paths' => [
            __DIR__ . '/../data/doctrine-proxies',
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            'solr_nodes' => 'Solr\Api\Adapter\SolrNodeAdapter',
            'solr_mappings' => 'Solr\Api\Adapter\SolrMappingAdapter',
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
            'Solr\Form\Admin\SolrNodeForm' => 'Solr\Service\Form\SolrNodeFormFactory',
            'Solr\Form\Admin\SolrMappingForm' => 'Solr\Service\Form\SolrMappingFormFactory',
        ],
    ],
    'router' => [
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
                            'node-id-mapping' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/node/:nodeId/mapping',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Mapping',
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                            'node-id-mapping-resource' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/node/:nodeId/mapping/:resourceName[/:action]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Mapping',
                                        'action' => 'browseResource',
                                    ],
                                ],
                            ],
                            'node-id-mapping-resource-id' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/node/:nodeId/mapping/:resourceName/:id[/:action]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Mapping',
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
            'Solr\Schema' => 'Solr\Service\SchemaFactory',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
    'search_adapters' => [
        'factories' => [
            'solr' => 'Solr\Service\AdapterFactory',
        ],
    ],
    'solr_value_extractors' => [
        'factories' => [
            'items' => 'Solr\Service\ValueExtractor\ItemValueExtractorFactory',
            'item_sets' => 'Solr\Service\ValueExtractor\ItemSetValueExtractorFactory',
        ],
    ],
    'solr_value_formatters' => [
        'invokables' => [
            'date_range' => 'Solr\ValueFormatter\DateRange',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => __DIR__ . '/../language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
];
