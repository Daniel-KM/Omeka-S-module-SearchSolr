<?php declare(strict_types=1);
namespace SearchSolr\Service\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use SearchSolr\Form\Admin\SolrMapForm;

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
