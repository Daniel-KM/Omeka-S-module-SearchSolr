<?php declare(strict_types=1);

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
            Form\Admin\SolrConfigFieldset::class => Form\Admin\SolrConfigFieldset::class,
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
            Controller\ApiController::class => Service\Controller\ApiControllerFactory::class,
            Controller\ApiLocalController::class => Service\Controller\ApiLocalControllerFactory::class,
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
        'AdvancedSearchSolr' => [
            'search' => [
                // Copy of the first level of navigation from the config of the module Search.
                // It avoids an error when Advanced Search is automatically disabled for upgrading. This errors occurs one time only anyway.
                'label' => 'Search manager', // @translate
                'route' => 'admin/search',
                'resource' => \AdvancedSearch\Controller\Admin\IndexController::class,
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
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/search-manager',
                            'defaults' => [
                                '__NAMESPACE__' => 'AdvancedSearch\Controller\Admin',
                                'controller' => \AdvancedSearch\Controller\Admin\IndexController::class,
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'solr' => [
                                'type' => \Laminas\Router\Http\Literal::class,
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
                                        'type' => \Laminas\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '/core[/:action]',
                                            'constraints' => [
                                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                            ],
                                            'defaults' => [
                                                'action' => 'browse',
                                            ],
                                        ],
                                    ],
                                    'core-id' => [
                                        'type' => \Laminas\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '/core/:id[/:action]',
                                            'constraints' => [
                                                'id' => '\d+',
                                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                            ],
                                            'defaults' => [
                                                'action' => 'show',
                                            ],
                                        ],
                                    ],
                                    'core-id-map' => [
                                        'type' => \Laminas\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '/core/:core-id/map',
                                            'constraints' => [
                                                'core-id' => '\d+',
                                            ],
                                            'defaults' => [
                                                'controller' => Controller\Admin\MapController::class,
                                                'action' => 'browse',
                                            ],
                                        ],
                                    ],
                                    'core-id-map-resource' => [
                                        'type' => \Laminas\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '/core/:core-id/map/:resource-name[/:action]',
                                            'constraints' => [
                                                'core-id' => '\d+',
                                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                            ],
                                            'defaults' => [
                                                'controller' => Controller\Admin\MapController::class,
                                                'action' => 'browseResource',
                                            ],
                                        ],
                                    ],
                                    'core-id-map-resource-id' => [
                                        'type' => \Laminas\Router\Http\Segment::class,
                                        'options' => [
                                            'route' => '/core/:core-id/map/:resource-name/:id[/:action]',
                                            'constraints' => [
                                                'core-id' => '\d+',
                                                'id' => '\d+',
                                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                                'resource-name' => '[a-zA-Z][a-zA-Z0-9_-]*',
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
            'api' => [
                'child_routes' => [
                    'search_solr' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:resource[/:id]',
                            'constraints' => [
                                'resource' => 'solr_cores|solr_maps',
                            ],
                            'defaults' => [
                                'controller' => Controller\ApiController::class,
                            ],
                        ],
                    ],
                ],
            ],
            'api-local' => [
                'child_routes' => [
                    'search_solr' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:resource[/:id]',
                            'constraints' => [
                                'resource' => 'solr_cores|solr_maps',
                            ],
                            'defaults' => [
                                'controller' => Controller\ApiLocalController::class,
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
    'advanced_search_engine_adapters' => [
        'factories' => [
            'solarium' => Service\EngineAdapter\SolariumFactory::class,
        ],
    ],
    'searchsolr' => [
        'config' => [
            'searchsolr_server_id' => null,
        ],
    ],
    'searchsolr_value_extractors' => [
        'factories' => [
            'generic' => Service\ValueExtractor\ResourceValueExtractorFactory::class,
            'resources' => Service\ValueExtractor\ResourceValueExtractorFactory::class,
            'items' => Service\ValueExtractor\ResourceValueExtractorFactory::class,
            'item_sets' => Service\ValueExtractor\ResourceValueExtractorFactory::class,
            'media' => Service\ValueExtractor\ResourceValueExtractorFactory::class,
        ],
    ],
    'searchsolr_value_formatters' => [
        'invokables' => [
            'text' => ValueFormatter\Text::class,
            'integer' => ValueFormatter\Integer::class,
            'date' => ValueFormatter\Date::class,
            'date_range' => ValueFormatter\DateRange::class,
            'place' => ValueFormatter\Place::class,
            'point' => ValueFormatter\Point::class,
            'thesaurus' => ValueFormatter\Thesaurus::class,
        ],
    ],
];
