<?php

namespace Solr\Schema;

use Omeka\Stdlib\Message;

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

    /**
     * @param string $schemaUrl
     */
    public function __construct($schemaUrl)
    {
        $this->schemaUrl = $schemaUrl;
    }

    /**
     * Get the Solr node schema.
     *
     * There is no method in php-solr to get the schema, so do request via http/https.
     *
     * @throws \SolrServerException
     * @throws \SolrClientException
     * @return array
     */
    public function getSchema()
    {
        if (!isset($this->schema)) {
            $contents = @file_get_contents($this->schemaUrl);
            if ($contents === false) {
                // Remove the credentials of the url for the logs.
                $parsed = parse_url($this->schemaUrl);
                $credentials = isset($parsed['user']) ? substr($parsed['user'], 0, 1) . '***:***@' : '';
                $url = $parsed['scheme'] . '://' . $credentials . $parsed['host'] . ':' . $parsed['port'] . $parsed['path'];
                if ($credentials) {
                    $message = new Message(
                        'Solr node is not available. Check config or certificate to get Solr node schema "%s".', // @translate
                        $url
                    );
                } else {
                    $message = new Message(
                        'Solr node is not available. Check config to get Solr node schema "%s".', // @translate
                        $url
                    );
                }
                throw new \SolrServerException($message);
            }

            $response = json_decode($contents, true);
            $this->setSchema($response['schema']);
        }

        return $this->schema;
    }

    /**
     * @param array $schema
     */
    public function setSchema($schema)
    {
        $this->schema = $schema;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function getField($name)
    {
        if (!isset($this->fields[$name])) {
            $fieldsByName = $this->getFieldsByName();
            $field = null;
            if (isset($fieldsByName[$name])) {
                $field = $fieldsByName[$name];
            } else {
                $field = $this->getDynamicFieldFor($name);
            }

            if (isset($field)) {
                $type = $this->getType($field['type']);
                $field = new Field($name, $field, $type);
            }
            $this->fields[$name] = $field;
        }

        return $this->fields[$name];
    }

    /**
     * @return array
     */
    public function getFieldsByName()
    {
        if (!isset($this->fieldsByName)) {
            $schema = $this->getSchema();
            $this->fieldsByName = [];
            foreach ($schema['fields'] as $field) {
                $this->fieldsByName[$field['name']] = $field;
            }
        }

        return $this->fieldsByName;
    }

    /**
     * @param string $name
     * @return string
     */
    public function getDynamicFieldFor($name)
    {
        $dynamicFieldsMap = $this->getDynamicFieldsMap();

        $firstChar = $name[0];
        if (isset($dynamicFieldsMap['prefix'][$firstChar])) {
            foreach ($dynamicFieldsMap['prefix'][$firstChar] as $field) {
                $prefix = substr($field['name'], 0, strlen($field['name']) - 1);
                if (0 === substr_compare($name, $prefix, 0, strlen($prefix))) {
                    return $field;
                }
            }
        }

        $lastChar = $name[strlen($name) - 1];
        if (isset($dynamicFieldsMap['suffix'][$lastChar])) {
            foreach ($dynamicFieldsMap['suffix'][$lastChar] as $field) {
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
    }

    /**
     * @return array
     */
    public function getDynamicFieldsMap()
    {
        if (!isset($this->dynamicFieldsMap)) {
            $schema = $this->getSchema();
            $this->dynamicFieldsMap = [];
            foreach ($schema['dynamicFields'] as $field) {
                $name = $field['name'];
                $char = $name[0];
                $key = 'prefix';
                if ($char === '*') {
                    $char = $name[strlen($name) - 1];
                    $key = 'suffix';
                }

                $this->dynamicFieldsMap[$key][$char][] = $field;
            }
        }

        return $this->dynamicFieldsMap;
    }

    /**
     * @param string $name
     * @return string
     */
    public function getType($name)
    {
        $typesByName = $this->getTypesByName();
        if (isset($typesByName[$name])) {
            return $typesByName[$name];
        }
    }

    /**
     * @return array
     */
    public function getTypesByName()
    {
        if (!isset($this->typesByName)) {
            $schema = $this->getSchema();
            foreach ($schema['fieldTypes'] as $type) {
                $this->typesByName[$type['name']] = $type;
            }
        }

        return $this->typesByName;
    }
}
