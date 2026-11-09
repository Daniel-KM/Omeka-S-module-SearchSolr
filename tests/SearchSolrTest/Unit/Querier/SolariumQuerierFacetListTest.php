<?php declare(strict_types=1);

namespace SearchSolrTest\Unit\Querier;

use AdvancedSearch\Query;
use PHPUnit\Framework\TestCase;
use SearchSolr\Querier\SolariumQuerier;
use Solarium\QueryType\Select\Query\Query as SelectQuery;

/**
 * The filters of the active facets are tagged, so the facets listing all values
 * can exclude them, the range facets as well as the term facets.
 *
 * The fields are not resolved against a core, so no Solr server is required.
 *
 * @group unit
 * @group facet
 * @group solr
 */
class SolariumQuerierFacetListTest extends TestCase
{
    public function testRangeFilterIsTagged(): void
    {
        $select = $this->apply('available', ['date' => ['from' => '1900', 'to' => null]]);
        $filter = $select->getFilterQuery('date-facet');
        $this->assertNotNull($filter);
        $this->assertSame('{!tag=DATE-FACET}date_i:["1900" TO *]', $filter->getQuery());
    }

    public function testListAllExcludesActiveRangeAndTermFilters(): void
    {
        $select = $this->apply('all', [
            'date' => ['from' => '1900', 'to' => '1950'],
            'class' => ['skos:Concept'],
        ]);
        $expected = ['DATE-FACET', 'CLASS-FACET'];
        foreach (['date', 'class', 'subject'] as $name) {
            $domain = $select->getFacetSet()->getFacet($name)->getOptions()['domain'] ?? [];
            $this->assertEqualsCanonicalizing($expected, $domain['excludeTags'] ?? [], $name);
        }
    }

    public function testListAvailableExcludesOnlyOwnTermFilter(): void
    {
        $select = $this->apply('available', [
            'date' => ['from' => '1900', 'to' => '1950'],
            'class' => ['skos:Concept'],
        ]);
        $facetSet = $select->getFacetSet();
        $this->assertSame(['CLASS-FACET'], $facetSet->getFacet('class')->getOptions()['domain']['excludeTags'] ?? []);
        $this->assertArrayNotHasKey('domain', $facetSet->getFacet('date')->getOptions());
    }

    private function apply(string $facetList, array $activeFacets): SelectQuery
    {
        $query = (new Query())
            ->setFacets([
                'date' => ['field' => 'date_i', 'type' => 'RangeDouble', 'min' => 1800, 'max' => 2000, 'step' => 10],
                'class' => ['field' => 'class_s', 'type' => 'Checkbox'],
                'subject' => ['field' => 'subject_ss', 'type' => 'Checkbox'],
            ])
            ->setActiveFacets($activeFacets)
            ->setOption('facet_list', $facetList);

        $querier = new class extends SolariumQuerier {
            protected function resolveFieldOrNull(string $field): ?string
            {
                return $field;
            }
        };
        $select = new SelectQuery();
        foreach (['query' => $query, 'select' => $select] as $name => $value) {
            $property = new \ReflectionProperty(SolariumQuerier::class, $name);
            $property->setAccessible(true);
            $property->setValue($querier, $value);
        }
        $method = new \ReflectionMethod(SolariumQuerier::class, 'applyFacets');
        $method->setAccessible(true);
        $method->invoke($querier);
        return $select;
    }
}
