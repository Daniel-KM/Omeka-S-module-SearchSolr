<?php
namespace Solr\Service\Form;

use Solr\Form\Admin\SolrNodeForm;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class SolrNodeFormFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $elements)
    {
        $serviceLocator = $elements->getServiceLocator();

        $form = new SolrNodeForm;
        $form->setTranslator($serviceLocator->get('MvcTranslator'));

        return $form;
    }
}
