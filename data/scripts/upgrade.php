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
 * @var \Omeka\View\Helper\Url $url
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\I18n\Translator $translator
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$config = $services->get('Config');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.91')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.91'
    );
    $messenger->addError($message);
    throw new ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

$hasError = false;

if (PHP_VERSION_ID < 80100) {
    $message = new PsrMessage(
        'This version of module {module} requires a version of php ≥ {version}.', // @translate
        ['module' => 'SearchSolr', 'version' => '8.1']
    );
    $messenger->addError($message);
    $hasError = true;
}

if (!$this->checkModuleActiveVersion('AdvancedSearch', '3.4.63')) {
    $message = new PsrMessage(
        $translator->translate('This module requires module "{module}" version "{version}" or greater.'), // @translate
        ['module' => 'Advanced Search', 'version' => '3.4.63']
    );
    $messenger->addError($message);
    $hasError = true;
}

// The module Thesaurus, when installed, should be up to date, else the maps and
// the queries on thesaurus fields may not work. The check applies whether it is
// enabled or not, since its data remain, but not to a module only present on
// the disk, and the version is compared without requiring an active module.
if ($this->isModuleInstalled('Thesaurus')
    && !$this->isModuleVersionAtLeast('Thesaurus', '3.4.26')
) {
    $message = new PsrMessage(
        $translator->translate('This module requires module "{module}" version "{version}" or greater.'), // @translate
        ['module' => 'Thesaurus', 'version' => '3.4.26']
    );
    $messenger->addError($message);
    $hasError = true;
}

if ($hasError) {
    throw new ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (version_compare($oldVersion, '3.5.15.2', '<')) {
    $sql = <<<'SQL'
        CREATE INDEX `IDX_39A565C527B35A195103DEBC` ON `solr_map` (`solr_core_id`, `resource_name`);
        SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
        CREATE INDEX `IDX_39A565C527B35A194DEF17BC` ON `solr_map` (`solr_core_id`, `field_name`);
        SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
        CREATE INDEX `IDX_39A565C527B35A195F8A7F73` ON `solr_map` (`solr_core_id`, `source`);
        SQL;
    $connection->executeStatement($sql);

    $serverId = strtolower(substr(strtr(base64_encode(random_bytes(128)), ['+' => '', '/' => '', '=' => '']), 0, 6));
    $settings->set('searchsolr_server_id', $serverId);

    $messenger->addWarning('You should reindex your Solr cores.'); // @translate
}

if (version_compare($oldVersion, '3.5.15.3.6', '<')) {
    $sql = <<<'SQL'
        ALTER TABLE `solr_map`
        ADD `data_types` LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)' AFTER `source`;
        SQL;
    $connection->executeStatement($sql);

    $sql = <<<'SQL'
        UPDATE `solr_map`
        SET `data_types` = "[]";
        SQL;
    $connection->executeStatement($sql);

    $sql = <<<'SQL'
        UPDATE `solr_map`
        SET `source` = REPLACE(`source`, "item_set", "item_sets")
        WHERE `source` LIKE "%item_set%";
        SQL;
    $connection->executeStatement($sql);

    $messenger->addNotice('Now, values can be indexed differently for each data type, if wanted.'); // @translate
    $messenger->addNotice('Use the new import/export tool to simplify config.'); // @translate
}

if (version_compare($oldVersion, '3.5.16.3', '<')) {
    $sql = <<<'SQL'
        ALTER TABLE `solr_core`
        CHANGE `settings` `settings` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
        SQL;
    $connection->executeStatement($sql);

    $sql = <<<'SQL'
        ALTER TABLE `solr_map`
        ADD `data_types` LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)' AFTER `source`;
        SQL;
    try {
        $connection->executeStatement($sql);
        $sql = <<<'SQL'
            UPDATE `solr_map`
            SET `data_types` = "[]";
            SQL;
        $connection->executeStatement($sql);

        $sql = <<<'SQL'
            UPDATE `solr_map`
            SET `source` = REPLACE(`source`, "item_set", "item_sets")
            WHERE `source` LIKE "%item_set%";
            SQL;
        $connection->executeStatement($sql);
    } catch (\Throwable $e) {
    }

    $sql = <<<'SQL'
        ALTER TABLE `solr_map`
        CHANGE `data_types` `pool` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
        CHANGE `settings` `settings` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
        SQL;
    $connection->executeStatement($sql);

    $sql = <<<'SQL'
        UPDATE `solr_map`
        SET `pool` = "[]"
        WHERE `pool` = "[]" OR `pool` = "{}" OR `pool` = "" OR `pool` IS NULL;
        SQL;
    $connection->executeStatement($sql);

    $sql = <<<'SQL'
        UPDATE `solr_map`
        SET `pool` = CONCAT('{"data_types":', `pool`, "}")
        WHERE `pool` != "[]" AND `pool` IS NOT NULL;
        SQL;
    $connection->executeStatement($sql);

    // Keep the standard formatter to simplify improvment.
    $sql = <<<'SQL'
        UPDATE `solr_map`
        SET `settings` = REPLACE(`settings`, '"formatter":"standard_no_uri"', '"formatter":"standard_without_uri"')
        WHERE `settings` LIKE '%"formatter":"standard_no_uri"%';
        SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
        UPDATE `solr_map`
        SET `settings` = REPLACE(`settings`, '"formatter":"uri_only"', '"formatter":"uri"')
        WHERE `settings` LIKE '%"formatter":"uri_only"%';
        SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.5.18.3', '<')) {
    $sql = <<<'SQL'
        ALTER TABLE `solr_map`
        CHANGE `data_types` `pool` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Throwable $e) {
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
        $messenger->addError($message);
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
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
        $messenger->addError($message);
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
    }
}

if (version_compare($oldVersion, '3.5.31.3', '<')) {
    // Fix upgrade issue in 3.5.18.3.
    $sql = <<<'SQL'
        ALTER TABLE `solr_map`
        CHANGE `data_types` `pool` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Throwable $e) {
    }

    $moduleManager = $services->get('Omeka\ModuleManager');
    /** @var \Omeka\Module\Module $module */
    $module = $moduleManager->getModule('AdvancedSearch');
    if (!$module || version_compare($module->getIni('version') ?? '', '3.3.6.7', '<')) {
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.3.6.7']
        );
        $messenger->addError($message);
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
    }

    // Remove an old option.
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('id', 'settings')
        ->from('solr_core', 'solr_core')
        ->orderBy('id', 'asc');
    $solrCoresSettings = $connection->executeQuery($qb->getSQL(), $qb->getParameters())->fetchAllKeyValue();
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
    $solrCoresSettings = $connection->executeQuery($qb->getSQL(), $qb->getParameters())->fetchAllKeyValue();
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
        // Table must be active and expose its "tables" api: the adapter is
        // registered only when it is active and up to date, else the
        // create('tables') below throws a BadRequestException.
        if (!$this->isModuleActive('Table')
            || !$services->get('Omeka\ApiAdapterManager')->has('tables')
        ) {
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
            ['link' => sprintf('<a href="%s">', htmlspecialchars($table->url())), 'link_end' => '</a>']
        );
        $message->setEscapeHtml(false);
        $messenger->addWarning($message);
    }
}

