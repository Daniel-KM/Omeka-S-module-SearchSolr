<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2017-2025
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
use Exception;
use Omeka\Api\Representation\AbstractResourceRepresentation;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use SearchSolr\Api\Representation\SolrMapRepresentation;
use Solarium\Client as SolariumClient;
use Solarium\QueryType\Update\Query\Document as SolariumInputDocument;

/**
 * @see https://solarium.readthedocs.io/en/stable/getting-started/
 * @see https://solarium.readthedocs.io/en/stable/documents/
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
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \SearchSolr\ValueExtractor\Manager
     */
    protected $valueExtractorManager;

    /**
     * @var \SearchSolr\ValueFormatter\Manager
     */
    protected $valueFormatterManager;

    /**
     * @var \Solarium\Plugin\BufferedAdd\BufferedAdd
     */
    protected $buffer;

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
     * @var string|null
     */
    protected $indexField;

    /**
     * @var string|null
     */
    protected $indexName;

    /**
     * @var string
     */
    protected $mainLocale;

    /**
     * @var string|null
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
            $solariumQuery = $this->searchEngine->querier()
                ->setQuery($query)
                ->getPreparedQuery();
            $isQuery = $solariumQuery !== null;
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

    public function indexResource(AbstractResourceRepresentation $resource): IndexerInterface
    {
        return $this->indexResources([$resource]);
    }

    /**
     * @param \Omeka\Api\Representation\AbstractResourceRepresentation[] $resources
     */
    public function indexResources(array $resources): IndexerInterface
    {
        if (!count($resources)) {
            return $this;
        }

        if (empty($this->api)) {
            $result = $this->init();
            if (!$result) {
                return $this;
            }
        }

        $resources = $this->filterResources($resources);
        if (!count($resources)) {
            return $this;
        }

        // For quick log.
        $resourcesIds = [];
        foreach ($resources as $resource) {
            $resourcesIds[] = $this->easyMeta->resourceType(get_class($resource)) . ' #' . $resource->id();
        }

        $this->getLogger()->info(
            'Indexing in Solr core "{solr_core}": {ids}', // @translate
            ['solr_core' => $this->solrCore->name(), 'ids' => implode(', ', $resourcesIds)]
        );

        $this->buffer = $this->getClient()->getPlugin('bufferedadd');
        $this->buffer
            ->setOverwrite(true)
            ->setBufferSize(count($resourcesIds));

        foreach ($resources as $resource) {
            $document = $this->prepareDocument($resource);
            if ($document) {
                // With buffer, the check is done when the document is added.
                try {
                    $this->buffer->addDocument($document);
                } catch (Exception $e) {
                    $this->solrError($e, $resource, $document);
                    // Remove the document with an issue from the buffer,
                    // allowing to commit other ones.
                    $documents = $this->buffer->getDocuments();
                    array_pop($documents);
                    $this->buffer->clear();
                    $this->buffer->addDocuments(array_values($documents));
                    unset($documents);
                }
            }
        }

        try {
            $this->buffer->commit();
        } catch (Exception $e) {
            $this->solrError($e);
            $this->buffer->clear();
        }

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
    protected function init(): ?IndexerInterface
    {
        $services = $this->getServiceLocator();
        $this->api = $services->get('Omeka\ApiManager');
        $this->apiAdapters = $services->get('Omeka\ApiAdapterManager');
        $this->easyMeta = $services->get('Common\EasyMeta');
        $this->valueExtractorManager = $services->get('SearchSolr\ValueExtractorManager');
        $this->valueFormatterManager = $services->get('SearchSolr\ValueFormatterManager');

        // Some values should be init to get the document id.
        $this->getServerId();
        $this->prepareIndexFieldAndName();
        $this->prepareSingleValuedFields();
        $this->getSupportFields();

        // Force indexation of required fields, in particular resource type,
        // visibility and sites, because they are the base of Omeka.
        // So check required fields one time and abord early.
        $missingMaps = $this->getSolrCore()->missingRequiredMaps();
        if ($missingMaps) {
            $this->getLogger()->err(
                'Unable to index resources in Solr core "{solr_core}". Some required fields are not mapped: {list}', // @translate
                ['solr_core' => $this->getSolrCore()->name(), 'list' => implode(', ', $missingMaps)]
            );
            return null;
        }

        $this->mainLocale = $services->get('Omeka\Settings')->get('locale');

        return $this;
    }

    protected function getDocumentId($resourceName, $resourceId)
    {
        // Adapted Drupal convention to be used for any single or multi-index.
        // @link https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/solr-conf-templates/8.x/schema.xml#L131-141
        // The 0-formatted id allows to sort quickly on id.
        // TODO Maybe propose a second version with id first, that is quicker to search for very big base. See Solr too.
        if (empty($this->serverId)) {
            return empty($this->indexField)
                ? sprintf('%s/%07s', $resourceName, $resourceId)
                : sprintf('%s-%s/%07s', $this->indexName, $resourceName, $resourceId);
        }
        return empty($this->indexField)
            ? sprintf('%s-%s/%07s', $this->serverId, $resourceName, $resourceId)
            : sprintf('%s-%s-%s/%07s', $this->serverId, $this->indexName, $resourceName, $resourceId);
    }

    protected function prepareDocument(AbstractResourceRepresentation $resource): ?SolariumInputDocument
    {
        $resourceName = $this->easyMeta->resourceName(get_class($resource));
        $resourceId = $resource->id();

        /** @var \SearchSolr\ValueExtractor\ValueExtractorInterface $valueExtractor */
        $valueExtractor = $this->valueExtractorManager->get($resourceName);

        // This shortcut is not working on some databases: the representation is
        // not fully loaded, so when getting resource values ($representation->values()),
        // an error occurs when getting the property term: the vocabulary is not
        // loaded and the prefix cannot be get.
        // TODO Is it still true with representation not created via adapter but api in AdvancedSearch?
        /** @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::values() */

        $isSingleFieldFilled = [];

        $document = new SolariumInputDocument();

        $documentId = $this->getDocumentId($resourceName, $resourceId);
        $document->addField('id', $documentId);

        if ($this->indexField) {
            $document->addField($this->indexField, $this->indexName);
        }

        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation $solrMap */
        foreach ($this->solrCore->mapsByResourceName($resourceName) as $solrMap) {
            $solrField = $solrMap->fieldName();
            $source = $solrMap->source();

            // Required fields (resource name, visibility, etc.) are already
            // checked, so they are all added during value extraction.

            // Resource name is not available through the representation.
            if ($source === 'resource_name') {
                if (empty($isSingleFieldFilled[$solrField])) {
                    $isSingleFieldFilled[$solrField] = true;
                    $document->addField($solrField, $resourceName);
                }
            }

            if ($source === 'site/o:id') {
                switch ($resourceName) {
                    case 'items':
                        $sites = $resource->sites();
                        if ($sites) {
                            $document->addField($solrField, array_keys($sites));
                        }
                        break;
                    case 'item_sets':
                        $sites = $resource->sites();
                        if ($sites) {
                            $document->addField($solrField, array_keys($sites));
                        }
                        break;
                    case 'media':
                        $sites = $resource->item()->sites();
                        if ($sites) {
                            $document->addField($solrField, array_keys($sites));
                        }
                        break;
                    default:
                        // Nothing to do.
                        break;
                }
                continue;
            }

            if ($source === 'search_index' && $solrField === $this->indexField) {
                continue;
            }

            // Check if the value is a single valued field already filled.
            // Is a single field value the first one or the last one? Most of
            // the time, the first one.
            if (!empty($isSingleFieldFilled[$solrField])) {
                continue;
            }

            $extractedValues = $valueExtractor->extractValue($resource, $solrMap);
            if (!count($extractedValues)) {
                continue;
            }

            $formattedValues = $this->formatValues($extractedValues, $solrMap);
            if (!count($formattedValues)) {
                continue;
            }

            if ($this->isSingleValuedFields[$solrField]) {
                // Store single fields one time only (checked above).
                if (empty($isSingleFieldFilled[$solrField])) {
                    $isSingleFieldFilled[$solrField] = true;
                    $document->addField($solrField, reset($formattedValues));
                }
            } else {
                foreach ($formattedValues as $value) {
                    $document->addField($solrField, $value);
                }
            }

            if ($this->support === 'drupal') {
                // Only one value is filled for special values of Drupal.
                $value = reset($extractedValues);
                // Generally, the value is a ValueRepresentation.
                $locale = is_object($value) && method_exists($value, 'lang')
                    ? $value->lang()
                    : null;
                $formattedValue = reset($formattedValues);
                $this->appendDrupalValues($resource, $document, $solrField, $formattedValue, $locale);
            }
        }

        $this->appendSupportedFields($resource, $document);

        // Remove the duplicates in the multiple indexes: this is generally an
        // unintentional issue (or related to a drupal field).
        // It's recommended to use Solr boost mechanisms to boost a property or
        // a document.
        foreach ($document->getFields() as $field => $values) {
            if (empty($this->isSingleValuedFields[$field]) && is_array($values) && count($values) > 1) {
                $deduplicatedValues = array_unique($values);
                if (count($deduplicatedValues) !== count($values)) {
                    // Note: to remove a field removes the boost data too.
                    $document->removeField($field);
                    foreach ($deduplicatedValues as $value) {
                        $document->addField($field, $value);
                    }
                }
            }
        }

        // It is useless to clean memory here when representation is used,
        // because the resource is loaded in job.

        return $document;
    }

    protected function formatValues(array $values, SolrMapRepresentation $solrMap)
    {
        /** @var \SearchSolr\ValueFormatter\ValueFormatterInterface $valueFormatter */
        $formatter = $solrMap->setting('formatter', '');
        $formatter = $formatter && $this->valueFormatterManager->has($formatter) ? $formatter : 'text';
        $valueFormatter = $this->valueFormatterManager->get($formatter)
            ->setServiceLocator($this->services)
            ->setSettings($solrMap->settings());

        $resultPreformatted = [];
        foreach ($values as $value) {
            $preFormattedValues = $valueFormatter->preFormat($value);
            $resultPreformatted = array_merge($resultPreformatted, $preFormattedValues);
        }

        $resultFormatted = [];
        foreach ($resultPreformatted as $value) {
            $formattedValues = $valueFormatter->format($value);
            $resultFormatted = array_merge($resultFormatted, $formattedValues);
        }

        $resultPostFormatted = [];
        foreach ($resultFormatted as $value) {
            $postFormattedValues = $valueFormatter->postFormat($value);
            $resultPostFormatted = array_merge($resultPostFormatted, $postFormattedValues);
        }

        return $valueFormatter->finalizeFormat($resultPostFormatted);
    }

    protected function appendSupportedFields(AbstractResourceRepresentation $resource, SolariumInputDocument $document): void
    {
        foreach ($this->supportFields as $solrField => $value) switch ($solrField) {
            // Drupal.
            // TODO Don't use "index_id", but the name set in the map, that should be "index_id" anyway.
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
                $document->addField(
                    $solrField,
                    $this->easyMeta->resourceName(get_class($resource))
                );
                break;
            case 'ss_search_api_id':
                $document->addField(
                    $solrField,
                    $this->easyMeta->resourceName(get_class($resource)) . '/' . $resource->id()
                );
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
        return $value !== null
            && (string) $value !== '';
    }

    /**
     * Prepare the commit message error for log.
     *
     * To get a better message: get the data ($request->getRawData()) and post it
     * in Solr admin board.
     * @see \Solarium\Core\Client\Adapter\Http::createContext()
     */
    protected function solrError(
        Exception $exception,
        ?AbstractResourceRepresentation $resource = null,
        ?SolariumInputDocument $document = null,
        bool $isRecall = false
    ): self {
        $error = method_exists($exception, 'getBody') ? json_decode((string) $exception->getBody(), true) : null;

        $message = is_array($error) && isset($error['error']['msg'])
            ? $error['error']['msg']
            : $exception->getMessage();

        if ($message === 'Solr HTTP error: HTTP request failed') {
            // The exception can occur before or after buffer->clear().
            if (!$isRecall && $this->buffer->getBuffer()) {
                // Most of the time, the issue is a config issue with a limit.
                sleep(30);
                try {
                    $this->buffer->commit();
                } catch (Exception $e) {
                    $this->solrError($exception, null, null, true);
                }
            } else {
                $firstDocument = $this->buffer->getBuffer();
                $firstDocument = $firstDocument ? reset($firstDocument) : null;
                if ($firstDocument) {
                    $this->getLogger()->err(
                        'Solr HTTP error: HTTP request failed due to network, limit of requests, or certificate issue. First document in the buffer: {document_id}.', // @translate
                        ['document_id' => $firstDocument->offsetGet('id')]
                    );
                } else {
                    $this->getLogger()->err(
                        'Solr HTTP error: HTTP request failed due to network, limit of requests, or certificate issue.' // @translate
                    );
                }
                $this->buffer->clear();
            }
        } elseif ($message === 'Solr HTTP error: Bad Request (400)') {
            // TODO Retry the request here, because \Solarium\Core\Client\Adapter\Http::createContext()
            /** @see \Solarium\Core\Client\Adapter\Http::createContext() */
            if ($resource) {
                $this->getLogger()->err(
                    'Indexing of {resource_name} #{id} failed: Invalid document (wrong field type or missing required field).', // @translate
                    ['resource_name' => $this->easyMeta->resourceName(get_class($resource)), 'id' => $resource->id(), 'message' => $message]
                );
            } else {
                $this->getLogger()->err(
                    'Indexing of resource failed: Invalid document (wrong field type or missing required field).' // @translate
                );
            }
        } elseif ($resource) {
            $this->getLogger()->err(
                'Indexing of {resource_name} #{id} failed: {message}', // @translate
                ['resource_name' => $this->easyMeta->resourceName(get_class($resource)), 'id' => $resource->id(), 'message' => $message]
            );
        } else {
            $this->getLogger()->err(
                'Indexing of resource failed: {message}', // @translate
                ['message' => $message]
            );
        }

        return $this;
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
        if ($this->serverId === null) {
            $this->serverId = $this->getSolrCore()->setting('server_id') ?: false;
        }
        return $this->serverId;
    }

    protected function prepareIndexFieldAndName()
    {
        $fields = $this->getSolrCore()->mapsBySource('search_index', 'generic') ?: [];
        $name = $this->searchEngine->settingEngineAdapter('index_name') ?: false;
        if ($fields && $name) {
            $this->indexField = reset($fields);
            $this->indexField = $this->indexField->fieldName();
            $this->indexName = $name;
        } else {
            $this->indexField = null;
            $this->indexName = null;
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
        if ($this->indexField === null) {
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
        if ($this->indexName === null) {
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
     * Because the multivalued quality is defined by the schema, it is not
     * related to the resource name, so a single list is created.
     * It avoids complexity with generic/resources/specific resources names too.
     *
     * @todo Move the single valued check inside Solr core settings to do it one time.
     */
    protected function prepareSingleValuedFields(): void
    {
        $schema = $this->getSolrCore()->schema();
        $this->isSingleValuedFields = [];
        foreach ($this->getSolrCore()->maps() as $solrMap) {
            $solrField = $solrMap->fieldName();
            $schemaField = $schema->getField($solrField);
            $this->isSingleValuedFields[$solrField] = $schemaField && !$schemaField->isMultivalued();
        }
    }

    /**
     * Get the specific supported fields.
     *
     * @return array
     */
    protected function getSupportFields()
    {
        if ($this->supportFields === null) {
            $this->support = $this->solrCore->setting('support') ?: null;
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
                    $helpers = $this->getServiceLocator()->get('ViewHelperManager');
                    $value = $helpers->get('ServerUrl')->__invoke($helpers->get('BasePath')->__invoke('/'));
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
     * @param AbstractResourceRepresentation $resource
     * @param SolariumInputDocument $document
     * @param string $solrField
     * @param mixed $value
     * @param string $locale
     * @return self
     */
    protected function appendDrupalValues(AbstractResourceRepresentation $resource, SolariumInputDocument $document, $solrField, $value, ?string $valueLocale = null)
    {
        $resourceName = $this->easyMeta->resourceName(get_class($resource));
        if (!isset($this->vars['solr_maps'][$resourceName][$solrField])) {
            return $this;
        }

        // When a value has a locale, only this locale is stored, else all
        // locales defined in the config.

        // In Drupal, the same value is copied in each language field for sort…
        // @link https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/src/Plugin/search_api/backend/SearchApiSolrBackend.php#L1130-1172
        // For example, field is "ss_title", so sort field is "sort_X3b_fr_title".
        // This is slighly different from Drupal process.
        $prefix = $this->vars['solr_maps'][$resourceName][$solrField]['prefix'];
        $matches = [];
        if (in_array($prefix, ['tm', 'ts', 'sm', 'ss'])) {
            // Don't translate a field twice.
            if (strpos($solrField, '_X3b_') !== false) {
                return $this;
            }
            // Drupal needs two letters language codes, except for undetermined.
            // TODO Use the right mapping for iso language 3 characters to 2 characters.
            if ($valueLocale && $valueLocale !== 'und' && strlen($valueLocale) > 2) {
                $valueLocale = substr($valueLocale, 0, 2);
            }
            // For sort, only the first 128 characters can be indexed.
            $valueSort = mb_substr((string) $value, 0, 128);
            $name = $this->vars['solr_maps'][$resourceName][$solrField]['name'];
            foreach ($valueLocale ? [$valueLocale] : $this->vars['locales'] as $locale) {
                $field = 'sort_X3b_' . $locale . '_' . $name;
                // Add only one sort value.
                if (!isset($document->{$field})) {
                    $document->addField($field, $valueSort);
                }
            }
            // The title and body (description) should be translated too.
            if (in_array($solrField, ['tm_title', 'ts_title', 'tm_body', 'ts_body'])) {
                $tmOrTs = $prefix === 'ts' ? 'ts' : 'tm';
                foreach ($valueLocale ? [$valueLocale] : $this->vars['locales'] as $locale) {
                    $field = $tmOrTs . '_X3b_' . $locale . '_' . $name;
                    // There may be multiple titles or bodies, but avoid issues.
                    if (!isset($document->{$field})) {
                        $document->addField($field, $value);
                    }
                }
            }
        } elseif (strpos($solrField, 'random_') !== 0
            && preg_match('/^([a-z]+)m(_.*)/', $solrField, $matches)
        ) {
            $field = $matches[1] . 's' . $matches[2];
            if (!isset($document->{$field})) {
                $document->addField($field, $value);
            }
        }
        return $this;
    }

    /**
     * The solr core can be configured to index only a part of resources.
     *
     * @param \Omeka\Api\Representation\AbstractResourceRepresentation[] $resources
     */
    protected function filterResources(array $resources): array
    {
        $query = $this->getSolrCore()->setting('filter_resources');
        if (!$query || !$resources) {
            return $resources;
        }

        $resourceIds = [];
        foreach ($resources as $resource) {
            $resourceIds[] = $resource->id();
        }

        $query['id'] = array_unique(array_merge($query['id'] ?? [], $resourceIds));

        // TODO Search api is currently unavailable for resources (wait v4.1)
        // For now, use the first resource.
        $first = reset($resources);
        $resourceName = $first->resourceName();
        return $this->api->search($resourceName, $query)->getContent();
    }

    protected function getSolrCore(): SolrCoreRepresentation
    {
        if (!isset($this->solrCore)) {
            $solrCoreId = $this->searchEngine->settingEngineAdapter('solr_core_id');
            if ($solrCoreId) {
                // Automatically throw an exception when empty.
                $this->solrCore = $this->getServiceLocator()->get('Omeka\ApiManager')
                    ->read('solr_cores', $solrCoreId)->getContent();
                $this->solariumClient = $this->solrCore->solariumClient();
            }
        }

        // Throw an exception if unavailable.
        if (!$this->solrCore->status()) {
            throw new \Omeka\Mvc\Exception\RuntimeException('Solr core is not available.'); // @translate
        }

        return $this->solrCore;
    }

    protected function getClient(): SolariumClient
    {
        if (!isset($this->solariumClient)) {
            $this->solariumClient = $this->getSolrCore()->solariumClient();
            // Use BufferedAdd plugin to reduce memory issue.
            $this->solariumClient->getPlugin('bufferedadd');
        }
        return $this->solariumClient;
    }
}
