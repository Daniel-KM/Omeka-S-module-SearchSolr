<?php
namespace Solr;

return [
    'api_adapters' => [
        'invokables' => [
            'solr_nodes' => Api\Adapter\SolrNodeAdapter::class,
            'solr_mappings' => Api\Adapter\SolrMappingAdapter::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\Admin\SolrNodeForm::class => Service\Form\SolrNodeFormFactory::class,
            Form\Admin\SolrMappingForm::class => Service\Form\SolrMappingFormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            Controller\Admin\NodeController::class => Controller\Admin\NodeController::class,
        ],
        'factories' => [
            Controller\Admin\MappingController::class => Service\Controller\MappingControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            'Solr\ValueExtractorManager' => Service\ValueExtractorManagerFactory::class,
            'Solr\ValueFormatterManager' => Service\ValueFormatterManagerFactory::class,
            'Solr\Schema' => Service\SchemaFactory::class,
        ],
    ],
    'navigation' => [
        'AdminGlobal' => [
            'search' => [
                // Copy of the first level of navigation from the config of the module Search.
                // It avoids an error when Search is automatically disabled for upgrading. This errors occurs one time only anyway.
                'label' => 'Search', // @translate
                'route' => 'admin/search',
                'resource' => \Search\Controller\Admin\IndexController::class,
                'privilege' => 'browse',
                'class' => 'o-icon-search',
                'pages' => [
                    [
                        'label' => 'Solr', // @translate
                        'route' => 'admin/solr',
                        'resource' => Controller\Admin\NodeController::class,
                        'privilege' => 'browse',
                        // 'class' => 'o-icon-search',
                        'pages' => [
                            [
                                'route' => 'admin/solr/node',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/solr/node-id',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/solr/node-id-mapping',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/solr/node-id-mapping-resource',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/solr/node-id-mapping-resource-id',
                                'visible' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'solr' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/solr',
                            'defaults' => [
                                '__NAMESPACE__' => 'Solr\Controller\Admin',
                                'controller' => Controller\Admin\NodeController::class,
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'node' => [
                                'type' => \Zend\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/node[/:action]',
                                    'defaults' => [
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                            'node-id' => [
                                'type' => \Zend\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/node/:id[/:action]',
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'show',
                                    ],
                                ],
                            ],
                            'node-id-mapping' => [
                                'type' => \Zend\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/node/:nodeId/mapping',
                                    'defaults' => [
                                        'controller' => Controller\Admin\MappingController::class,
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                            'node-id-mapping-resource' => [
                                'type' => \Zend\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/node/:nodeId/mapping/:resourceName[/:action]',
                                    'defaults' => [
                                        'controller' => Controller\Admin\MappingController::class,
                                        'action' => 'browseResource',
                                    ],
                                ],
                            ],
                            'node-id-mapping-resource-id' => [
                                'type' => \Zend\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/node/:nodeId/mapping/:resourceName/:id[/:action]',
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'controller' => Controller\Admin\MappingController::class,
                                        'action' => 'show',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'js_translate_strings' => [
        'Field', // @translate
        'Type', // @translate
        'Set sub-property', // @translate
        'Choose a field…', // @translate
        'Dynamic field', // @translate
    ],
    'search_adapters' => [
        'factories' => [
            'solr' => Service\Adapter\SolrAdapterFactory::class,
        ],
    ],
    'solr_value_extractors' => [
        'factories' => [
            'items' => Service\ValueExtractor\ItemValueExtractorFactory::class,
            'item_sets' => Service\ValueExtractor\ItemSetValueExtractorFactory::class,
        ],
    ],
    'solr_value_formatters' => [
        'invokables' => [
            'date_range' => ValueFormatter\DateRange::class,
            'plain_text' => ValueFormatter\PlainText::class,
        ],
    ],
];