if (version_compare($oldVersion, '3.5.44', '<')) {
    $sql = <<<'SQL'
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

    $sql = <<<'SQL'
        UPDATE `search_config`
        SET
            `settings` = REPLACE(`settings`, '"score desc"', '"relevance desc"')
        ;
        SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.5.55', '<')) {
    if (!$this->isModuleActive('AdvancedSearch')) {
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.4.43']
        );
        throw new ModuleCannotInstallException((string) $message->setTranslator($translator));
    }

    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('AdvancedSearch');
    $moduleVersion = $module->getIni('version');
    if (version_compare($moduleVersion, '3.4.43', '<')) {
        $message = new PsrMessage(
            'This module requires the module "{module}", version {version} or above.', // @translate
            ['module' => 'AdvancedSearch', 'version' => '3.4.43']
        );
        throw new ModuleCannotInstallException((string) $message->setTranslator($translator));
    }

    // WARNING: Useless, because aliases are used in 3.5.57, so only kept for info.
    // The insertion from 3.5.54 was removed earlier too.

    /*
    // Add index "is_id_s" and "ss_name_s" for generic management.
    // The names are compatible with drupal.
    $newIndexes = [
        'is_id_i' => 'o:id',
        'ss_name_s' => 'o:title',
    ];

    $qb = $connection->createQueryBuilder();
    $qb
        ->select('id', 'id')
        ->from('solr_core', 'solr_core')
        ->orderBy('id', 'asc');
    $solrCoreIds = $connection->executeQuery($qb->getSQL(), $qb->getParameters())->fetchAllKeyValue();
    foreach ($solrCoreIds as $solrCoreId) {
        foreach ($newIndexes as $fieldName => $sourceName) {
            // Check if the map exists.
            $qb = $connection->createQueryBuilder();
            $qb
                ->select('id', 'id')
                ->from('solr_map', 'solr_map')
                /*
                ->where($qb->expr()->eq('solr_core_id', ':solr_core_id'))
                ->andWhere($qb->expr()->eq('resource_name', ':resource_name'))
                ->andWhere($qb->expr()->eq('field_name', ':field_name'))
                ->setParameter('solr_core_id', $solrCoreId)
                ->setParameter('resource_name', 'generic')
                ->setParameter('field_name', $fieldName)
                * /
                ->where("solr_core_id = $solrCoreId AND resource_name = 'generic' AND field_name = '$fieldName'")
            ;
            $solrCoreMaps = $connection->executeQuery($qb->getSQL(), $qb->getParameters())->rowCount();
            if (!is_numeric($solrCoreMaps) || $solrCoreMaps) {
                continue;
            }
            // There is no unique fields.
            $sql = <<<'SQL'
                INSERT INTO `solr_map` (`solr_core_id`, `resource_name`, `field_name`, `source`, `pool`, `settings`)
                VALUES (?, ?, ?, ?, ?, ?);
                SQL;
            $connection->executeStatement($sql, [
                $solrCoreId,
                'generic',
                $fieldName,
                $sourceName,
                '[]',
                '[]',
            ]);
        }
    }

    // Remove indices from version 3.5.54.
    $sql = 'DELETE FROM `solr_map` WHERE `field_name` IN ("o_id_i", "o_title_s")';
    $connection->executeStatement($sql);
    */

    $message = new PsrMessage(
        'It is now possible to list {link}all resources and values indexed by a core{link_end}.', // @translate
        ['link' => '<a href="/admin/search-manager/solr/core/1">' , 'link_end' => '</a>']
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);

    $messenger->addWarning('You should reindex your Solr cores.'); // @translate

    // Replace deprecated formatters with Text.
    $replacedToNormalizations = [
        'alphanumeric' => 'alphanumeric',
        'plain_text' => 'strip_tags',
        'raw_text' => null,
        'html_escaped_text' => 'html_escaped',
        'uc_first_text' => 'ucfirst',
    ];
    $removedStandards = [
        'standard',
        'standard_with_uri',
        'standard_without_uri',
        'uri',
    ];
    $sql = <<<'SQL'
        UPDATE `solr_map`
        SET
            `settings` = ?
        WHERE
            `id` = ?
        SQL;
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('id', 'settings')
        ->from('solr_map', 'solr_map')
        ->orderBy('id', 'asc');
    $solrMapIds = $connection->executeQuery($qb->getSQL(), $qb->getParameters())->fetchAllKeyValue();
    foreach ($solrMapIds as $solrMapId => $solrMapSettings) {
        $solrMapSettings = json_decode($solrMapSettings, true);
        $formatter = $solrMapSettings['formatter'] ?? '';
        $label = $solrMapSettings['label'] ?? '';

        if (!$formatter) {
            $solrMapSettings = $label ? ['formatter' => 'standard', 'label' => $label] : ['formatter' => 'standard'];
            $formatter = 'standard';
        } else {
            if (array_key_exists($formatter, $replacedToNormalizations)) {
                $solrMapSettings = array_filter([
                    'formatter' => 'text',
                    'label' => $label,
                    'normalization' => array_filter([$replacedToNormalizations[$formatter]]),
                ], fn ($v) => $v !== '' && $v !== [] && $v !== null);
                $formatter = 'text';
            } else {
                $solrMapSettings['normalization'] = $solrMapSettings['transformations'] ?? [];
                unset($solrMapSettings['transformations']);
            }
        }

        if (in_array($formatter, $removedStandards)) {
            switch ($formatter) {
                case 'standard_with_uri':
                    $solrMapSettings['parts'] = ['value', 'uri'];
                    break;
                case 'standard_without_uri':
                    $solrMapSettings['parts'] = ['value'];
                    break;
                case 'uri':
                    $solrMapSettings['parts'] = ['uri'];
                    break;
                case 'standard':
                default:
                    $solrMapSettings['parts'] = ['auto'];
                    break;
            }
            $formatter = 'text';
            $solrMapSettings['formatter'] = $formatter;
        }

        if ($formatter === 'table') {
            $formatter = 'text';
            $solrMapSettings['formatter'] = $formatter;
            $solrMapSettings['parts'] = ['auto'];
            $solrMapSettings['normalization'] = ['table'];
        }

        if ($formatter === 'year') {
            $formatter = 'date';
            $solrMapSettings['formatter'] = $formatter;
            $solrMapSettings['parts'] = ['auto'];
            $solrMapSettings['normalization'] = ['year'];
        }

        $sql = 'UPDATE `solr_map` SET `settings` = ? WHERE `id` = ?;';
        $connection->executeStatement($sql, [json_encode($solrMapSettings, 320), $solrMapId]);
    }

    $message = new PsrMessage(
        'The list of formats was simplified.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'It is now possible to do exact search with query wrapped with double quotes.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'The management of indices has been merged in a {link}single page{link_end}.', // @translate
        ['link' => '<a href="/admin/search-manager/solr/core/1">' , 'link_end' => '</a>']
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.5.56', '<')) {
    $settings->set('searchsolr_solarium_adapter', 'auto');
    $settings->set('searchsolr_solarium_timeout', 5);
}

