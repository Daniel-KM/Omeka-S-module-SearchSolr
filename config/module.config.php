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
            'Omeka\Form\Element\DataTypeSelect' => Service\Form\Element\DataTypeSelectFactory::class,
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
        'AdminModule' => [
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
                        // Kept to simplify upgrade.
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
                                'type' => \Laminas\Router\Http\Segment::class,
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
                                            ],
                                            'defaults' => [
                                                'action' => 'show',
                                            ],
                                        ],
                                    ],
                                    'core-id-map' => [
                                        'type' => \Laminas\Router\Http\Segment::class,
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
                                        'type' => \Laminas\Router\Http\Segment::class,
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
                                        'type' => \Laminas\Router\Http\Segment::class,
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
            'items' => Service\ValueExtractor\ResourceValueExtractorFactory::class,
            'item_sets' => Service\ValueExtractor\ResourceValueExtractorFactory::class,
            // 'media' => Service\ValueExtractor\ResourceValueExtractorFactory::class,
        ],
    ],
    'searchsolr_value_formatters' => [
        'invokables' => [
            'date' => ValueFormatter\Date::class,
            'date_range' => ValueFormatter\DateRange::class,
            'plain_text' => ValueFormatter\PlainText::class,
            'point' => ValueFormatter\Point::class,
            'standard' => ValueFormatter\Standard::class,
            'standard_with_uri' => ValueFormatter\StandardWithUri::class,
            'standard_without_uri' => ValueFormatter\StandardWithoutUri::class,
            'uri' => ValueFormatter\Uri::class,
        ],
    ],
];
