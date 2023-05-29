<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

abstract class AbstractValueFormatter implements ValueFormatterInterface
{
    /**
     * @var array
     */
    protected $settings = [];

    public function setSettings(array $settings): self
    {
        $this->settings = $settings;
        return $this;
    }
}
