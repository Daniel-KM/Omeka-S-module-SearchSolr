<?php

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2017-2020
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

use Generic\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Search\Api\Representation\SearchIndexRepresentation;
use Search\Api\Representation\SearchPageRepresentation;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\ModuleManager\ModuleManager;
use Zend\Mvc\MvcEvent;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependency = 'Search';

    public function init(ModuleManager $moduleManager)
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

    public function onBootstrap(MvcEvent $event)
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

    protected function preInstall()
    {
        $services = $this->getServiceLocator();
        if (!file_exists(__DIR__ . '/vendor/solarium/solarium/src/Client.php')) {
            $translator = $services->get('MvcTranslator');
            $message = sprintf($translator->translate('The composer library "%s" is not installed. See readme.'), 'Solarium'); // @translate
            throw new ModuleCannotInstallException($message);
        }
    }

    protected function postInstall()
    {
        // Install a default config.
        $serviceLocator = $this->getServiceLocator();
        $connection = $serviceLocator->get('Omeka\Connection');

        $sql = <<<'SQL'
INSERT INTO `solr_core` (`name`, `settings`)
VALUES ("default", ?);
SQL;
        $defaultSettings = $this->getSolrCoreDefaultSettings();
        $connection->executeQuery($sql, [json_encode($defaultSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

        $sql = <<<'SQL'
INSERT INTO `solr_map` (`solr_core_id`, `resource_name`, `field_name`, `source`, `settings`)
VALUES (1, ?, ?, ?, ?);
SQL;
        $defaultMaps = $this->getDefaultSolrMaps();
        foreach ($defaultMaps as $map) {
            $connection->executeQuery($sql, [
                $map['resource_name'],
                $map['field_name'],
                $map['source'],
                json_encode($map['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    protected function preUninstall()
    {
        $serviceLocator = $this->getServiceLocator();
        $moduleManager = $serviceLocator->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Search');
        if ($module && in_array($module->getState(), [
            \Omeka\Module\Manager::STATE_ACTIVE,
            \Omeka\Module\Manager::STATE_NOT_ACTIVE,
        ])) {
            $sql = <<<'SQL'
DELETE FROM `search_index` WHERE `adapter` = 'solarium';
SQL;
        }
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec($sql);
    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules()
    {
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(null, [
            \SearchSolr\Api\Adapter\SolrCoreAdapter::class,
            \SearchSolr\Api\Adapter\SolrMapAdapter::class,
        ]);
        $acl->allow(null, \SearchSolr\Entity\SolrCore::class, 'read');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
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

    public function deletePostSolrCore(Event $event)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $id = $request->getId();
        $searchIndexes = $this->searchSearchIndexesByCoreId($id);
        if (empty($searchIndexes)) {
            return;
        }
        $api->batchDelete('search_indexes', array_keys($searchIndexes), [], ['continueOnError' => true]);
    }

    public function preSolrMap(Event $event)
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

    public function updatePostSolrMap(Event $event)
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

        $searchPages = $this->searchSearchPagesByCoreId($solrMap->getSolrCore()->getId());
        if (empty($searchPages)) {
            return;
        }

        foreach ($searchPages as $searchPage) {
            $searchPageSettings = $searchPage->settings();
            foreach ($searchPageSettings as $key => $value) {
                if (is_array($value)) {
                    if (isset($searchPageSettings[$key][$oldFieldName])) {
                        $searchPageSettings[$key][$fieldName] = $searchPageSettings[$key][$oldFieldName];
                        unset($searchPageSettings[$key][$oldFieldName]);
                    }
                    if (isset($searchPageSettings[$key][$oldFieldName . ' asc'])) {
                        $searchPageSettings[$key][$fieldName . ' asc'] = $searchPageSettings[$key][$oldFieldName . ' asc'];
                        unset($searchPageSettings[$key][$oldFieldName]);
                    }
                    if (isset($searchPageSettings[$key][$oldFieldName . ' desc'])) {
                        $searchPageSettings[$key][$fieldName . ' desc'] = $searchPageSettings[$key][$oldFieldName . ' desc'];
                        unset($searchPageSettings[$key][$oldFieldName]);
                    }
                }
            }
            $api->update(
                'search_pages',
                $searchPage->id(),
                ['o:settings' => $searchPageSettings],
                [],
                ['isPartial' => true]
            );
        }
    }

    public function deletePostSolrMap(Event $event)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $solrMapValues = $request->getValue('solrMap');
        $searchPages = $this->searchSearchPagesByCoreId($solrMapValues['solr_core_id']);
        if (empty($searchPages)) {
            return;
        }

        $fieldName = $solrMapValues['field_name'];
        foreach ($searchPages as $searchPage) {
            $searchPageSettings = $searchPage->settings();
            foreach ($searchPageSettings as $key => $value) {
                if (is_array($value)) {
                    unset($searchPageSettings[$key][$fieldName]);
                    unset($searchPageSettings[$key][$fieldName . ' asc']);
                    unset($searchPageSettings[$key][$fieldName . ' desc']);
                }
            }
            $api->update(
                'search_pages',
                $searchPage->id(),
                ['o:settings' => $searchPageSettings],
                [],
                ['isPartial' => true]
            );
        }
    }

    /**
     * Find all search indexes related to a specific solr core.
     *
     * @todo Factorize searchSearchIndexes() from core with CoreController.
     * @param int $solrCoreId
     * @return SearchIndexRepresentation[] Result is indexed by id.
     */
    protected function searchSearchIndexesByCoreId($solrCoreId)
    {
        $result = [];
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $searchIndexes = $api->search('search_indexes', ['adapter' => 'solarium'])->getContent();
        foreach ($searchIndexes as $searchIndex) {
            $searchIndexSettings = $searchIndex->settings();
            if ($solrCoreId == $searchIndexSettings['adapter']['solr_core_id']) {
                $result[$searchIndex->id()] = $searchIndex;
            }
        }
        return $result;
    }

    /**
     * Find all search pages that use a specific solr core id.
     *
     * @todo Factorize searchSearchPages() from core with CoreController.
     * @param int $solrCoreId
     * @return SearchPageRepresentation[] Result is indexed by id.
     */
    protected function searchSearchPagesByCoreId($solrCoreId)
    {
        // TODO Use entity manager to simplify search of pages from core.
        $result = [];
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $searchIndexes = $this->searchSearchIndexesByCoreId($solrCoreId);
        foreach ($searchIndexes as $searchIndex) {
            $searchPages = $api->search('search_pages', ['index_id' => $searchIndex->id()])->getContent();
            foreach ($searchPages as $searchPage) {
                $result[$searchPage->id()] = $searchPage;
            }
        }
        return $result;
    }

    protected function getSolrCoreDefaultSettings()
    {
        return [
            'client' => [
                'hostname' => 'localhost',
                'port' => 8983,
                'path' => 'solr/omeka',
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
