<?php declare(strict_types=1);

namespace SearchSolr;

use Common\Stdlib\PsrMessage;
use Omeka\Module\Exception\ModuleCannotInstallException;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$config = $services->get('Config');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.65')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.65'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
}

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
UPDATE `solr_map`
SET `data_types` = "[]";
SQL;
    $connection->executeStatement($sql);

    $sql = <<<SQL
UPDATE `solr_map`
SET `source` = REPLACE(`source`, "item_set", "item_sets")
WHERE `source` LIKE "%item_set%";
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
ALTER TABLE `solr_map`
ADD `data_types` LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)' AFTER `source`;
SQL;
    try {
        $connection->executeStatement($sql);
        $sql = <<<SQL
UPDATE `solr_map`
SET `data_types` = "[]";
SQL;
        $connection->executeStatement($sql);

        $sql = <<<SQL
UPDATE `solr_map`
SET `source` = REPLACE(`source`, "item_set", "item_sets")
WHERE `source` LIKE "%item_set%";
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
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'Search / AdvancedSearch', 'version' => '3.5.22.3 / 3.3.6']
        );
        throw new ModuleCannotInstallException((string) $message);
    }

    $message = new PsrMessage(
        'The auto-suggestion requires a specific url for now.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.5.27.3', '<')) {
    $moduleManager = $services->get('Omeka\ModuleManager');
    /** @var \Omeka\Module\Module $module */
    $module = $moduleManager->getModule('AdvancedSearch');
    if (!$module) {
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.3.6']
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
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.3.6.7']
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

    $message = new PsrMessage(
        'The resource types are now structured to simplify config: "generic" and "resource" allow to set mapping for any resource.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'All mapping for items and item sets have been copied to resources.' // @translate
    );
    $messenger->addWarning($message);
    $message = new PsrMessage(
        'It is recommended to check mappings, to remove the useless and duplicate ones, and to run a full reindexation.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.5.33.3', '<')) {
    $message = new PsrMessage(
        'It is now possible to index original and thumbnails urls.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.5.37.3', '<')) {
    if (!$this->isModuleActive('AdvancedSearch')) {
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.3.6.16']
        );
        throw new ModuleCannotInstallException((string) $message->setTranslator($translator));
    }
    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('AdvancedSearch');
    $moduleVersion = $module->getIni('version');
    if (version_compare($moduleVersion, '3.3.6.16', '<')) {
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.3.6.16']
        );
        throw new ModuleCannotInstallException((string) $message->setTranslator($translator));
    }
}

if (version_compare($oldVersion, '3.5.42', '<')) {
    // Force to use module Table to manage tables if there is a table.
    if (!empty($config['searchsolr']['table'])) {
        if (!$this->isModuleActive('Table')) {
            $message = new PsrMessage(
                'This module requires the module "{module}", version {version} or above.', // @translate
                ['module' => 'Table', 'version' => '3.4.1']
            );
            throw new ModuleCannotInstallException((string) $message->setTranslator($translator));
        }

        $table = $config['searchsolr']['table'];
        /** @var \Table\Api\Representation\TableRepresentation $table */
        $table = $api->create('tables', [
            'o:title' => 'Advanced Search Solr',
            'o:codes' => $table,
        ])->getContent();
        $tableId = (int) $table->id();
        $sql = <<<SQL
UPDATE `solr_map`
SET `settings` = REPLACE(`settings`, '"formatter":"table"', '"formatter":"table","table":$tableId')
WHERE `settings` LIKE '%"formatter":"table"%';
SQL;
        $connection->executeStatement($sql);

        $message = new PsrMessage(
            'It is now possible to filter values to index via a regex, a list of languages or a visibility.' // @translate
        );
        $messenger->addSuccess($message);

        $message = new PsrMessage(
            'It is now possible to filter resources to index, for example an item set, a template, an owner, a visibility, etc.' // @translate
        );
        $messenger->addSuccess($message);

        $message = new PsrMessage(
            'It is now possible to use module Table to manage tables for normalization of indexation.' // @translate
        );
        $messenger->addSuccess($message);

        $message = new PsrMessage(
            'The table used for indexation has been converted into a standard {link}table{link_end}. It is recommended to remove the old one from the config.', // @translate
            ['link' => sprintf('<a href="%s">', $table->url()), 'link_end' => '</a>']
        );
        $message->setEscapeHtml(false);
        $messenger->addWarning($message);
    }
}

if (version_compare($oldVersion, '3.5.44', '<')) {
    $sql = <<<SQL
UPDATE `solr_map`
SET
    `field_name` = REPLACE(`field_name`, 'access_source', 'access_level'),
    `source` = REPLACE(`source`, 'access_source', 'access_level')
;
SQL;
    $connection->executeStatement($sql);

    $message = new PsrMessage(
        'The support of module Access Resource has been removed. Support of module Access has been added.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A reindexing is needed.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.5.47', '<')) {
    if (!$this->isModuleActive('AdvancedSearch')) {
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.4.34']
        );
        throw new ModuleCannotInstallException((string) $message->setTranslator($translator));
    }

    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('AdvancedSearch');
    $moduleVersion = $module->getIni('version');
    if (version_compare($moduleVersion, '3.4.34', '<')) {
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.4.34']
        );
        throw new ModuleCannotInstallException((string) $message->setTranslator($translator));
    }

    // Force to clean the config if there is a table.
    if (array_key_exists('searchsolr', $config) && array_key_exists('table', $config['searchsolr'])) {
        $message = new PsrMessage(
            'You should remove key "table" from the file config/local.config.php before upgrading.' // @translate
        );
        throw new ModuleCannotInstallException((string) $message->setTranslator($translator));
    }

    $sql = <<<SQL
        UPDATE `search_config`
        SET
            `settings` = REPLACE(`settings`, '"score desc"', '"relevance desc"')
        ;
        SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.5.48', '<')) {
    if (!$this->isModuleActive('AdvancedSearch')) {
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.4.34']
        );
        throw new ModuleCannotInstallException((string) $message->setTranslator($translator));
    }

    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('AdvancedSearch');
    $moduleVersion = $module->getIni('version');
    if (version_compare($moduleVersion, '3.4.34', '<')) {
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.4.34']
        );
        throw new ModuleCannotInstallException((string) $message->setTranslator($translator));
    }
}

if (version_compare($oldVersion, '3.5.49', '<')) {
    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('AdvancedSearch');
    $moduleVersion = $module->getIni('version');
    if (version_compare($moduleVersion, '3.4.34', '<')) {
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.4.34']
        );
        throw new ModuleCannotInstallException((string) $message->setTranslator($translator));
    }
}
