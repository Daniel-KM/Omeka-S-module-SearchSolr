<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2017-2021
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

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use AdvancedSearch\Api\Representation\SearchConfigRepresentation;
use AdvancedSearch\Api\Representation\SearchEngineRepresentation;
use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Omeka\Module\Exception\ModuleCannotInstallException;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependency = 'AdvancedSearch';

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
        if (!$this->isModuleActive($this->dependency)) {
            $this->disableModule(__NAMESPACE__);
            return;
        }

        $this->addAclRules();
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        if (!file_exists(__DIR__ . '/vendor/solarium/solarium/src/Client.php')) {
            $translator = $services->get('MvcTranslator');
            $message = sprintf($translator->translate('The composer library "%s" is not installed. See readme.'), 'Solarium'); // @translate
            throw new ModuleCannotInstallException($message);
        }
    }

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();

        $settings = $services->get('Omeka\Settings');
        $serverId = strtolower(substr(str_replace(['+', '/'], '', base64_encode(random_bytes(20))), 0, 6));
        $settings->set('searchsolr_server_id', $serverId);

        // Upgrade from old module Solr if any, else install a default config.
        $connection = $services->get('Omeka\Connection');

        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Solr');
        if ($module
            && version_compare($module->getIni('version') ?? '', '3.5.5', '>=')
            && version_compare($module->getIni('version') ?? '', '3.5.14', '<=')
        ) {
            // Check if Solr was really installed.
            try {
                $connection->fetchAll('SELECT id FROM solr_node LIMIT 1;');
                // So upgrade Solr.
                $filepath = $this->modulePath() . '/data/scripts/upgrade_from_solr.php';
                require_once $filepath;
                return;
            } catch (\Exception $e) {
            }
        }

        // Install a default config.
        $sql = <<<'SQL'
INSERT INTO `solr_core` (`name`, `settings`)
VALUES ("default", ?);
SQL;
        $defaultSettings = $this->getSolrCoreDefaultSettings();
        $connection->executeQuery($sql, [json_encode($defaultSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

        $sql = <<<'SQL'
INSERT INTO `solr_map` (`solr_core_id`, `resource_name`, `field_name`, `source`, `pool`, `settings`)
VALUES (1, ?, ?, ?, ?, ?);
SQL;
        $defaultMaps = $this->getDefaultSolrMaps();
        foreach ($defaultMaps as $map) {
            $connection->executeQuery($sql, [
                $map['resource_name'],
                $map['field_name'],
                $map['source'],
                json_encode($map['pool'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($map['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    protected function preUninstall(): void
    {
        $serviceLocator = $this->getServiceLocator();
        $moduleManager = $serviceLocator->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('AdvancedSearch');
        if ($module && in_array($module->getState(), [
            \Omeka\Module\Manager::STATE_ACTIVE,
            \Omeka\Module\Manager::STATE_NOT_ACTIVE,
        ])) {
            $sql = <<<'SQL'
DELETE FROM `search_engine` WHERE `adapter` = 'solarium';
SQL;
        }
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec($sql);
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
            if ($searchEngine->settingAdapter('solr_core_id') == $solrCoreId) {
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

    protected function getSolrCoreDefaultSettings()
    {
        return [
            'client' => [
                'scheme' => 'http',
                'host' => 'localhost',
                'port' => 8983,
                'path' => '/',
                // 'collection' => null,
                'core' => 'omeka',
                'username' => null,
                'password' => null,
            ],
            'is_public_field' => 'is_public_b',
            'resource_name_field' => 'resource_name_s',
            'sites_field' => 'site_id_is',
        ];
    }

    protected function getDefaultSolrMaps()
    {
        return include __DIR__ . '/config/default_mappings.php';
    }
}
