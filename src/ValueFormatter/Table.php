<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

use Omeka\Stdlib\Message;

/**
 * ValueFormatter to replace a value by another one(s).
 */
class Table extends PlainText
{
    public function getLabel(): string
    {
        return 'Table'; // @translate
    }

    public function format($value): array
    {
        /** @var \Table\Api\Representation\TableRepresentation[] $tables */
        static $tables;

        $values = parent::format($value);
        if (!count($values)) {
            return $values;
        }

        $tableId = $this->settings['table'] ?? null;
        if (!$tableId) {
            return $values;
        }

        // Check if table is available one time only.
        if (!array_key_exists($tableId, $tables)) {
            /** @var \Omeka\Api\Manager $api */
            $api = $this->services->get('Omeka\ApiManager');
            try {
                $tables[$tableId] = $api->read('tables', is_numeric($tableId) ? ['id' => $tableId] : ['slug' => $tableId])->getContent();
            } catch (\Exception $e) {
                $tables[$tableId] = null;
                $this->services->get('Omeka\Logger')->err(new Message(
                    'For formatter "Table", the table #%s does not exist and values are not normalized.', // @translate
                    $tableId
                ));
                return $values;
            }
        }
        if (!$tables[$tableId]) {
            return $values;
        }

        // Keep original order of values.
        foreach ($values as &$val) {
            $val = $tables[$tableId]->labelFromCode($val) ?? $val;
        }
        unset($val);

        $values = array_unique(array_filter($values, 'strlen'));

        return $values;
    }
}
