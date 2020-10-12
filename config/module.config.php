<?php
namespace SearchSolr;

return [
    'api_adapters' => [
        'invokables' => [
            'solr_cores' => Api\Adapter\SolrCoreAdapter::class,
            'solr_maps' => Api\Adapter\SolrMapAdapter::class,
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
        'invokables' => [
            Form\Admin\ConfigFieldset::class => Form\Admin\ConfigFieldset::class,
            Form\Admin\SolrCoreMappingImportForm::class => Form\Admin\SolrCoreMappingImportForm::class,
            Form\Admin\SourceFieldset::class => Form\Admin\SourceFieldset::class,
        ],
        'factories' => [
            Form\Admin\SolrCoreForm::class => Service\Form\SolrCoreFormFactory::class,
            Form\Admin\SolrMapForm::class => Service\Form\SolrMapFormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            Controller\Admin\CoreController::class => Controller\Admin\CoreController::class,
        ],
        'factories' => [
            Controller\Admin\MapController::class => Service\Controller\MapControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            'SearchSolr\ValueExtractorManager' => Service\ValueExtractorManagerFactory::class,
            'SearchSolr\ValueFormatterManager' => Service\ValueFormatterManagerFactory::class,
            'SearchSolr\Schema' => Service\SchemaFactory::class,
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
                        'route' => 'admin/search/solr',
                        'resource' => Controller\Admin\CoreController::class,
                        'privilege' => 'browse',
                        // 'class' => 'o-icon-search',
                        'pages' => [
                            [
                                'route' => 'admin/search/solr/core',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/search/solr/core-id',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/search/solr/core-id-map',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/search/solr/core-id-map-resource',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/search/solr/core-id-map-resource-id',
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
                    'search' => [
                        // Kept to simplify upgrade.
                        'type' => \Zend\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/search-manager',
                            'defaults' => [
                                '__NAMESPACE__' => 'Search\Controller\Admin',
                                'controller' => \Search\Controller\Admin\IndexController::class,
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'solr' => [
                                'type' => \Zend\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/solr',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'SearchSolr\Controller\Admin',
                                        'controller' => Controller\Admin\CoreController::class,
                                        'action' => 'browse',
                                    ],
                                ],
                                'may_terminate' => true,
                                'child_routes' => [
                                    'core' => [
                                        'type' => \Zend\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '/core[/:action]',
                                            'defaults' => [
                                                'action' => 'browse',
                                            ],
                                        ],
                                    ],
                                    'core-id' => [
                                        'type' => \Zend\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '/core/:id[/:action]',
                                            'constraints' => [
                                                'id' => '\d+',
                                            ],
                                            'defaults' => [
                                                'action' => 'show',
                                            ],
                                        ],
                                    ],
                                    'core-id-map' => [
                                        'type' => \Zend\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '/core/:coreId/map',
                                            'constraints' => [
                                                'coreId' => '\d+',
                                            ],
                                            'defaults' => [
                                                'controller' => Controller\Admin\MapController::class,
                                                'action' => 'browse',
                                            ],
                                        ],
                                    ],
                                    'core-id-map-resource' => [
                                        'type' => \Zend\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '/core/:coreId/map/:resourceName[/:action]',
                                            'constraints' => [
                                                'coreId' => '\d+',
                                            ],
                                            'defaults' => [
                                                'controller' => Controller\Admin\MapController::class,
                                                'action' => 'browseResource',
                                            ],
                                        ],
                                    ],
                                    'core-id-map-resource-id' => [
                                        'type' => \Zend\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '/core/:coreId/map/:resourceName/:id[/:action]',
                                            'constraints' => [
                                                'coreId' => '\d+',
                                                'id' => '\d+',
                                            ],
                                            'defaults' => [
                                                'controller' => Controller\Admin\MapController::class,
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
            'solarium' => Service\Adapter\SolariumAdapterFactory::class,
        ],
    ],
    'searchsolr' => [
        'config' => [
            'searchsolr_server_id' => null,
        ],
    ],
    'searchsolr_value_extractors' => [
        'factories' => [
            'items' => Service\ValueExtractor\ItemValueExtractorFactory::class,
            'item_sets' => Service\ValueExtractor\ItemSetValueExtractorFactory::class,
        ],
    ],
    'searchsolr_value_formatters' => [
        'invokables' => [
            'date' => ValueFormatter\Date::class,
            'date_range' => ValueFormatter\DateRange::class,
            'plain_text' => ValueFormatter\PlainText::class,
            'point' => ValueFormatter\Point::class,
        ],
    ],
];
