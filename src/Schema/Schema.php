<?php declare(strict_types=1);

namespace SearchSolr\Schema;

use Common\Stdlib\PsrMessage;
use Solarium\Exception\HttpException as SolariumException;

/**
 * @todo Replace by the solarium schema.
 */
class Schema
{
    /**
     * @var string
     */
    protected $schemaUrl;

    /**
     * @var array
     */
    protected $schema;

    /**
     * @var array
     */
    protected $fieldsByName;

    /**
     * @var array
     */
    protected $dynamicFieldsMap;

    /**
     * @var array
     */
    protected $typesByName;

    /**
     * @var array
     */
    protected $fields = [];

    public function __construct(string $schemaUrl)
    {
        $this->schemaUrl = $schemaUrl;
    }

    /**
     * Get the Solr core schema.
     *
     * There is no method in php-solr to get the schema, so do request via http/https.
     *
     * @todo Use Solarium to get schema.
     *
     * @throws \Solarium\Exception\HttpException
     * @return array
     */
    public function getSchema(): array
    {
        if (!isset($this->schema)) {
            $contents = @file_get_contents($this->schemaUrl);

            if ($contents === false) {
                // False result might be because file_get_contents is disabled, trying with curl
                if (function_exists('curl_init')) {
                    $curl = curl_init();
                    curl_setopt_array($curl, [
                        CURLOPT_RETURNTRANSFER => 1,
                        CURLOPT_URL => $this->schemaUrl,
                        CURLOPT_USERAGENT => 'curl/' . curl_version()['version'],
                    ]);
                    $contents = curl_exec($curl);
                    curl_close($curl);
                }
            }

            if ($contents === false) {
                // Remove the credentials of the url for the logs.
                $parsed = parse_url($this->schemaUrl);
                $credentials = isset($parsed['username']) ? substr($parsed['username'], 0, 1) . '***:***@' : '';
                $url = $parsed['scheme'] . '://' . $credentials . $parsed['host'] . ':' . $parsed['port'] . $parsed['path'];
                if ($credentials) {
                    $message = new PsrMessage(
                        'Solr core is not available. Check config or certificate to get Solr core schema {url}.', // @translate
                        ['url' => $url]
                    );
                } else {
                    $message = new PsrMessage(
                        'Solr core is not available. Check config to get Solr core schema {url}.', // @translate
                        ['url' => $url]
                    );
                }
                throw new SolariumException((string) $message);
            }

            $response = json_decode($contents, true);
            if (!is_array($response)) {
                // Remove the credentials of the url for the logs.
                $parsed = parse_url($this->schemaUrl);
                $credentials = isset($parsed['username']) ? substr($parsed['username'], 0, 1) . '***:***@' : '';
                $url = $parsed['scheme'] . '://' . $credentials . $parsed['host'] . ':' . $parsed['port'] . $parsed['path'];
                $message = new PsrMessage(
                    'Response is not valid. Check output of {url}, that should be valid json data.', // @translate
                    ['url' => $url]
                );
                throw new SolariumException((string) $message);
            }

            if (empty($response['schema'])) {
                // Remove the credentials of the url for the logs.
                $parsed = parse_url($this->schemaUrl);
                $credentials = isset($parsed['username']) ? substr($parsed['username'], 0, 1) . '***:***@' : '';
                $url = $parsed['scheme'] . '://' . $credentials . $parsed['host'] . ':' . $parsed['port'] . $parsed['path'];
                $message = new PsrMessage(
                    'Response is not valid. Check output of {ur}, that should be a json with a key "schema".', // @translate
                    ['url' => $url]
                );
                throw new SolariumException((string) $message);
            }

            $this->setSchema($response['schema']);
        }

        return $this->schema;
    }

    public function setSchema(array $schema): Schema
    {
        $this->schema = $schema;
        return $this;
    }

    public function getField(string $name): ?Field
    {
        // Fill fields only when needed.
        if (!array_key_exists($name, $this->fields)) {
            $field = $this->getFieldsByName()[$name]
                ?? $this->getDynamicFieldFor($name);
            if ($field) {
                $type = $this->getType($field['type']);
                $field = new Field($name, $field, $type);
            }
            $this->fields[$name] = $field;
        }
        return $this->fields[$name];
    }

