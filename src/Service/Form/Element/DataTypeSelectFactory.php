<?php declare(strict_types=1);

namespace SearchSolr\Service\Form\Element;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use SearchSolr\Form\Element\DataTypeSelect;

class DataTypeSelectFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $element = new DataTypeSelect;
        return $element
            ->setDataTypeManager($services->get('Omeka\DataTypeManager'));
    }
}
