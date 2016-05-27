<?php
namespace Solr\Service\Form;

use Solr\Form\Admin\SolrFieldForm;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class SolrFieldFormFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $elements)
    {
        $serviceLocator = $elements->getServiceLocator();

        $form = new SolrFieldForm;
        $form->setTranslator($serviceLocator->get('MvcTranslator'));

        return $form;
    }
}
