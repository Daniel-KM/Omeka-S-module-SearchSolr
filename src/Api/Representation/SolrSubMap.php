<?php declare(strict_types=1);

namespace SearchSolr\Api\Representation;

class SolrSubMap extends SolrMapRepresentation
{
    /**
     * @var string
     */
    protected $subSource;

    /**
     * In a sub map, the source is the sub-source (so no recursion).
     *
     * {@inheritDoc}
     * @see \SearchSolr\Api\Representation\SolrMapRepresentation::source()
     */
    public function source(): string
    {
        return $this->subSource;
    }

    public function setSubSource(string $subSource)
    {
        $this->subSource = $subSource;
        return $this;
    }
}
