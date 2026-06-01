<?php declare(strict_types=1);

namespace SearchSolrTest\Unit\Querier;

use PHPUnit\Framework\TestCase;
use SearchSolr\Querier\SolariumQuerier;

/**
 * Security-critical: the group visibility clause must only ever match the
 * user's own group ids, and must collapse to "no clause" (public only) when
 * there is no field or no group id.
 *
 * @group unit
 * @group group
 * @group solr
 */
class SolariumQuerierGroupVisibilityTest extends TestCase
{
    public function testEmptyGroupIdsYieldNoClause(): void
    {
        // No clause => visibility stays restricted to public (no leak).
        $this->assertNull($this->build('group_id_is', []));
    }

    public function testNullFieldYieldsNoClause(): void
    {
        $this->assertNull($this->build(null, [1, 2]));
    }

    public function testOnlyInvalidIdsYieldNoClause(): void
    {
        $this->assertNull($this->build('group_id_is', [0, '', 'x']));
    }

    public function testGroupIdsBuildClause(): void
    {
        $this->assertSame(
            'group_id_is:(1 OR 2 OR 3)',
            $this->build('group_id_is', [1, 2, 3])
        );
    }

    public function testIdsAreDedupedFilteredAndCastToInt(): void
    {
        $this->assertSame(
            'group_id_is:(2 OR 3)',
            $this->build('group_id_is', ['2', '2', 0, 'x', '3'])
        );
    }

    private function build(?string $field, array $groupIds): ?string
    {
        $querier = new SolariumQuerier();
        $method = new \ReflectionMethod($querier, 'buildGroupClause');
        $method->setAccessible(true);
        return $method->invokeArgs($querier, [$field, $groupIds]);
    }
}
