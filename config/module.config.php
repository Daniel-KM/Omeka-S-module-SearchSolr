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
            'Solr\Controller\Admin\Node' => Controller\Admin\NodeController::class,
        ],
        'factories' => [
            'Solr\Controller\Admin\Mapping' => Service\Controller\MappingControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            'Solr\ValueExtractorManager' => Service\ValueExtractorManagerFactory::class,
            'Solr\ValueFormatterManager' => Service\ValueFormatterManagerFactory::class,
            Schema::class => Service\SchemaFactory::class,
        ],
    ],
    'navigation' => [
        'AdminGlobal' => [
            [
                'label' => 'Solr', // @translate
                'route' => 'admin/solr',
                'resource' => 'Solr\Controller\Admin\Node',
                'privilege' => 'browse',
                'class' => 'o-icon-search',
            ],
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
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Node',
                                        'action' => 'show',
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
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Solr\Controller\Admin',
                                        'controller' => 'Mapping',
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
        'Choose a field...', // @translate
        'Dynamic field', // @translate
    ],
    'search_adapters' => [
        'factories' => [
            'solr' => Service\AdapterFactory::class,
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
