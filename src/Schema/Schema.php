<?php

namespace Solr\Schema;

use Omeka\Stdlib\Message;

class Schema
{
    /**
     * @var string
     */
    protected $hostname;

    /**
     * @var string
     */
    protected $port;

    /**
     * @var string
     */
    protected $path;

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
     * @param string $hostname
     * @param string $port
     * @param string $path
     */
    public function __construct($hostname, $port, $path)
    {
        $this->hostname = $hostname;
        $this->port = $port;
        $this->path = $path;
    }

    /**
     * @throws \SolrServerException
     * @throws \SolrClientException
     * @return array
     */
    public function getSchema()
    {
        if (!isset($this->schema)) {
            $url = "http://{$this->hostname}:{$this->port}/{$this->path}/schema";
            try {
                $contents = @file_get_contents($url);
                if ($contents === false) {
                    throw new \SolrServerException(new Message(
                        'Solr is not available: check the server (%s).', // @translate
                        $url
                    ));
                }
            } catch (\SolrException $e) {
                throw new \SolrClientException(
                    new Message(
                        'Solr is not available: check url %s to the schema (message: %s).', // @translate
                        $url,
                        $e->getMessage()
                    ),
                    $e->getCode(),
                    $e
                );
            }

            $response = json_decode($contents, true);
            $this->schema = $response['schema'];
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
