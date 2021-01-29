<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2017
 * Copyright Daniel Berthereau, 2020-2021
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace SearchSolr\Entity;

use Omeka\Entity\AbstractEntity;

/**
 * @Entity
 * @Table(
 *     indexes={
 *         @Index(
 *             columns={"solr_core_id","resource_name"}
 *         ),
 *         @Index(
 *             columns={"solr_core_id","field_name"}
 *         ),
 *         @Index(
 *             columns={"solr_core_id","source"}
 *         )
 *     }
 * )
 */
class SolrMap extends AbstractEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @var SolrCore
     * @ManyToOne(
     *     targetEntity="SearchSolr\Entity\SolrCore",
     *     inversedBy="maps"
     * )
     * @JoinColumn(
     *     nullable=false,
     *     onDelete="CASCADE"
     * )
     */
    protected $solrCore;

    /**
     * @var string
     * @Column(
     *     type="string",
     *     length=190
     * )
     */
    protected $resourceName;

    /**
     * @var string
     * @Column(
     *     type="string",
     *     length=190
     * )
     */
    protected $fieldName;

    /**
     * @var string
     * @Column(
     *     type="string",
     *     length=190
     * )
     */
    protected $source;

    /**
     * @var array
     * @Column(
     *     type="json",
     *     nullable=false
     * )
     */
    protected $pool = [];

    /**
     * @var array
     * @Column(
     *     type="json",
     *     nullable=false
     * )
     */
    protected $settings = [];

    public function getId()
    {
        return $this->id;
    }

    public function setSolrCore(SolrCore $solrCore): self
    {
        $this->solrCore = $solrCore;
        return $this;
    }

    public function getSolrCore(): SolrCore
    {
        return $this->solrCore;
    }

    public function setResourceName(string $resourceName): self
    {
        $this->resourceName = $resourceName;
        return $this;
    }

    public function getResourceName(): string
    {
        return $this->resourceName;
    }

    public function setFieldName(string $fieldName): self
    {
        $this->fieldName = $fieldName;
        return $this;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setPool(array $pool): self
    {
        $this->pool = $pool;
        return $this;
    }

    public function getPool(): array
    {
        return $this->pool;
    }

    public function setSettings(array $settings): self
    {
        $this->settings = $settings;
        return $this;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }
}
