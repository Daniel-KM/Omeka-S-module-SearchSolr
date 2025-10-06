<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

use Laminas\ServiceManager\ServiceLocatorInterface;

abstract class AbstractValueFormatter implements ValueFormatterInterface
{
    /**
     * @var string
     */
    protected $label;

    /**
     * @var string|null
     */
    protected $comment = null;

    /**
     * @var \Laminas\ServiceManager\ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var array
     */
    protected $settings = [];

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Set the service locator.
     */
    public function setServiceLocator(ServiceLocatorInterface $services): self
    {
        $this->services = $services;
        return $this;
    }

    public function setSettings(array $settings): self
    {
        $this->settings = $settings;
        return $this;
    }

    public function preFormat($value): array
    {
        if ($value === '' || $value === [] || $value === null) {
            return [];
        }

        $result = [];

        $parts = empty($this->settings['parts']) ? ['full'] : $this->settings['parts'];

        // Keep only scalar or ValueRepresentation.

        foreach ($parts as $part) switch ($part) {
            default:
            case 'full':
                if (is_object($value)) {
                    // Full means all possible values.
                    if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                        $v = $value->value();
                        $result['full_1'] = is_bool($v) ? ($v ? '1' : '0') : trim((string) $v);
                        $v = trim((string) $value->uri());
                        if ($v !== '') {
                            $result['full_2'] = $v;
                        }
                        $vr = $value->valueResource();
                        if ($vr) {
                            $result['full_3'] = $vr->displayTitle();
                        }
                    } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                        $result['value'] = trim((string) $value->altText());
                        $result['uri'] = trim((string) $value->assetUrl());
                    } elseif (method_exists('__toString', $value)) {
                        $result['string'] = trim((string) $value);
                    }
                } elseif (is_bool($value)) {
                    $result['bool'] = $value ? '1' : "0";
                } elseif (is_scalar($value)) {
                    $result['string'] = trim((string) $value);
                }
                break;

