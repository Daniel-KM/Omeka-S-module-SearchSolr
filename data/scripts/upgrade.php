<?php declare(strict_types=1);

namespace SearchSolr;

use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (version_compare($oldVersion, '3.5.15.2', '<')) {
    $sql = <<<SQL
CREATE INDEX `IDX_39A565C527B35A195103DEBC` ON `solr_map` (`solr_core_id`, `resource_name`);
SQL;
    $connection->executeStatement($sql);
    $sql = <<<SQL
CREATE INDEX `IDX_39A565C527B35A194DEF17BC` ON `solr_map` (`solr_core_id`, `field_name`);
SQL;
    $connection->executeStatement($sql);
    $sql = <<<SQL
CREATE INDEX `IDX_39A565C527B35A195F8A7F73` ON `solr_map` (`solr_core_id`, `source`);
SQL;
    $connection->executeStatement($sql);

    $serverId = strtolower(substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(128))), 0, 6));
    $settings->set('searchsolr_server_id', $serverId);

    $messenger->addWarning('You should reindex your Solr cores.'); // @translate
}

if (version_compare($oldVersion, '3.5.15.3.6', '<')) {
    $sql = <<<SQL
ALTER TABLE `solr_map` ADD `data_types` LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)' AFTER `source`;
SQL;
    $connection->executeStatement($sql);

    $sql = <<<SQL
UPDATE `solr_map` SET `data_types` = "[]";
SQL;
    $connection->executeStatement($sql);

    $sql = <<<SQL
UPDATE `solr_map` SET `source` = REPLACE(`source`, "item_set", "item_sets") WHERE `source` LIKE "%item_set%";
SQL;
    $connection->executeStatement($sql);

    $messenger->addNotice('Now, values can be indexed differently for each data type, if wanted.'); // @translate
    $messenger->addNotice('Use the new import/export tool to simplify config.'); // @translate
}

if (version_compare($oldVersion, '3.5.16.3', '<')) {
    $sql = <<<SQL
ALTER TABLE `solr_core`
CHANGE `settings` `settings` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
SQL;
    $connection->executeStatement($sql);

    $sql = <<<SQL
ALTER TABLE `solr_map` ADD `data_types` LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)' AFTER `source`;
SQL;
    try {
        $connection->executeStatement($sql);
        $sql = <<<SQL
UPDATE `solr_map` SET `data_types` = "[]";
SQL;
        $connection->executeStatement($sql);

        $sql = <<<SQL
UPDATE `solr_map` SET `source` = REPLACE(`source`, "item_set", "item_sets") WHERE `source` LIKE "%item_set%";
SQL;
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
    }

    $sql = <<<SQL
ALTER TABLE `solr_map`
CHANGE `data_types` `pool` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
CHANGE `settings` `settings` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
SQL;
    $connection->executeStatement($sql);

    $sql = <<<SQL
UPDATE `solr_map`
SET `pool` = "[]"
WHERE `pool` = "[]" OR `pool` = "{}" OR `pool` = "" OR `pool` IS NULL;
SQL;
    $connection->executeStatement($sql);

    $sql = <<<SQL
UPDATE `solr_map`
SET `pool` = CONCAT('{"data_types":', `pool`, "}")
WHERE `pool` != "[]" AND `pool` IS NOT NULL;
SQL;
    $connection->executeStatement($sql);

    // Keep the standard formatter to simplify improvment.
    $sql = <<<SQL
UPDATE `solr_map`
SET `settings` = REPLACE(`settings`, '"formatter":"standard_no_uri"', '"formatter":"standard_without_uri"')
WHERE `settings` LIKE '%"formatter":"standard_no_uri"%';
SQL;
    $connection->executeStatement($sql);
    $sql = <<<SQL
UPDATE `solr_map`
SET `settings` = REPLACE(`settings`, '"formatter":"uri_only"', '"formatter":"uri"')
WHERE `settings` LIKE '%"formatter":"uri_only"%';
SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.5.18.3', '<')) {
    $sql = <<<SQL
ALTER TABLE `solr_map`
CHANGE `data_types` `pool` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
    }
}

