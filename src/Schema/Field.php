<?php declare(strict_types=1);

namespace SearchSolr\Schema;

class Field
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string|array
     */
    protected $field;

    /**
     * @var array
     */
    protected $type;

    /**
     * @todo Check why field may be an array (or is it still true)?
     */
    public function __construct(string $name, $field, array $type)
    {
        $this->name = $name;
        $this->field = $field;
        $this->type = $type;
    }

    public function getType(): array
    {
        return $this->type ?? [];
    }

    public function isMultivalued(): bool
    {
        $result = $this->field['multiValued']
            ?? $this->type['multiValued']
            ?? false;
        return (bool) $result;
    }

    public function isBoolean(): bool
    {
        $typeName = $this->type['name'] ?? null;
        return $typeName === 'boolean' || $typeName === 'booleans';
    }

    public function isDate(): bool
    {
        $dateTypes = [
            'date',
            'tdate',
            'pdate',
            'dates',
            'tdates',
            'pdates',
        ];
        return in_array($this->type['name'] ?? null, $dateTypes, true);
    }

    public function isFloat(): bool
    {
        $dateTypes = [
            'float',
            'tfloat',
            'pfloat',
            'double',
            'tdouble',
            'pdouble',
            'floats',
            'tfloats',
            'pfloats',
            'doubles',
            'tdoubles',
            'pdoubles',
        ];
        return in_array($this->type['name'] ?? null, $dateTypes, true);
    }

    public function isInteger(): bool
    {
        $integerTypes = [
            'int',
            'tint',
            'pint',
            'long',
            'tlong',
            'plong',
            'ints',
            'tints',
            'pints',
            'longs',
            'tlongs',
            'plongs',
        ];
        return in_array($this->type['name'] ?? null, $integerTypes, true);
    }

    public function isLowercase(): bool
    {
        $typeName = $this->type['name'] ?? null;
        return $typeName === 'lowercase' || $typeName === 'lowercases';
    }

    public function isString(): bool
    {
        $typeName = $this->type['name'] ?? null;
        return $typeName === 'string' || $typeName === 'strings';
    }

    /**
     * @todo Fix isGeneralText, that may be a false positive.
     * For now, it includes __text__ and any field with "text" in its name.
     */
    public function isGeneralText(): bool
    {
        return strpos($this->type['name'], 'text') !== false;
    }

    public function isTokenized(): bool
    {
        $typeName = $this->type['name'] ?? null;
        return strpos($typeName, 'text') === 0;
    }

    public function isNumeric(): bool
    {
        $numericTypes = [
            'int',
            'tint',
            'pint',
            'long',
            'tlong',
            'plong',
            'float',
            'tfloat',
            'pfloat',
            'double',
            'tdouble',
            'pdouble',
            'date',
            'tdate',
            'pdate',
            'ints',
            'tints',
            'pints',
            'longs',
            'tlongs',
            'plongs',
            'floats',
            'tfloats',
            'pfloats',
            'doubles',
            'tdoubles',
            'pdoubles',
            'dates',
            'tdates',
            'pdates',
        ];
        return in_array($this->type['name'] ?? null, $numericTypes, true);
    }
}