            case 'main':
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $v = trim((string) $value->value());
                    if ($v === '') {
                        $vr = $value->valueResource();
                        $result['main'] = $vr
                            ? trim((string) $vr->displayTitle())
                            : trim((string) $value->uri());
                    } else {
                        $result['main'] = $v;
                    }
                } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                    $result['main'] = trim((string) $value->altText());
                } elseif (is_object($value) && method_exists('__toString', $value)) {
                    $result['string'] = trim((string) $value);
                } elseif (is_bool($value)) {
                    $result['main'] = $value ? '1' : "0";
                } elseif (is_scalar($value)) {
                    $result['main'] = trim((string) $value);
                }
                break;

            case 'value':
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $v = $value->value();
                    $result['value'] = is_bool($v) ? ($v ? '1' : '0') : trim((string) $v);
                } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                    $result['value'] = trim((string) $value->altText());
                }
                break;

            case 'uri':
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $result['uri'] = trim((string) $value->uri());
                } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                    $result['uri'] = trim((string) $value->assetUrl());
                }
                break;

            case 'vrid':
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $v = $value->valueResource();
                    if ($v) {
                        $result['vrid'] = $v->id();
                    }
                } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                    // TODO Get the list of all resources ids with this asset.
                }
                break;

            case 'id':
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $result['id'] = $value->resource()->id();
                } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                    $result['id'] = $value->id();
                }
                break;

            case 'html':
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $result['html'] = trim((string) $value->asHtml());
                }
                break;
        }

        return array_values(array_filter($result, fn ($v) => $v !== '' && $v !== [] && $v !== null));
    }

    abstract public function format($value): array;

    public function postFormat($value): array
    {
        if ($value === '' || $value === [] || $value === null) {
            return [];
        }

        $normalizations = $this->settings['normalization'] ?? [];

        if (in_array('html_escaped', $normalizations)) {
            // New default for php 8.1.
            // @link https://forum.omeka.org/t/solr-text-field-length/13430
            $value = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
        }

        if (in_array('strip_tags', $normalizations)) {
            $value = strip_tags((string) $value);
        }

        if (in_array('lowercase', $normalizations)) {
            $value = mb_strtolower((string) $value);
        }

        if (in_array('uppercase', $normalizations)) {
            $value = mb_strtoupper((string) $value);
        }

        if (in_array('ucfirst', $normalizations)) {
            $value = mb_ucfirst((string) $value);
        }

        if (in_array('remove_diacritics', $normalizations)) {
            if (extension_loaded('intl')) {
                $transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
                $value = $transliterator->transliterate((string) $value);
            } elseif (extension_loaded('iconv')) {
                $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $value);
            }
        }

        if (in_array('alphanumeric', $normalizations)) {
            // Remove space recursively.
            $value = str_replace('  ', ' ', preg_replace('~[^\p{L}\p{N}-]++~u', ' ', (string) $value));
        }

        if (in_array('alphabetic', $normalizations)) {
            // Remove space recursively.
            $value = str_replace('  ', ' ', preg_replace('~[^\p{L}\p{N}-]++~u', ' ', (string) $value));
            // Remove digits.
            $value = preg_replace('~\p{N}+~u', '', (string) $value);
        }

        if (in_array('max_length', $normalizations)) {
            $maxLength = !empty($this->settings['max_length'])
                ? (int) $this->settings['max_length']
                : 0;
            if ($maxLength) {
                $value = mb_substr((string) $value, 0, $maxLength);
            }
        }

        if (in_array('integer', $normalizations)) {
            $value = (int) $value;
        }

        if (in_array('year', $normalizations)) {
            $value = (int) $value ?: null;
        }

        if (in_array('table', $normalizations)) {
            $value = $this->formatTable($value);
        }

        if ($value === '' || $value === [] || $value === null) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    public function finalizeFormat(array $values): array
    {
        $values = array_filter($values, fn ($v) => $v !== '' && $v !== [] && $v !== null);
        if (!$values) {
            return [];
        }

        $usePath = !empty($this->settings['finalization']['path']);
        if ($usePath) {
            // Check for the default separator
            $logger = $this->services->get('Omeka\Logger');
            foreach ($values as &$value) {
                if (mb_strpos($value, '/') !== false) {
                    $logger->warn(
                        'The value "{value}" cannot be included in a path. The "/" is replaced by " - ".', // @translate
                        ['value' => $value]
                    );
                    $value = trim(strtr($value, ['/' => ' - ']));
                }
            }
            unset($value);
            $values = [implode('/', $values)];
        }

        // FIXME Indexation of string "0" breaks Solr, so currently replaced by "00".
        foreach ($values as $value) {
            if ($value === '0') {
                $value = '00';
            }
        }

        // Don't use array_unique early, because objects may not be stringable.
        return array_values(array_unique($values));
    }

    public function formatTable($value): array
    {
        /** @var \Table\Api\Representation\TableRepresentation[] $tables */
        static $tables = [];

        // TODO Add an option to force output when there is no table.

        $value = trim(strip_tags((string) $value));
        if (!strlen($value)) {
            return [];
        }

        $tableId = $this->settings['table'] ?? null;
        if (!$tableId) {
            $this->services->get('Omeka\Logger')->err(
                'For formatter "Table", the table is not set.' // @translate
            );
            return [$value];
        }

        // Check if table is available one time only.
        if (!array_key_exists($tableId, $tables)) {
            /** @var \Omeka\Api\Manager $api */
            $api = $this->services->get('Omeka\ApiManager');
            try {
                $tables[$tableId] = $api->read('tables', is_numeric($tableId) ? ['id' => $tableId] : ['slug' => $tableId])->getContent();
            } catch (\Exception $e) {
                $tables[$tableId] = null;
                $this->services->get('Omeka\Logger')->err(
                    'For formatter "Table", the table #{table_id} does not exist and values are not normalized.', // @translate
                    ['table_id' => $tableId]
                );
                return [$value];
            }
        }
        if (!$tables[$tableId]) {
            return [$value];
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
                if ($indexOriginal) {
                    $result[] = $value;
                }
                $result[] = $table->labelFromCode($value, $checkStrict) ?? '';
                break;

            case 'code':
                if ($indexOriginal) {
                    $result[] = $value;
                }
                $result[] = $table->codeFromLabel($value, $checkStrict) ?? '';
                break;

            case 'both':
                if ($indexOriginal) {
                    $result[] = $value;
                }
                $result[] = $table->labelFromCode($value, $checkStrict) ?? '';
                $result[] = $table->codeFromLabel($value, $checkStrict) ?? '';
                break;
        }

        return array_values(array_unique(array_filter($result, 'strlen')));
    }
}