if (version_compare($oldVersion, '3.5.25.3', '<')) {
    $moduleManager = $services->get('Omeka\ModuleManager');
    /** @var \Omeka\Module\Module $module */
    $module1 = $moduleManager->getModule('Search');
    $missingModule1 = !$module1
            || version_compare($module1->getIni('version') ?? '', '3.5.22.3', '<')
            || $module1->getState() !== \Omeka\Module\Manager::STATE_ACTIVE;
    $module2 = $moduleManager->getModule('AdvancedSearch');
    $missingModule2 = !$module2
            || version_compare($module2->getIni('version') ?? '', '3.3.6', '<')
            || $module2->getState() !== \Omeka\Module\Manager::STATE_ACTIVE;

    if ($missingModule1 && $missingModule2) {
        $message = new Message(
            'This module requires the module "%s", version %s or above.', // @translate
            'Search / AdvancedSearch',
            '3.5.22.3 / 3.3.6'
        );
        throw new ModuleCannotInstallException((string) $message);
    }

    $message = new Message(
        'The auto-suggestion requires a specific url for now.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.5.27.3', '<')) {
    $moduleManager = $services->get('Omeka\ModuleManager');
    /** @var \Omeka\Module\Module $module */
    $module = $moduleManager->getModule('AdvancedSearch');
    if (!$module) {
        $message = new Message(
            'This module requires the module "%s", version %s or above.', // @translate
            'AdvancedSearch',
            '3.3.6'
        );
        throw new ModuleCannotInstallException((string) $message);
    }
}

