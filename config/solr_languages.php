<?php declare(strict_types=1);

/**
 * Solr language-specific analyzer configurations.
 *
 * Each entry defines the filters for a language analyzer used by
 * the "Linguistic" search configuration option. The tokenizer
 * (StandardTokenizer), LowerCase and ASCIIFolding filters are
 * always prepended automatically.
 *
 * Keys are ISO 639-1 language codes. Values are arrays of Solr
 * filter definitions.
 *
 * @see https://solr.apache.org/guide/solr/latest/indexing-guide/languages.html
 */

return [
    'ar' => [
        'label' => 'العربية (Arabic)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_ar.txt', 'ignoreCase' => true],
            ['class' => 'solr.ArabicNormalizationFilterFactory'],
            ['class' => 'solr.ArabicStemFilterFactory'],
        ],
    ],
    'bg' => [
        'label' => 'Български (Bulgarian)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_bg.txt', 'ignoreCase' => true],
            ['class' => 'solr.BulgarianStemFilterFactory'],
        ],
    ],
    'ca' => [
        'label' => 'Català (Catalan)', // @translate
        'filters' => [
            ['class' => 'solr.ElisionFilterFactory', 'ignoreCase' => true, 'articles' => 'lang/contractions_ca.txt'],
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_ca.txt', 'ignoreCase' => true],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Catalan'],
        ],
    ],
    'da' => [
        'label' => 'Dansk (Danish)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_da.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Danish'],
        ],
    ],
    'de' => [
        'label' => 'Deutsch (German)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_de.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.GermanNormalizationFilterFactory'],
            ['class' => 'solr.GermanLightStemFilterFactory'],
        ],
    ],
    'el' => [
        'label' => 'Ελληνικά (Greek)', // @translate
        'filters' => [
            ['class' => 'solr.GreekLowerCaseFilterFactory'],
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_el.txt', 'ignoreCase' => false],
            ['class' => 'solr.GreekStemFilterFactory'],
        ],
    ],
    'en' => [
        'label' => 'English', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_en.txt', 'ignoreCase' => true],
            ['class' => 'solr.EnglishPossessiveFilterFactory'],
            ['class' => 'solr.PorterStemFilterFactory'],
        ],
    ],
    'es' => [
        'label' => 'Español (Spanish)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_es.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.SpanishLightStemFilterFactory'],
        ],
    ],
    'eu' => [
        'label' => 'Euskara (Basque)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_eu.txt', 'ignoreCase' => true],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Basque'],
        ],
    ],
    'fi' => [
        'label' => 'Suomi (Finnish)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_fi.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Finnish'],
        ],
    ],
    'fr' => [
        'label' => 'Français (French)', // @translate
        'filters' => [
            ['class' => 'solr.ElisionFilterFactory', 'ignoreCase' => true, 'articles' => 'lang/contractions_fr.txt'],
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_fr.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.FrenchLightStemFilterFactory'],
        ],
    ],
    'ga' => [
        'label' => 'Gaeilge (Irish)', // @translate
        'filters' => [
            ['class' => 'solr.ElisionFilterFactory', 'ignoreCase' => true, 'articles' => 'lang/contractions_ga.txt'],
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_ga.txt', 'ignoreCase' => true],
            ['class' => 'solr.IrishLowerCaseFilterFactory'],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Irish'],
        ],
    ],
    'gl' => [
        'label' => 'Galego (Galician)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_gl.txt', 'ignoreCase' => true],
            ['class' => 'solr.GalicianStemFilterFactory'],
        ],
    ],
    'hu' => [
        'label' => 'Magyar (Hungarian)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_hu.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Hungarian'],
        ],
    ],
    'it' => [
        'label' => 'Italiano (Italian)', // @translate
        'filters' => [
            ['class' => 'solr.ElisionFilterFactory', 'ignoreCase' => true, 'articles' => 'lang/contractions_it.txt'],
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_it.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.ItalianLightStemFilterFactory'],
        ],
    ],
    'nl' => [
        'label' => 'Nederlands (Dutch)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_nl.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.StemmerOverrideFilterFactory', 'dictionary' => 'lang/stemdict_nl.txt', 'ignoreCase' => false],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Dutch'],
        ],
    ],
    'no' => [
        'label' => 'Norsk (Norwegian)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_no.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Norwegian'],
        ],
    ],
    'pt' => [
        'label' => 'Português (Portuguese)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_pt.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.PortugueseLightStemFilterFactory'],
        ],
    ],
    'ro' => [
        'label' => 'Română (Romanian)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_ro.txt', 'ignoreCase' => true],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Romanian'],
        ],
    ],
    'ru' => [
        'label' => 'Русский (Russian)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_ru.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Russian'],
        ],
    ],
    'sv' => [
        'label' => 'Svenska (Swedish)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_sv.txt', 'ignoreCase' => true, 'format' => 'snowball'],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Swedish'],
        ],
    ],
    'tr' => [
        'label' => 'Türkçe (Turkish)', // @translate
        'filters' => [
            ['class' => 'solr.StopFilterFactory', 'words' => 'lang/stopwords_tr.txt', 'ignoreCase' => false],
            ['class' => 'solr.TurkishLowerCaseFilterFactory'],
            ['class' => 'solr.SnowballPorterFilterFactory', 'language' => 'Turkish'],
        ],
    ],
];
