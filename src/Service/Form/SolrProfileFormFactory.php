<?php
namespace Solr\Service\Form;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use Solr\Form\Admin\SolrProfileForm;

class SolrProfileFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $valueExtractorManager = $services->get('Solr\ValueExtractorManager');
        $translator = $services->get('MvcTranslator');

        $form = new SolrProfileForm;
        $form->setTranslator($translator);
        $form->setValueExtractorManager($valueExtractorManager);

        return $form;
    }
}
