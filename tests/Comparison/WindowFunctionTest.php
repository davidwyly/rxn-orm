<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Comparison;

use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Raw;

/**
 * Window functions: ROW_NUMBER, RANK, LAG/LEAD, running totals.
 *
 * NEITHER ORM has a native fluent syntax for window functions.
 * Both express them via Raw / DB::raw / selectRaw.
 *
 *   // Eloquent:
 *   $top = DB::table('posts')
 *       ->selectRaw('user_id, title, votes,
 *           ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY votes DESC) AS rk')
 *       ->get();
 *
 *   // rxn-orm:
 *   $q = $db->table('posts')->select([
 *       'user_id', 'title', 'votes',
 *       Raw::of('ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY votes DESC) AS rk'),
 *   ]);
 *
 * Both are essentially "use Raw and write SQL." rxn-orm's Raw::of
 * inside select([]) is one fewer indirection than Eloquent's
 * `selectRaw`. **Verdict:** essentially tied.
 *
 * Where rxn-orm shines is COMPOSING window functions with other
 * builder features (where, joins, limits) — the Raw fragment is
 * just one element of the SELECT list, no method call disruption.
 */
final class WindowFunctionTest extends ComplexQueryTestCase
{
    public function testRowNumberPerUser(): void
    {
        $rows = $this->db->table('posts')
            ->select([
                'user_id', 'title', 'votes',
                Raw::of('ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY votes DESC) AS rk'),
            ])
            ->orderBy('user_id')
            ->orderBy(Raw::of('rk'))
            ->get();

        // alice's top post is Gamma (88), bob's is Epsilon (55), carol's is Zeta (33)
        $tops = array_filter($rows, fn ($r) => (int)$r['rk'] === 1);
        $tops = array_values($tops);
        $this->assertSame('Gamma', $tops[0]['title']);
        $this->assertSame('Epsilon', $tops[1]['title']);
        $this->assertSame('Zeta', $tops[2]['title']);
    }

    public function testTopNPerGroupViaWindowSubquery(): void
    {
        // Top 2 posts per user — wrap the windowed query in a derived
        // table and filter on the rank.
        $inner = $this->db->table('posts')->select([
            'user_id', 'title', 'votes',
            Raw::of('ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY votes DESC) AS rk'),
        ]);

        $rows = $this->db->query()
            ->select(['user_id', 'title', 'votes'])
            ->from($inner, 'ranked')
            ->where('rk', '<=', 2)
            ->orderBy('user_id')
            ->orderBy('votes', 'DESC')
            ->get();

        // alice has 3 posts → top 2: Gamma(88), Alpha(42)
        // bob has 2 posts → both: Epsilon(55), Delta(12)
        // carol has 2 posts → both: Zeta(33), Eta(21)
        $titles = array_map(fn ($r) => $r['title'], $rows);
        $this->assertSame(['Gamma', 'Alpha', 'Epsilon', 'Delta', 'Zeta', 'Eta'], $titles);
    }

    public function testRunningTotal(): void
    {
        $rows = $this->db->table('sales')
            ->select([
                'region', 'product', 'amount',
                Raw::of('SUM(amount) OVER (PARTITION BY region ORDER BY id) AS running_total'),
            ])
            ->orderBy('region')
            ->orderBy('id')
            ->get();

        // EU running totals: 300, 450, 525
        $eu = array_values(array_filter($rows, fn ($r) => $r['region'] === 'EU'));
        $this->assertSame([300, 450, 525], array_map(fn ($r) => (int)$r['running_total'], $eu));
    }

    public function testLagPreviousRow(): void
    {
        $rows = $this->db->table('posts')
            ->select([
                'title', 'votes',
                Raw::of('LAG(votes, 1) OVER (ORDER BY id) AS prev_votes'),
            ])
            ->orderBy('id')
            ->get();

        $this->assertNull($rows[0]['prev_votes']);
        $this->assertSame(42, (int)$rows[1]['prev_votes']);
        $this->assertSame(17, (int)$rows[2]['prev_votes']);
    }
}
