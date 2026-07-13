<?php declare(strict_types=1);

namespace SearchSolr\Controller\Admin;

use SearchSolr\Stdlib\SolrCore;

trait TraitSolrController
{
    /**
     * List all single field names from solr maps.
     */
    protected function listFieldNames(SolrCore $solrCore): array
    {
        $fields = [];
        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation $map */
        foreach ($solrCore->mapsOrderedByStructure() as $map) {
            $field = $map->fieldName();
            $fields[$field] = $field;
        }
        return $fields;
    }

    /**
     * Build Solr query fields with boost multipliers from solr maps as array.
     *
     * All the fields are included by default, else they will be excluded from
     * any search.
     *
     * @return array Associative array [field => boost], with boost default 1.0.
     * @todo Keep only the "_txt", dates fields and other contents fields? Not ids?
     */
    protected function prepareFieldsBoost(SolrCore $solrCore): array
    {
        $fields = [];
        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation $map */
        foreach ($solrCore->mapsOrderedByStructure() as $map) {
            $field = $map->fieldName();
            $boost = $map->setting('boost');
            $fields[$field] = ($boost && is_numeric($boost) && $boost > 0)
                ? (float) $boost
                : 1.0;
        }
        return $fields;
    }

    /**
     * @todo Ideally, the update of the core should be done one time via an event.
     */
    protected function updateFieldsBoost(SolrCore $solrCore): void
    {
        $solrCoreSettings = $solrCore->settings();
        $solrCoreSettings['field_boost'] = $this->prepareFieldsBoost($solrCore);
        $this->updateSolrSettings($solrCore->id(), $solrCoreSettings);
    }

    /**
     * The Solr core of an engine: a facet of the engine (facade).
     */
    protected function solrCore(?int $id = null): SolrCore
    {
        $id = $id ?: (int) $this->params('id');
        /** @var \AdvancedSearch\Api\Representation\SearchEngineRepresentation $engine */
        $engine = $this->api()->read('search_engines', $id)->getContent();
        $services = $this->getEvent()->getApplication()->getServiceManager();
        return new SolrCore($engine, $services);
    }

    /**
     * Save the solr settings of the engine (facet "solr" of its settings).
     */
    protected function updateSolrSettings(int $engineId, array $solrSettings): void
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');
        $engineSettings = json_decode((string) $connection->fetchOne(
            'SELECT `settings` FROM `search_engine` WHERE `id` = ?', [$engineId]
        ), true) ?: [];
        $engineSettings['solr'] = $solrSettings;
        $connection->executeStatement(
            'UPDATE `search_engine` SET `settings` = ?, `modified` = NOW() WHERE `id` = ?;',
            [json_encode($engineSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $engineId]
        );
    }
}
