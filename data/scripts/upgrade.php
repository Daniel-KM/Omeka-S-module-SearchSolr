<?php
namespace Solr;

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

if (version_compare($oldVersion, '0.1.1', '<')) {
    $sql = '
        CREATE TABLE IF NOT EXISTS `solr_node` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `settings` text,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ';
    $connection->exec($sql);
    $sql = '
        INSERT INTO `solr_node` (`name`, `settings`)
        VALUES ("default", ?)
    ';
    $defaultSettings = $this->getSolrNodeDefaultSettings();
    $connection->executeQuery($sql, [json_encode($defaultSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    $solrNodeId = $connection->lastInsertId();

    $sql = '
        SELECT CONSTRAINT_NAME
        FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME = ?
            AND CONSTRAINT_TYPE = ?
    ';
    $constraintName = $connection->fetchColumn($sql,
        [$connection->getDatabase(), 'solr_field', 'FOREIGN KEY']);

    $connection->exec('
        ALTER TABLE `solr_field`
        CHANGE COLUMN `label` `description` varchar(255) NULL DEFAULT NULL
    ');
    $connection->exec("
        ALTER TABLE `solr_field`
        DROP FOREIGN KEY `$constraintName`
    ");
    $connection->exec('
        ALTER TABLE `solr_field`
        DROP COLUMN `property_id`
    ');

    $connection->exec('
        ALTER TABLE `solr_field`
        ADD COLUMN `solr_node_id` int(11) unsigned NULL AFTER `id`
    ');
    $connection->executeQuery('
        UPDATE `solr_field`
        SET `solr_node_id` = ?
    ', [$solrNodeId]);
    $connection->exec('
        ALTER TABLE `solr_field`
        MODIFY `solr_node_id` int(11) unsigned NOT NULL
    ');

    $connection->exec('
        ALTER TABLE `solr_field`
        ADD CONSTRAINT `solr_field_fk_solr_node_id`
            FOREIGN KEY (`solr_node_id`) REFERENCES `solr_node` (`id`)
            ON DELETE RESTRICT ON UPDATE CASCADE
    ');

    $connection->exec('
        CREATE TABLE IF NOT EXISTS `solr_profile` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `solr_node_id` int(11) unsigned NOT NULL,
            `resource_name` varchar(255) NOT NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT `solr_profile_fk_solr_node_id`
                FOREIGN KEY (`solr_node_id`) REFERENCES `solr_node` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ');

    $connection->exec('
        CREATE TABLE IF NOT EXISTS `solr_profile_rule` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `solr_profile_id` int(11) unsigned NOT NULL,
            `solr_field_id` int(11) unsigned NOT NULL,
            `source` varchar(255) NOT NULL,
            `settings` text,
            PRIMARY KEY (`id`),
            CONSTRAINT `solr_profile_rule_fk_solr_profile_id`
                FOREIGN KEY (`solr_profile_id`) REFERENCES `solr_profile` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `solr_profile_rule_fk_solr_field_id`
                FOREIGN KEY (`solr_field_id`) REFERENCES `solr_field` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ');
}

if (version_compare($oldVersion, '0.2.0', '<')) {
    $connection->exec('
        CREATE TABLE IF NOT EXISTS `solr_mapping` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `solr_node_id` int(11) unsigned NOT NULL,
            `resource_name` varchar(255) NOT NULL,
            `field_name` varchar(255) NOT NULL,
            `source` varchar(255) NOT NULL,
            `settings` text,
            PRIMARY KEY (`id`),
            CONSTRAINT `solr_mapping_fk_solr_node_id`
                FOREIGN KEY (`solr_node_id`) REFERENCES `solr_node` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ');

    $connection->exec('
        INSERT INTO `solr_mapping` (`solr_node_id`, `resource_name`, `field_name`, `source`, `settings`)
        SELECT solr_node.id, solr_profile.resource_name, solr_field.name, solr_profile_rule.source, solr_profile_rule.settings
        FROM solr_profile_rule
            LEFT JOIN solr_profile ON (solr_profile_rule.solr_profile_id = solr_profile.id)
            LEFT JOIN solr_node ON (solr_profile.solr_node_id = solr_node.id)
            LEFT JOIN solr_field ON (solr_profile_rule.solr_field_id = solr_field.id)
    ');

    $connection->exec('DROP TABLE IF EXISTS `solr_profile_rule`');
    $connection->exec('DROP TABLE IF EXISTS `solr_profile`');
    $connection->exec('DROP TABLE IF EXISTS `solr_field`');
}

if (version_compare($oldVersion, '0.5.0', '<')) {
    $sql = <<<'SQL'
ALTER TABLE solr_mapping DROP FOREIGN KEY solr_mapping_fk_solr_node_id;
ALTER TABLE solr_node CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE settings settings LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)';
ALTER TABLE solr_mapping CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE solr_node_id solr_node_id INT NOT NULL, CHANGE settings settings LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)';
DROP INDEX solr_mapping_fk_solr_node_id ON solr_mapping;
CREATE INDEX IDX_A62FEAA6A9C459FB ON solr_mapping (solr_node_id);
ALTER TABLE solr_mapping ADD CONSTRAINT FK_A62FEAA6A9C459FB FOREIGN KEY (solr_node_id) REFERENCES solr_node (id);
SQL;
    $sqls = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($sqls as $sql) {
        $connection->exec($sql);
    }
}

if (version_compare($oldVersion, '0.5.1', '<')) {
    $sql = <<<'SQL'
ALTER TABLE solr_mapping DROP FOREIGN KEY FK_A62FEAA6A9C459FB;
ALTER TABLE solr_mapping ADD CONSTRAINT FK_A62FEAA6A9C459FB FOREIGN KEY (solr_node_id) REFERENCES solr_node (id) ON DELETE CASCADE;
SQL;
    $sqls = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($sqls as $sql) {
        $connection->exec($sql);
    }
}

if (version_compare($oldVersion, '0.5.4', '<')) {
    // Add "is_public" as required index field.
    $sql = <<<SQL
UPDATE solr_node
SET settings = CONCAT('{"is_public_field":"is_public_b",', SUBSTR(settings, 2))
;
SQL;
    $connection->exec($sql);
}

if (version_compare($oldVersion, '3.5.7', '<')) {
    // Replace "item_set/id" by "item_set/o:id" .
    $sql = <<<SQL
UPDATE `solr_mapping`
SET `source` = 'item_set/o:id'
WHERE `source` = 'item_set/id'
;
SQL;
    $connection->exec($sql);
}