if (version_compare($oldVersion, '3.5.31.3', '<')) {
    // Fix upgrade issue in 3.5.18.3.
    $sql = <<<SQL
ALTER TABLE `solr_map`
CHANGE `data_types` `pool` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
    }

    $moduleManager = $services->get('Omeka\ModuleManager');
    /** @var \Omeka\Module\Module $module */
    $module = $moduleManager->getModule('AdvancedSearch');
    if (!$module || version_compare($module->getIni('version') ?? '', '3.3.6.7', '<')) {
        $message = new Message(
            'This module requires the module "%s", version %s or above.', // @translate
            'AdvancedSearch',
            '3.3.6.7'
        );
        throw new ModuleCannotInstallException((string) $message);
    }

    // Remove an old option.
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('id', 'settings')
        ->from('solr_core', 'solr_core')
        ->orderBy('id', 'asc');
    $solrCoresSettings = $connection->executeQuery($qb)->fetchAllKeyValue();
    foreach ($solrCoresSettings as $solrCoreId => $solrCoreSettings) {
        $solrCoreSettings = json_decode($solrCoreSettings, true) ?: [];
        unset($solrCoreSettings['site_url']);
        $sql = <<<'SQL'
UPDATE `solr_core`
SET
    `settings` = ?
WHERE
    `id` = ?
;
SQL;
        $connection->executeStatement($sql, [
            json_encode($solrCoreSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $solrCoreId,
        ]);
    }

    // Move generic settings to map and add new ones.
    $fields = [
        'resource_name_field' => [
            'resource_name' => 'generic',
            'field_name' => 'resource_name_s',
            'source' => 'resource_name',
        ],
        'is_public_field' => [
            'resource_name' => 'generic',
            'field_name' => 'is_public_b',
            'source' => 'is_public',
            'settings' => ['formatter' => '', 'label' => 'Public'],
        ],
        'sites_field' => [
            'resource_name' => 'generic',
            'field_name' => 'site_id_is',
            'source' => 'site/o:id',
        ],
        'index_field' => [
            'resource_name' => 'generic',
            // Not required.
            // 'field_name' => 'index_id',
            'field_name' => null,
            'source' => 'search_index',
        ],
        [
            'resource_name' => 'generic',
            'field_name' => 'owner_id_i',
            'source' => 'owner/o:id',
        ],

        [
            'resource_name' => 'resources',
            'field_name' => 'resource_class_id_i',
            'source' => 'resource_class/o:id',
        ],
        [
            'resource_name' => 'resources',
            'field_name' => 'resource_template_id_i',
            'source' => 'resource_template/o:id',
        ],

        [
            'resource_name' => 'items',
            'field_name' => 'item_set_id_is',
            'source' => 'item_set/o:id',
            'settings' => ['formatter' => '', 'label' => 'Item set'],
        ],
    ];
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('id', 'settings')
        ->from('solr_core', 'solr_core')
        ->orderBy('id', 'asc');
    $solrCoresSettings = $connection->executeQuery($qb)->fetchAllKeyValue();
    foreach ($solrCoresSettings as $solrCoreId => $solrCoreSettings) {
        $solrCoreSettings = json_decode($solrCoreSettings, true) ?: [];
        foreach ($fields as $oldName => $newField) {
            $fieldName = $solrCoreSettings[$oldName] ?? $newField['field_name'];
            unset($solrCoreSettings[$oldName]);
            if (!$fieldName) {
                continue;
            }
            $sql = <<<'SQL'
INSERT INTO `solr_map` (`solr_core_id`, `resource_name`, `field_name`, `source`, `pool`, `settings`)
VALUES (?, ?, ?, ?, ?, ?);
SQL;
            $connection->executeStatement($sql, [
                $solrCoreId,
                $newField['resource_name'],
                $fieldName,
                $newField['source'],
                json_encode($newField['pool'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($newField['settings'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
        $sql = <<<'SQL'
UPDATE `solr_core`
SET
    `settings` = ?
WHERE
    `id` = ?
;
SQL;
        $connection->executeStatement($sql, [
            json_encode($solrCoreSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $solrCoreId,
        ]);
    }

    // Rename any source from "item_sets/xxx" into "item_set/xxx".
    $sql = <<<'SQL'
UPDATE `solr_map`
SET `source` = REPLACE(`source`, "item_sets", "item_set")
SQL;
    $connection->executeStatement($sql);

    // Rename "resource_class".
    $sql = <<<'SQL'
UPDATE `solr_map`
SET `source` = "resource_class/o:term"
WHERE `source` = "resource_class";
SQL;
    $connection->executeStatement($sql);

    // Rename "resource_class".
    $sql = <<<'SQL'
UPDATE `solr_map`
SET `source` = "resource_template/o:label"
WHERE `source` = "resource_template";
SQL;
    $connection->executeStatement($sql);

    // Copy all mapping "items" and "item_sets" into "resources", except "item_set/xxx".
    $sql = <<<'SQL'
INSERT INTO `solr_map` (`solr_core_id`, `resource_name`, `field_name`, `source`, `pool`, `settings`)
SELECT `solr_core_id`, "resources", `field_name`, `source`, `pool`, `settings`
FROM `solr_map`
WHERE `resource_name` != "generic"
    AND `resource_name` != "resource"
    AND `source` NOT LIKE "item_set%"
;
SQL;
    $connection->executeStatement($sql);

    // Remove duplicate mappings
    $sql = <<<'SQL'
DELETE `t1` FROM `solr_map` as `t1`
INNER JOIN `solr_map` as `t2`
WHERE
    `t1`.`id` > `t2`.`id`
    AND `t1`.`solr_core_id` = `t2`.`solr_core_id`
    AND `t1`.`resource_name` = `t2`.`resource_name`
    AND `t1`.`field_name` = `t2`.`field_name`
    AND `t1`.`source` = `t2`.`source`
    AND `t1`.`pool` = `t2`.`pool`
    AND `t1`.`settings` = `t2`.`settings`
;
SQL;
    $connection->executeStatement($sql);

    $message = new Message(
        'The resource types are now structured to simplify config: "generic" and "resource" allow to set mapping for any resource.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'All mapping for items and item sets have been copied to resources.' // @translate
    );
    $messenger->addWarning($message);
    $message = new Message(
        'It is recommended to check mappings, to remove the useless and duplicate ones, and to run a full reindexation.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.5.33.3', '<')) {
    $message = new Message(
        'It is now possible to index original and thumbnails urls.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.5.37.3', '<')) {
    $translator = $services->get('MvcTranslator');

    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $advancedSearchModule = $moduleManager->getModule('AdvancedSearch');
    if (!$advancedSearchModule) {
        $message = new Message(
            $translator->translate('This module requires module "%s" version "%s" or greater.'), // @translate
            'Advanced Search', '3.3.6.16'
        );
        throw new ModuleCannotInstallException((string) $message);
    }
    $advancedSearchVersion = $advancedSearchModule->getIni('version');
    if (version_compare($advancedSearchVersion, '3.3.6.16', '<')) {
        $message = new Message(
            $translator->translate('This module requires module "%s" version "%s" or greater.'), // @translate
            'Advanced Search', '3.3.6.16'
        );
        throw new ModuleCannotInstallException((string) $message);
    }
}
