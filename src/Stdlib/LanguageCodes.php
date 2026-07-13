<?php declare(strict_types=1);

namespace SearchSolr\Stdlib;

/**
 * Normalize a language code to its two-letter ISO 639-1 code.
 *
 * The language of a value and the locale of a site are rarely written the same
 * way: a site locale is a full locale ("en", "en-US", "en_US") and the language
 * of a value may be a two-letter code, a locale, or a three-letter ISO 639-2
 * code, either bibliographic ("fre", "ger", "dut") or terminological ("fra",
 * "deu", "nld"). They are all reduced to the same two-letter code, so a site in
 * "en_US" matches values in "en", "en-GB" and "eng".
 *
 * Unlike the Solr analyzer suffixes used to build the language field types,
 * this is a pure language code: "cs" is not "cz" and "zh" is not "cjk".
 *
 * @link https://www.loc.gov/standards/iso639-2/php/code_list.php
 */
class LanguageCodes
{
    /**
     * ISO 639-2 codes to ISO 639-1, bibliographic and terminological ones.
     */
    const ISO_639_2_TO_1 = [
        'alb' => 'sq', 'sqi' => 'sq',
        'ara' => 'ar',
        'arm' => 'hy', 'hye' => 'hy',
        'baq' => 'eu', 'eus' => 'eu',
        'bel' => 'be',
        'ben' => 'bn',
        'bre' => 'br',
        'bul' => 'bg',
        'cat' => 'ca',
        'chi' => 'zh', 'zho' => 'zh',
        'cor' => 'kw',
        'cos' => 'co',
        'cze' => 'cs', 'ces' => 'cs',
        'dan' => 'da',
        'dut' => 'nl', 'nld' => 'nl',
        'eng' => 'en',
        'epo' => 'eo',
        'est' => 'et',
        'fao' => 'fo',
        'fin' => 'fi',
        'fre' => 'fr', 'fra' => 'fr',
        'geo' => 'ka', 'kat' => 'ka',
        'ger' => 'de', 'deu' => 'de',
        'gla' => 'gd',
        'gle' => 'ga',
        'glg' => 'gl',
        'gre' => 'el', 'ell' => 'el',
        'heb' => 'he',
        'hin' => 'hi',
        'hrv' => 'hr',
        'hun' => 'hu',
        'ice' => 'is', 'isl' => 'is',
        'ind' => 'id',
        'ita' => 'it',
        'jpn' => 'ja',
        'kor' => 'ko',
        'lat' => 'la',
        'lav' => 'lv',
        'lit' => 'lt',
        'ltz' => 'lb',
        'mac' => 'mk', 'mkd' => 'mk',
        'mal' => 'ml',
        'may' => 'ms', 'msa' => 'ms',
        'mlt' => 'mt',
        'nor' => 'no',
        'nob' => 'nb',
        'nno' => 'nn',
        'oci' => 'oc',
        'per' => 'fa', 'fas' => 'fa',
        'pol' => 'pl',
        'por' => 'pt',
        'rum' => 'ro', 'ron' => 'ro',
        'rus' => 'ru',
        'slo' => 'sk', 'slk' => 'sk',
        'slv' => 'sl',
        'spa' => 'es',
        'srp' => 'sr',
        'swe' => 'sv',
        'tha' => 'th',
        'tur' => 'tr',
        'ukr' => 'uk',
        'vie' => 'vi',
        'wel' => 'cy', 'cym' => 'cy',
    ];

    /**
     * Get the two-letter code of a language or a locale, else an empty string.
     *
     * The three-letter codes without a two-letter equivalent are kept as is, so
     * they can still be matched between them.
     */
    public static function toIso1(?string $lang): string
    {
        // Keep the primary subtag only: "en-US" and "en_US" are "en".
        $lang = strtolower(strtok((string) $lang, '-_') ?: '');
        return self::ISO_639_2_TO_1[$lang] ?? $lang;
    }
}
