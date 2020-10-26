<?php declare(strict_types=1);
namespace SearchSolr\Service\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use SearchSolr\Form\Admin\SolrCoreForm;

class SolrCoreFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $form = new SolrCoreForm(null, $options);
        return $form;
    }
}
