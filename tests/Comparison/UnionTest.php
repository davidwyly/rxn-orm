<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Comparison;

use Rxn\Orm\Builder\Query;

/**
 * UNION / UNION ALL — combining results of multiple queries.
 *
 *   // Eloquent:
 *   $a = DB::table('users')->select('name')->where('id', '<', 3);
 *   $b = DB::table('users')->select('name')->where('id', '>=', 3);
 *   $rows = $a->union($b)->get();
 *
 *   // rxn-orm:
 *   [HasNoNativeUnion — we currently express UNION via raw SQL]
 *
 * **HONEST GAP:** rxn-orm doesn't have a native `union()` method.
 * Eloquent does. For UNION queries today you'd build the SQL string
 * yourself or compose two queries' toSql() output and concatenate.
 *
 * This is a real adoption gap worth closing — `Query::union(Buildable)`
 * + bindings merge, ~30 LOC. Filed as a follow-up. The test below
 * uses the manual concatenation pattern to demonstrate the workaround.
 *
 * **Verdict: Eloquent wins on built-in support; rxn-orm requires a
 * 5-line workaround until we ship it.**
 */
final class UnionTest extends ComplexQueryTestCase
{
    public function testUnionViaManualConcatenation(): void
    {
        // The "I just want UNION" workaround: emit each side, glue them.
        [$leftSql, $leftBindings] = $this->db->table('users')
            ->select(['name'])->where('id', '<', 3)->toSql();
        [$rightSql, $rightBindings] = $this->db->table('users')
            ->select(['name'])->where('id', '>=', 3)->toSql();

        $sql = "$leftSql UNION ALL $rightSql ORDER BY name";
        $rows = $this->db->select($sql, [...$leftBindings, ...$rightBindings]);

        $names = array_map(fn ($r) => $r['name'], $rows);
        $this->assertSame(['alice', 'bob', 'carol', 'dave'], $names);
    }

    public function testUnionAllPreservesDuplicates(): void
    {
        // UNION ALL keeps duplicates, UNION dedupes. Each side carries
        // its own bindings; concat them in order.
        [$leftSql, $leftB]   = $this->db->table('users')->select(['name'])->where('id', '=', 1)->toSql();
        [$rightSql, $rightB] = $this->db->table('users')->select(['name'])->where('id', '=', 1)->toSql();

        $rows = $this->db->select("$leftSql UNION ALL $rightSql", [...$leftB, ...$rightB]);
        $this->assertCount(2, $rows);

        $rows = $this->db->select("$leftSql UNION $rightSql", [...$leftB, ...$rightB]);
        $this->assertCount(1, $rows);
    }
}