    public function getFieldsByName(): array
    {
        if (!isset($this->fieldsByName)) {
            $this->fieldsByName = [];
            try {
                $schema = $this->getSchema();
            } catch (\Exception $e) {
                return [];
            }
            foreach ($schema['fields'] as $field) {
                $this->fieldsByName[$field['name']] = $field;
            }
        }
        return $this->fieldsByName;
    }

    public function getDynamicFieldFor(string $name): ?array
    {
        $map = $this->getDynamicFieldsMap();

        $firstChar = $name[0];
        if (isset($map['prefix'][$firstChar])) {
            foreach ($map['prefix'][$firstChar] as $field) {
                $prefix = substr($field['name'], 0, strlen($field['name']) - 1);
                if (0 === substr_compare($name, $prefix, 0, strlen($prefix))) {
                    return $field;
                }
            }
        }

        $lastChar = $name[strlen($name) - 1];
        if (isset($map['suffix'][$lastChar])) {
            foreach ($map['suffix'][$lastChar] as $field) {
                $suffix = substr($field['name'], 1);
                $suffixLen = strlen($suffix);
                $offset = strlen($name) - $suffixLen;
                if ($offset <= 0) {
                    continue;
                }
                if (0 === substr_compare($name, $suffix, $offset, $suffixLen)) {
                    return $field;
                }
            }
        }
        return null;
    }

    public function getDynamicFieldsMap(): array
    {
        if (!isset($this->dynamicFieldsMap)) {
            $this->dynamicFieldsMap = [];
            try {
                $schema = $this->getSchema();
            } catch (\Exception $e) {
                return [];
            }
            foreach ($schema['dynamicFields'] as $field) {
                $name = $field['name'];
                $char = $name[0];
                if ($char === '*') {
                    $char = $name[strlen($name) - 1];
                    $key = 'suffix';
                } else {
                    $key = 'prefix';
                }
                $this->dynamicFieldsMap[$key][$char][] = $field;
            }
        }
        return $this->dynamicFieldsMap;
    }

    /**
     * @param string $fieldNameType "prefix", "suffix", or all fields.
     */
    public function getDynamicFieldsMapByMainPart(?string $fieldNameType = null): array
    {
        static $dynamicFieldsMapBy;
        if (!isset($dynamicFieldsMapBy)) {
            $dynamicFieldsMapBy = [];
            try {
                $schema = $this->getSchema();
            } catch (\Exception $e) {
                return [];
            }
            foreach ($schema['dynamicFields'] as $field) {
                $name = $field['name'];
                $isSuffix = $name[0] === '*';
                $field['is_suffix'] = $isSuffix;
                $dynamicFieldsMapBy[$isSuffix ? mb_substr($name, 1) : mb_substr($name, 0, -1)] = $field;
            }
        }

        switch ($fieldNameType) {
            default:
                return $dynamicFieldsMapBy;
            case 'prefix':
                return array_filter($dynamicFieldsMapBy, fn ($v) => !$v['is_suffix']);
            case 'suffix':
                return array_filter($dynamicFieldsMapBy, fn ($v) => $v['is_suffix']);
        }
    }

    public function getType(string $name): ?array
    {
        return $this->getTypesByName()[$name] ?? null;
    }

    public function getTypesByName(): array
    {
        if (!isset($this->typesByName)) {
            $this->typesByName = [];
            try {
                $schema = $this->getSchema();
            } catch (\Exception $e) {
                return [];
            }
            foreach ($schema['fieldTypes'] as $type) {
                $this->typesByName[$type['name']] = $type;
            }
        }
        return $this->typesByName;
    }

    /**
     * Check if the config has a default field to query (catchall), else nothing
     * will be returned in a standard query.
     *
     * @todo Currently, the check is done on the catchall copy field, but the default field may be set somewhere else in the config.
     *
     * @link https://forum.omeka.org/t/search-field-doesnt-return-results-with-solr/11650/12
     * @link https://lucene.apache.org/solr/guide/solr-tutorial.html#create-a-catchall-copy-field
     */
    public function checkDefaultField(): bool
    {
        $hasDefaultField = false;
        $solrSchema = $this->getSchema();
        $fieldsList = $this->getFieldsByName();
        if (!empty($fieldsList['_text_']['indexed']) && !empty($solrSchema['copyFields'])) {
            foreach ($solrSchema['copyFields'] as $copyField) {
                if ($copyField['dest'] === '_text_' && $copyField['source'] === '*') {
                    $hasDefaultField = true;
                    break;
                }
            }
        }
        return $hasDefaultField;
    }
}
