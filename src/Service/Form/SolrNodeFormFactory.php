<?php
namespace Solr\Service\Form;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use Solr\Form\Admin\SolrNodeForm;

class SolrNodeFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $form = new SolrNodeForm(null, $options);
        $form->setTranslator($services->get('MvcTranslator'));

        return $form;
    }
}
