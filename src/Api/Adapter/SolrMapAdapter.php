<?php declare(strict_types=1);

/*
 * Copyright BibLibre, 2017
 * Copyright Daniel Berthereau, 2017-2026
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
    use TraitArrayFilterRecursiveEmptyValue;

    protected $sortFields = [
        'id' => 'id',
        'core' => 'engine',
        'resource_name' => 'resourceName',
        'field_name' => 'fieldName',
        'alias' => 'alias',
        'source' => 'source',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'core' => 'engine',
        'resource_name' => 'resourceName',
        'field_name' => 'fieldName',
        'alias' => 'alias',
        'source' => 'source',
        'pool' => 'pool',
        'settings' => 'settings',
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

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        // Id is managed via entity adapter.

        // The canonical key is "engine_id"; "solr_core_id" is kept as an
        // alias, since the engine and the former core are the same object.
        $engineId = $query['engine_id'] ?? $query['solr_core_id'] ?? null;
        if ($engineId) {
            $engineAlias = $this->createAlias();
            $qb
                ->innerJoin(
                    'omeka_root.engine',
                    $engineAlias
                )
                ->andWhere($expr->eq(
                    $engineAlias . '.id',
                    $this->createNamedParameter($qb, $engineId))
                );
        }
        if (isset($query['resource_name']) && $query['resource_name']) {
            $qb->andWhere($expr->eq(
                'omeka_root.resourceName',
                $this->createNamedParameter($qb, $query['resource_name'])
            ));
        }
        if (isset($query['field_name']) && $query['field_name']) {
            $qb->andWhere($expr->eq(
                'omeka_root.fieldName',
                $this->createNamedParameter($qb, $query['field_name'])
            ));
        }
        if (isset($query['alias']) && $query['alias']) {
            $qb->andWhere($expr->eq(
                'omeka_root.alias',
                $this->createNamedParameter($qb, $query['alias'])
            ));
        }
        if (isset($query['source']) && $query['source']) {
            $qb->andWhere($expr->eq(
                'omeka_root.source',
                $this->createNamedParameter($qb, $query['source'])
            ));
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        /** @var \SearchSolr\Entity\SolrMap $entity */

        if ($this->shouldHydrate($request, 'o:resource_name')) {
            $entity->setResourceName(trim($request->getValue('o:resource_name')));
        }
        if ($this->shouldHydrate($request, 'o:field_name')) {
            $entity->setFieldName(trim($request->getValue('o:field_name')));
        }
        if ($this->shouldHydrate($request, 'o:alias')) {
            $entity->setAlias(trim($request->getValue('o:alias') ?? '') ?: null);
        }
        if ($this->shouldHydrate($request, 'o:source')) {
            $entity->setSource(trim($request->getValue('o:source')));
        }
        if ($this->shouldHydrate($request, 'o:pool')) {
            $array = $this->arrayFilterRecursiveEmptyValue($request->getValue('o:pool') ?: []);
            $entity->setPool($array);
        }
        if ($this->shouldHydrate($request, 'o:settings')) {
            $array = $this->arrayFilterRecursiveEmptyValue($request->getValue('o:settings') ?: []);
            $array = self::normalizeListSettings($array);
            $entity->setSettings($array);
        }

        $this->hydrateSolrCore($request, $entity);
    }

    /**
     * Re-index multi-value list settings so that removing an empty option does
     * not leave a gap stored as a json object ({"1":"main"}) instead of a list
     * (["main"]). Keep map-like settings such as "table" (code => label).
     */
    public static function normalizeListSettings(array $settings): array
    {
        foreach (['parts', 'normalization', 'thesaurus_metadata', 'finalization'] as $key) {
            if (isset($settings[$key]) && is_array($settings[$key])) {
                $settings[$key] = array_values($settings[$key]);
            }
        }
        return $settings;
    }

    protected function hydrateSolrCore(Request $request, EntityInterface $entity): void
    {
        // The canonical payload is "o:engine"; "o:solr_core" is kept as an
        // alias, since the engine and the former core are the same object.
        $data = $request->getContent();
        $engineId = null;
        if ($this->shouldHydrate($request, 'o:engine')
            && isset($data['o:engine']['o:id'])
            && is_numeric($data['o:engine']['o:id'])
        ) {
            $engineId = (int) $data['o:engine']['o:id'];
        } elseif ($this->shouldHydrate($request, 'o:solr_core')
            && isset($data['o:solr_core']['o:id'])
            && is_numeric($data['o:solr_core']['o:id'])
        ) {
            $engineId = (int) $data['o:solr_core']['o:id'];
        } else {
            return;
        }
        $engine = $this->getAdapter('search_engines')->findEntity($engineId);
        $entity->setEngine($engine);
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore): void
    {
        // Solr refuses a field whose name does not follow its rules, but only
        // when a document is sent, so the map is checked at once. The names
        // that start and end with "_" are reserved by Solr.
        /** @see https://solr.apache.org/guide/solr/latest/indexing-guide/fields.html */
        $fieldName = (string) $entity->getFieldName();
        if ($fieldName === '') {
            $errorStore->addError('o:field_name', 'The name of the index cannot be empty.'); // @translate
        } elseif (!preg_match('~^[a-zA-Z_][a-zA-Z0-9_]*$~', $fieldName)) {
            $errorStore->addError('o:field_name', 'The name of the index must start with a letter or "_", then letters, digits or "_" only.'); // @translate
        } elseif (mb_substr($fieldName, 0, 1) === '_' && mb_substr($fieldName, -1) === '_') {
            $errorStore->addError('o:field_name', 'A name of index starting and ending with "_" is reserved by Solr.'); // @translate
        }

        if (!$entity->getSource()) {
            $errorStore->addError('o:source', 'The source cannot be empty.'); // @translate
        }
    }

}
