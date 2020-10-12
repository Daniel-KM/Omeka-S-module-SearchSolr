<?php
namespace SearchSolr\Service\Form;

use Interop\Container\ContainerInterface;
use SearchSolr\Form\Admin\SolrCoreForm;
use Zend\ServiceManager\Factory\FactoryInterface;

class SolrCoreFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $form = new SolrCoreForm(null, $options);
        return $form;
    }
}
