<?php
namespace Solr\Service\Form;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use Solr\Form\Admin\SolrFieldForm;

class SolrFieldFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $form = new SolrFieldForm;
        $form->setTranslator($services->get('MvcTranslator'));

        return $form;
    }
}
