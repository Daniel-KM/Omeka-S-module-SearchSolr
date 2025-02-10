<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2017-2025
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

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
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
 * @copyright Daniel Berthereau, 2017-2025
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
        require_once __DIR__ . '/vendor/autoload.php';

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

        // Manage the dependency upon Search, in particular when upgrading.
        // Once disabled, this current method and other ones are no more called.
        if (!$this->isModuleActive('AdvancedSearch')) {
            $this->disableModule(__NAMESPACE__);
            return;
        }

        $this->addAclRules();
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.66')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.66'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        if (!file_exists(__DIR__ . '/vendor/solarium/solarium/src/Client.php')) {
            $message = new PsrMessage(
                'The composer library "{library}" is not installed. See readme.', // @translate
                ['library' => 'Solarium']
            );
            throw new ModuleCannotInstallException((string) $message->setTransalor($translator));
        }

        if (!$this->checkModuleActiveVersion('AdvancedSearch', '3.4.41')) {
            $message = new PsrMessage(
                $translator->translate('This module requires module "{module}" version "{version}" or greater.'), // @translate
                ['module' => 'Advanced Search', 'version' => '3.4.41']
            );
            throw new ModuleCannotInstallException((string) $message);
        }
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
     * Add ACL rules for this module.
     */
    protected function addAclRules(): void
    {
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(null, [
            \SearchSolr\Api\Adapter\SolrCoreAdapter::class,
            \SearchSolr\Api\Adapter\SolrMapAdapter::class,
        ]);
        $acl->allow(null, \SearchSolr\Entity\SolrCore::class, 'read');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \AdvancedSearch\Controller\Admin\IndexController::class,
            'view.browse.after',
            [$this, 'appendBrowseCores']
        );

        $sharedEventManager->attach(
            Api\Adapter\SolrCoreAdapter::class,
            'api.delete.post',
            [$this, 'deletePostSolrCore']
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
    }

    public function appendBrowseCores(Event $event): void
    {
        $view = $event->getTarget();
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $response = $api->search('solr_cores');
        $cores = $response->getContent();
        echo $view->partial('search-solr/admin/core/browse-table', [
            'cores' => $cores,
        ]);
    }

    public function deletePostSolrCore(Event $event): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $id = $request->getId();
        $searchEngines = $this->searchSearchEnginesByCoreId($id);
        if (empty($searchEngines)) {
            return;
        }
        $api->batchDelete('search_engines', array_keys($searchEngines), [], ['continueOnError' => true]);
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

    /**
     * Find all search indexes related to a specific solr core.
     *
     * @todo Factorize searchSearchEngines() from core with CoreController.
     * @param int $solrCoreId
     * @return SearchEngineRepresentation[] Result is indexed by id.
     */
    protected function searchSearchEnginesByCoreId($solrCoreId)
    {
        $result = [];
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        /** @var \AdvancedSearch\Api\Representation\SearchEngineRepresentation[] $searchEngines */
        $searchEngines = $api->search('search_engines', ['adapter' => 'solarium'])->getContent();
        foreach ($searchEngines as $searchEngine) {
            if ($searchEngine->settingEngineAdapter('solr_core_id') == $solrCoreId) {
                $result[$searchEngine->id()] = $searchEngine;
            }
        }
        return $result;
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
        // TODO Use entity manager to simplify search of pages from core.
        $result = [];
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $searchEngines = $this->searchSearchEnginesByCoreId($solrCoreId);
        foreach ($searchEngines as $searchEngine) {
            $searchConfigs = $api->search('search_configs', ['engine_id' => $searchEngine->id()])->getContent();
            foreach ($searchConfigs as $searchConfig) {
                $result[$searchConfig->id()] = $searchConfig;
            }
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

        // Check if the internal index exists.
        $sqlSolrCoreId = <<<'SQL'
            SELECT `id`
            FROM `solr_core`
            ORDER BY `id` ASC
            SQL;
        $solrCoreId = (int) $connection->fetchColumn($sqlSolrCoreId);
        if ($solrCoreId) {
            return;
        }

        // Set the default server id, used in some cases (shared core with Drupal).
        $settings = $services->get('Omeka\Settings');
        $serverId = strtolower(substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(128))), 0, 6));
        $settings->set('searchsolr_server_id', $serverId);

        // Install a default config.
        $sql = <<<'SQL'
            INSERT INTO `solr_core` (`name`, `settings`)
            VALUES (?, ?);
            SQL;
        $solrCoreData = require __DIR__ . '/data/solr_cores/default.php';
        $connection->executeStatement($sql, [
            $solrCoreData['o:name'],
            json_encode($solrCoreData['o:settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $solrCoreId = (int) $connection->fetchColumn($sqlSolrCoreId);

        // Install a default mapping.
        $sql = <<<'SQL'
            INSERT INTO `solr_map` (`solr_core_id`, `resource_name`, `field_name`, `source`, `pool`, `settings`)
            VALUES (?, ?, ?, ?, ?, ?);
            SQL;
        $defaultMaps = require __DIR__ . '/config/default_mappings.php';
        foreach ($defaultMaps as $map) {
            $connection->executeStatement($sql, [
                $solrCoreId,
                $map['resource_name'],
                $map['field_name'],
                $map['source'],
                json_encode($map['pool'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($map['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }

        $message = new \Omeka\Stdlib\Message(
            'The default core can be configured in the %1$ssearch manager%2$s.', // @translate
            // Don't use the url helper, the route is not available during install.
            sprintf('<a href="%s">', $urlHelper('admin') . '/search-manager/solr/core/' . $solrCoreId . '/edit'),
            '</a>'
        );
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);
    }
}
