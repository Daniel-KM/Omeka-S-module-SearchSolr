<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2017-2021
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace SearchSolr\Indexer;

use AdvancedSearch\Indexer\AbstractIndexer;
use AdvancedSearch\Indexer\IndexerInterface;
use AdvancedSearch\Query;
use Omeka\Entity\Resource;
use Omeka\Stdlib\Message;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use SearchSolr\Api\Representation\SolrMapRepresentation;
use Solarium\Client as SolariumClient;
use Solarium\Exception\HttpException as SolariumServerException;
use Solarium\QueryType\Update\Query\Document as SolariumInputDocument;

/**
 * @link https://solarium.readthedocs.io/en/stable/getting-started/
 * @link https://solarium.readthedocs.io/en/stable/documents/
 */
class SolariumIndexer extends AbstractIndexer
{
    /**
     * @var SolrCoreRepresentation
     */
    protected $solrCore;

    /**
     * @var SolariumClient
     */
    protected $solariumClient;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Omeka\Api\Adapter\Manager
     */
    protected $apiAdapters;

    /**
     * @var \SearchSolr\ValueExtractor\Manager
     */
    protected $valueExtractorManager;

    /**
     * @var \SearchSolr\ValueFormatter\Manager
     */
    protected $valueFormatterManager;

    /**
     * @var \Solarium\Core\Query\Helper
     */
    protected $solariumHelpers;

    /**
     * @var array
     */
    protected $formatters = [];

    /**
     * @var array
     */
    protected $isSingleValuedFields = [];

    /**
     * @var int[]
     */
    protected $siteIds;

    /**
     * @var string
     */
    protected $serverId;

    /**
     * @var string|false
     */
    protected $indexField;

    /**
     * @var string
     */
    protected $indexName;

    /**
     * @var string|false
     */
    protected $support;

    /**
     * @var array
     */
    protected $supportFields;

    /**
     * @var array
     */
    protected $vars = [];

    /**
     * @var \Solarium\QueryType\Update\Query\Document[]
     */
    protected $solariumDocuments = [];

    public function canIndex(string $resourceName): bool
    {
        return $this->getServiceLocator()
            ->get('SearchSolr\ValueExtractorManager')
            ->has($resourceName);
    }

    public function clearIndex(?Query $query = null): IndexerInterface
    {
        // Solr does not use the same query format than the one used for select:
        // filter queries cannot be used directly. So use them as query part.

        // Limit deletion to current search index in case of a multi-index core.
        $this->prepareIndexFieldAndName();

        $isQuery = false;
        if ($query) {
            /** @var \Solarium\QueryType\Select\Query\Query|null $solariumQuery */
            $solariumQuery = $this->engine->querier()
                ->setQuery($query)
                ->getPreparedQuery();
            $isQuery = !is_null($solariumQuery);
        }

        if ($isQuery) {
            $query = $solariumQuery->getQuery() ?: '*:*';
            $isDefaultQuery = $query === '*:*';
            $filterQueries = $solariumQuery->getFilterQueries();
            if (count($filterQueries)) {
                foreach ($filterQueries as $filterQuery) {
                    $query .= ' AND ' . $filterQuery->getQuery();
                }
                if ($isDefaultQuery) {
                    $query = mb_substr($query, 8);
                }
            }
        } else {
            $query = $this->indexField
                ? "$this->indexField:$this->indexName"
                : '*:*';
        }

        $client = $this->getClient();
        $update = $client
            ->createUpdate()
            ->addDeleteQuery($query)
            ->addCommit();
        $client->update($update);
        return $this;
    }

    public function indexResource(Resource $resource): IndexerInterface
    {
        return $this->indexResources([$resource]);
    }

    /**
     * @var \Omeka\Entity\AbstractEntity[] $resources
     */
    public function indexResources(array $resources): IndexerInterface
    {
        if (!count($resources)) {
            return $this;
        }

        if (empty($this->api)) {
            $this->init();
        }

        $resourceNames = [
            'items' => 'item',
            'item_sets' => 'item set',
        ];

        $resourcesIds = [];
        foreach ($resources as $resource) {
            $resourcesIds[] = $resourceNames[$resource->getResourceName()] . ' #' . $resource->getId();
        }
        $this->getLogger()->info(new Message(
            'Indexing in Solr core "%1$s": %2$s', // @translate
            $this->solrCore->name(), implode(', ', $resourcesIds)
        ));

        foreach ($resources as $resource) {
            $this->addResource($resource);
        }
        $this->commit();

        return $this;
    }

