<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\EdgeCases;

use Rxn\Orm\Builder\Query;
use Rxn\Orm\Tests\Support\SqliteTestCase;

/**
 * IN-list edge cases. Compare with Laravel:
 *   - `testEmptyWhereIns` rewrites `whereIn('id', [])` to `where 0 = 1`
 *   - `testEmptyWhereNotIns` rewrites `whereNotIn('id', [])` to `where 1 = 1`
 *
 * **rxn-orm's choice differs:** we throw an InvalidArgumentException
 * up-front rather than silently rewriting. Reasoning: an empty IN
 * list is almost always a programming mistake — silently returning
 * "no rows" (Laravel) or "all rows" (Laravel for NOT IN) hides bugs.
 * The honest move is to surface the misuse.
 *
 * If the caller really wants "match nothing", they can write
 * `where('id', '=', null)->whereIsNotNull('id')` or just check the
 * input array before building the query.
 */
final class WhereInEdgeCasesTest extends SqliteTestCase
{
    public function testEmptyWhereInThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->select(['id'])->from('users')->whereIn('id', []);
    }

    public function testEmptyWhereNotInThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->select(['id'])->from('users')->whereNotIn('id', []);
    }

    public function testSingleElementWhereInEmitsOnePlaceholder(): void
    {
        [$sql, $bindings] = (new Query())->select(['id'])->from('users')
            ->whereIn('id', [42])
            ->toSql();
        $this->assertSame('SELECT `id` FROM `users` WHERE `id` IN (?)', $sql);
        $this->assertSame([42], $bindings);
    }

    public function testManyElementsWhereInEmitsCorrectCount(): void
    {
        $values = range(1, 100);
        [$sql, $bindings] = (new Query())->select(['id'])->from('users')
            ->whereIn('id', $values)
            ->toSql();

        // 100 placeholders, comma-separated
        $this->assertSame(100, substr_count($sql, '?'));
        $this->assertSame($values, $bindings);
    }

    public function testWhereInPreservesValueTypesAcrossDrivers(): void
    {
        // After the type-correct binding fix, int/string/bool round-trip
        // cleanly through PDO::PARAM_*.
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec("INSERT INTO t VALUES (1, 'a'), (2, 'b'), (3, 'c')");

        $rows = $this->db->table('t')->whereIn('id', [1, 3])->orderBy('id')->get();
        $names = array_map(fn ($r) => $r['name'], $rows);
        $this->assertSame(['a', 'c'], $names);
    }

    public function testWhereInWithSubqueryDoesNotRequireArray(): void
    {
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, parent INTEGER)');
        $this->pdo->exec('INSERT INTO t (id, parent) VALUES (1, NULL), (2, 1), (3, 1), (4, 2)');

        // whereIn accepts a Buildable — no array required.
        $sub = (new Query())->select(['id'])->from('t')->where('parent', '=', 1);
        $rows = $this->db->table('t')->whereIn('id', $sub)->orderBy('id')->get();
        $ids = array_map(fn ($r) => (int)$r['id'], $rows);
        $this->assertSame([2, 3], $ids);
    }
}
