<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2017-2026
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace SearchSolr;

// Load the module dependencies when installed as a zip.
// With composer, libraries are stored in omeka vendor/ and the module has none.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Common may be installed but not registered in autoloader, in particular
// during upgrade. So dynamically register all classes of the module.
if (!defined('COMMON_PSR4_FALLBACK')) {
    foreach ([
        OMEKA_PATH . '/modules/Common/src',
        OMEKA_PATH . '/composer-addons/modules/Common/src',
        dirname(__DIR__) . '/Common/src',
    ] as $commonSrc) {
        if (file_exists($commonSrc . '/TraitModule.php')) {
            define('COMMON_PSR4_FALLBACK', $commonSrc);
            spl_autoload_register(static function ($class): void {
                if (str_starts_with($class, 'Common\\')) {
                    $file = COMMON_PSR4_FALLBACK . '/' . strtr(substr($class, 7), '\\', '/') . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
            });
            break;
        }
    }
}

use AdvancedSearch\Api\Representation\SearchConfigRepresentation;
use AdvancedSearch\Api\Representation\SearchEngineRepresentation;
use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;

/**
 * SearchSolr
 *
 * Use search engine Solr with Omeka.
 *
 * @copyright Daniel Berthereau, 2017-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
        'AdvancedSearch',
    ];

    public function init(ModuleManager $moduleManager): void
    {

        // No need to check the dependency upon Search here.
        // Once disabled via onBootstrap(), thiis method is no more called.

        $event = $moduleManager->getEvent();
        $container = $event->getParam('ServiceManager');
        $serviceListener = $container->get('ServiceListener');

        $serviceListener->addServiceManager(
            'SearchSolr\ValueExtractorManager',
            'searchsolr_value_extractors',
            Feature\ValueExtractorProviderInterface::class,
            'getSolrValueExtractorConfig'
        );
        $serviceListener->addServiceManager(
            'SearchSolr\ValueFormatterManager',
            'searchsolr_value_formatters',
            Feature\ValueFormatterProviderInterface::class,
            'getSolrValueFormatterConfig'
        );
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        // Only read and search: without privileges, any anonymous visitor is
        // allowed to create, update and delete maps through the rest api.
        $acl
            ->allow(
                null,
                [
                    \SearchSolr\Api\Adapter\SolrMapAdapter::class,
                ],
                ['read', 'search']
            );
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.90')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.90'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $errors = [];

        if (PHP_VERSION_ID < 80100) {
            $errors[] = (string) (new PsrMessage(
                'This version of module {module} requires a version of php ≥ {version}.', // @translate
                ['module' => 'SearchSolr', 'version' => '8.1']
            ))->setTranslator($translator);
        }

        if (!file_exists(__DIR__ . '/vendor/solarium/solarium/src/Client.php')) {
            $errors[] = (string) (new PsrMessage(
                'The composer library "{library}" is not installed. See readme.', // @translate
                ['library' => 'Solarium']
            ))->setTranslator($translator);
        }

        if (!$this->checkModuleActiveVersion('AdvancedSearch', '3.4.63')) {
            $errors[] = (string) (new PsrMessage(
                'This module requires module "{module}" version "{version}" or greater.', // @translate
                ['module' => 'Advanced Search', 'version' => '3.4.63']
            ))->setTranslator($translator);
        }

        // The module Thesaurus, when installed, should be up to date, else the
        // maps and the queries on thesaurus fields may not work. The check
        // applies whether it is enabled or not, since its data remain, but not
        // to a module only present on the disk.
        if ($this->isModuleInstalled('Thesaurus')
            && !$this->isModuleVersionAtLeast('Thesaurus', '3.4.26')
        ) {
            $errors[] = (string) (new PsrMessage(
                'This module requires module "{module}" version "{version}" or greater.', // @translate
                ['module' => 'Thesaurus', 'version' => '3.4.26']
            ))->setTranslator($translator);
        }

        if ($errors) {
            throw new ModuleCannotInstallException(implode("\n", $errors));
        }
    }

    /**
     * Check if a module is installed, active or not.
     *
     * The module manager returns a module for any directory it finds, so the
     * sole presence of a module says nothing: an archive that was unzipped and
     * never installed, or a module with a broken ini, is returned like an
     * installed one. Only the state tells that the module was really installed,
     * so that its tables, its settings and its data are there, whether it is
     * currently enabled or not.
     *
     * @todo Remove this method once Common 3.4.91, that provides it, is required.
     */
    protected function isModuleInstalled(string $module): bool
    {
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $this->getServiceLocator()->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($module);
        return $module
            && in_array($module->getState(), [
                \Omeka\Module\Manager::STATE_ACTIVE,
                \Omeka\Module\Manager::STATE_NOT_ACTIVE,
                \Omeka\Module\Manager::STATE_NEEDS_UPGRADE,
            ], true);
    }

    protected function postInstall(): void
    {
        $this->installResources();
    }

    protected function postUninstall(): void
    {
        $services = $this->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('AdvancedSearch');
        if ($module && in_array($module->getState(), [
            \Omeka\Module\Manager::STATE_ACTIVE,
            \Omeka\Module\Manager::STATE_NOT_ACTIVE,
        ])) {
            $sql = <<<'SQL'
                DELETE FROM `search_engine` WHERE `adapter` = 'solarium';
                SQL;
            $connection = $services->get('Omeka\Connection');
            $connection->executeStatement($sql);
        }
    }

    /**
     * Forbid the rest api to non-admin users, but keep the internal api.
     *
     * The check is done on the adapter and not on the controller or the route,
     * because only the adapter throws the exception inside the api action, so
     * the error is rendered as a json 403 and not as a html 500.
     */
    public function denyRestApiToNonAdmin(Event $event): void
    {
        $services = $this->getServiceLocator();
        if (!$services->get('Omeka\Status')->isApiRequest()) {
            return;
        }

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        if ($user && $services->get('Omeka\Acl')->isAdminRole($user->getRole())) {
            return;
        }

        throw new \Omeka\Api\Exception\PermissionDeniedException(
            (string) new PsrMessage(
                'The resource "{resource}" is not available through the rest api.', // @translate
                ['resource' => $event->getTarget()->getResourceName()]
            )
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // The url, the status and the actions of each core are displayed in the
        // table of the search engines of the search manager.
        $sharedEventManager->attach(
            \AdvancedSearch\Controller\Admin\IndexController::class,
            'view.browse.engine_details',
            [$this, 'filterBrowseEngineDetails']
        );

        // An engine is a real backend: forbid two solarium engines on the same
        // solr core.
        $sharedEventManager->attach(
            \AdvancedSearch\Api\Adapter\SearchEngineAdapter::class,
            'api.hydrate.post',
            [$this, 'validateSingleEnginePerCore']
        );

        // The maps describe the internal structure of the solr index, so they
        // are administrative resources and they are not published via the rest
        // api, that is available to anonymous visitors by default. The internal
        // api is not impacted.
        foreach (['api.search.pre', 'api.read.pre'] as $event) {
            $sharedEventManager->attach(
                \SearchSolr\Api\Adapter\SolrMapAdapter::class,
                $event,
                [$this, 'denyRestApiToNonAdmin']
            );
        }

        // Handle suggester form for Solr engines.
        $sharedEventManager->attach(
            \AdvancedSearch\Form\Admin\SearchSuggesterForm::class,
            'form.add_elements',
            [$this, 'handleSuggesterFormAddElements']
        );

        // Append the tab "Solr" to the form of a search page bound to a
        // solarium engine (query relevance settings).
        $sharedEventManager->attach(
            \AdvancedSearch\Form\Admin\SearchConfigConfigureForm::class,
            'form.add_elements',
            [$this, 'handleSearchConfigFormAddElements']
        );

        // Handle suggester save for Solr engines.
        $sharedEventManager->attach(
            \AdvancedSearch\Controller\Admin\SearchSuggesterController::class,
            'advancedsearch.suggester.save',
            [$this, 'handleSuggesterSave']
        );

        // Handle suggester reindex for Solr engines.
        $sharedEventManager->attach(
            \AdvancedSearch\Controller\Admin\SearchSuggesterController::class,
            'advancedsearch.suggester.index',
            [$this, 'handleSuggesterIndex']
        );

        $sharedEventManager->attach(
            Api\Adapter\SolrMapAdapter::class,
            'api.update.pre',
            [$this, 'preSolrMap']
        );
        $sharedEventManager->attach(
            Api\Adapter\SolrMapAdapter::class,
            'api.delete.pre',
            [$this, 'preSolrMap']
        );
        $sharedEventManager->attach(
            Api\Adapter\SolrMapAdapter::class,
            'api.update.post',
            [$this, 'updatePostSolrMap']
        );
        $sharedEventManager->attach(
            Api\Adapter\SolrMapAdapter::class,
            'api.delete.post',
            [$this, 'deletePostSolrMap']
        );

        // Append solr documents in admin.
        $controllers = [
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\ItemSet',
            'Omeka\Controller\Admin\Media',
        ];
        foreach ($controllers as $controller) {
            $sharedEventManager->attach(
                $controller,
                'view.show.sidebar',
                [$this, 'handleViewShowAfterAdmin']
            );
            $sharedEventManager->attach(
                $controller,
                'view.details',
                [$this, 'handleViewShowAfterAdmin']
            );
            $sharedEventManager->attach(
                $controller,
                'view.browse.after',
                [$this, 'handleViewBrowseAfterAdmin']
            );
        }
        $sharedEventManager->attach(
            \AdvancedSearch\Controller\SearchController::class,
            'view.browse.after',
            [$this, 'appendBrowseAfterAdmin']
        );
    }

    public function filterBrowseEngineDetails(Event $event): void
    {
        // A core is a facet of a solarium engine: fill the url, the status and
        // the core actions of each solarium engine in the table of the search
        // engines.
        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        $services = $this->getServiceLocator();
        $translate = $view->plugin('translate');
        $escape = $view->plugin('escapeHtml');
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        $hyperlink = $view->plugin('hyperlink');

        $solrCores = [];
        foreach ($event->getParam('searchEngines', []) as $engine) {
            if ($engine->engineAdapterName() === 'solarium') {
                $solrCores[$engine->id()] = new \SearchSolr\Stdlib\SolrCore($engine, $services);
            }
        }
        if (!$solrCores) {
            return;
        }

        // Two cores on the same physical core write to the same index and
        // schema, so one clobbers the other and a reindex wipes both: flag the
        // shared urls.
        $coreUrlUsage = [];
        foreach ($solrCores as $solrCore) {
            $coreUrlUsage[$solrCore->clientUrlAdmin()] = ($coreUrlUsage[$solrCore->clientUrlAdmin()] ?? 0) + 1;
        }

        $details = $event->getParam('details', []);
        foreach ($solrCores as $engineId => $solrCore) {
            $urlHtml = $hyperlink($solrCore->clientUrlAdmin(), $solrCore->clientUrlAdminBoard(), [
                'target' => '_blank',
                'title' => $translate('Solr admin interface, if reachable'),
            ]);
            if ($coreUrlUsage[$solrCore->clientUrlAdmin()] > 1) {
                $urlHtml .= ' <span class="field-generic o-icon-warning" title="'
                    . $escapeAttr($translate('This url is shared by another core. Two cores on the same physical core conflict on the schema and the reindex.')) // @translate
                    . '">' . $escape($translate('shared url')) . '</span>';
            }
            $actions = [
                '<li>' . $solrCore->link('', 'edit', [
                    'class' => 'o-icon- fas fa-plug',
                    'title' => $translate('Edit the Solr connection'), // @translate
                ]) . '</li>',
                '<li>' . $hyperlink('', $solrCore->adminUrl(), [
                    'class' => 'o-icon- far fa-sun',
                    'title' => $translate('Map Omeka metadata and Solr indices'), // @translate
                ]) . '</li>',
                '<li>' . $solrCore->link('', 'show-indexing-stats', [
                    'class' => 'o-icon- far fa-chart-bar',
                    'title' => $translate('View indexing statistics'), // @translate
                ]) . '</li>',
            ];
            // The parity between the api and the index costs some hundreds of
            // milliseconds, too much for each display of the search manager, so
            // it is filled by javascript from the cached result.
            $statusHtml = $escape((string) $solrCore->status(true)) . ' '
                . sprintf('<span class="solr-parity" data-url="%1$s" data-label-ok="%2$s" data-label-mismatch="%3$s" data-label-error="%4$s" data-label-stale="%5$s" data-label-stale-only="%6$s" title="%7$s"></span>',
                    $escapeAttr($solrCore->adminUrl('check-parity')),
                    $escapeAttr($translate('index complete')), // @translate
                    $escapeAttr($translate('index incomplete')), // @translate
                    $escapeAttr($translate('index unreachable')), // @translate
                    $escapeAttr($translate('outdated')), // @translate
                    $escapeAttr($translate('index complete but outdated')), // @translate
                    $escapeAttr($translate('Resources of the api compared with the documents of the index: total of the api / total of the index, and the documents that were not reindexed after a change of their resource. An indirect change, through a linked resource or an item set, is not detected: only a full reindexation fixes such documents.')) // @translate
                );
            $details[$engineId] = [
                'url' => $urlHtml,
                'status' => $statusHtml,
                'actions' => $actions,
            ];
        }
        $event->setParam('details', $details);

        $view->headScript()->appendFile(
            $view->assetUrl('js/search-solr-parity.js', 'SearchSolr'),
            'text/javascript',
            ['defer' => 'defer']
        );
    }

    /**
     * Forbid two solarium engines on the same solr core: an engine is a real
     * backend, so one engine per core. The visibility is a property of the
     * query context, not of the engine.
     */
    public function validateSingleEnginePerCore(Event $event): void
    {
        /** @var \AdvancedSearch\Entity\SearchEngine $entity */
        $entity = $event->getParam('entity');
        if ($entity->getAdapter() !== 'solarium') {
            return;
        }
        // An engine is a real backend: forbid two engines on the same
        // physical Solr core (same scheme, host, port and core name), since
        // they would write to one index and schema, so one overwrites the
        // other and a reindex wipes both.
        $endpoint = $this->engineEndpointKey($entity->getSettings() ?: []);
        if ($endpoint === '') {
            return;
        }
        /** @var \Omeka\Stdlib\ErrorStore $errorStore */
        $errorStore = $event->getParam('errorStore');
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        foreach ($entityManager->getRepository(\AdvancedSearch\Entity\SearchEngine::class)->findBy(['adapter' => 'solarium']) as $other) {
            if ($other->getId() === $entity->getId()) {
                continue;
            }
            if ($this->engineEndpointKey($other->getSettings() ?: []) === $endpoint) {
                $errorStore->addError('o:settings', new \Omeka\Stdlib\Message(
                    'The engine "%s" already uses this Solr endpoint. Give each engine its own physical Solr core (a distinct core name in the url).', // @translate
                    $other->getName()
                ));
                break;
            }
        }
    }

    /**
     * Normalized Solr endpoint of an engine: scheme + host + port + core.
     * Empty when the host or the core is missing.
     */
    protected function engineEndpointKey(array $engineSettings): string
    {
        $client = $engineSettings['solr']['client'] ?? [];
        $host = trim((string) ($client['host'] ?? ''));
        $core = trim((string) ($client['core'] ?? ''));
        if ($host === '' || $core === '') {
            return '';
        }
        return strtolower(sprintf(
            '%s://%s:%s/%s',
            $client['scheme'] ?? '',
            $host,
            $client['port'] ?? '',
            $core
        ));
    }

    /**
     * Add Solr-specific fields to the suggester form.
     */
    /**
     * List the indices of the core of a search config, to fill a key picker.
     *
     * @return array Field names as keys, with an empty default value, since the
     * multiplier is chosen by the user.
     */
    protected function listCoreFieldNames(
        \AdvancedSearch\Api\Representation\SearchConfigRepresentation $searchConfig
    ): array {
        $solrCore = new \SearchSolr\Stdlib\SolrCore(
            $searchConfig->searchEngine(),
            $this->getServiceLocator()
        );
        $fieldNames = [];
        foreach ($solrCore->maps() as $map) {
            $fieldNames[] = $map->fieldName();
        }
        $fieldNames = array_unique(array_filter($fieldNames));
        sort($fieldNames);
        return array_fill_keys($fieldNames, '');
    }

    public function handleSearchConfigFormAddElements(Event $event): void
    {
        /** @var \AdvancedSearch\Form\Admin\SearchConfigConfigureForm $form */
        $form = $event->getTarget();

        /** @var \AdvancedSearch\Api\Representation\SearchConfigRepresentation $searchConfig */
        $searchConfig = $form->getOption('search_config');
        if (!$searchConfig
            || $searchConfig->searchEngine()->engineAdapterName() !== 'solarium'
        ) {
            return;
        }

        // The fieldset name "engine" is the reserved section for the engine
        // specific settings of a search page; it is displayed as a tab.
        $fieldset = new \Laminas\Form\Fieldset('engine');
        $fieldset
            ->setLabel('Solr') // @translate
            ->add([
                'name' => 'field_boosts',
                'type' => \Common\Form\Element\ArrayTextarea::class,
                'options' => [
                    'label' => 'Boost multipliers by index', // @translate
                    'as_key_value' => true,
                    // The indices of the core are listed in the picker, so the
                    // name of an index does not need to be typed, but a free
                    // key remains possible for an index managed outside.
                    'pairs_editor' => [
                        'key_label' => 'Index', // @translate
                        'value_label' => 'Multiplier', // @translate
                        'value_type' => 'number',
                        'keys' => $this->listCoreFieldNames($searchConfig),
                        'key_fill' => false,
                    ],
                ],
                'attributes' => [
                    'id' => 'engine_field_boosts',
                    'required' => false,
                    'rows' => 12,
                    'placeholder' => <<<'STRING'
                        dcterms_creator_ss = 100
                        dcterms_creator_txt = 50
                        dcterms_subject_ss = 10
                        dcterms_subject_txt = 5
                        dcterms_description_txt = 0.01
                        bibo_content_txt = 0.001
                        STRING,
                ],
            ])
            ->add([
                'name' => 'minimum_match',
                'type' => \Laminas\Form\Element\Text::class,
                'options' => [
                    'label' => 'Minimum match (or/and)', // @translate
                    'info' => 'Integer "1" means "OR", "100%" means "AND". Complex expressions are possible, like "3<80%". If empty, the solrconfig.xml config is used.', // @translate
                ],
                'attributes' => [
                    'id' => 'engine_minimum_match',
                    'required' => false,
                    'placeholder' => '3<80%',
                ],
            ])
            ->add([
                'name' => 'tie_breaker',
                'type' => \Common\Form\Element\OptionalNumber::class,
                'options' => [
                    'label' => 'Tie breaker', // @translate
                    'info' => 'Increase score according to the number of matched fields. If empty, the solrconfig.xml config is used.', // @translate
                ],
                'attributes' => [
                    'id' => 'engine_tie_breaker',
                    'required' => false,
                    'placeholder' => '0.15',
                    'min' => '0.0',
                    'max' => '1.0',
                    'step' => '0.01',
                ],
            ]);
        $form->add($fieldset);
    }

    public function handleSuggesterFormAddElements(Event $event): void
    {
        /** @var \AdvancedSearch\EngineAdapter\EngineAdapterInterface $engineAdapter */
        $engineAdapter = $event->getParam('engine_adapter');
        if (!$engineAdapter instanceof \SearchSolr\EngineAdapter\Solarium) {
            return;
        }

        /** @var \Laminas\Form\Fieldset $fieldset */
        $fieldset = $event->getParam('fieldset');

        $solrCore = $engineAdapter->getSolrCore();
        $indexFields = $this->getSolrFieldsForSuggester($solrCore);

        // Build optgroups: recommended single indexes first,
        // then individual indexes to group into one suggester.
        $recommended = [
            'suggest_txt' => 'suggest_txt (unified field, recommended)', // @translate
            'auto' => 'All text and string fields', // @translate
        ];
        $fieldOptions = [
            'Recommended' => ['label' => 'Recommended', 'options' => $recommended], // @translate
            'Individual fields' => ['label' => 'Individual fields', 'options' => $indexFields], // @translate
        ];

        $fieldset
            ->add([
                'name' => 'solr_suggester_name',
                'type' => \Laminas\Form\Element\Text::class,
                'options' => [
                    'label' => 'Solr suggester name', // @translate
                    'info' => 'Base name of the suggester component in Solr. If empty, will be auto-generated. When multiple fields are selected, a suffix is added for each field.', // @translate
                ],
                'attributes' => [
                    'id' => 'solr_suggester_name',
                    'placeholder' => 'omeka_suggester', // @translate
                ],
            ])
            ->add([
                'name' => 'solr_fields',
                'type' => \Common\Form\Element\OptionalSelect::class,
                'options' => [
                    'label' => 'Solr fields for suggestions', // @translate
                    'info' => 'The unified field "suggest_txt" is the most efficient option: single suggester, short-value fields only. "All fields" creates one suggester per text/string field (including long values). Individual indexes can be grouped.', // @translate
                    'value_options' => $fieldOptions,
                ],
                'attributes' => [
                    'id' => 'solr_fields',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select fields…', // @translate
                ],
            ])
            ->add([
                'name' => 'solr_lookup_implementation',
                'type' => \Common\Form\Element\OptionalSelect::class,
                'options' => [
                    'label' => 'Algorithm for suggestions', // @translate
                    'value_options' => [
                        'AnalyzingInfixLookupFactory' => 'AnalyzingInfixLookup (matches anywhere)', // @translate
                        'BlendedInfixLookupFactory' => 'BlendedInfixLookup (prefix weighted)', // @translate
                        'AnalyzingLookupFactory' => 'AnalyzingLookup (prefix only)', // @translate
                        'FuzzyLookupFactory' => 'FuzzyLookup (fuzzy matching)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'solr_lookup_implementation',
                    'value' => 'AnalyzingInfixLookupFactory',
                ],
            ])
            ->add([
                'name' => 'solr_skip_build_on_commit',
                'type' => \Laminas\Form\Element\Checkbox::class,
                'options' => [
                    'label' => 'Skip automatic reindex on resource save', // @translate
                    'info' => 'By default, the suggester dictionary is rebuilt each time documents are committed. Check to disable this on very large indexes.', // @translate
                ],
                'attributes' => [
                    'id' => 'solr_skip_build_on_commit',
                ],
            ])
        ;

        // Mark the form as handled so AdvancedSearch doesn't show
        // the "no settings" message.
        $event->setParam('handled', true);
    }

    /**
     * Handle suggester save/reindex for Solr engines.
     *
     * Dispatches a background job to create Solr suggesters and build
     * dictionaries. Field resolution ("auto") is done inside the job.
     */
    public function handleSuggesterSave(Event $event): void
    {
        /** @var \AdvancedSearch\EngineAdapter\EngineAdapterInterface $engineAdapter */
        $engineAdapter = $event->getParam('engine_adapter');
        if (!$engineAdapter instanceof \SearchSolr\EngineAdapter\Solarium) {
            return;
        }

        /** @var \AdvancedSearch\Api\Representation\SearchSuggesterRepresentation $suggester */
        $suggester = $event->getParam('suggester');
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $messenger = $event->getParam('messenger');

        $services = $this->getServiceLocator();

        // Skip if a suggester build job is already running.
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $runningJob = $connection->fetchOne(
            'SELECT id FROM job WHERE class = ? AND status IN (?, ?)',
            [
                \SearchSolr\Job\CreateSolrSuggesters::class,
                \Omeka\Entity\Job::STATUS_STARTING,
                \Omeka\Entity\Job::STATUS_IN_PROGRESS,
            ]
        );
        if ($runningJob) {
            $messenger->addWarning(
                'A suggester build job is already running (job #{job_id}). Skipping.' // @translate
            );
            return;
        }

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\SearchSolr\Job\CreateSolrSuggesters::class, [
            'search_suggester_id' => $suggester->id(),
        ]);

        $urlHelper = $services->get('ViewHelperManager')->get('url');
        $message = new PsrMessage(
            'Processing indexation of Solr suggestions in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf('<a href="%s">', htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', htmlspecialchars($urlHelper('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])))
                    : sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">', htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))),
            ]
        );
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);
    }

    /**
     * Handle suggester reindex for Solr engines.
     */
    public function handleSuggesterIndex(Event $event): void
    {
        $this->handleSuggesterSave($event);
    }

    /**
     * Get stored Solr fields suitable for suggestions.
     *
     * Only stored fields with human-readable values are returned:
     * - `*_txt` (text_general, stored): full text, word-level matching
     * - `*_ss` (strings, stored): exact values (only if no _txt exists
     *   for the same property)
     * - `*_s` (string, stored): idem
     * Fields like `_text_` (not stored) or `*_str` (not stored) are excluded.
     */
    protected function getSolrFieldsForSuggester(
        ?\SearchSolr\Stdlib\SolrCore $solrCore,
        bool $deduplicate = false
    ): array {
        if (!$solrCore) {
            return [];
        }

        $allowedSuffixes = ['_txt', '_ss', '_s'];
        $schema = $solrCore->schema();

        // Collect all matching stored fields.
        $allFields = [];
        $txtPrefixes = [];
        foreach ($solrCore->mapsOrderedByStructure() as $map) {
            $fieldName = $map->fieldName();
            foreach ($allowedSuffixes as $suffix) {
                if (substr($fieldName, -strlen($suffix)) === $suffix) {
                    if (!$schema->getField($fieldName)) {
                        break;
                    }
                    $prefix = substr($fieldName, 0, -strlen($suffix));
                    $allFields[] = [
                        'name' => $fieldName,
                        'suffix' => $suffix,
                        'prefix' => $prefix,
                        'label' => $map->setting('label', ''),
                    ];
                    if ($suffix === '_txt') {
                        $txtPrefixes[$prefix] = true;
                    }
                    break;
                }
            }
        }

        // When deduplicating, skip _ss/_s when _txt exists for the same
        // property prefix (used for "auto" resolution, not for the form).
        $fields = [];
        foreach ($allFields as $field) {
            if ($deduplicate
                && $field['suffix'] !== '_txt'
                && isset($txtPrefixes[$field['prefix']])
            ) {
                continue;
            }
            $fields[$field['name']] = $field['label']
                ? sprintf('%s (%s)', $field['label'], $field['name'])
                : $field['name'];
        }

        return $fields;
    }

    public function preSolrMap(Event $event): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $solrMap = $api->read('solr_maps', $request->getId())->getContent();
        $data = $request->getContent();
        $data['solrMap'] = [
            'solr_core_id' => $solrMap->solrCore()->id(),
            'resource_name' => $solrMap->resourceName(),
            'field_name' => $solrMap->fieldName(),
            'alias' => $solrMap->alias(),
            'source' => $solrMap->source(),
            'settings' => $solrMap->settings(),
        ];
        $request->setContent($data);
    }

    public function updatePostSolrMap(Event $event): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $response = $event->getParam('response');
        $solrMap = $response->getContent();
        $oldSolrMapValues = $request->getValue('solrMap');

        // Quick check if the Solr field name is unchanged.
        $fieldName = $solrMap->getFieldName();
        $oldFieldName = $oldSolrMapValues['field_name'];
        if ($fieldName === $oldFieldName) {
            return;
        }

        $searchConfigs = $this->searchSearchConfigsByCoreId($solrMap->getSolrCore()->getId());
        if (empty($searchConfigs)) {
            return;
        }

        foreach ($searchConfigs as $searchConfig) {
            $searchConfigSettings = $searchConfig->settings();
            foreach ($searchConfigSettings as $key => $value) {
                if (is_array($value)) {
                    if (isset($searchConfigSettings[$key][$oldFieldName])) {
                        $searchConfigSettings[$key][$fieldName] = $searchConfigSettings[$key][$oldFieldName];
                        unset($searchConfigSettings[$key][$oldFieldName]);
                    }
                    if (isset($searchConfigSettings[$key][$oldFieldName . ' asc'])) {
                        $searchConfigSettings[$key][$fieldName . ' asc'] = $searchConfigSettings[$key][$oldFieldName . ' asc'];
                        unset($searchConfigSettings[$key][$oldFieldName]);
                    }
                    if (isset($searchConfigSettings[$key][$oldFieldName . ' desc'])) {
                        $searchConfigSettings[$key][$fieldName . ' desc'] = $searchConfigSettings[$key][$oldFieldName . ' desc'];
                        unset($searchConfigSettings[$key][$oldFieldName]);
                    }
                }
            }
            $api->update(
                'search_configs',
                $searchConfig->id(),
                ['o:settings' => $searchConfigSettings],
                [],
                ['isPartial' => true]
            );
        }
    }

    public function deletePostSolrMap(Event $event): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $solrMapValues = $request->getValue('solrMap');
        $searchConfigs = $this->searchSearchConfigsByCoreId($solrMapValues['solr_core_id']);
        if (empty($searchConfigs)) {
            return;
        }

        $fieldName = $solrMapValues['field_name'];
        foreach ($searchConfigs as $searchConfig) {
            $searchConfigSettings = $searchConfig->settings();
            foreach ($searchConfigSettings as $key => $value) {
                if (is_array($value)) {
                    unset($searchConfigSettings[$key][$fieldName]);
                    unset($searchConfigSettings[$key][$fieldName . ' asc']);
                    unset($searchConfigSettings[$key][$fieldName . ' desc']);
                }
            }
            $api->update(
                'search_configs',
                $searchConfig->id(),
                ['o:settings' => $searchConfigSettings],
                [],
                ['isPartial' => true]
            );
        }
    }

    public function handleViewShowAfterAdmin(Event $event): void
    {
        /**
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Permissions\Acl $acl
         */
        $services = $this->getServiceLocator();
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        // TODO Check rights? Useless: the ids are a list of allowed ids.
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        if (!$user || !$acl->isAdminRole($user->getRole())) {
            return;
        }

        $view = $event->getTarget();
        $vars = $view->vars();

        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $resource = $vars->offsetGet('resource');
        if (!$resource) {
            return;
        }

        // Get the solr core configured for admin.
        $solrCore = $this->getSolrCoreAdmin();
        if (!$solrCore) {
            return;
        }

        $vars->offsetSet('heading', $view->translate('Solr')); // @translate
        $vars->offsetSet('resourceName', $resource->resourceName());
        $vars->offsetSet('ids', [$resource->id()]);
        $vars->offsetSet('solrCore', $solrCore);
        echo $view->partial('common/solr-documents-sidebar');
    }

    public function handleViewBrowseAfterAdmin(Event $event): void
    {
        /**
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Permissions\Acl $acl
         */
        $services = $this->getServiceLocator();
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        // TODO Check rights? Useless: the ids are a list of allowed ids.
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        if (!$user || !$acl->isAdminRole($user->getRole())) {
            return;
        }

        $view = $event->getTarget();
        $vars = $view->vars();

        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation[] $resources */
        $resources = $vars->offsetGet('resources');
        if (!$resources) {
            return;
        }

        // Get the solr core configured for admin.
        $solrCore = $this->getSolrCoreAdmin();
        if (!$solrCore) {
            return;
        }

        $ids = [];
        foreach ($resources as $resource) {
            $ids[] = $resource->id();
        }

        $vars->offsetSet('resourceName', $resource->resourceName());
        $vars->offsetSet('ids', $ids);
        $vars->offsetSet('solrCore', $solrCore);
        echo $view->partial('common/solr-documents-link');
    }

    public function appendBrowseAfterAdmin(Event $event): void
    {
        /**
         * @var \Omeka\Mvc\Status $status
         */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        if (!$status->isAdminRequest()) {
            return;
        }

        /** @var \AdvancedSearch\Response $respoonse */
        $view = $event->getTarget();
        $response = $view->response;
        $view->resources = $response->getResources();
        $this->handleViewBrowseAfterAdmin($event);
    }

    /**
     * Adapted:
     * @see \SearchSolr\Module::getSearchConfigAdmin()
     * @see \SearchSolr\Controller\Admin\CoreController::getSearchConfigAdmin()
     */
    protected function getSearchConfigAdmin(): ?SearchConfigRepresentation
    {
        /**
         * @var \Omeka\Api\Manager $api
         * @var \Common\Stdlib\EasyMeta $easyMeta
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $searchConfig = $settings->get('advancedsearch_main_config');
        if (!$searchConfig) {
            return null;
        }

        $api = $services->get('Omeka\ApiManager');
        try {
            return $api->read('search_configs', [is_numeric($searchConfig) ? 'id' : 'slug' => $searchConfig])->getContent();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getSolrCoreAdmin(): ?\SearchSolr\Stdlib\SolrCore
    {
        $searchConfig = $this->getSearchConfigAdmin();
        if (!$searchConfig) {
            return null;
        }

        $engineAdapter = $searchConfig->engineAdapter();
        return $engineAdapter instanceof \SearchSolr\EngineAdapter\Solarium
            ? $engineAdapter->getSolrCore()
            : null;
    }

    /**
     * Find all search pages that use a specific solr core id.
     *
     * @todo Factorize searchSearchConfigs() from core with CoreController.
     * @param int $solrCoreId
     * @return SearchConfigRepresentation[] Result is indexed by id.
     */
    protected function searchSearchConfigsByCoreId($solrCoreId)
    {
        // A core is a facet of its engine (1:1): the core id is the engine id.
        $result = [];
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $searchConfigs = $api->search('search_configs', ['engine_id' => (int) $solrCoreId])->getContent();
        foreach ($searchConfigs as $searchConfig) {
            $result[$searchConfig->id()] = $searchConfig;
        }
        return $result;
    }

    protected function installResources(): void
    {
        $this->createDefaultSolrConfig();
    }

    protected function createDefaultSolrConfig(): void
    {
        // Note: during installation or upgrade, the api may not be available
        // for the search api adapters, so use direct sql queries.

        $services = $this->getServiceLocator();

        $urlHelper = $services->get('ViewHelperManager')->get('url');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        // Check if a solarium engine exists (a core is a facet of it).
        $sqlEngineId = <<<'SQL'
            SELECT `id`
            FROM `search_engine`
            WHERE `adapter` = 'solarium'
            ORDER BY `id` ASC
            SQL;
        $solrCoreId = (int) $connection->fetchOne($sqlEngineId);
        if ($solrCoreId) {
            return;
        }

        // Set the default server id, used in some cases (shared
        // core with Drupal).
        $settings = $services->get('Omeka\Settings');
        $serverId = strtolower(substr(strtr(base64_encode(random_bytes(128)), ['+' => '', '/' => '', '=' => '']), 0, 6));
        $settings->set('searchsolr_server_id', $serverId);

        // Install the default engine, whose settings carry the connection
        // under "solr".
        $solrCoreData = require __DIR__ . '/data/configs/solr_core.default.php';
        $searchEngineData = require __DIR__ . '/data/configs/search_engine.solr.php';
        $engineSettings = $searchEngineData['o:settings'];
        $engineSettings['solr'] = $solrCoreData['o:settings'];
        $connection->executeStatement(
            'INSERT INTO `search_engine` (`name`, `adapter`, `settings`, `created`, `modified`) VALUES (?, ?, ?, NOW(), NOW());',
            [$solrCoreData['o:name'], 'solarium', json_encode($engineSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
        );
        $solrCoreId = (int) $connection->lastInsertId();

        // Install a default mapping.
        $sql = <<<'SQL'
            INSERT INTO `solr_map` (`engine_id`, `resource_name`, `field_name`, `alias`, `source`, `pool`, `settings`)
            VALUES (?, ?, ?, ?, ?, ?, ?);
            SQL;
        $defaultMaps = require __DIR__ . '/config/default_mappings.php';
        foreach ($defaultMaps as $map) {
            $connection->executeStatement($sql, [
                $solrCoreId,
                $map['resource_name'],
                $map['field_name'],
                $map['alias'],
                $map['source'],
                json_encode($map['pool'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($map['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }

        $message = new \Omeka\Stdlib\Message(
            'The default core can be configured in the %1$ssearch manager%2$s.', // @translate
            // Don't use the url helper, the route is not available
            // during install.
            sprintf('<a href="%s">', htmlspecialchars($urlHelper('admin') . '/search-manager/solr/core/' . $solrCoreId . '/edit')),
            '</a>'
        );
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);

        // Create the Solr engines sharing the core and a suggester.
        $this->createDefaultSearchEngines($connection, $solrCoreId, $messenger, $urlHelper);
    }

    /**
     * Create the single Solr search engine of a core, a suggester and the api
     * config.
     *
     * An engine is a real backend: one engine per core. The visibility (public
     * on sites, user rights in admin and api) is a property of the query
     * context, applied by the querier, not of the engine. Idempotent, usable
     * both at install and upgrade.
     */
    protected function createDefaultSearchEngines(
        \Doctrine\DBAL\Connection $connection,
        int $engineId,
        $messenger,
        $urlHelper
    ): void {
        if (!$engineId) {
            return;
        }
        $this->createDefaultSuggester($connection, $engineId, $messenger);

        // A minimal search config bound to the engine, so the admin only has
        // to select it in the main settings (advancedsearch_api_config) to
        // redirect the api searches to Solr, once the core is indexed. The api
        // queries are normalized generically, so the config carries nothing but
        // the engine. Idempotent by slug; the setting is never set
        // automatically.
        $apiConfigId = (int) $connection->fetchOne(
            "SELECT `id` FROM `search_config` WHERE `slug` = 'api'"
        );
        if (!$apiConfigId) {
            $connection->executeStatement(
                "INSERT INTO `search_config` (`engine_id`, `name`, `slug`, `form_adapter`, `settings`, `created`) VALUES (?, 'Api', 'api', 'main', '{}', NOW());",
                [$engineId]
            );
            $message = new \Omeka\Stdlib\Message(
                'A search config "Api" bound to the Solr engine has been created. To speed up the admin api searches (for example the linked resources sidebar), select it in the %1$smain settings%2$s once the core is indexed.', // @translate
                sprintf('<a href="%s">', htmlspecialchars($urlHelper('admin') . '/setting#advancedsearch_api_config')),
                '</a>'
            );
            $message->setEscapeHtml(false);
            $messenger->addSuccess($message);
        }

        // For a strong protection against private metadata leak on public
        // sites, recommend a dedicated public core: the shared core holds
        // private data and the public search (and the suggester, built from
        // the same index) is protected only by the query filter. A second core
        // indexed from public resources only, with its own engine, is the sole
        // protection independent of the query filters.
        $messenger->addNotice(new \Omeka\Stdlib\Message(
            'The public search is protected by the query filter. For a strong protection against private metadata leak, you can create a second core indexed from public resources only, with its own engine, and use it for the public search pages and suggesters.' // @translate
        ));
    }

    /**
     * Create a default suggester for the Solr search engine.
     */
    protected function createDefaultSuggester(
        \Doctrine\DBAL\Connection $connection,
        int $searchEngineId,
        $messenger
    ): void {
        // Check if a suggester already exists for this engine.
        $sqlSuggesterId = <<<'SQL'
            SELECT `id`
            FROM `search_suggester`
            WHERE `engine_id` = ?
            ORDER BY `id` ASC
            SQL;
        $suggesterId = (int) $connection->fetchOne($sqlSuggesterId, [$searchEngineId]);
        if ($suggesterId) {
            return;
        }

        // Load default suggester config.
        $suggesterData = require __DIR__ . '/data/configs/search_suggester.solr.php';

        $sql = <<<'SQL'
            INSERT INTO `search_suggester` (`engine_id`, `name`, `settings`, `created`, `modified`)
            VALUES (?, ?, ?, NOW(), NOW());
            SQL;
        $connection->executeStatement($sql, [
            $searchEngineId,
            $suggesterData['o:name'],
            json_encode($suggesterData['o:settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $messenger->addSuccess(new \Omeka\Stdlib\Message(
            'A default Solr suggester has been created.' // @translate
        ));
    }
}
