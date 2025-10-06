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

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.72')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.72'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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
    } catch (\Exception $e) {
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
    $sql = <<<'SQL'
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
    $solrCoreIds = $connection->executeQuery($qb)->fetchAllKeyValue();
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
            $solrCoreMaps = $connection->executeQuery($qb)->rowCount();
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
    $solrMapIds = $connection->executeQuery($qb)->fetchAllKeyValue();
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
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
    }

    $sql = <<<'SQL'
        ALTER TABLE `solr_map`
        ADD `alias` VARCHAR(190) DEFAULT NULL AFTER `source`;
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
            'link' => sprintf('<a href="%s">', $url('admin/default', ['controller' => 'module', 'action' => 'configure'], ['query' => ['id' => 'SearchSolr']])),
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
