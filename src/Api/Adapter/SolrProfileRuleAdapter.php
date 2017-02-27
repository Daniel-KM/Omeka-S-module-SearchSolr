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

class SolrProfileRuleAdapter extends AbstractEntityAdapter
{
    /**
     * {@inheritDoc}
     */
    protected $sortFields = [
        'id' => 'id',
        'name' => 'name',
    ];

    /**
     * {@inheritDoc}
     */
    public function getResourceName()
    {
        return 'solr_profile_rules';
    }

    /**
     * {@inheritDoc}
     */
    public function getRepresentationClass()
    {
        return 'Solr\Api\Representation\SolrProfileRuleRepresentation';
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityClass()
    {
        return 'Solr\Entity\SolrProfileRule';
    }

    /**
     * {@inheritDoc}
     */
    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        if ($this->shouldHydrate($request, 'o:source')) {
            $entity->setSource($request->getValue('o:source'));
        }
        if ($this->shouldHydrate($request, 'o:settings')) {
            $entity->setSettings($request->getValue('o:settings'));
        }

        $this->hydrateSolrProfile($request, $entity);
        $this->hydrateSolrField($request, $entity);
    }

    /**
     * {@inheritDoc}
     */
    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['solr_profile_id'])) {
            $solrProfileAlias = $this->createAlias();
            $qb->innerJoin('Solr\Entity\SolrProfileRule.solrProfile', $solrProfileAlias);
            $qb->andWhere($qb->expr()->eq(
                "$solrProfileAlias.id",
                $this->createNamedParameter($qb, $query['solr_profile_id']))
            );
        }
        if (isset($query['solr_field_id'])) {
            $solrFieldAlias = $this->createAlias();
            $qb->innerJoin('Solr\Entity\SolrProfileRule.solrField', $solrFieldAlias);
            $qb->andWhere($qb->expr()->eq(
                "$solrFieldAlias.id",
                $this->createNamedParameter($qb, $query['solr_field_id']))
            );
        }
    }

    protected function hydrateSolrProfile(Request $request, EntityInterface $entity)
    {
        if ($this->shouldHydrate($request, 'o:solr_profile')) {
            $data = $request->getContent();
            if (isset($data['o:solr_profile']['o:id'])
                && is_numeric($data['o:solr_profile']['o:id'])
            ) {
                $profile = $this->getAdapter('solr_profiles')
                    ->findEntity($data['o:solr_profile']['o:id']);
            } else {
                $profile = null;
            }
            $entity->setSolrProfile($profile);
        }
    }

    protected function hydrateSolrField(Request $request, EntityInterface $entity)
    {
        if ($this->shouldHydrate($request, 'o:solr_field')) {
            $data = $request->getContent();
            if (isset($data['o:solr_field']['o:id'])
                && is_numeric($data['o:solr_field']['o:id'])
            ) {
                $field = $this->getAdapter('solr_fields')
                    ->findEntity($data['o:solr_field']['o:id']);
            } else {
                $field = null;
            }
            $entity->setSolrField($field);
        }
    }
}
