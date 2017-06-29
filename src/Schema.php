<?php

namespace Solr;

use Solr\Schema\Field;

class Schema
{
    protected $hostname;
    protected $port;
    protected $path;

    protected $schema;
    protected $fieldsByName;
    protected $dynamicFieldsMap;
    protected $typesByName;

    protected $fields = [];

    public function __construct($hostname, $port, $path)
    {
        $this->hostname = $hostname;
        $this->port = $port;
        $this->path = $path;
    }

    public function getSchema()
    {
        if (!isset($this->schema)) {
            $url = "http://{$this->hostname}:{$this->port}/{$this->path}/schema";
            $response = json_decode(file_get_contents($url), true);
            $this->schema = $response['schema'];
        }

        return $this->schema;
    }

    public function setSchema($schema)
    {
        $this->schema = $schema;
    }

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

    public function getDynamicFieldFor($name)
    {
        $dynamicFieldsMap = $this->getDynamicFieldsMap();

        $firstChar = $name[0];
        if (isset($dynamicFieldsMap['prefix'][$firstChar])) {
            foreach ($dynamicFieldsMap['prefix'][$firstChar] as $field) {
                $prefix = substr($field['name'], 0, strlen($field['name'] - 1));
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

    public function getType($name)
    {
        $typesByName = $this->getTypesByName();
        if (isset($typesByName[$name])) {
            return $typesByName[$name];
        }
    }

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
