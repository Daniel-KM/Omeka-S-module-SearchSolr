<?php
namespace Solr\Service\Form;

use Solr\Form\Admin\SolrProfileForm;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class SolrProfileFormFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $elements)
    {
        $serviceLocator = $elements->getServiceLocator();
        $valueExtractorManager = $serviceLocator->get('Solr\ValueExtractorManager');

        $form = new SolrProfileForm;
        $form->setTranslator($serviceLocator->get('MvcTranslator'));
        $form->setValueExtractorManager($valueExtractorManager);

        return $form;
    }
}
