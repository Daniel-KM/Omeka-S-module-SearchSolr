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
        static $tables = [];

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

        $table = $tables[$tableId];

        // Keep original order of values.

        $mode = $this->settings['table_mode'] ?? 'label';
        $indexOriginal = !empty($this->settings['table_index_original']);
        $checkStrict = !empty($this->settings['table_check_strict']);

        $result = [];
        switch ($mode) {
            default:
            case 'label':
                foreach ($values as $val) {
                    if ($indexOriginal) {
                        $result[] = $val;
                    }
                    $result[] = $table->labelFromCode($val, $checkStrict) ?? '';
                }
                break;

            case 'code':
                foreach ($values as &$val) {
                    if ($indexOriginal) {
                        $result[] = $val;
                    }
                    $result[] = $table->codeFromLabel($val, $checkStrict) ?? '';
                }
                break;

            case 'both':
                foreach ($values as $val) {
                    if ($indexOriginal) {
                        $result[] = $val;
                    }
                    $result[] = $table->labelFromCode($val, $checkStrict) ?? '';
                    $result[] = $table->codeFromLabel($val, $checkStrict) ?? '';
                }
                break;
        }
        $values = $result;

        $values = array_values(array_unique(array_filter($values, 'strlen')));

        return $values;
    }
}
