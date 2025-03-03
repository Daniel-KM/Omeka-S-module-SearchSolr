<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

use Laminas\ServiceManager\ServiceLocatorInterface;

abstract class AbstractValueFormatter implements ValueFormatterInterface
{
    /**
     * @var string
     */
    protected $label;

    /**
     * @var string|null
     */
    protected $comment = null;

    /**
     * @var \Laminas\ServiceManager\ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var array
     */
    protected $settings = [];

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

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
