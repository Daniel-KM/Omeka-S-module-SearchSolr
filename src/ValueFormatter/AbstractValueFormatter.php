<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

use Laminas\ServiceManager\ServiceLocatorInterface;

abstract class AbstractValueFormatter implements ValueFormatterInterface
{
    /**
     * @var \Laminas\ServiceManager\ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var array
     */
    protected $settings = [];

    /**
     * Set the service locator.
     */
    public function setServiceLocator(ServiceLocatorInterface $services): self
    {
        $this->services = $services;
        return $this;
    }

    public function setSettings(array $settings): self
    {
        $this->settings = $settings;
        return $this;
    }
}