    public function deleteResource(string $resourceName, $resourceId): IndexerInterface
    {
        // Some values should be init to get the document id.
        $this->getServerId();
        $this->prepareIndexFieldAndName();

        $documentId = $this->getDocumentId($resourceName, $resourceId);
        $client = $this->getClient();
        $update = $client
            ->createUpdate()
            ->addDeleteById($documentId)
            ->addCommit();
        $client->update($update);
        return $this;
    }

    /**
     * Initialize the indexer.
     *
     * @todo Create/use a full service manager factory.
     */
    protected function init()
    {
        $services = $this->getServiceLocator();
        $this->api = $services->get('Omeka\ApiManager');
        $this->apiAdapters = $services->get('Omeka\ApiAdapterManager');
        $this->valueExtractorManager = $services->get('SearchSolr\ValueExtractorManager');
        $this->valueFormatterManager = $services->get('SearchSolr\ValueFormatterManager');
        $this->siteIds = $this->api->search('sites', [], ['returnScalar' => 'id'])->getContent();

        // Some values should be init to get the document id.
        $this->getServerId();
        $this->prepareIndexFieldAndName();
        $this->prepareSingleValuedFields();
        $this->getSupportFields();
        $this->prepareFomatters();

        $doc = new SolariumInputDocument();
        $this->solariumHelpers = $doc->getHelper();

        return $this;
    }

    protected function getDocumentId($resourceName, $resourceId)
    {
        // Adapted Drupal convention to be used for any single or multi-index.
        // @link https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/solr-conf-templates/8.x/schema.xml#L131-141
        // The 0-formatted id allows to sort quickly on id.
        if (empty($this->serverId)) {
            return empty($this->indexField)
                ? sprintf('%s/%07s', $resourceName, $resourceId)
                : sprintf('%s-%s/%07s', $this->indexName, $resourceName, $resourceId);
        }
        return empty($this->indexField)
            ? sprintf('%s-%s/%07s', $this->serverId, $resourceName, $resourceId)
            : sprintf('%s-%s-%s/%07s', $this->serverId, $this->indexName, $resourceName, $resourceId);
    }

    protected function addResource(Resource $resource): void
    {
        $resourceName = $resource->getResourceName();
        $resourceId = $resource->getId();

        $solrCoreSettings = $this->solrCore->settings();
        /** @var \SearchSolr\ValueExtractor\ValueExtractorInterface $valueExtractor */
        $valueExtractor = $this->valueExtractorManager->get($resourceName);

        /** @var \Omeka\Api\Representation\AbstractResourceRepresentation $representation */
        $adapter = $this->apiAdapters->get($resourceName);
        $representation = $adapter->getRepresentation($resource);

        $isSingleFieldFilled = [];

        $document = new SolariumInputDocument;

        $documentId = $this->getDocumentId($resourceName, $resourceId);
        $document->addField('id', $documentId);

        if ($this->indexField) {
            $document->addField($this->indexField, $this->indexName);
        }

        // Force the indexation of visibility, resource type and sites, even if
        // not selected in mapping, because they are the base of Omeka.

        $isPublicField = $solrCoreSettings['is_public_field'];
        $document->addField($isPublicField, $resource->isPublic());

        $resourceNameField = $solrCoreSettings['resource_name_field'];
        $document->addField($resourceNameField, $resourceName);

        $sitesField = $solrCoreSettings['sites_field'];
        switch ($resourceName) {
            case 'items':
                $sites = array_map(function (\Omeka\Entity\Site $v) {
                    return $v->getId();
                }, $resource->getSites()->toArray());
                $document->addField($sitesField, array_values($sites));
                break;

            case 'item_sets':
                $sites = array_map(function (\Omeka\Entity\SiteItemSet $v) {
                    return $v->getSite()->getId();
                }, $resource->getSiteItemSets()->toArray());
                $document->addField($sitesField, $sites);
                break;

            default:
                return;
        }

        foreach ($this->solrCore->mapsByResourceName($resourceName) as $solrMap) {
            $solrField = $solrMap->fieldName();
            $source = $solrMap->source();

            // Index the required fields one time only except if the admin wants
            // to store it in a different field too.
            if ($source === 'is_public' && $solrField === $isPublicField) {
                continue;
            }
            // The admin can’t modify this parameter via the standard interface.
            if ($source === 'resource_name' && $solrField === $sitesField) {
                continue;
            }
            // The admin can’t modify this parameter via the standard interface.
            if ($source === 'site/o:id' && $solrField === $sitesField) {
                continue;
            }

            // Check if the value is a single valued field already filled.
            if (!empty($isSingleFieldFilled[$solrField])) {
                continue;
            }

            $values = $valueExtractor->extractValue($representation, $solrMap);
            if (!count($values)) {
                continue;
            }

            $values = $this->formatValues($values, $solrMap);
            if (!count($values)) {
                continue;
            }

            if ($this->isSingleValuedFields[$resourceName][$solrField]) {
                $isSingleFieldFilled[$solrField] = true;
                $document->addField($solrField, reset($values));
            } else {
                foreach ($values as $value) {
                    $document->addField($solrField, $value);
                }
            }

            if ($this->support === 'drupal') {
                // Only one value is filled for special values of Drupal.
                $this->appendDrupalValues($resource, $document, $solrField, reset($values));
            }
        }

        $this->appendSupportedFields($resource, $document);

        $this->solariumDocuments[$documentId] = $document;
    }

