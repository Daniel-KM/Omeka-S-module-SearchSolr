<?php declare(strict_types=1);
namespace SearchSolr\Form\Admin;

use Laminas\Form\Form;

class SolrCoreMappingImportForm extends Form
{
    /**
     * A list of standard delimiters.
     *
     * @var array
     */
    protected $delimiterList = [
        ',' => 'comma', // @translate
        ';' => 'semi-colon', // @translate
        ':' => 'colon', // @translate
        'tabulation' => 'tabulation', // @translate
    ];

    /**
     * A list of standard enclosures.
     *
     * @var array
     */
    protected $enclosureList = [
        '"' => 'double quote', // @translate
        "'" => 'single quote', // @translate
        '#' => 'hash', // @translate
        'empty' => 'empty', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'solr-core-mapping-import-form');

        $this
            ->add([
                'name' => 'source',
                'type' => 'file',
                'options' => [
                    'label' => 'Spreadsheet (tsv or csv)', // @translate
                    'info' => 'LibreOffice and tsv are recommended for compliant formats.', //@translate
                ],
                'attributes' => [
                    'id' => 'source',
                    'required' => 'true',
                ],
            ])
            ->add([
                'name' => 'delimiter',
                'type' => 'select',
                'options' => [
                    'label' => 'Column delimiter', // @translate
                    'info' => 'A single character that will be used to separate columns in the csv file.', // @translate
                    'value_options' => $this->delimiterList,
                ],
                'attributes' => [
                    'id' => 'delimiter',
                    'value' => 'tabulation',
                ],
            ])
            ->add([
                'name' => 'enclosure',
                'type' => 'select',
                'options' => [
                    'label' => 'Column enclosure', // @translate
                    'info' => 'A single character that will be used to separate columns in the csv file. The enclosure can be omitted when the content does not contain the delimiter.', // @translate
                    'value_options' => $this->enclosureList,
                ],
                'attributes' => [
                    'id' => 'enclosure',
                    'value' => 'empty',
                ],
            ]);

        $this->getInputFilter()
            ->add([
                'name' => 'source',
                'required' => false,
            ])
            ->add([
                'name' => 'delimiter',
                'required' => false,
            ])
            ->add([
                'name' => 'enclosure',
                'required' => false,
            ]);
    }
}
