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

    /**
     * @todo Manage a sub pool something like dcterms:creator/skos:altLabel[xxx].
     *
     * {@inheritDoc}
     * @see \SearchSolr\Api\Representation\SolrMapRepresentation::pool()
     */
    public function pool(?string $name = null, $default = null)
    {
        $subPool = [
            'filter_resources' => null,
            'filter_values' => null,
            'filter_uris' => null,
            'filter_value_resources' => null,
            'data_types' => null,
            'data_types_exclude' => null,
            'filter_languages' => null,
            'filter_visibility' => null,
        ];

        return $name === null
            ? $subPool
            : ($subPool[$name] ?? $default);
    }

    public function setSubSource(string $subSource)
    {
        $this->subSource = $subSource;
        return $this;
    }
}