    protected function formatValues(array $values, SolrMapRepresentation $solrMap)
    {
        /** @var \SearchSolr\ValueFormatter\ValueFormatterInterface $valueFormatter */
        $valueFormatter = $this->formatters[$solrMap->setting('formatter', '')] ?: $this->formatters['standard'];
        $result = [];
        foreach ($values as $value) {
            $result = array_merge($result, $valueFormatter->format($value));
        }
        return $result;
    }

    protected function appendSupportedFields(Resource $resource, SolariumInputDocument $document): void
    {
        foreach ($this->supportFields as $solrField => $value) switch ($solrField) {
            // Drupal.
            case 'index_id':
                // Already set for multi-index, and it is a single value.
                break;
            case 'site':
            case 'hash':
            case 'timestamp':
            case 'boost_document':
            case 'ss_search_api_language':
            case 'sm_context_tags':
                $document->addField($solrField, $value);
                break;
            case 'ss_search_api_datasource':
                $document->addField($solrField, $resource->getResourceName());
                break;
            case 'ss_search_api_id':
                $document->addField($solrField, $resource->getResourceName() . '/' . $resource->getId());
                break;
            default:
                // Nothing to do.
                break;
        }
    }

    /**
     * Check if a value is not null neither an empty string.
     *
     * @param mixed $value
     * @return bool
     */
    protected function isNotNullAndNotEmptyString($value): bool
    {
        return !is_null($value)
            && (string) $value !== '';
    }

    /**
     * Commit the prepared documents.
     */
    protected function commit(): void
    {
        if (!count($this->solariumDocuments)) {
            $this->getLogger()->notice('No document to commit in Solr.'); // @translate
            return;
        }

        // TODO use BufferedAdd plugin?
        $client = $this->getClient();
        try {
            $update = $client
                ->createUpdate()
                ->addDocuments($this->solariumDocuments)
                ->addCommit();
            $client->update($update);
        } catch (\Exception $e) {
            // Index documents by one to avoid to skip well formatted documents.
            $isMultiple = count($this->solariumDocuments) > 1;
            if ($isMultiple) {
                foreach ($this->solariumDocuments as $documentId => $document) {
                    try {
                        $update = $client
                            ->createUpdate()
                            ->addDocument($document)
                            ->addCommit();
                        $client->update($update);
                    } catch (SolariumServerException $e) {
                        $dId = explode('-', $documentId);
                        $error = json_decode((string) $e->getBody(), true);
                        $message = is_array($error) && isset($error['error']['msg']) ? $error['error']['msg'] : $e->getMessage();
                        $message = new Message('Indexing of resource %1$s failed: %2$s', array_pop($dId), $message);
                        $this->getLogger()->err($message);
                    } catch (\Exception $e) {
                        $dId = explode('-', $documentId);
                        $message = new Message('Indexing of resource %1$s failed: %2$s', array_pop($dId), $e->getMessage());
                        $this->getLogger()->err($message);
                    }
                }
            } else {
                $dId = explode('-', key($this->solariumDocuments));
                $error = json_decode((string) $e->getMessage(), true);
                $message = is_array($error) || isset($error['error']['msg']) ? $error['error']['msg'] : $e->getMessage();
                $message = new Message('Indexing of resource %1$s failed: %2$s', array_pop($dId), $message);
                $this->getLogger()->err($message);
            }
        }

        $this->solariumDocuments = [];
    }

