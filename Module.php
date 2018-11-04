<?php

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2017-2018
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

namespace Solr;

use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Search\Api\Representation\SearchIndexRepresentation;
use Search\Api\Representation\SearchPageRepresentation;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\ModuleManager\ModuleManager;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;

class Module extends AbstractModule
{
    protected $dependency = 'Search';

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function init(ModuleManager $moduleManager)
    {
        // No need to check the dependency upon Search here.
        // Once disabled via onBootstrap(), thiis method is no more called.

        $event = $moduleManager->getEvent();
        $container = $event->getParam('ServiceManager');
        $serviceListener = $container->get('ServiceListener');

        $serviceListener->addServiceManager(
            'Solr\ValueExtractorManager',
            'solr_value_extractors',
            Feature\ValueExtractorProviderInterface::class,
            'getSolrValueExtractorConfig'
        );
        $serviceListener->addServiceManager(
            'Solr\ValueFormatterManager',
            'solr_value_formatters',
            Feature\ValueFormatterProviderInterface::class,
            'getSolrValueFormatterConfig'
        );
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        // Manage the dependency upon Search, in particular when upgrading.
        // This simple check is the quickest way to check the Search dependency.
        // Once disabled, this current method and other ones are no more called.
        $services = $event->getApplication()->getServiceManager();
        if (!$this->isModuleActive($services, $this->dependency)) {
            $this->disableModule($services, __NAMESPACE__);
            return;
        }

        $this->addAclRules();
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');

        if (!extension_loaded('solr')) {
            $translator = $serviceLocator->get('MvcTranslator');
            $message = $translator->translate('Solr module requires PHP Solr extension, which is not loaded.');
            throw new ModuleCannotInstallException($message);
        }

        $sql = <<<'SQL'
CREATE TABLE solr_node (
    id INT AUTO_INCREMENT NOT NULL,
    name VARCHAR(255) NOT NULL,
    settings LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)',
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE solr_mapping (
    id INT AUTO_INCREMENT NOT NULL,
    solr_node_id INT NOT NULL,
    resource_name VARCHAR(255) NOT NULL,
    field_name VARCHAR(255) NOT NULL,
    source VARCHAR(255) NOT NULL,
    settings LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)',
    INDEX IDX_A62FEAA6A9C459FB (solr_node_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE solr_mapping ADD CONSTRAINT FK_A62FEAA6A9C459FB FOREIGN KEY (solr_node_id) REFERENCES solr_node (id) ON DELETE CASCADE;
SQL;
        $sqls = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }

        $sql = <<<'SQL'
INSERT INTO `solr_node` (`name`, `settings`)
VALUES ("default", ?);
SQL;
        $defaultSettings = $this->getSolrNodeDefaultSettings();
        $connection->executeQuery($sql, [json_encode($defaultSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

        $sql = <<<'SQL'
INSERT INTO `solr_mapping` (`solr_node_id`, `resource_name`, `field_name`, `source`, `settings`)
VALUES (1, ?, ?, ?, ?);
SQL;
        $defaultMappings = $this->getDefaultSolrMappings();
        foreach ($defaultMappings as $mapping) {
            $connection->executeQuery($sql, [
                $mapping['resource_name'],
                $mapping['field_name'],
                $mapping['source'],
                json_encode($mapping['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $sql = '';
        $moduleManager = $serviceLocator->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Search');
        if ($module && in_array($module->getState(), [
            \Omeka\Module\Manager::STATE_ACTIVE,
            \Omeka\Module\Manager::STATE_NOT_ACTIVE,
        ])) {
            $sql = <<<'SQL'
DELETE FROM `search_index` WHERE `adapter` = 'solr';
SQL;
        }

        $sql .= <<<'SQL'
DROP TABLE IF EXISTS `solr_mapping`;
DROP TABLE IF EXISTS `solr_node`;
SQL;
        $connection = $serviceLocator->get('Omeka\Connection');
        $sqls = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }
    }

    public function upgrade($oldVersion, $newVersion,
        ServiceLocatorInterface $serviceLocator)
    {
        require_once 'data/scripts/upgrade.php';
    }

    /**
     * Check if a module is active.
     *
     * @param ServiceLocatorInterface $services
     * @param string $moduleClass
     * @return bool
     */
    protected function isModuleActive(ServiceLocatorInterface $services, $moduleClass)
    {
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($moduleClass);
        return $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
    }

    protected function disableModule(ServiceLocatorInterface $services, $moduleClass)
    {
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($moduleClass);
        $moduleManager->deactivate($module);

        $translator = $services->get('MvcTranslator');
        $message = new \Omeka\Stdlib\Message(
            $translator->translate('The module "%s" was automatically deactivated because the dependencies are unavailable.'), // @translate
            $moduleClass
        );
        $messenger = new \Omeka\Mvc\Controller\Plugin\Messenger();
        $messenger->addWarning($message);

        $logger = $services->get('Omeka\Logger');
        $logger->warn($message);
    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules()
    {
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(null, [
            \Solr\Api\Adapter\SolrNodeAdapter::class,
            \Solr\Api\Adapter\SolrMappingAdapter::class,
        ]);
        $acl->allow(null, \Solr\Entity\SolrNode::class, 'read');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            Api\Adapter\SolrNodeAdapter::class,
            'api.delete.post',
            [$this, 'deletePostSolrNode']
        );
        $sharedEventManager->attach(
            Api\Adapter\SolrMappingAdapter::class,
            'api.update.pre',
            [$this, 'preSolrMapping']
        );
        $sharedEventManager->attach(
            Api\Adapter\SolrMappingAdapter::class,
            'api.delete.pre',
            [$this, 'preSolrMapping']
        );
        $sharedEventManager->attach(
            Api\Adapter\SolrMappingAdapter::class,
            'api.update.post',
            [$this, 'updatePostSolrMapping']
        );
        $sharedEventManager->attach(
            Api\Adapter\SolrMappingAdapter::class,
            'api.delete.post',
            [$this, 'deletePostSolrMapping']
        );
    }

    public function deletePostSolrNode(Event $event)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $id = $request->getId();
        $searchIndexes = $this->searchSearchIndexesByNodeId($id);
        if (empty($searchIndexes)) {
            return;
        }
        $api->batchDelete('search_indexes', array_keys($searchIndexes), [], ['continueOnError' => true]);
    }

    public function preSolrMapping(Event $event)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $solrMapping = $api->read('solr_mappings', $request->getId())->getContent();
        $data = $request->getContent();
        $data['solrMapping'] = [
            'solr_node_id' => $solrMapping->solrNode()->id(),
            'resource_name' => $solrMapping->resourceName(),
            'field_name' => $solrMapping->fieldName(),
            'source' => $solrMapping->source(),
            'settings' => $solrMapping->settings(),
        ];
        $request->setContent($data);
    }

    public function updatePostSolrMapping(Event $event)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $response = $event->getParam('response');
        $solrMapping = $response->getContent();
        $oldSolrMappingValues = $request->getValue('solrMapping');

        // Quick check if the Solr field name is unchanged.
        $fieldName = $solrMapping->getFieldName();
        $oldFieldName = $oldSolrMappingValues['field_name'];
        if ($fieldName === $oldFieldName) {
            return;
        }

        $searchPages = $this->searchSearchPagesByNodeId($solrMapping->getSolrNode()->getId());
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

    public function deletePostSolrMapping(Event $event)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $solrMappingValues = $request->getValue('solrMapping');
        $searchPages = $this->searchSearchPagesByNodeId($solrMappingValues['solr_node_id']);
        if (empty($searchPages)) {
            return;
        }

        $fieldName = $solrMappingValues['field_name'];
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
     * Find all search indexes related to a specific solr node.
     *
     * @todo Factorize searchSearchIndexes() from node with NodeController.
     * @param int $solrNodeId
     * @return SearchIndexRepresentation[] Result is indexed by id.
     */
    protected function searchSearchIndexesByNodeId($solrNodeId)
    {
        $result = [];
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $searchIndexes = $api->search('search_indexes', ['adapter' => 'solr'])->getContent();
        foreach ($searchIndexes as $searchIndex) {
            $searchIndexSettings = $searchIndex->settings();
            if ($solrNodeId == $searchIndexSettings['adapter']['solr_node_id']) {
                $result[$searchIndex->id()] = $searchIndex;
            }
        }
        return $result;
    }

    /**
     * Find all search pages that use a specific solr node id.
     *
     * @todo Factorize searchSearchPages() from node with NodeController.
     * @param int $solrNodeId
     * @return SearchPageRepresentation[] Result is indexed by id.
     */
    protected function searchSearchPagesByNodeId($solrNodeId)
    {
        // TODO Use entity manager to simplify search of pages from node.
        $result = [];
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $searchIndexes = $this->searchSearchIndexesByNodeId($solrNodeId);
        foreach ($searchIndexes as $searchIndex) {
            $searchPages = $api->search('search_pages', ['index_id' => $searchIndex->id()])->getContent();
            foreach ($searchPages as $searchPage) {
                $result[$searchPage->id()] = $searchPage;
            }
        }
        return $result;
    }

    protected function getSolrNodeDefaultSettings()
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

    protected function getDefaultSolrMappings()
    {
        return include __DIR__ . '/config/default_mappings.php';
    }
}
