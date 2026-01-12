<?php declare(strict_types=1);

namespace SearchSolr\Controller\Admin;

use SearchSolr\Api\Representation\SolrCoreRepresentation;

trait TraitSolrController
{
    /**
     * List all single field names from solr maps.
     */
    protected function listFieldNames(SolrCoreRepresentation $solrCore): array
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
    protected function prepareFieldsBoost(SolrCoreRepresentation $solrCore): array
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
    protected function updateFieldsBoost(SolrCoreRepresentation $solrCore): void
    {
        $solrCoreSettings = $solrCore->settings();
        $solrCoreSettings['field_boost'] = $this->prepareFieldsBoost($solrCore);
        $this->api()->update('solr_cores', $solrCore->id(), ['o:settings' => $solrCoreSettings], [], ['isPartial' => true]);
    }
}
