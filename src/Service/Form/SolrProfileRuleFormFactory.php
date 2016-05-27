<?php
namespace Solr\Service\Form;

use Solr\Form\Admin\SolrProfileRuleForm;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class SolrProfileRuleFormFactory implements FactoryInterface
{
    protected $options;

    public function createService(ServiceLocatorInterface $elements)
    {
        $serviceLocator = $elements->getServiceLocator();
        $valueExtractorManager = $serviceLocator->get('Solr\ValueExtractorManager');
        $api = $serviceLocator->get('Omeka\ApiManager');

        $form = new SolrProfileRuleForm(null, $this->options);
        $form->setTranslator($serviceLocator->get('MvcTranslator'));
        $form->setValueExtractorManager($valueExtractorManager);
        $form->setApiManager($api);

        return $form;
    }

    public function setCreationOptions($options)
    {
        $this->options = $options;
    }
}
