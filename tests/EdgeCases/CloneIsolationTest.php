<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\EdgeCases;

use Rxn\Orm\Builder\Query;
use Rxn\Orm\Tests\Support\SqliteTestCase;

/**
 * Clone isolation. Mutating a clone must NOT affect the original.
 * This is a regression-prone area: we use `clone $this` heavily in
 * `Query::first/find/count/paginate/chunk` and the union/withCount
 * machinery. PHP's default clone is shallow — array properties are
 * copied by value (good), object properties share refs (potentially
 * bad). The properties we mutate after clone are arrays (commands,
 * bindings, eagerLoads, withCounts) so the default clone is sound,
 * but we need explicit tests to catch regressions.
 *
 * Compare with Laravel's `testClone` / `testCloneWithout`.
 */
final class CloneIsolationTest extends SqliteTestCase
{
    public function testCloneDoesNotAffectOriginalOnLimit(): void
    {
        $base = (new Query())->select(['id'])->from('users')->limit(10);

        $clone = clone $base;
        $clone->limit(5);

        [$baseSql] = $base->toSql();
        [$cloneSql] = $clone->toSql();
        $this->assertStringContainsString('LIMIT 10', $baseSql);
        $this->assertStringContainsString('LIMIT 5', $cloneSql);
    }

    public function testCloneDoesNotAffectOriginalOnWhere(): void
    {
        $base = (new Query())->select(['id'])->from('users')->where('a', '=', 1);

        $clone = clone $base;
        $clone->where('b', '=', 2);

        [$baseSql, $baseBindings]   = $base->toSql();
        [$cloneSql, $cloneBindings] = $clone->toSql();

        $this->assertSame([1], $baseBindings);
        $this->assertSame([1, 2], $cloneBindings);
        $this->assertStringNotContainsString('`b` = ?', $baseSql);
    }

    public function testFirstOnQueryWithLimitDoesNotMutateOriginal(): void
    {
        // Query::first() clones, sets LIMIT 1, executes. The original's
        // LIMIT 10 must be unaffected.
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $this->pdo->exec('INSERT INTO t VALUES (1), (2), (3)');

        $base = $this->db->table('t')->limit(10);
        $first = $base->first();
        $this->assertNotNull($first);

        // Now run get() — should return all 3, not just 1
        $rows = $base->get();
        $this->assertCount(3, $rows);
    }

    public function testCountOnQueryDoesNotMutateOriginalSelect(): void
    {
        // Connection::count wraps the user's SELECT in a derived table
        // — our clone-friendly approach. Verify the parent's SELECT
        // list is not modified.
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec("INSERT INTO t VALUES (1, 'a'), (2, 'b')");

        $base = $this->db->table('t')->select(['id', 'name']);
        $count = $base->count();
        $this->assertSame(2, $count);

        $rows = $base->get();
        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayHasKey('name', $rows[0]);
    }

    public function testPaginateDoesNotMutateOriginal(): void
    {
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        for ($i = 1; $i <= 50; $i++) {
            $this->pdo->exec("INSERT INTO t VALUES ($i)");
        }

        $base = $this->db->table('t')->orderBy('id');
        $page = $base->paginate(perPage: 10, page: 2);
        $this->assertCount(10, $page['data']);
        $this->assertSame(50, $page['total']);

        // Original still produces the full 50 rows when re-executed.
        $rows = $base->get();
        $this->assertCount(50, $rows);
    }

    public function testChunkDoesNotMutateOriginal(): void
    {
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        for ($i = 1; $i <= 25; $i++) {
            $this->pdo->exec("INSERT INTO t VALUES ($i)");
        }

        $base = $this->db->table('t')->orderBy('id');

        $count = 0;
        $base->chunk(10, function (array $rows) use (&$count) {
            $count += count($rows);
        });
        $this->assertSame(25, $count);

        // After chunking, the original Query is still unmodified.
        $rows = $base->get();
        $this->assertCount(25, $rows);
    }
}