    /**
     * Get the unique server id of the Omeka install.
     *
     * @return string
     */
    protected function getServerId()
    {
        // Inspired from module Drupal search api solr.
        // See search_api_solr\Utility\Utility::getSiteHash()).
        if (is_null($this->serverId)) {
            $this->serverId = $this->getSolrCore()->setting('server_id') ?: false;
        }
        return $this->serverId;
    }

    protected function prepareIndexFieldAndName()
    {
        $field = $this->getSolrCore()->setting('index_field') ?: false;
        $name = $this->engine->settingAdapter('index_name') ?: false;
        if ($field && $name) {
            $this->indexField = $field;
            $this->indexName = $name;
        } else {
            $this->indexField = false;
            $this->indexName = false;
        }
        return $this;
    }

    /**
     * Get the index field name for the multi-index, if set.
     *
     * @return string|false
     */
    protected function getIndexField()
    {
        if (is_null($this->indexField)) {
            $this->prepareIndexFieldAndName();
        }
        return $this->indexField;
    }

    /**
     * Get the index name of the search index for multi-index.
     *
     * @return string|false
     */
    protected function getIndexName()
    {
        if (is_null($this->indexName)) {
            $this->prepareIndexFieldAndName();
        }
        return $this->indexName;
    }

    /**
     * The mapper allows to prepare multiple maps to the same Solr field.
     * But this Solr field may be single valued, so a check should be done when
     * a multi-mapped single-value field is already filled in order not to send
     * multiple values to a single valued field.
     *
     * @todo Move the single valued check inside Solr core settings to do it one time.
     */
    protected function prepareSingleValuedFields(): void
    {
        $solrCore = $this->getSolrCore();
        $schema = $solrCore->schema();
        $this->isSingleValuedFields = [];
        foreach ($this->getSolrCore()->mapsByResourceName() as $resourceName => $solrMaps) {
            $this->isSingleValuedFields[$resourceName] = [];
            foreach ($solrMaps as $solrMap) {
                $solrField = $solrMap->fieldName();
                $schemaField = $schema->getField($solrField);
                $this->isSingleValuedFields[$resourceName][$solrField] = $schemaField && !$schemaField->isMultivalued();
            }
        }
    }

    protected function prepareFomatters(): void
    {
        $this->formatters = ['' => null];
        foreach ($this->valueFormatterManager->getRegisteredNames() as $formatter) {
            $this->formatters[$formatter] = $this->valueFormatterManager->get($formatter);
        }
        $this->formatters[''] = $this->formatters['standard'];
    }

