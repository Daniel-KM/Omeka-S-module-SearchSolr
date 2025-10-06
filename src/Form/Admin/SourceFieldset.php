<?php declare(strict_types=1);

namespace SearchSolr\Form\Admin;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\InputFilter\InputFilterProviderInterface;

class SourceFieldset extends Fieldset implements InputFilterProviderInterface
{
    public function __construct($name = null, $options = [])
    {
        parent::__construct($name, $options);
        $this->init();
    }

    public function init(): void
    {
        $this
            ->add([
                'name' => 'source',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'value_options' => $this->getOption('options'),
                    'empty_option' => 'Select a metadata from the resource…', // @translate
                ],
            ])->add([
                'name' => 'set_sub',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Sub-property', // @translate
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
