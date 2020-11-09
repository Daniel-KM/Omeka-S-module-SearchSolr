<?php declare(strict_types=1);

namespace SearchSolr\Schema;

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
        return $this->field['multiValued']
            ?? $this->type['multiValued']
            ?? false;
    }
}