if (version_compare($oldVersion, '3.5.57', '<')) {
    if (!$this->checkModuleActiveVersion('AdvancedSearch', '3.4.46')) {
        $message = new \Omeka\Stdlib\Message(
            $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
            'AdvancedSearch', '3.4.46'
        );
        $messenger->addError($message);
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
    }

    $sql = <<<'SQL'
        ALTER TABLE `solr_map`
        ADD `alias` VARCHAR(190) DEFAULT NULL AFTER `source`;
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Throwable $e) {
        // Already added.
    }

    // Log existing map names.
    $sql = <<<'SQL'
        SELECT id, field_name
        FROM solr_map
        WHERE field_name LIKE "tm\_%"
            OR field_name LIKE "ts\_%"
            OR field_name LIKE "sort\_X3b\_%";
        SQL;
    $isDrupal = (bool) $connection->executeQuery($sql)->fetchOne();
    // Rename indexes to use boolean.
    $renameIndexes = [
        'o_id_i' => 'id_i',
        'is_o_id' => 'is_id',
        // TODO Find why indexing boolean is not working.
        // 'is_public_i' => 'is_public_b',
        // 'is_public' => 'bs_is_public',
        // 'is_is_public' => 'bs_is_public',
        'o_title_s' => 'name_s',
        'ss_o_title' => 'ss_name',
        'ss_name_s' => $isDrupal ? 'ss_name' : 'name_s',
        'is_id_i' => $isDrupal ? 'is_id' : 'id_i',
    ];
    $sql = <<<'SQL'
        SELECT id, field_name
        FROM solr_map
        WHERE field_name IN (:list);
        SQL;
    $list = (bool) $connection->executeQuery($sql, ['list' => array_keys($renameIndexes)], ['list' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY])->fetchAllKeyValue();
    $logger->info('Updatable field names: {json}', ['json' => json_encode($list, 448)]);
    $updateds = [];
    foreach ($renameIndexes as $oldIndex => $newIndex) {
        try {
            $result = $connection->update('solr_map', ['field_name' => $newIndex], ['field_name' => $oldIndex]);
            if ($result) {
                $updateds[$oldIndex] = $newIndex;
            }
        } catch (\Throwable $e) {
            // Nothing to do.
            $messenger->addError($e->getMessage());
        }
    }

    if ($updateds) {
        $message = new PsrMessage(
            'Some solr map fields were renamed: {json}.', // @translate
            ['json' => json_encode($updateds, 448)]
        );
        $messenger->addWarning($message);
    }

    $aliasesFromSource = [
        'resource_name' => 'resource_name',
        'o:id' => 'id',
        'is_public' => 'is_public',
        'owner/o:id' => 'owner_id',
        'site/o:id' => 'site_id',
        'resource_class/o:id' => 'resource_class_id',
        'resource_class/o:term' => 'resource_class_term',
        'resource_class/o:label' => 'resource_class_label',
        'resource_template/o:id' => 'resource_template_id',
        'resource_template/o:label' => 'resource_template_label',
        'o:title' => 'title',
        'item_set/o:id' => 'item_set_id',
        'item/o:id' => 'item_id',
        'item/has_media' => 'has_media',
        'item_set/is_open' => 'is_open',
        'item_set/o:is_open' => 'is_open',
        'media/o:media_type' => 'media_type',
        'media/o:ingester' => 'ingester',
        'media/o:renderer' => 'renderer',
    ];
    foreach ($aliasesFromSource as $source => $alias) {
        try {
            $connection->update('solr_map', ['alias' => $alias], ['source' => $source]);
        } catch (\Throwable $e) {
            // Nothing to do.
            $messenger->addError($e->getMessage());
        }
    }

    $aliasesFromFieldName = [
        'name_s' => 'name',
        'ss_name' => 'name',
        'item_set_id_is' => 'item_set_id',
        'im_item_set_id' => 'item_set_id',
    ];
    foreach ($aliasesFromFieldName as $fieldName => $alias) {
        try {
            $connection->update('solr_map', ['alias' => $alias], ['field_name' => $fieldName]);
        } catch (\Throwable $e) {
            // Nothing to do.
            $messenger->addError($e->getMessage());
        }
    }

    $message = new PsrMessage(
        'It is now possible to set a default alias for each omeka/solr map. Common aliases were added, for example "id" for "o:id" and "item_set_id" for "item_set_id_is".' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'Property terms can be used as dynamic aliases when an index exists for them.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A reindexing is needed.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.5.58', '<')) {
    $message = new PsrMessage(
        'A {link}config form{link_end} was added to specify the use of php-curl if wanted and the solarium timeout.', // @translate
        [
            'link' => sprintf('<a href="%s">', htmlspecialchars($url('admin/default', ['controller' => 'module', 'action' => 'configure'], ['query' => ['id' => 'SearchSolr']]))),
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'The button {link}Map all{link_end} creates new indexes for languages.', // @translate
        ['link' => '<a href="/admin/search-manager/solr/core/1">' , 'link_end' => '</a>']
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.5.60', '<')) {
    // For all maps with no parts or part auto without uri, update the setting
    // to replace "auto" by "main".
    // Furtermore, rename "part" as "parts".
    $sql = <<<'SQL'
        UPDATE `solr_map`
        SET
            `settings` = ?
        WHERE
            `id` = ?
        SQL;
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('id', 'settings')
        ->from('solr_map', 'solr_map')
        ->orderBy('id', 'asc');
    $solrMapIds = $connection->executeQuery($qb->getSQL(), $qb->getParameters())->fetchAllKeyValue();
    foreach ($solrMapIds as $solrMapId => $solrMapSettings) {
        $solrMapSettings = json_decode($solrMapSettings, true);
        $parts = $solrMapSettings['part'] ?? $solrMapSettings['parts'] ?? [];
        // Can be simplified of course.
        if (empty($parts)) {
            // Keep old behavior for empty parts. "auto" is now "main".
            $parts = ['main'];
        } elseif (in_array('auto', $parts) && in_array('uri', $parts)) {
            // Replace auto by value to avoid to add uri.
            $parts[] = 'value';
        } elseif (in_array('auto', $parts)) {
            $parts[] = 'main';
        }
        if (in_array('string', $parts)) {
            $parts[] = 'main';
        }
        $solrMapSettings['parts'] = array_diff($parts, ['auto', 'label', 'string']);
        unset($solrMapSettings['part']);
        $formatter = $solrMapSettings['formatter'] ?? '';
        if (empty($solrMapSettings['index_for_link'])) {
            unset($solrMapSettings['index_for_link']);
        }
        if ($formatter !== 'place') {
            unset(
                $solrMapSettings['place_mode']
            );
        }
        if ($formatter !== 'thesaurus_self') {
            unset(
                $solrMapSettings['thesaurus_resources'],
                $solrMapSettings['thesaurus_self'],
                $solrMapSettings['thesaurus_metadata']
            );
        }
        $sql = 'UPDATE `solr_map` SET `settings` = ? WHERE `id` = ?;';
        $connection->executeStatement($sql, [json_encode($solrMapSettings, 320), $solrMapId]);
    }

    $message = new PsrMessage(
        'The default option "auto" for format of indexed values was replaced by "main". A new option "full" now include the uri and the linked resource id. You should check your indices, filters and facets.', // @translate
    );
    $messenger->addWarning($message);

    $message = new PsrMessage(
        'It is now possible to index the label of uri and linked resource.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'It is now possible to specify a boost for selected indexes.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.5.61', '<')) {
    $message = new PsrMessage(
        'The statistics about index were moved to a specific page.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'The performance was improved for indexing and querying. Warning: It is no more possible to query with any diacritics on static fields like _ss.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.5.62', '<')) {
    // Convert field_boost from string format "field1 field2^2 field3^0.5" to
    // array format [field => boost].
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('id', 'settings')
        ->from('solr_core', 'solr_core')
        ->orderBy('id', 'asc');
    $solrCoresSettings = $connection->executeQuery($qb->getSQL(), $qb->getParameters())->fetchAllKeyValue();
    foreach ($solrCoresSettings as $solrCoreId => $solrCoreSettings) {
        $solrCoreSettings = json_decode($solrCoreSettings, true) ?: [];
        $fieldBoost = $solrCoreSettings['field_boost'] ?? '';
        // Skip if already an array (already migrated) or empty.
        if (is_array($fieldBoost)) {
            continue;
        }
        // Parse string format "field1 field2^2 field3^0.5" into array [field => boost].
        $result = [];
        $parts = preg_split('/\s+/', trim((string) $fieldBoost));
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (strpos($part, '^') !== false) {
                [$field, $boost] = explode('^', $part, 2);
                $result[$field] = (float) $boost;
            } else {
                $result[$part] = 1.0;
            }
        }
        $solrCoreSettings['field_boost'] = $result;
        $sql = <<<'SQL'
            UPDATE `solr_core`
            SET `settings` = :settings
            WHERE `id` = :id
            SQL;
        $connection
            ->executeStatement($sql, [
                'settings' => json_encode($solrCoreSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'id' => $solrCoreId,
            ]);
    }
}

if (version_compare($oldVersion, '3.5.64', '<')) {
    // Fix possible invalid data in json columns (pool and settings).
    $sql = <<<'SQL'
        UPDATE `solr_map`
        SET `pool` = '[]'
        WHERE `pool` = '' OR `pool` IS NULL OR JSON_VALID(`pool`) = 0
        SQL;
    $fixed = $connection->executeStatement($sql);
    $sql = <<<'SQL'
        UPDATE `solr_map`
        SET `settings` = '[]'
        WHERE `settings` = '' OR `settings` IS NULL OR JSON_VALID(`settings`) = 0
        SQL;
    $fixed += $connection->executeStatement($sql);
    if ($fixed) {
        $message = new PsrMessage(
            '{count} invalid data in solr maps were fixed.', // @translate
            ['count' => $fixed]
        );
        $messenger->addWarning($message);
    }

    // Clean up normalization "table" referencing an empty or missing table.
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('id', 'settings')
        ->from('solr_map', 'solr_map')
        ->where('settings LIKE \'%"table"%\'')
        ->orderBy('id', 'asc');
    $solrMapRows = $connection->executeQuery($qb->getSQL(), $qb->getParameters())->fetchAllKeyValue();
    $fixedTable = 0;
    foreach ($solrMapRows as $solrMapId => $solrMapSettings) {
        $solrMapSettings = json_decode($solrMapSettings, true) ?: [];
        $normalizations = $solrMapSettings['normalization'] ?? [];
        if (!in_array('table', $normalizations)) {
            continue;
        }
        $tableId = $solrMapSettings['table'] ?? '';
        if ($tableId !== '' && $tableId !== null) {
            continue;
        }
        $solrMapSettings['normalization'] = array_values(array_diff($normalizations, ['table']));
        unset(
            $solrMapSettings['table'],
            $solrMapSettings['table_mode'],
            $solrMapSettings['table_index_original'],
            $solrMapSettings['table_check_strict']
        );
        $connection->executeStatement(
            'UPDATE `solr_map` SET `settings` = ? WHERE `id` = ?',
            [json_encode($solrMapSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $solrMapId]
        );
        ++$fixedTable;
    }
    if ($fixedTable) {
        $message = new PsrMessage(
            '{count} solr maps with normalization "table" referencing an empty table were cleaned.', // @translate
            ['count' => $fixedTable]
        );
        $messenger->addWarning($message);
    }

    // Create missing required maps for each solr core.
    // Checked by source, like missingRequiredMaps().
    $requiredMapsBySource = [
        ['source' => 'resource_name', 'field_name' => 'resource_name_s', 'alias' => 'resource_name', 'settings' => ['label' => 'Resource type']],
        ['source' => 'o:id', 'field_name' => 'id_i', 'alias' => 'id', 'settings' => ['label' => 'Internal id']],
        ['source' => 'is_public', 'field_name' => 'is_public_b', 'alias' => 'is_public', 'settings' => ['parts' => ['main'], 'formatter' => 'boolean', 'label' => 'Public']],
        ['source' => 'owner/o:id', 'field_name' => 'owner_id_i', 'alias' => 'owner_id', 'settings' => ['label' => 'Owner']],
        ['source' => 'site/o:id', 'field_name' => 'site_id_is', 'alias' => 'site_id', 'settings' => ['label' => 'Site']],
    ];
    // Checked by field_name: name_s is required but o:title may already exist
    // for "resources" (title_s); missingRequiredMaps() checks field_name.
    $requiredMapsByFieldName = [
        ['field_names' => ['name_s', 'ss_name'], 'source' => 'o:title', 'field_name' => 'name_s', 'alias' => 'name', 'settings' => ['label' => 'Name']],
    ];
    $solrCoreIds = $connection->executeQuery('SELECT `id` FROM `solr_core` ORDER BY `id` ASC')->fetchFirstColumn();
    $createdMaps = 0;
    $sqlInsert = 'INSERT INTO `solr_map` (`solr_core_id`, `resource_name`, `field_name`, `alias`, `source`, `pool`, `settings`) VALUES (?, ?, ?, ?, ?, ?, ?)';
    foreach ($solrCoreIds as $solrCoreId) {
        foreach ($requiredMapsBySource as $requiredMap) {
            $exists = $connection->fetchOne(
                'SELECT `id` FROM `solr_map` WHERE `solr_core_id` = ? AND `source` = ? LIMIT 1',
                [$solrCoreId, $requiredMap['source']]
            );
            if ($exists) {
                continue;
            }
            $connection->executeStatement($sqlInsert, [
                $solrCoreId, 'generic', $requiredMap['field_name'], $requiredMap['alias'],
                $requiredMap['source'], '[]',
                json_encode($requiredMap['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            ++$createdMaps;
        }
        foreach ($requiredMapsByFieldName as $requiredMap) {
            $exists = $connection->fetchOne(
                'SELECT `id` FROM `solr_map` WHERE `solr_core_id` = ? AND `field_name` IN (?) LIMIT 1',
                [$solrCoreId, $requiredMap['field_names']],
                [null, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]
            );
            if ($exists) {
                continue;
            }
            $connection->executeStatement($sqlInsert, [
                $solrCoreId, 'generic', $requiredMap['field_name'], $requiredMap['alias'],
                $requiredMap['source'], '[]',
                json_encode($requiredMap['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            ++$createdMaps;
        }
    }
    if ($createdMaps) {
        $message = new PsrMessage(
            '{count} required maps were created for existing solr cores.', // @translate
            ['count' => $createdMaps]
        );
        $messenger->addSuccess($message);
        $messenger->addWarning('You should reindex your Solr cores.'); // @translate
    }

    // Create a default Solr suggester and add it to search configs without suggester.

    // Check if there's a Solr search engine.
    $sql = <<<'SQL'
        SELECT `id`
        FROM `search_engine`
        WHERE `adapter` = 'solarium'
        ORDER BY `id` ASC
        LIMIT 1
        SQL;
    $solrEngineId = (int) $connection->fetchOne($sql);

    if ($solrEngineId) {
        // Check if a suggester already exists for this engine.
        $sql = <<<'SQL'
            SELECT `id`
            FROM `search_suggester`
            WHERE `engine_id` = ?
            ORDER BY `id` ASC
            LIMIT 1
            SQL;
        $suggesterId = (int) $connection->fetchOne($sql, [$solrEngineId]);

        if (!$suggesterId) {
            // Create a default Solr suggester using _text_ catchall copy field.
            $suggesterSettings = [
                'solr_suggester_name' => 'omeka_suggester',
                'solr_fields' => ['_text_'],
                'solr_lookup_implementation' => 'AnalyzingInfixLookupFactory',
                'solr_skip_build_on_commit' => false,
            ];

            $sql = <<<'SQL'
                INSERT INTO `search_suggester` (`engine_id`, `name`, `settings`, `created`, `modified`)
                VALUES (?, ?, ?, NOW(), NOW())
                SQL;
            $connection->executeStatement($sql, [
                $solrEngineId,
                'Solr',
                json_encode($suggesterSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            // Get the new suggester ID.
            $sql = <<<'SQL'
                SELECT `id`
                FROM `search_suggester`
                WHERE `engine_id` = ?
                ORDER BY `id` DESC
                LIMIT 1
                SQL;
            $suggesterId = (int) $connection->fetchOne($sql, [$solrEngineId]);
        }

        if ($suggesterId) {
            // Update search configs that use this Solr engine and don't have a suggester.
            $sql = <<<'SQL'
                SELECT `id`, `settings`
                FROM `search_config`
                WHERE `engine_id` = ?
                SQL;
            $searchConfigs = $connection->fetchAllKeyValue($sql, [$solrEngineId]);

            $updatedConfigs = 0;
            foreach ($searchConfigs as $configId => $configSettings) {
                $configData = json_decode($configSettings, true) ?: [];
                // Check if suggester is not set or is empty/null.
                $currentSuggester = $configData['q']['suggester'] ?? null;
                if (empty($currentSuggester)) {
                    $configData['q']['suggester'] = $suggesterId;
                    $sql = <<<'SQL'
                        UPDATE `search_config`
                        SET `settings` = ?
                        WHERE `id` = ?
                        SQL;
                    $connection->executeStatement($sql, [
                        json_encode($configData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        $configId,
                    ]);
                    $updatedConfigs++;
                }
            }

            // Messages - routes are not available during upgrade, use static paths.
            $message = new PsrMessage(
                'A default Solr suggester has been created. You can configure it in the {link}search manager{link_end}.', // @translate
                ['link' => '<a href="/admin/search-manager/suggester/' . $suggesterId . '/edit">', 'link_end' => '</a>']
            );
            $message->setEscapeHtml(false);
            $messenger->addSuccess($message);

            if ($updatedConfigs) {
                $message = new PsrMessage(
                    '{count} search configuration(s) have been updated to use the new Solr suggester.', // @translate
                    ['count' => $updatedConfigs]
                );
                $messenger->addSuccess($message);
            }

            $message = new PsrMessage(
                'Note: The suggester uses the catchall copy field "_text_". If it does not exist, create it via the Solr core admin page or change the suggester field. Reindex your Solr core and build the suggester dictionary.' // @translate
            );
            $messenger->addWarning($message);
        }
    }
}

if (version_compare($oldVersion, '3.5.65', '<')) {
    // Rename suggester settings:
    // - "solr_lookup_impl" to "solr_lookup_implementation"
    // - "solr_build_on_commit" to "solr_skip_build_on_commit" (inverted).
    // Replace "_text_" (not stored, EdgeNGram) with "auto" (all stored fields)
    // when the suggester is not used in any search config.

    // Get suggester ids used in search configs.
    $sql = <<<'SQL'
        SELECT `id`, `settings` FROM `search_config`
        SQL;
    $usedSuggesterIds = [];
    foreach ($connection->executeQuery($sql)->fetchAllAssociative() as $configRow) {
        $configSettings = json_decode($configRow['settings'], true) ?: [];
        $suggesterId = $configSettings['q']['suggester'] ?? null;
        if ($suggesterId) {
            $usedSuggesterIds[(int) $suggesterId] = (int) $configRow['id'];
        }
    }

    $sql = <<<'SQL'
        SELECT `id`, `settings` FROM `search_suggester`
        WHERE `settings` LIKE '%solr_%'
        SQL;
    foreach ($connection->executeQuery($sql)->fetchAllAssociative() as $suggester) {
        $suggesterData = json_decode($suggester['settings'], true) ?: [];
        $updated = false;

        if (isset($suggesterData['solr_lookup_impl'])) {
            $suggesterData['solr_lookup_implementation'] = $suggesterData['solr_lookup_impl'];
            unset($suggesterData['solr_lookup_impl']);
            $updated = true;
        }

        if (isset($suggesterData['solr_build_on_commit'])) {
            $suggesterData['solr_skip_build_on_commit'] = !$suggesterData['solr_build_on_commit'];
            unset($suggesterData['solr_build_on_commit']);
            $updated = true;
        }

        // Replace _text_ with stored fields.
        $solrFields = $suggesterData['solr_fields'] ?? [];
        if (in_array('_text_', $solrFields)) {
            $id = (int) $suggester['id'];
            if (isset($usedSuggesterIds[$id])) {
                $messenger->addWarning(new PsrMessage(
                    'Solr suggester #{suggester_id} uses "_text_" (not stored), which produces character-level suggestions. Update it to use stored fields (words). It is used in search config #{config_id}.', // @translate
                    ['suggester_id' => $id, 'config_id' => $usedSuggesterIds[$id]]
                ));
            } else {
                $suggesterData['solr_fields'] = ['auto'];
                $suggesterData['solr_lookup_implementation'] = 'AnalyzingInfixLookupFactory';
                $updated = true;
                $messenger->addNotice(new PsrMessage(
                    'Solr suggester #{suggester_id}: "_text_" replaced with "auto" (all stored fields). You should rebuild the suggester dictionary.', // @translate
                    ['suggester_id' => $id]
                ));
            }
        }

        if ($updated) {
            $connection->executeStatement(
                'UPDATE `search_suggester` SET `settings` = ? WHERE `id` = ?',
                [json_encode($suggesterData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $suggester['id']]
            );
        }
    }
}

if (version_compare($oldVersion, '3.5.66', '<')) {
    $messenger->addSuccess(new PsrMessage(
        'The suggester now uses an optimized unified field "suggest_txt" by default. You can select it in the suggester settings.' // @translate
    ));
}

if (version_compare($oldVersion, '3.5.68', '<')) {
    // Check if any engine uses visibility = public.
    $sql = 'SELECT id, name, settings FROM search_engine';
    $engines = $connection->executeQuery($sql)->fetchAllAssociative();
    $hasPublicEngine = false;
    foreach ($engines as $engine) {
        $engineSettings = json_decode($engine['settings'], true) ?: [];
        if (($engineSettings['visibility'] ?? null) === 'public') {
            $hasPublicEngine = true;
            break;
        }
    }

    if ($hasPublicEngine) {
        $messenger->addWarning(new PsrMessage(
            'The visibility filter for Solr maps now defaults to "follow engine setting" instead of "all". Since at least one search engine is configured as "public", maps without an explicit visibility filter will now exclude private values. To keep the previous behavior (index all values), use the new maintenance action "Set all maps to index all values" on the Solr core page, or set individual maps to "All values".' // @translate
        ));

        // Set all existing maps to "all" to preserve current behavior.
        $sql = <<<'SQL'
            UPDATE solr_map
            SET settings = JSON_SET(
                COALESCE(settings, '{}'),
                '$.pool.filter_visibility', 'all'
            )
            WHERE settings IS NULL
               OR JSON_EXTRACT(settings, '$.pool.filter_visibility') IS NULL
               OR JSON_EXTRACT(settings, '$.pool.filter_visibility') = ''
            SQL;
        $connection->executeStatement($sql);

        $messenger->addSuccess(new PsrMessage(
            'All existing Solr maps have been set to "All values" to preserve the current indexing behavior. Use the maintenance action "Remove private values from maps" to switch to the secure default.' // @translate
        ));
    } else {
        $messenger->addSuccess(new PsrMessage(
            'The visibility filter for Solr maps now defaults to "follow engine setting". Since no engine is configured as "public", this does not change the current behavior.' // @translate
        ));
    }

    $messenger->addSuccess(new PsrMessage(
        'A new maintenance action "Sync maps from search configs" is available on each Solr core page. It automatically creates the Solr maps needed by your search configurations (facets, filters, sorts, suggesters, boosts, bounce links) and removes unused property maps. It is recommended to run it now. A reindex is required after sync.' // @translate
    ));

    $sql = <<<'SQL'
        ALTER TABLE `solr_core`
            ADD `backup_maps` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)' AFTER `settings`
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Throwable $e) {
        // Column already exists.
    }

    $messenger->addSuccess(new PsrMessage(
        'The maintenance action "Sync maps from search configs" now stores a backup of the previous map configuration on each run. The last snapshots are kept on each Solr core and can be restored from the core page.' // @translate
    ));
}

if (version_compare($oldVersion, '3.5.69', '<')) {
    // Re-index multi-value list settings (parts, normalization, etc.) that
    // were stored as json objects with a gap ({"1":"main"}) after an empty
    // option was filtered out without re-indexing, so they become lists again
    // (["main"]). Map-like settings such as "table" keep their keys.
    $listKeys = ['parts', 'normalization', 'thesaurus_metadata', 'finalization'];
    $rows = $connection
        ->executeQuery('SELECT `id`, `settings` FROM `solr_map`')
        ->fetchAllAssociative();
    foreach ($rows as $row) {
        // Not "$settings", that is the service of the main settings.
        $mapSettings = strlen((string) $row['settings'])
            ? json_decode($row['settings'], true)
            : [];
        if (!is_array($mapSettings) || !$mapSettings) {
            continue;
        }
        $normalized = $mapSettings;
        foreach ($listKeys as $listKey) {
            if (isset($normalized[$listKey]) && is_array($normalized[$listKey])) {
                $normalized[$listKey] = array_values($normalized[$listKey]);
            }
        }
        if ($normalized !== $mapSettings) {
            $connection->executeStatement(
                'UPDATE `solr_map` SET `settings` = :settings WHERE `id` = :id',
                ['settings' => json_encode($normalized), 'id' => $row['id']]
            );
        }
    }

    $messenger->addSuccess(new PsrMessage(
        'Autocompletion is now diacritics-insensitive by default. To apply, click "Recreate suggest_txt" in the "Suggest configuration" section on the core show page and reindex.' // @translate
    ));

    $messenger->addSuccess(new PsrMessage(
        'The search now supports jokers "*" and "?" by default. They cannot be used as first character and the query should contains at least three characters.' // @translate
    ));

    $messenger->addSuccess(new PsrMessage(
        'The map source "Item: Has media" has a new option "Include digital objects": when set, an item with no native media but linked to at least one digital object (module Digital Object) is also indexed as having media.' // @translate
    ));
}

if (version_compare($oldVersion, '3.5.70', '<')) {
    // An engine is a real backend: the solr_core table merges into the
    // solarium engine. The connection and the core settings move to the
    // engine settings under "solr" (snapshots included) and the maps belong
    // to the engine directly. The mapping is 1:1; a core without engine gets
    // one. This migration runs first, so the next steps work on the new
    // model.
    $hasSolrCoreTable = (bool) $connection->fetchOne("SHOW TABLES LIKE 'solr_core'");
    if ($hasSolrCoreTable) {
        // 1. Merge each core into its engine, creating the missing engines.
        $coreToEngine = [];
        $engines = $connection->fetchAllAssociative(
            "SELECT `id`, `settings` FROM `search_engine` WHERE `adapter` = 'solarium' ORDER BY `id` ASC"
        );
        $cores = $connection->fetchAllAssociative(
            'SELECT `id`, `name`, `settings`, `backup_maps` FROM `solr_core` ORDER BY `id` ASC'
        );
        foreach ($cores as $core) {
            $coreId = (int) $core['id'];
            $engineId = null;
            $engineSettings = [];
            foreach ($engines as $engine) {
                $checkSettings = json_decode((string) $engine['settings'], true) ?: [];
                if ((int) ($checkSettings['engine_adapter']['solr_core_id'] ?? 0) === $coreId) {
                    $engineId = (int) $engine['id'];
                    $engineSettings = $checkSettings;
                    break;
                }
            }
            $solrSettings = json_decode((string) $core['settings'], true) ?: [];
            $backups = json_decode((string) ($core['backup_maps'] ?? ''), true);
            if (is_array($backups) && count($backups)) {
                $solrSettings['backup_maps'] = $backups;
            }
            if ($engineId) {
                $engineSettings['solr'] = $solrSettings;
                // The index name for a shared core is a facet of the solr
                // settings; the solarium engine has no more adapter settings.
                if (($engineSettings['engine_adapter']['index_name'] ?? '') !== '') {
                    $engineSettings['solr']['index_name'] = $engineSettings['engine_adapter']['index_name'];
                }
                unset($engineSettings['engine_adapter']);
                $connection->executeStatement(
                    'UPDATE `search_engine` SET `settings` = ?, `modified` = NOW() WHERE `id` = ?;',
                    [json_encode($engineSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $engineId]
                );
            } else {
                $engineSettings = [
                    'resource_types' => ['items', 'item_sets'],
                    'solr' => $solrSettings,
                ];
                $connection->executeStatement(
                    'INSERT INTO `search_engine` (`name`, `adapter`, `settings`, `created`, `modified`) VALUES (?, ?, ?, NOW(), NOW());',
                    [$core['name'], 'solarium', json_encode($engineSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
                );
                $engineId = (int) $connection->lastInsertId();
            }
            $coreToEngine[$coreId] = $engineId;
        }

        // 2. Move the maps to the engine.
        $hasEngineColumn = (bool) $connection->fetchOne(
            "SHOW COLUMNS FROM `solr_map` LIKE 'engine_id'"
        );
        if (!$hasEngineColumn) {
            // The column identifies the owner of the map, so it is created
            // right after the id, like in the schema of a new install.
            $connection->executeStatement('ALTER TABLE `solr_map` ADD `engine_id` INT DEFAULT NULL AFTER `id`;');
        }
        foreach ($coreToEngine as $coreId => $engineId) {
            $connection->executeStatement(
                'UPDATE `solr_map` SET `engine_id` = ? WHERE `solr_core_id` = ?;',
                [$engineId, $coreId]
            );
        }

        // 3. Drop the legacy column with its constraint and indexes, whose
        // names may differ between installs, then finalize the new column.
        $foreignKeys = $connection->fetchFirstColumn(
            "SELECT DISTINCT `CONSTRAINT_NAME` FROM `information_schema`.`KEY_COLUMN_USAGE`
            WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'solr_map'
                AND `COLUMN_NAME` = 'solr_core_id' AND `REFERENCED_TABLE_NAME` IS NOT NULL"
        );
        foreach ($foreignKeys as $foreignKey) {
            $connection->executeStatement("ALTER TABLE `solr_map` DROP FOREIGN KEY `$foreignKey`;");
        }
        $indexes = $connection->fetchFirstColumn(
            "SELECT DISTINCT `INDEX_NAME` FROM `information_schema`.`STATISTICS`
            WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'solr_map'
                AND `COLUMN_NAME` = 'solr_core_id' AND `INDEX_NAME` != 'PRIMARY'"
        );
        foreach ($indexes as $index) {
            $connection->executeStatement("ALTER TABLE `solr_map` DROP INDEX `$index`;");
        }
        $connection->executeStatement('ALTER TABLE `solr_map` DROP COLUMN `solr_core_id`;');
        $connection->executeStatement('ALTER TABLE `solr_map` MODIFY `engine_id` INT NOT NULL AFTER `id`;');
        $connection->executeStatement('ALTER TABLE `solr_map` ADD INDEX IDX_39A565C5E78C9C0A (`engine_id`);');
        $connection->executeStatement('ALTER TABLE `solr_map` ADD INDEX IDX_39A565C5E78C9C0A5103DEBC (`engine_id`, `resource_name`);');
        $connection->executeStatement('ALTER TABLE `solr_map` ADD INDEX IDX_39A565C5E78C9C0A4DEF17BC (`engine_id`, `field_name`);');
        $connection->executeStatement('ALTER TABLE `solr_map` ADD INDEX IDX_39A565C5E78C9C0AE16C6B94 (`engine_id`, `alias`);');
        $connection->executeStatement('ALTER TABLE `solr_map` ADD INDEX IDX_39A565C5E78C9C0A5F8A7F73 (`engine_id`, `source`);');
        $connection->executeStatement('ALTER TABLE `solr_map` ADD CONSTRAINT FK_39A565C5E78C9C0A FOREIGN KEY (`engine_id`) REFERENCES `search_engine` (`id`) ON DELETE CASCADE;');

        // 4. The core table is merged: drop it.
        $connection->executeStatement('DROP TABLE `solr_core`;');

        $messenger->addSuccess(new PsrMessage(
            'The Solr cores were merged into their search engines: the connection and the maps now belong to the engine directly.' // @translate
        ));
    }

    // The query relevance settings (minimum match, tie breaker) are a facet of
    // the query context: move them from the core to the search pages of the
    // engine, under the section "engine" with the field boosts.
    $engines = $connection->fetchAllAssociative(
        "SELECT `id`, `settings` FROM `search_engine` WHERE `adapter` = 'solarium' ORDER BY `id` ASC"
    );
    foreach ($engines as $engine) {
        $engineSettings = json_decode((string) $engine['settings'], true) ?: [];
        if (!array_key_exists('query', $engineSettings['solr'] ?? [])) {
            continue;
        }
        $queryRelevance = array_intersect_key(
            array_filter($engineSettings['solr']['query'] ?? [], fn ($v) => $v !== '' && $v !== null),
            ['minimum_match' => null, 'tie_breaker' => null]
        );
        if ($queryRelevance) {
            $configs = $connection->fetchAllAssociative(
                'SELECT `id`, `settings` FROM `search_config` WHERE `engine_id` = ?;',
                [(int) $engine['id']]
            );
            foreach ($configs as $configRow) {
                $configSettings = json_decode((string) $configRow['settings'], true) ?: [];
                foreach ($queryRelevance as $key => $value) {
                    if (($configSettings['engine'][$key] ?? '') === '') {
                        $configSettings['engine'][$key] = $value;
                    }
                }
                $connection->executeStatement(
                    'UPDATE `search_config` SET `settings` = ? WHERE `id` = ?;',
                    [json_encode($configSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $configRow['id']]
                );
            }
        }
        unset($engineSettings['solr']['query']);
        $connection->executeStatement(
            'UPDATE `search_engine` SET `settings` = ?, `modified` = NOW() WHERE `id` = ?;',
            [json_encode($engineSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $engine['id']]
        );
    }

    // Many maps stored the term as their label: replace it with the label of
    // the property, in the default admin language. Custom labels are kept.
    $defaultLocale = (string) $settings->get('locale') ?: 'en';
    $propertyLabels = $connection->fetchAllKeyValue(
        'SELECT CONCAT(vo.prefix, ":", pr.local_name), pr.label
        FROM property pr
        INNER JOIN vocabulary vo ON vo.id = pr.vocabulary_id'
    );
    $maps = $connection->fetchAllAssociative(
        'SELECT `id`, `source`, `settings` FROM `solr_map`'
    );
    foreach ($maps as $map) {
        $mapSettings = json_decode((string) $map['settings'], true) ?: [];
        if (!isset($propertyLabels[$map['source']])) {
            continue;
        }
        $label = (string) ($mapSettings['label'] ?? '');
        $source = $map['source'];
        $propertyLabel = $translator->translate($propertyLabels[$source], 'default', $defaultLocale);
        if ($label === $source) {
            $mapSettings['label'] = $propertyLabel;
        } elseif (preg_match('~^' . preg_quote($source, '~') . ' \((\w+)\)$~', $label, $matches)) {
            $mapSettings['label'] = $propertyLabel . ' (' . $matches[1] . ')';
        } else {
            continue;
        }
        $connection->executeStatement(
            'UPDATE `solr_map` SET `settings` = ? WHERE `id` = ?;',
            [json_encode($mapSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $map['id']]
        );
    }

    // The setting "index_for_link" was informational only: the part "link"
    // is the single source of truth of the bounce link indexes.
    $maps = $connection->fetchAllAssociative(
        'SELECT `id`, `settings` FROM `solr_map` WHERE `settings` LIKE \'%index_for_link%\''
    );
    foreach ($maps as $map) {
        $mapSettings = json_decode((string) $map['settings'], true) ?: [];
        unset($mapSettings['index_for_link']);
        $connection->executeStatement(
            'UPDATE `solr_map` SET `settings` = ? WHERE `id` = ?;',
            [json_encode($mapSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $map['id']]
        );
    }

    // The empty formatter was a duplicate of "text", the fallback of the
    // indexer: set it explicitly.
    $maps = $connection->fetchAllAssociative(
        'SELECT `id`, `settings` FROM `solr_map`'
    );
    foreach ($maps as $map) {
        $mapSettings = json_decode((string) $map['settings'], true) ?: [];
        if (($mapSettings['formatter'] ?? '') !== '') {
            continue;
        }
        $mapSettings['formatter'] = 'text';
        $connection->executeStatement(
            'UPDATE `solr_map` SET `settings` = ? WHERE `id` = ?;',
            [json_encode($mapSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $map['id']]
        );
    }

    // The five date formatters are merged into the single formatter "date",
    // with the mode (single or interval) and the precision of the output
    // (year, date, date time) as settings.
    $dateFormatterMap = [
        'edtf' => [],
        'edtf_date' => ['date_out' => 'date'],
        'edtf_year' => ['date_out' => 'year'],
        'date_range' => ['date_mode' => 'interval', 'date_out' => 'year'],
    ];
    $maps = $connection->fetchAllAssociative(
        'SELECT `id`, `settings` FROM `solr_map`'
    );
    foreach ($maps as $map) {
        $mapSettings = json_decode((string) $map['settings'], true) ?: [];
        $formatter = $mapSettings['formatter'] ?? '';
        if (!isset($dateFormatterMap[$formatter])) {
            continue;
        }
        $mapSettings['formatter'] = 'date';
        $mapSettings += $dateFormatterMap[$formatter];
        $connection->executeStatement(
            'UPDATE `solr_map` SET `settings` = ? WHERE `id` = ?;',
            [json_encode($mapSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $map['id']]
        );
    }

    // Standardize the visibility map on the boolean field "is_public_b" (like
    // the other boolean field "has_media_b"; the two are separate indexes)
    // instead of the integer "is_public_i", with a zero-downtime, two-phase
    // migration.
    //
    // Phase 1: add "is_public_b" next to "is_public_i" (a clone of the same
    // source) and relaunch indexing. Both fields get populated; the querier
    // keeps using the older "is_public_i" (see SolariumQuerier::solrCoreField),
    // so public search is never interrupted.
    $isPublicToAdd = $connection->fetchAllAssociative(<<<'SQL'
        SELECT `i`.`engine_id` AS `engine_id`, `i`.`alias`, `i`.`pool`, `i`.`settings`
        FROM `solr_map` `i`
        LEFT JOIN `solr_map` `b`
            ON `b`.`engine_id` = `i`.`engine_id`
            AND `b`.`source` = 'is_public'
            AND `b`.`field_name` = 'is_public_b'
        WHERE `i`.`source` = 'is_public'
            AND `i`.`field_name` = 'is_public_i'
            AND `b`.`id` IS NULL;
        SQL);
    $addedEngineIds = [];
    foreach ($isPublicToAdd as $row) {
        $connection->executeStatement(
            'INSERT INTO `solr_map` (`engine_id`, `resource_name`, `field_name`, `alias`, `source`, `pool`, `settings`) VALUES (?, ?, ?, ?, ?, ?, ?);',
            [(int) $row['engine_id'], 'generic', 'is_public_b', $row['alias'], 'is_public', $row['pool'], $row['settings']]
        );
        $addedEngineIds[$row['engine_id']] = (int) $row['engine_id'];
    }
    if ($addedEngineIds) {
        $services->get('Omeka\Job\Dispatcher')->dispatch(
            \AdvancedSearch\Job\IndexSearch::class,
            ['search_engine_ids' => array_values($addedEngineIds)]
        );
        $messenger->addNotice(new PsrMessage(
            'A boolean visibility index "is_public_b" was added and indexing relaunched. The legacy "is_public_i" stays active until the reindex completes, then it is removed automatically on a later upgrade (no interruption).' // @translate
        ));
    }

    // The Solr passwords are now stored encrypted at rest via Omeka\Cipher.
    // encrypt() is idempotent and skips a value that is already encrypted.
    $cipherAtRest = $services->get('Omeka\Cipher');
    foreach ($connection->fetchAllAssociative("SELECT `id`, `settings` FROM `search_engine` WHERE `adapter` = 'solarium'") as $engineRow) {
        $engineSettings = json_decode((string) $engineRow['settings'], true) ?: [];
        if (empty($engineSettings['solr']['client']) || !is_array($engineSettings['solr']['client'])) {
            continue;
        }
        $changed = false;
        foreach (['password', 'admin_password'] as $key) {
            $value = (string) ($engineSettings['solr']['client'][$key] ?? '');
            if ($value === '') {
                continue;
            }
            $encrypted = $cipherAtRest->encrypt($value);
            if ($encrypted !== $value) {
                $engineSettings['solr']['client'][$key] = $encrypted;
                $changed = true;
            }
        }
        if ($changed) {
            $connection->executeStatement(
                'UPDATE `search_engine` SET `settings` = ? WHERE `id` = ?;',
                [json_encode($engineSettings, 320), (int) $engineRow['id']]
            );
        }
    }

    // Ensure the suggester and the api config of the first solarium engine.
    $firstEngineId = (int) $connection->fetchOne(
        "SELECT `id` FROM `search_engine` WHERE `adapter` = 'solarium' ORDER BY `id` ASC"
    );
    if ($firstEngineId) {
        $this->createDefaultSearchEngines($connection, $firstEngineId, $messenger, $url);
    }
}

// Not version-gated on purpose: the finalization must retry on every later
// upgrade until the reindex launched by phase 1 has populated the new field.
// Self-guarded and cheap once done (both maps no longer coexist).
// Phase 2: once "is_public_b" is populated in Solr, drop the legacy
// "is_public_i" so the querier switches to the boolean field, and align the
// rare search configs referencing the raw field name. Resilient: an engine
// that is unreachable or not yet reindexed is skipped and retried on the next
// upgrade. The engines are read with direct sql (no api during upgrade) and
// the document count is queried on Solr from the connection stored in the
// engine settings (passwords decrypted at rest).
$finalized = false;
$cipher = $services->get('Omeka\Cipher');
$decryptPassword = function ($value) use ($cipher) {
    if ($value === null || $value === '') {
        return (string) $value;
    }
    try {
        return $cipher->decrypt((string) $value);
    } catch (\Throwable $e) {
        // Legacy clear value stored before encryption at rest.
        return (string) $value;
    }
};
$fieldDocCount = function (array $client, string $field) use ($decryptPassword): ?int {
    $scheme = $client['scheme'] ?? null;
    $host = $client['host'] ?? null;
    $core = $client['core'] ?? null;
    if (!$scheme || !$host || !$core) {
        return null;
    }
    $base = $scheme . '://' . $host . (empty($client['port']) ? '' : ':' . $client['port']) . '/solr/' . $core;
    $header = null;
    if (!empty($client['username'])) {
        $header = 'Authorization: Basic ' . base64_encode($client['username'] . ':' . $decryptPassword($client['password'] ?? ''));
    }
    $response = @file_get_contents(
        $base . '/admin/luke?numTerms=0&fl=' . urlencode($field),
        false,
        stream_context_create(['http' => ['timeout' => 10, 'header' => $header]])
    );
    if ($response === false) {
        return null;
    }
    $luke = json_decode($response, true);
    if (!is_array($luke) || !isset($luke['fields'])) {
        return null;
    }
    return (int) ($luke['fields'][$field]['docs'] ?? 0);
};
foreach ($connection->fetchAllAssociative("SELECT `id`, `settings` FROM `search_engine` WHERE `adapter` = 'solarium'") as $engineRow) {
    $engineId = (int) $engineRow['id'];
    $engineSettings = json_decode((string) $engineRow['settings'], true) ?: [];
    $client = $engineSettings['solr']['client'] ?? [];
    $mapsByField = [];
    foreach ($connection->fetchAllAssociative(
        "SELECT `id`, `field_name` FROM `solr_map` WHERE `engine_id` = ? AND `source` = 'is_public' AND `field_name` IN ('is_public_i', 'is_public_b')",
        [$engineId]
    ) as $mapRow) {
        $mapsByField[$mapRow['field_name']] = (int) $mapRow['id'];
    }
    if (!isset($mapsByField['is_public_i'], $mapsByField['is_public_b'])) {
        continue;
    }
    $docs = $fieldDocCount($client, 'is_public_b');
    if ($docs !== null && $docs > 0) {
        $connection->executeStatement('DELETE FROM `solr_map` WHERE `id` = ?;', [$mapsByField['is_public_i']]);
        $finalized = true;
    }
}
if ($finalized) {
    // Aliases stay "is_public" and are untouched; "has_media_b" never matched.
    $connection->executeStatement(<<<'SQL'
        UPDATE `search_config`
        SET `settings` = REPLACE(`settings`, 'is_public_i', 'is_public_b')
        WHERE `settings` LIKE '%is_public_i%';
        SQL);
    $messenger->addSuccess(new PsrMessage(
        'The visibility index switched to "is_public_b"; the legacy "is_public_i" was removed.' // @translate
    ));
}

// Stamp provenance on historical maps created before the sync tracked it.
// Without provenance, the alignment relies on a heuristic only, so a plain
// legacy map not referenced by any config would be removed at the first clean.
// A map with a custom formatter, normalization, boost, pool filter, visibility
// or a renamed field is flagged "manual" and therefore never removed; the
// generic and system maps are flagged "system"; all the others become "sync",
// so they are managed automatically from now on.
$mapRows = $connection->fetchAllAssociative(
    'SELECT `id`, `source`, `field_name`, `settings`, `pool` FROM `solr_map`;'
);
$stamped = ['manual' => 0, 'system' => 0, 'sync' => 0];
foreach ($mapRows as $mapRow) {
    $mapSettings = json_decode((string) $mapRow['settings'], true) ?: [];
    if (array_key_exists('origin', $mapSettings)) {
        continue;
    }
    $pool = json_decode((string) $mapRow['pool'], true) ?: [];
    $source = (string) $mapRow['source'];

    $visibility = $pool['filter_visibility'] ?? '';
    $isCustomized = !empty($mapSettings['formatter'])
        || !empty($mapSettings['normalization'])
        || (!empty($mapSettings['boost']) && (float) $mapSettings['boost'] !== 1.0)
        || !empty($pool['filter_values'])
        || !empty($pool['filter_uris'])
        || !empty($pool['filter_resources'])
        || !empty($pool['filter_value_resources'])
        || !empty($pool['data_types'])
        || !empty($pool['data_types_exclude'])
        || !empty($pool['filter_languages'])
        || ($visibility !== '' && $visibility !== 'default');
    if (!$isCustomized && strpos($source, ':') !== false) {
        $expectedPrefix = strtr($source, ':', '_') . '_';
        $isCustomized = strpos((string) $mapRow['field_name'], $expectedPrefix) !== 0;
    }

    if ($isCustomized) {
        $origin = 'manual';
    } elseif (strpos($source, ':') === false || strpos($source, '/') !== false) {
        $origin = 'system';
    } else {
        $origin = 'sync';
    }

    $mapSettings['origin'] = $origin;
    $connection->executeStatement(
        'UPDATE `solr_map` SET `settings` = ? WHERE `id` = ?;',
        [json_encode($mapSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $mapRow['id']]
    );
    ++$stamped[$origin];
}
if (array_sum($stamped)) {
    $messenger->addSuccess(new PsrMessage(
        'The provenance was set on the historical maps: {sync} automatic, {manual} manual (customized, never removed), {system} system.', // @translate
        ['sync' => $stamped['sync'], 'manual' => $stamped['manual'], 'system' => $stamped['system']]
    ));
    $messenger->addWarning(new PsrMessage(
        'It is recommended to align the maps manually on each Solr core, then to reindex: the automatic maps not used by any config will be removed when the cleaning is checked.' // @translate
    ));
}

if (version_compare($oldVersion, '3.5.71', '<')) {
    // The column "engine_id" was appended at the end of the table when the cores
    // were merged into the engines. It identifies the owner of the map, so it is
    // moved right after the id, like in the schema of a new install. Idempotent:
    // the position is checked first, and the constraint and the indexes are kept by
    // a modification in place.
    $positionEngineId = (int) $connection->fetchOne(
        "SELECT `ORDINAL_POSITION`
        FROM `INFORMATION_SCHEMA`.`COLUMNS`
        WHERE `TABLE_SCHEMA` = DATABASE()
            AND `TABLE_NAME` = 'solr_map'
            AND `COLUMN_NAME` = 'engine_id'"
    );
    if ($positionEngineId > 2) {
        $connection->executeStatement('ALTER TABLE `solr_map` MODIFY `engine_id` INT NOT NULL AFTER `id`;');
    }

    // The date of the indexation is a default field: it is the only date the index
    // knows and the database does not, so it allows to find the documents that were
    // not reindexed after a change of their resource. Add it to the engines that
    // have no such map yet. A reindexation is needed to fill it.
    $engineIdsSolr = $connection->fetchFirstColumn(
        "SELECT `id` FROM `search_engine` WHERE `adapter` = 'solarium' ORDER BY `id` ASC"
    );
    $engineIdsIndexedAt = [];
    foreach ($engineIdsSolr as $engineIdSolr) {
        $hasMapIndexedAt = (bool) $connection->fetchOne(
            'SELECT `id` FROM `solr_map` WHERE `engine_id` = ? AND `source` = ?',
            [$engineIdSolr, 'indexed_at']
        );
        if ($hasMapIndexedAt) {
            continue;
        }
        $connection->executeStatement(
            'INSERT INTO `solr_map` (`engine_id`, `resource_name`, `field_name`, `alias`, `source`, `pool`, `settings`) VALUES (?, ?, ?, ?, ?, ?, ?);',
            [
                $engineIdSolr,
                'generic',
                'indexed_at_dt',
                'indexed_at',
                'indexed_at',
                '[]',
                json_encode(['label' => 'Indexed at', 'origin' => 'system'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
        $engineIdsIndexedAt[] = $engineIdSolr;
    }
    if ($engineIdsIndexedAt) {
        $messenger->addSuccess(new PsrMessage(
            'The date of indexation is now indexed ("indexed_at_dt"), so the check of the parity can find the documents that were not reindexed after a change of their resource. Reindex to fill it.' // @translate
        ));
    }

    // Some historical maps reference a source that matches no extractor, so the
    // index is declared but stays empty: the scope glued to the source
    // ("generic:title"), a separator ":" instead of "_" ("has:media"), a truncated
    // name, or a property that no longer exists. Normalize what can be, and remove
    // the maps that point to nothing and are already mapped elsewhere.
    $sourcesRenamed = [
        // The scope is not part of the source.
        'generic:title' => 'o:title',
        'generic:property_values' => 'property_values',
        // The separator of a source is "_", and a path uses "/".
        'has:media' => 'has_media',
        'resource:name' => 'resource_name',
        'resource:class' => 'resource_class',
        'resource:template_id' => 'resource_template/o:id',
        // The name was truncated.
        'selection_public' => 'selection_public_id',
    ];
    // A property that was renamed in its vocabulary, or that never existed: there
    // is nothing to point to, so the map is removed.
    $sourcesRemoved = [
        // Renamed as "curation:end" by the vocabulary of the modules that install
        // it, and the maps of the new name already exist.
        'curation:dateEnd',
        'curation:dateStart',
        // Never existed in the vocabulary Dublin Core terms.
        'dcterms:temporal_uri',
    ];

    $mapsNormalized = [];
    $mapsRemovedDuplicate = [];
    $mapsRemovedUnknown = [];
    $mapsSourceInvalid = $connection->fetchAllAssociative(
        'SELECT `id`, `engine_id`, `resource_name`, `field_name`, `source` FROM `solr_map` ORDER BY `id` ASC'
    );
    // A source prefixed with "va:" was an attempt to index a value annotation,
    // but the syntax of the extractor is "value_annotations[/term]" or
    // "term/annotation[/term]": the index stayed empty. Remove them, unless a real
    // vocabulary uses this prefix.
    $hasVocabularyVa = (bool) $connection->fetchOne(
        'SELECT `id` FROM `vocabulary` WHERE `prefix` = ? LIMIT 1',
        ['va']
    );

    foreach ($mapsSourceInvalid as $mapRow) {
        $source = (string) $mapRow['source'];

        if (!$hasVocabularyVa && strncmp($source, 'va:', 3) === 0) {
            $connection->executeStatement('DELETE FROM `solr_map` WHERE `id` = ?', [$mapRow['id']]);
            $mapsRemovedUnknown[] = $mapRow['field_name'] . ' (' . $source . ')';
            continue;
        }

        if (in_array($source, $sourcesRemoved, true)) {
            $connection->executeStatement('DELETE FROM `solr_map` WHERE `id` = ?', [$mapRow['id']]);
            $mapsRemovedUnknown[] = $mapRow['field_name'] . ' (' . $source . ')';
            continue;
        }

        if (isset($sourcesRenamed[$source])) {
            $sourceNormalized = $sourcesRenamed[$source];
        } elseif ($source === 'item:set_id' || $source === 'resource:template') {
            // The data is the id or the label, according to the type of the solr
            // field: an integer field cannot hold a label.
            $isFieldInteger = (bool) preg_match('~_(i|is|l|ls)$~', (string) $mapRow['field_name']);
            if ($source === 'item:set_id') {
                $sourceNormalized = $isFieldInteger ? 'item_set/o:id' : 'item_set/o:title';
            } else {
                $sourceNormalized = $isFieldInteger ? 'resource_template/o:id' : 'resource_template';
            }
        } else {
            continue;
        }

        $idDuplicate = (int) $connection->fetchOne(
            'SELECT `id` FROM `solr_map` WHERE `engine_id` = ? AND `resource_name` = ? AND `field_name` = ? AND `source` = ? AND `id` != ? LIMIT 1',
            [$mapRow['engine_id'], $mapRow['resource_name'], $mapRow['field_name'], $sourceNormalized, $mapRow['id']]
        );
        if ($idDuplicate) {
            $connection->executeStatement('DELETE FROM `solr_map` WHERE `id` = ?', [$mapRow['id']]);
            $mapsRemovedDuplicate[] = $mapRow['field_name'] . ' (' . $source . ')';
            continue;
        }

        $connection->executeStatement(
            'UPDATE `solr_map` SET `source` = ? WHERE `id` = ?',
            [$sourceNormalized, $mapRow['id']]
        );
        $mapsNormalized[] = $source . ' → ' . $sourceNormalized;
    }

    if ($mapsNormalized) {
        $messenger->addSuccess(new PsrMessage(
            'The sources of some maps matched no extractor, so their index stayed empty: {sources}. Reindex to fill them.', // @translate
            ['sources' => implode(', ', array_unique($mapsNormalized))]
        ));
    }
    if ($mapsRemovedDuplicate) {
        $messenger->addWarning(new PsrMessage(
            'Some maps were removed, because the same index was already mapped to the same source: {maps}.', // @translate
            ['maps' => implode(', ', $mapsRemovedDuplicate)]
        ));
    }
    if ($mapsRemovedUnknown) {
        $messenger->addWarning(new PsrMessage(
            'Some maps were removed, because they point to a property that no longer exists: {maps}.', // @translate
            ['maps' => implode(', ', $mapsRemovedUnknown)]
        ));
    }

    $messenger->addSuccess(new PsrMessage(
        'The alignment of the maps has new sources: the values of the media, indexed on their item, the numeric values, that get an integer or decimal index for a real sort and range facets, and the geographic coordinates, that get a spatial index. A limit of distinct values avoids the exact indexes that cannot be browsed. See the sidebar "Align the maps" of a Solr core.' // @translate
    ));

    $messenger->addWarning(new PsrMessage(
        'The geographic points were never indexed: reindex to get them.' // @translate
    ));

    $messenger->addWarning(new PsrMessage(
        'A sync and a re-indexation are recommended.' // @translate
    ));
}
