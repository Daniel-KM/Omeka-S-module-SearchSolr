<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2017-2023
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
     * @param \Omeka\Entity\AbstractEntity[] $resources
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

        $resourceNames = [
            'items' => 'item',
            'item_sets' => 'item set',
            'media' => 'media',
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
    protected function init(): ?IndexerInterface
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

        // Force indexation of required fields, in particular resource type,
        // visibility and sites, because they are the base of Omeka.
        // So check required fields one time and abord early.
        $missingMaps = $this->getSolrCore()->missingRequiredMaps();
        if ($missingMaps) {
            $this->getLogger()->err(new Message(
                'Unable to index resources in Solr core "%1$s". Some required fields are not mapped: %2$s', // @translate
                $this->getSolrCore()->name(), implode(', ', $missingMaps)
            ));
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

    protected function addResource(Resource $resource): void
    {
        $resourceName = $resource->getResourceName();
        $resourceId = $resource->getId();

        /** @var \SearchSolr\ValueExtractor\ValueExtractorInterface $valueExtractor */
        $valueExtractor = $this->valueExtractorManager->get($resourceName);

        // This shortcut is not working on some databases: the representation is
        // not fully loaded, so when getting resource values ($representation->values()),
        // an error occurs when getting the property term: the vocabulary is not
        // loaded and the prefix cannot be get.
        /** @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::values() */

        /** @var \Omeka\Api\Representation\AbstractResourceRepresentation $representation */
        // $adapter = $this->apiAdapters->get($resourceName);
        // $representation = $adapter->getRepresentation($resource);

        try {
            $representation = $this->api->read($resourceName, $resourceId)->getContent();
        } catch (\Exception $e) {
            $this->getLogger()->notice(
                new Message('The %1$s #%2$d is no more available and cannot be indexed.', // @translate
                $resourceName, $resourceId
            ));
            return;
        }

        $isSingleFieldFilled = [];

        $document = new SolariumInputDocument;

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
                $document->addField($solrField, $resourceName);
            }

            if ($source === 'site/o:id') {
                switch ($resourceName) {
                    case 'items':
                        $sites = array_map(function (\Omeka\Entity\Site $v) {
                            return $v->getId();
                        }, $resource->getSites()->toArray());
                        $document->addField($solrField, array_values($sites));
                        break;
                    case 'item_sets':
                        $sites = array_map(function (\Omeka\Entity\SiteItemSet $v) {
                            return $v->getSite()->getId();
                        }, $resource->getSiteItemSets()->toArray());
                        $document->addField($solrField, $sites);
                        break;
                    case 'media':
                        $sites = array_map(function (\Omeka\Entity\Site $v) {
                            return $v->getId();
                        }, $resource->getItem()->getSites()->toArray());
                        $document->addField($solrField, array_values($sites));
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
            if (!empty($isSingleFieldFilled[$solrField])) {
                continue;
            }

            $values = $valueExtractor->extractValue($representation, $solrMap);
            if (!count($values)) {
                continue;
            }

            $formattedValues = $this->formatValues($values, $solrMap);
            if (!count($formattedValues)) {
                continue;
            }

            if ($this->isSingleValuedFields[$solrField]) {
                // Store single fields one time only (checked above).
                $isSingleFieldFilled[$solrField] = true;
                $document->addField($solrField, reset($formattedValues));
            } else {
                foreach ($formattedValues as $value) {
                    $document->addField($solrField, $value);
                }
            }

            if ($this->support === 'drupal') {
                // Only one value is filled for special values of Drupal.
                $value = reset($values);
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

        $this->solariumDocuments[$documentId] = $document;
    }

    protected function formatValues(array $values, SolrMapRepresentation $solrMap)
    {
        /** @var \SearchSolr\ValueFormatter\ValueFormatterInterface $valueFormatter */
        $valueFormatter = $this->formatters[$solrMap->setting('formatter', '')] ?: $this->formatters['standard'];
        $valueFormatter->setSettings($solrMap->settings());
        $result = [];
        foreach ($values as $value) {
            $formattedResult = $valueFormatter->format($value);
            // FIXME Indexation of "0" breaks Solr, so currently replaced by "00".
            $formattedResult = array_map(function ($v) {
                return $v === '0' ? '00' : $v;
            }, $formattedResult);
            $result = array_merge($result, $formattedResult);
        }
        // Don't use array_unique before, because objects may not be stringable.
        return array_unique($result);
    }

    protected function appendSupportedFields(Resource $resource, SolariumInputDocument $document): void
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
                // To improve speed when error and avoid 100 requests, try
                // indexing by ten first, then individually, so usually 11 to 20
                // requests.
                foreach (array_chunk($this->solariumDocuments, 10, true) as $solariumDocs) {
                    try {
                        $update = $client
                            ->createUpdate()
                            ->addDocuments($solariumDocs)
                            ->addCommit();
                        $client->update($update);
                    } catch (\Exception $e) {
                        if (count($solariumDocs) > 1) {
                            foreach ($solariumDocs as $documentId => $document) {
                                if (!$document) {
                                    $dId = explode('-', $documentId);
                                    $dId = array_pop($dId);
                                    $this->commitError($document, $dId, $e);
                                    continue;
                                }
                                try {
                                    $update = $client
                                        ->createUpdate()
                                        ->addDocument($document)
                                        ->addCommit();
                                    $client->update($update);
                                } catch (\Exception $e) {
                                    $dId = explode('-', $documentId);
                                    $dId = array_pop($dId);
                                    $this->commitError($document, $dId, $e);
                                }
                            }
                        } else {
                            $dId = explode('-', key($solariumDocs));
                            $dId = array_pop($dId);
                            $this->commitError(reset($solariumDocs), $dId, $e);
                        }
                    }
                }
            } else {
                $dId = explode('-', key($this->solariumDocuments));
                $dId = array_pop($dId);
                $this->commitError(reset($this->solariumDocuments), $dId, $e);
            }
        }

        $this->solariumDocuments = [];
    }

    /**
     * Prepare the commit message error for log.
     *
     * To get a better message: get the data ($request->getRawData()) and post it
     * in Solr admin board.
     * @see \Solarium\Core\Client\Adapter\Http::createContext()
     */
    protected function commitError(?SolariumInputDocument $document, string $dId, \Exception $exception): self
    {
        if (!$document) {
            $message = new Message('Indexing of resource failed: empty of invalid document: %s', $exception);
            $this->getLogger()->err($message);
            return $this;
        }
        $error = method_exists($exception, 'getBody') ? json_decode((string) $exception->getBody(), true) : null;
        $message = is_array($error) && isset($error['error']['msg'])
            ? $error['error']['msg']
            : $exception->getMessage();
        if ($message === 'Solr HTTP error: Bad Request (400)') {
            // TODO Retry the request here, because \Solarium\Core\Client\Adapter\Http::createContext()
            $message = new Message('Invalid document (wrong field type or missing required field).'); // @translate
        } elseif ($message === 'Solr HTTP error: HTTP request failed') {
            $message = new Message('Solr HTTP error: HTTP request failed due to network or certificate issue.'); // @translate
        }
        $message = new Message('Indexing of resource %1$s failed: %2$s', $dId, $message);
        $this->getLogger()->err($message);
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
        if (is_null($this->serverId)) {
            $this->serverId = $this->getSolrCore()->setting('server_id') ?: false;
        }
        return $this->serverId;
    }

    protected function prepareIndexFieldAndName()
    {
        $fields = $this->getSolrCore()->mapsBySource('search_index', 'generic') ?: [];
        $name = $this->engine->settingAdapter('index_name') ?: false;
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

    protected function prepareFomatters(): void
    {
        $this->formatters = ['' => null];
        foreach ($this->valueFormatterManager->getRegisteredNames() as $formatter) {
            $valueFormatter = $this->valueFormatterManager->get($formatter);
            $valueFormatter->setServiceLocator($this->services);
            $this->formatters[$formatter] = $valueFormatter;
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
     * @param SolariumInputDocument $document
     * @param string $solrField
     * @param mixed $value
     * @param string $locale
     * @return self
     */
    protected function appendDrupalValues(Resource $resource, SolariumInputDocument $document, $solrField, $value, ?string $valueLocale = null)
    {
        $resourceName = $resource->getResourceName();
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
     * @param array \Omeka\Entity\Resource[]
     */
    protected function filterResources(array $resources): array
    {
        $query = $this->getSolrCore()->setting('filter_resources');
        if (!$query || !$resources) {
            return $resources;
        }

        $resourceIds = [];
        foreach ($resources as $resource) {
            $resourceIds[] = $resource->getId();
        }

        $query['id'] = array_unique(array_merge($query['id'] ?? [], $resourceIds));

        // TODO Search api is currently unavailable for resources (wait v4.1)
        // For now, use the first resource.
        $first = reset($resources);
        $resourceName = $first->getResourceName();
        return $this->api->search($resourceName, $query, ['responseContent' => 'resource'])->getContent();
    }

    protected function getSolrCore(): SolrCoreRepresentation
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
        }
        return $this->solariumClient;
    }
}