    /**
     * Get the specific supported fields.
     *
     * @return array
     */
    protected function getSupportFields()
    {
        if (is_null($this->supportFields)) {
            $this->support = $this->solrCore->setting('support') ?: false;
            $this->supportFields = array_filter($this->solrCore->schemaSupport($this->support));
            // Manage some static values.
            foreach ($this->supportFields as $solrField => &$value) switch ($solrField) {
                // Drupal.
                // @link https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/solr-conf-templates/8.x/schema.xml
                // @link https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/src/Plugin/search_api/backend/SearchApiSolrBackend.php#L1060-1184
                case 'index_id':
                    $value = $this->indexName;
                    break;
                case 'site':
                    // The site should be the language site one, but urls don't
                    // include locale in Omeka.
                    // TODO Check if the site url should be the default site one or the root of Omeka.
                    $value = $this->solrCore->setting('site_url') ?: 'http://localhost/';
                    break;
                case 'hash':
                    $value = $this->serverId;
                    break;
                case 'timestamp':
                    // If this is It should be the same timestamp for all documents that are being indexed.
                    $value = gmdate('Y-m-d\TH:i:s\Z');
                    break;
                case 'boost_document':
                    // Boost at index time is not supported any more, but Drupal
                    // stores a value in each item and uses it during request.
                    $value = 1.0;
                    break;
                case 'sm_context_tags':
                    // No need to escape already cleaned index values.
                    $value = [
                        'drupal_X2f_langcode_X3a_' . (strtok($this->solrCore->setting('resource_languages'), ' ') ?: 'und'),
                        'search_api_X2f_index_X3a_' . $this->indexName,
                        'search_api_solr_X2f_site_hash_X3a_' . $this->serverId,
                    ];
                    break;
                case 'ss_search_api_language':
                    $value = strtok($this->solrCore->setting('resource_languages'), ' ') ?: 'und';
                    break;
                case 'ss_search_api_datasource':
                case 'ss_search_api_id':
                case 'boost_term':
                default:
                    // Nothing to do.
                    break;
            }
            unset($value);
        }
        if ($this->support === 'drupal') {
            $this->vars['locales'] = explode(' ', $this->solrCore->setting('resource_languages')) ?: ['und'];
            // In Drupal, dynamic fields are in schema.xml, except text ones, in
            // schema_extra_fields.xml.
            $dynamicFields = $this->solrCore->schema()->getDynamicFieldsMapByMainPart('prefix');
            foreach ($this->solrCore->mapsByResourceName() as $resourceName => $solrMaps) {
                $this->vars['solr_maps'][$resourceName] = [];
                foreach ($solrMaps as $solrMap) {
                    $source = $solrMap->source();
                    if (in_array($source, ['is_public', 'resource_name', 'site/o:id'])) {
                        continue;
                    }
                    $solrField = $solrMap->fieldName();
                    // Check if it is a dynamic field (prefix only.
                    $prefix = strtok($solrField, '_') . '_';
                    if (!isset($dynamicFields[$prefix])) {
                        continue;
                    }
                    $this->vars['solr_maps'][$resourceName][$solrField] = [
                        'prefix' => mb_substr($prefix, 0, -1),
                        'name' => mb_substr($solrField, mb_strlen($prefix)),
                    ];
                }
            }
        }
        return $this->supportFields;
    }

    /**
     * Specific fields for Drupal.
     *
     * @param SolariumInputDocument $document
     * @param string $solrField
     * @param mixed $value
     * @return self
     */
    protected function appendDrupalValues(Resource $resource, SolariumInputDocument $document, $solrField, $value)
    {
        $resourceName = $resource->getResourceName();
        if (!isset($this->vars['solr_maps'][$resourceName][$solrField])) {
            return $this;
        }

        // FIXME Use the translated values in Drupal sort fields.
        // In Drupal, the same value is copied in each language field for sort…
        // @link https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/src/Plugin/search_api/backend/SearchApiSolrBackend.php#L1130-1172
        // For example, field is "ss_title", so sort field is "sort_X3b_fr_title".
        // This is slighly different from Drupal process.
        $prefix = $this->vars['solr_maps'][$resourceName][$solrField]['prefix'];
        $matches = [];
        if (in_array(mb_substr($prefix . '_', 0, 3), ['tm_', 'ts_', 'sm_', 'ss_'])) {
            foreach ($this->vars['locales'] as $locale) {
                $field = 'sort_X3b_' . $locale . '_' . $this->vars['solr_maps'][$resourceName][$solrField]['name'];
                if (!$document->{$field}) {
                    $value = mb_substr((string) $value, 0, 128);
                    $document->addField($field, $value);
                }
            }
        } elseif (strpos($solrField, 'random_') !== 0
            && preg_match('/^([a-z]+)m(_.*)/', $solrField, $matches)
        ) {
            $field = $matches[1] . 's' . $matches[2];
            if (!$document->{$field}) {
                $document->addField($field, $value);
            }
        }
        return $this;
    }

    /**
     * @return \SearchSolr\Api\Representation\SolrCoreRepresentation
     */
    protected function getSolrCore()
    {
        if (!isset($this->solrCore)) {
            $solrCoreId = $this->engine->settingAdapter('solr_core_id');
            if ($solrCoreId) {
                // Automatically throw an exception when empty.
                $this->solrCore = $this->getServiceLocator()->get('Omeka\ApiManager')
                    ->read('solr_cores', $solrCoreId)->getContent();
                $this->solariumClient = $this->solrCore->solariumClient();
            }
        }
        return $this->solrCore;
    }

    /**
     * @return SolariumClient
     */
    protected function getClient()
    {
        if (!isset($this->solariumClient)) {
            $this->solariumClient = $this->getSolrCore()->solariumClient();
        }
        return $this->solariumClient;
    }
}
