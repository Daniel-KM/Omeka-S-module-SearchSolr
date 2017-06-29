<?php

namespace Solr\Schema;

class Field
{
    protected $name;
    protected $field;
    protected $type;

    public function __construct($name, $field, $type)
    {
        $this->name = $name;
        $this->field = $field;
        $this->type = $type;
    }

    public function isMultivalued()
    {
        $multiValued = false;
        if (isset($this->field['multiValued'])) {
            $multiValued = $this->field['multiValued'];
        } elseif (isset($this->type['multiValued'])) {
            $multiValued = $this->type['multiValued'];
        }

        return $multiValued;
    }
}
