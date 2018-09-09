<?php

namespace Solr\Schema;

class Field
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $field;

    /**
     * @var string
     */
    protected $type;

    /**
     * @param string $name
     * @param string $field
     * @param string $type
     */
    public function __construct($name, $field, $type)
    {
        $this->name = $name;
        $this->field = $field;
        $this->type = $type;
    }

    /**
     * @return bool
     */
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
