<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * ValueFormatter to get text with options.
 */
class Text extends AbstractValueFormatter
{
    public function getLabel(): string
    {
        return 'Text'; // @translate
    }

    public function format($value): array
    {
        if (is_object($value)) {
            if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                $value = (string) $value->value();
            } elseif ($value instanceof \Omeka\Api\Representation\AssetRepresentation) {
                $value = (string) $value->altText();
            } elseif (method_exists('__toString', $value)) {
                $value = (string) $value;
            } else {
                return [];
            }
        } else {
            $value = (string) $value;
        }

        $transformations = $this->settings['transformations'] ?? [];

        if (in_array('html_escaped', $transformations)) {
            // New default for php 8.1.
            // @link https://forum.omeka.org/t/solr-text-field-length/13430
            $value = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
        }

        if (in_array('strip_tags', $transformations)) {
            $value = strip_tags($value);
        }

        if (in_array('lowercase', $transformations)) {
            $value = mb_strtolower($value);
        }

        if (in_array('uppercase', $transformations)) {
            $value = mb_strtoupper($value);
        }

        if (in_array('ucfirst', $transformations)) {
            $value = mb_ucfirst($value);
        }

        if (in_array('remove_diacritics', $transformations)) {
            if (extension_loaded('intl')) {
                $transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
                $value = $transliterator->transliterate($value);
            } elseif (extension_loaded('iconv')) {
                $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            }
        }

        if (in_array('alphanumeric', $transformations)) {
            $value = str_replace('  ', ' ', preg_replace('~[^\p{L}\p{N}-]++~u', ' ', $value));
        }

        $maxLength = !empty($this->settings['max_length'])
            ? (int) $this->settings['max_length']
            : 0;
        if ($maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        $value = trim($value);
        return strlen($value) ? [$value] : [];
    }
}
