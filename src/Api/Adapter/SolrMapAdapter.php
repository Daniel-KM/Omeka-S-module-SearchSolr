<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2017
 * Copyright Daniel Berthereau, 2017-2021
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

namespace SearchSolr\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class SolrMapAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'core' => 'solrCore',
        'resource_name' => 'resourceName',
        'field_name' => 'fieldName',
        'source' => 'source',
    ];

    public function getResourceName()
    {
        return 'solr_maps';
    }

    public function getRepresentationClass()
    {
        return \SearchSolr\Api\Representation\SolrMapRepresentation::class;
    }

    public function getEntityClass()
    {
        return \SearchSolr\Entity\SolrMap::class;
    }

    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ): void {
        if ($this->shouldHydrate($request, 'o:resource_name')) {
            $entity->setResourceName(trim($request->getValue('o:resource_name')));
        }
        if ($this->shouldHydrate($request, 'o:field_name')) {
            $entity->setFieldName(trim($request->getValue('o:field_name')));
        }
        if ($this->shouldHydrate($request, 'o:source')) {
            $entity->setSource(trim($request->getValue('o:source')));
        }
        if ($this->shouldHydrate($request, 'o:pool')) {
            $entity->setPool($request->getValue('o:pool') ?: []);
        }
        if ($this->shouldHydrate($request, 'o:settings')) {
            $entity->setSettings($request->getValue('o:settings') ?: []);
        }

        $this->hydrateSolrCore($request, $entity);
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['solr_core_id'])) {
            $coreAlias = $this->createAlias();
            $qb
                ->innerJoin(
                    'omeka_root.solrCore',
                    $coreAlias
                )
                ->andWhere($expr->eq(
                    $coreAlias . '.id',
                    $this->createNamedParameter($qb, $query['solr_core_id']))
                );
        }
        if (isset($query['resource_name'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.resourceName',
                $this->createNamedParameter($qb, $query['resource_name'])
            ));
        }
    }

    protected function hydrateSolrCore(Request $request, EntityInterface $entity): void
    {
        if ($this->shouldHydrate($request, 'o:solr_core')) {
            $data = $request->getContent();
            if (isset($data['o:solr_core']['o:id'])
                && is_numeric($data['o:solr_core']['o:id'])
            ) {
                $core = $this->getAdapter('solr_cores')
                    ->findEntity($data['o:solr_core']['o:id']);
            } else {
                $core = null;
            }
            $entity->setSolrCore($core);
        }
    }
}
