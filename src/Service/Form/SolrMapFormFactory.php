<?php
namespace SearchSolr\Service\Form;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use SearchSolr\Form\Admin\SolrMappingForm;

class SolrMappingFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $valueExtractorManager = $services->get('SearchSolr\ValueExtractorManager');
        $valueFormatterManager = $services->get('SearchSolr\ValueFormatterManager');
        $api = $services->get('Omeka\ApiManager');
        $form = new SolrMappingForm(null, $options);
        return $form
            ->setValueExtractorManager($valueExtractorManager)
            ->setValueFormatterManager($valueFormatterManager)
            ->setApiManager($api);
    }
}
