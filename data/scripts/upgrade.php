<?php

namespace SearchSolr;

/**
 * @var Module $this
 * @var \Zend\ServiceManager\ServiceLocatorInterface $serviceLocator
 * @var string $oldVersion
 * @var string $newVersion
 */
$services = $serviceLocator;

/**
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Omeka\Api\Manager $api
 * @var array $config
 */
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$api = $services->get('Omeka\ApiManager');
$config = require dirname(dirname(__DIR__)) . '/config/module.config.php';

if (version_compare($oldVersion, '3.5.15.2', '<')) {
    $sql = <<<SQL
CREATE INDEX `IDX_39A565C527B35A195103DEBC` ON `solr_map` (`solr_core_id`, `resource_name`);
SQL;
    $connection->exec($sql);
    $sql = <<<SQL
CREATE INDEX `IDX_39A565C527B35A194DEF17BC` ON `solr_map` (`solr_core_id`, `field_name`);
SQL;
    $connection->exec($sql);
    $sql = <<<SQL
CREATE INDEX `IDX_39A565C527B35A195F8A7F73` ON `solr_map` (`solr_core_id`, `source`);
SQL;
    $connection->exec($sql);

    $serverId = strtolower(substr(str_replace(['+', '/'], '', base64_encode(random_bytes(20))), 0, 6));
    $settings->set('searchsolr_server_id', $serverId);

    $messenger = new \Omeka\Mvc\Controller\Plugin\Messenger();
    $messenger->addWarning('You should reindex your Solr cores.'); // @translate
}
