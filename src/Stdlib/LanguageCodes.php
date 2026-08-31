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

    /**
     * Language codes to the suffix of the matching Solr analyzer field type.
     *
     * These are the field types shipped by the default Solr schema, so the
     * suffix is not always the language code: Czech is "cz" and the Chinese,
     * Japanese and Korean share the "cjk" analyzer.
     */
    const SOLR_SUFFIXES = [
        'cjk' => 'cjk',
        'zh' => 'cjk',
        'zho' => 'cjk',
        'chi' => 'cjk',
        // 'ja' => 'cjk', 'jpn' => 'cjk', 'ko' => 'cjk', 'kor' => 'cjk',
        'en' => 'en',
        'eng' => 'en',
        'ar' => 'ar',
        'ara' => 'ar',
        'bg' => 'bg',
        'bul' => 'bg',
        'ca' => 'ca',
        'cat' => 'ca',
        'cz' => 'cz',
        'ces' => 'cz',
        'cze' => 'cz',
        'da' => 'da',
        'dan' => 'da',
        'de' => 'de',
        'deu' => 'de',
        'ger' => 'de',
        'el' => 'el',
        'ell' => 'el',
        'gre' => 'el',
        'es' => 'es',
        'spa' => 'es',
        'et' => 'et',
        'est' => 'et',
        'eu' => 'eu',
        'eus' => 'eu',
        'baq' => 'eu',
        'fa' => 'fa',
        'fas' => 'fa',
        'per' => 'fa',
        'fi' => 'fi',
        'fin' => 'fi',
        'fr' => 'fr',
        'fra' => 'fr',
        'fre' => 'fr',
        'ga' => 'ga',
        'gle' => 'ga',
        'gl' => 'gl',
        'glg' => 'gl',
        'hi' => 'hi',
        'hin' => 'hi',
        'hu' => 'hu',
        'hun' => 'hu',
        'hy' => 'hy',
        'hye' => 'hy',
        'arm' => 'hy',
        'id' => 'id',
        'ind' => 'id',
        'it' => 'it',
        'ita' => 'it',
        'ja' => 'ja',
        'jpn' => 'ja',
        'ko' => 'ko',
        'kor' => 'ko',
        'lv' => 'lv',
        'lav' => 'lv',
        'nl' => 'nl',
        'nld' => 'nl',
        'dut' => 'nl',
        'no' => 'no',
        'nor' => 'no',
        'pt' => 'pt',
        'por' => 'pt',
        'ro' => 'ro',
        'ron' => 'ro',
        'rum' => 'ro',
        'ru' => 'ru',
        'rus' => 'ru',
        'sv' => 'sv',
        'swe' => 'sv',
        'th' => 'th',
        'tha' => 'th',
        'tr' => 'tr',
        'tur' => 'tr',
    ];

    /**
     * Get the Solr analyzer suffix of a language, else an empty string.
     */
    public static function toSolrSuffix(?string $lang): string
    {
        return self::SOLR_SUFFIXES[strtolower((string) $lang)] ?? '';
    }

    /**
     * Get all the language codes indexed by a Solr analyzer suffix.
     *
     * @return string[]
     */
    public static function codesForSolrSuffix(string $suffix): array
    {
        return array_keys(self::SOLR_SUFFIXES, $suffix);
    }
}
