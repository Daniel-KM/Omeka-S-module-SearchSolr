<?php declare(strict_types=1);
namespace SearchSolr;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Omeka\Module\Manager $moduleManager
 * @var \Omeka\Settings\Settings $settings
 */
$connection = $services->get('Omeka\Connection');
$settings = $services->get('Omeka\Settings');
$moduleManager = $services->get('Omeka\ModuleManager');
$solrModule = $moduleManager->getModule('Solr');

if (!$solrModule) {
    return;
}

$oldVersion = $solrModule->getIni('version');
if (version_compare($oldVersion, '3.5.5', '<')
    || version_compare($oldVersion, '3.5.14', '>')
) {
    $message = new \Omeka\Stdlib\Message(
        'The version of the module Solr should be at least %s.', // @translate
        '3.5.5'
    );
    $messenger = new \Omeka\Mvc\Controller\Plugin\Messenger;
    $messenger->addWarning($message);
    return;
}

// Check if Solr was really installed.
try {
    $connection->fetchAll('SELECT id FROM solr_node LIMIT 1;');
} catch (\Exception $e) {
    return;
}

// Apply all upgrades since 3.5.5.
// Copy from the module Solr.

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

if (version_compare($oldVersion, '3.5.12', '<')) {
    // Move query_all to search settings.
    $sql = <<<'SQL'
SELECT id, settings FROM solr_node;
SQL;
    $stmt = $connection->executeQuery($sql);
    $solrNodes = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    foreach ($solrNodes as $solrNodeId => $solrNodeSettings) {
        $solrNodeSettings = json_decode($solrNodeSettings, true) ?: [];
        $queryAlt = empty($solrNodeSettings['query']['query_alt']) ? '*:*' : $solrNodeSettings['query']['query_alt'];
        unset($solrNodeSettings['query']['query_alt']);
        $solrNodeSettings = $connection->quote(json_encode($solrNodeSettings, 320));
        $sql = <<<SQL
UPDATE solr_node
SET `settings` = $solrNodeSettings
WHERE id = $solrNodeId;
SQL;
        $connection->exec($sql);

        $sql = <<<'SQL'
SELECT id, settings FROM search_engine WHERE adapter = "solr";
SQL;
        $stmt = $connection->executeQuery($sql);
        $searchEngines = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach ($searchEngines as $searchEngineId => $searchEngineSettings) {
            $searchEngineSettings = json_decode($searchEngineSettings, true) ?: [];
            if (isset($searchEngineSettings['adapter']['solr_node_id'])
                && (int) $searchEngineSettings['adapter']['solr_node_id'] === $solrNodeId
            ) {
                $sql = <<<SQL
SELECT id, settings FROM search_config WHERE engine_id = $searchEngineId;
SQL;
                $stmt = $connection->executeQuery($sql);
                $searchConfigs = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
                foreach ($searchConfigs as $searchConfigId => $searchConfigSettings) {
                    $searchConfigSettings = json_decode($searchConfigSettings, true) ?: [];
                    $searchConfigSettings['default_results'] = 'query';
                    $searchConfigSettings['default_query'] = $queryAlt;
                    $searchConfigSettings = $connection->quote(json_encode($searchConfigSettings, 320));
                    $sql = <<<SQL
UPDATE search_config
SET `settings` = $searchConfigSettings
WHERE id = $searchConfigId;
SQL;
                    $connection->exec($sql);
                }
            }
        }
    }
}

// Rename tables for module SearchSolr.
// The tables of the current module should be removed too.

$scheme = !empty($services->get('Config')['solr']['config']['secure']) ? 'https' : 'http';
$bypass = !empty($services->get('Config')['solr']['config']['solr_bypass_certificate_check']) ? 'true' : 'false';

// Copy is used instead of rename. The tables are created during install.
$sql = <<<SQL
INSERT solr_core (id, name, settings)
SELECT id, name, settings FROM solr_node;

INSERT solr_map (id, solr_core_id, resource_name, field_name, source, settings)
SELECT id, solr_node_id, resource_name, field_name, source, settings FROM solr_mapping;

UPDATE solr_core
SET
    settings =
        REPLACE(
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    settings,
                                    '"secure":',
                                    '"bypass_certificate_check":$bypass,"secure":'
                                ),
                                '"hostname":',
                                '"scheme":"$scheme","host":'
                            ),
                            '"login":',
                            '"username":'
                        ),
                        '"path":"solr/',
                        '"core":"'
                    ),
                    '"path":"/solr/',
                    '"core":"'
                ),
                '"path":"solr\\\\/',
                '"core":"'
            ),
            '"path":"\\\\/solr\\\\/',
            '"core":"'
        );

UPDATE search_engine
SET
    adapter = "solarium",
    settings =
        REPLACE(
            settings,
            "solr_node_id",
            "solr_core_id"
        )
WHERE adapter = "solr";

# Uninstall the module Solr.
DROP TABLE IF EXISTS `solr_mapping`;
DROP TABLE IF EXISTS `solr_node`;

DELETE FROM module WHERE id = "Solr";
SQL;

$sqls = array_filter(array_map('trim', explode(";\n", $sql)));
foreach ($sqls as $sql) {
    $connection->exec($sql);
}

// Convert the settings.
// None, but there may be "solr_bypass_certificate_check" in the local config in Omeka.

$message = new \Omeka\Stdlib\Message(
    'The module Solr was upgraded by module SearchSolr and uninstalled.' // @translate
);
$messenger = new \Omeka\Mvc\Controller\Plugin\Messenger;
$messenger->addWarning($message);
