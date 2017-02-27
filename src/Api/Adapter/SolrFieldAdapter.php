<?php

/*
 * Copyright BibLibre, 2016
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

namespace Solr\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class SolrFieldAdapter extends AbstractEntityAdapter
{
    /**
     * {@inheritDoc}
     */
    protected $sortFields = [
        'id' => 'id',
        'name' => 'name',
        'created' => 'created',
        'modified' => 'modified',
    ];

    /**
     * {@inheritDoc}
     */
    public function getResourceName()
    {
        return 'solr_fields';
    }

    /**
     * {@inheritDoc}
     */
    public function getRepresentationClass()
    {
        return 'Solr\Api\Representation\SolrFieldRepresentation';
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityClass()
    {
        return 'Solr\Entity\SolrField';
    }

    /**
     * {@inheritDoc}
     */
    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        if ($this->shouldHydrate($request, 'o:name')) {
            $entity->setName($request->getValue('o:name'));
        }
        if ($this->shouldHydrate($request, 'o:description')) {
            $entity->setDescription($request->getValue('o:description'));
        }
        if ($this->shouldHydrate($request, 'o:is_indexed')) {
            $entity->setIsIndexed($request->getValue('o:is_indexed'));
        }
        if ($this->shouldHydrate($request, 'o:is_multivalued')) {
            $entity->setIsMultivalued($request->getValue('o:is_multivalued'));
        }

        $this->hydrateSolrNode($request, $entity);
    }

    /**
     * {@inheritDoc}
     */
    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore)
    {
        if (false == $entity->getName()) {
            $errorStore->addError('o:name', 'The name cannot be empty.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['solr_node_id'])) {
            $nodeAlias = $this->createAlias();
            $qb->innerJoin('Solr\Entity\SolrField.solrNode', $nodeAlias);
            $qb->andWhere($qb->expr()->eq(
                "$nodeAlias.id",
                $this->createNamedParameter($qb, $query['solr_node_id']))
            );
        }
        if (isset($query['is_indexed'])) {
            $qb->andWhere($qb->expr()->eq(
                $this->getEntityClass() . ".isIndexed",
                $this->createNamedParameter($qb, $query['is_indexed']))
            );
        }
        if (isset($query['is_multivalued'])) {
            $qb->andWhere($qb->expr()->eq(
                $this->getEntityClass() . ".isMultivalued",
                $this->createNamedParameter($qb, $query['is_multivalued']))
            );
        }
    }

    protected function hydrateSolrNode(Request $request, EntityInterface $entity)
    {
        if ($this->shouldHydrate($request, 'o:solr_node')) {
            $data = $request->getContent();
            if (isset($data['o:solr_node']['o:id'])
                && is_numeric($data['o:solr_node']['o:id'])
            ) {
                $node = $this->getAdapter('solr_nodes')
                    ->findEntity($data['o:solr_node']['o:id']);
            } else {
                $node = null;
            }
            $entity->setSolrNode($node);
        }
    }
}
