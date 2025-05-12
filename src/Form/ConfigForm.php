<?php declare(strict_types=1);

namespace SearchSolr\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'searchsolr_solarium_adapter',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Solarium adapter', // @translate
                    'value_options' => [
                        [
                            'value' => 'auto',
                            'label' => 'Auto (curl when available)', // @translate
                        ],
                        [
                            'value' => 'curl',
                            'label' => extension_loaded('curl')
                                ? 'Curl (via extension php-curl)' // @translate
                                : 'Curl (unavailable: require extension php-curl)', // @translate
                            'attributes' => [
                                'disabled' => extension_loaded('curl') ? false : 'disabled',
                            ],
                        ],
                        [
                            'value' => 'http',
                            'label' => 'Http', // @translate
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'searchsolr_solarium_adapter',
                    'required' => false,
                    'value' => 'auto',
                ],
            ])
            ->add([
                'name' => 'searchsolr_solarium_timeout',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Solarium timeout (seconds)', // @translate
                ],
                'attributes' => [
                    'id' => 'searchsolr_solarium_timeout',
                    'required' => false,
                    // This is the default in Solarium.
                    'value' => '5',
                    'min' => '0',
                ],
            ])
        ;

        $this->getInputFilter()
            ->add([
                'name' => 'searchsolr_solarium_adapter',
                'required' => false,
            ])
            ->add([
                'name' => 'searchsolr_solarium_timeout',
                'required' => false,
            ])
        ;
    }
}
