<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Comparison;

use Rxn\Orm\Builder\Raw;

/**
 * Conditional aggregates — `SUM(CASE WHEN ... END)` patterns. The
 * bread and butter of analytics dashboards: "for each region, total
 * revenue from product A vs product B vs everything else."
 *
 *   // Eloquent (selectRaw):
 *   DB::table('sales')->selectRaw("
 *       region,
 *       SUM(CASE WHEN product = 'A' THEN amount ELSE 0 END) AS total_a,
 *       SUM(CASE WHEN product = 'B' THEN amount ELSE 0 END) AS total_b,
 *       SUM(amount) AS total
 *   ")->groupBy('region')->get();
 *
 *   // rxn-orm (Raw inside select):
 *   $db->table('sales')->select([
 *       'region',
 *       Raw::of("SUM(CASE WHEN product = 'A' THEN amount ELSE 0 END) AS total_a"),
 *       ...
 *   ])->groupBy('region')->get();
 *
 * Same shape; both lean on a Raw fragment per CASE WHEN. Where
 * rxn-orm is slightly cleaner: the group/having clauses below the
 * raw select are still fluent. **Verdict: tied.**
 *
 * Where this pattern bites OTHER ORMs (specifically Doctrine ORM
 * with DQL): you can't easily emit `CASE WHEN` aggregates without
 * dropping to native SQL. rxn-orm and Eloquent both let you stay
 * in their builder.
 */
final class ConditionalAggregateTest extends ComplexQueryTestCase
{
    public function testRevenueBreakdownByRegion(): void
    {
        $rows = $this->db->table('sales')
            ->select([
                'region',
                Raw::of("SUM(CASE WHEN product = 'A' THEN amount ELSE 0 END) AS total_a"),
                Raw::of("SUM(CASE WHEN product = 'B' THEN amount ELSE 0 END) AS total_b"),
                Raw::of('SUM(amount) AS total'),
            ])
            ->groupBy('region')
            ->orderBy('region')
            ->get();

        // NA: A=150 (100+50), B=200, total=350
        // EU: A=300, B=225 (150+75), total=525
        // AS: A=400, B=0, total=400
        $byRegion = [];
        foreach ($rows as $r) {
            $byRegion[$r['region']] = $r;
        }

        $this->assertSame(150, (int)$byRegion['NA']['total_a']);
        $this->assertSame(200, (int)$byRegion['NA']['total_b']);
        $this->assertSame(350, (int)$byRegion['NA']['total']);

        $this->assertSame(525, (int)$byRegion['EU']['total']);
        $this->assertSame(400, (int)$byRegion['AS']['total']);
        $this->assertSame(0, (int)$byRegion['AS']['total_b']);
    }

    public function testHavingOnAggregate(): void
    {
        // Regions whose total exceeds 400
        $rows = $this->db->table('sales')
            ->select(['region', Raw::of('SUM(amount) AS total')])
            ->groupBy('region')
            ->having('SUM(amount) > 400')
            ->orderBy('region')
            ->get();

        $regions = array_map(fn ($r) => $r['region'], $rows);
        $this->assertSame(['EU'], $regions);
    }

    public function testCountDistinctConditional(): void
    {
        // How many distinct regions sold product A?
        $row = $this->db->table('sales')
            ->select([Raw::of('COUNT(DISTINCT CASE WHEN product = \'A\' THEN region END) AS regions_with_a')])
            ->first();
        $this->assertSame(3, (int)$row['regions_with_a']);
    }
}
