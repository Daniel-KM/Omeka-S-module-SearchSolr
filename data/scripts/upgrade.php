<?php declare(strict_types=1);

namespace SearchSolr;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $serviceLocator
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
$config = require dirname(__DIR__, 2) . '/config/module.config.php';

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

if (version_compare($oldVersion, '3.5.15.3.6', '<')) {
    $sql = <<<SQL
ALTER TABLE `solr_map` ADD `data_types` LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)' AFTER `source`;
SQL;
    $connection->exec($sql);

    $sql = <<<SQL
UPDATE `solr_map` SET `data_types` = "[]";
SQL;
    $connection->exec($sql);

    $sql = <<<SQL
UPDATE `solr_map` SET `source` = REPLACE(`source`, "item_set", "item_sets") WHERE `source` LIKE "%item_set%";
SQL;
    $connection->exec($sql);

    $messenger = new \Omeka\Mvc\Controller\Plugin\Messenger();
    $messenger->addNotice('Now, values can be indexed differently for each data type, if wanted.'); // @translate
    $messenger->addNotice('Use the new import/export tool to simplify config.'); // @translate
}

if (version_compare($oldVersion, '3.5.16.3', '<')) {
    $sql = <<<SQL
ALTER TABLE `solr_core`
CHANGE `settings` `settings` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
SQL;
    $connection->exec($sql);

    $sql = <<<SQL
ALTER TABLE `solr_map`
CHANGE `data_types` `pool` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
CHANGE `settings` `settings` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
SQL;
    $connection->exec($sql);

    $sql = <<<SQL
UPDATE `solr_map`
SET `pool` = "[]"
WHERE `pool` = "[]" OR `pool` = "{}" OR `pool` = "" OR `pool` IS NULL;
SQL;
    $connection->exec($sql);

    $sql = <<<SQL
UPDATE `solr_map`
SET `pool` = CONCAT('{"data_types":', `pool`, "}")
WHERE `pool` != "[]" AND `pool` IS NOT NULL;
SQL;
    $connection->exec($sql);

    // Keep the standard formatter to simplify improvment.
    $sql = <<<SQL
UPDATE `solr_map`
SET `settings` = REPLACE(`settings`, '"formatter":"standard_no_uri"', '"formatter":"standard_without_uri"')
WHERE `settings` LIKE '%"formatter":"standard_no_uri"%';
SQL;
    $connection->exec($sql);
    $sql = <<<SQL
UPDATE `solr_map`
SET `settings` = REPLACE(`settings`, '"formatter":"uri_only"', '"formatter":"uri"')
WHERE `settings` LIKE '%"formatter":"uri_only"%';
SQL;
    $connection->exec($sql);
}
