<?php
namespace SearchSolr\Service\Form;

use Interop\Container\ContainerInterface;
use SearchSolr\Form\Admin\SolrMapForm;
use Zend\ServiceManager\Factory\FactoryInterface;

class SolrMapFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $valueExtractorManager = $services->get('SearchSolr\ValueExtractorManager');
        $valueFormatterManager = $services->get('SearchSolr\ValueFormatterManager');
        $api = $services->get('Omeka\ApiManager');
        $form = new SolrMapForm(null, $options);
        return $form
            ->setValueExtractorManager($valueExtractorManager)
            ->setValueFormatterManager($valueFormatterManager)
            ->setApiManager($api);
    }
}
