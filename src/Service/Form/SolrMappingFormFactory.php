<?php
namespace Solr\Service\Form;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use Solr\Form\Admin\SolrMappingForm;

class SolrMappingFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $valueExtractorManager = $services->get('Solr\ValueExtractorManager');
        $valueFormatterManager = $services->get('Solr\ValueFormatterManager');
        $api = $services->get('Omeka\ApiManager');

        $form = new SolrMappingForm(null, $options);
        $form->setValueExtractorManager($valueExtractorManager);
        $form->setValueFormatterManager($valueFormatterManager);
        $form->setApiManager($api);
        return $form;
    }
}
