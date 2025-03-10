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

        $parts = empty($this->settings['part']) ? ['auto'] : $this->settings['part'];

        // Keep only scalar or ValueRepresentation.

        foreach ($parts as $part) switch ($part) {
            default:
            case 'auto':
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $result['auto'] = $value;
                } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                    $result['value'] = trim((string) $value->altText());
                    $result['uri'] = trim((string) $value->assetUrl());
                } else {
                    $result['string'] = trim((string) $value);
                }
                break;

            case 'string':
                if (is_object($value)) {
                    if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                        $result['string'] = trim((string) $value);
                    } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                        $result['string'] = trim((string) $value->altText());
                    } elseif (method_exists('__toString', $value)) {
                        $result['string'] = trim((string) $value);
                    }
                } elseif (is_scalar($value)) {
                    $result['string'] = trim((string) $value);
                }
                break;

            case 'value':
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $result['value'] = trim((string) $value->value());
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
            $value = strip_tags($value);
        }

        if (in_array('lowercase', $normalizations)) {
            $value = mb_strtolower($value);
        }

        if (in_array('uppercase', $normalizations)) {
            $value = mb_strtoupper($value);
        }

        if (in_array('ucfirst', $normalizations)) {
            $value = mb_ucfirst($value);
        }

        if (in_array('remove_diacritics', $normalizations)) {
            if (extension_loaded('intl')) {
                $transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
                $value = $transliterator->transliterate($value);
            } elseif (extension_loaded('iconv')) {
                $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            }
        }

        if (in_array('alphanumeric', $normalizations)) {
            $value = str_replace('  ', ' ', preg_replace('~[^\p{L}\p{N}-]++~u', ' ', $value));
        }

        if (in_array('max_length', $normalizations)) {
            $maxLength = !empty($this->settings['max_length'])
                ? (int) $this->settings['max_length']
                : 0;
            if ($maxLength) {
                $value = mb_substr($value, 0, $maxLength);
            }
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
                    $value = trim(str_replace('/', ' - ', $value));
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
}
