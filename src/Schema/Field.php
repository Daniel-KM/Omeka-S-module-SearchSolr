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
     * @var array
     */
    protected $type;

    /**
     * @param string $name
     * @param string $field
     * @param array $type
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
    public function isMultivalued(): bool
    {
        $result = $this->field['multiValued']
            ?? $this->type['multiValued']
            ?? false;
        return (bool) $result;
    }

    /**
     * @return bool
     */
    public function isGeneralText(): bool
    {
        return strpos($this->type['name'], 'text') !== false;
    }
}
