<?php

/*
 * Copyright BibLibre, 2016
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

use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(null, 'Solr\Api\Adapter\SolrFieldAdapter');
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $translator = $serviceLocator->get('MvcTranslator');
        if (!extension_loaded('solr')) {
            $message = $translator->translate("Solr module requires PHP Solr extension, which is not loaded.");
            throw new ModuleCannotInstallException($message);
        }

        $connection = $serviceLocator->get('Omeka\Connection');
        $sql = '
            CREATE TABLE IF NOT EXISTS `solr_field` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `label` varchar(255) NOT NULL,
                `property_id` int(11) NOT NULL,
                `is_indexed` tinyint(1) NOT NULL DEFAULT 1,
                `is_multivalued` tinyint(1) NOT NULL DEFAULT 1,
                `created` datetime NOT NULL,
                `modified` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                FOREIGN KEY (`property_id`) REFERENCES `property` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ';
        $connection->exec($sql);

        $api = $serviceLocator->get('Omeka\ApiManager');
        $titleProperties = $api->search('properties', [
            'term' => 'dcterms:title',
            'limit' => 1,
        ])->getContent();
        $titlePropertyId = $titleProperties[0]->id();
        $sql = '
            INSERT INTO `solr_field`
            (`name`, `label`, `property_id`, `is_multivalued`, `created`)
            VALUES
                ("title_t", "Title", ?, 1, NOW()),
                ("title_s", "Title", ?, 0, NOW())
        ';
        $params = [$titlePropertyId, $titlePropertyId];
        $connection->executeQuery($sql, $params);
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $sql = 'DROP TABLE IF EXISTS `solr_field`';
        $connection->exec($sql);
    }
}
