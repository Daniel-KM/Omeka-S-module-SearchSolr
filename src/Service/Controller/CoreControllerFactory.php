<?php declare(strict_types=1);

namespace SearchSolr\Service\Controller;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use SearchSolr\Controller\Admin\CoreController;

class CoreControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new CoreController(
            $services->get('SearchSolr\ValueExtractorManager')
        );
    }
}
