<?php

namespace Solr\Form\Admin;

use Zend\Form\Fieldset;
use Zend\Form\Element;
use Zend\InputFilter\InputFilterProviderInterface;

class SourceFieldset extends Fieldset implements InputFilterProviderInterface
{
    public function __construct($name = null, $options = [])
    {
        parent::__construct($name, $options);
        $this->init();
    }

    public function init()
    {
        $this->add([
            'name' => 'source',
            'type' => Element\Select::class,
            'options' => [
                'value_options' => $this->getOption('options'),
                'empty_option' => 'Select a metadata from the resource…', // @translate
            ],
        ]);

        $this->add([
            'name' => 'set_sub',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Set sub-property', // @translate
                'use_hidden_element' => false,
            ],
        ]);
    }

    public function getInputFilterSpecification()
    {
        return [
            'source' => ['required' => true],
            'set_sub' => ['required' => false],
        ];
    }
}
