<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder\Query;

use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Raw;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class WhereExistsTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $this->pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER)');
        $this->pdo->exec("INSERT INTO users (id, email) VALUES (1, 'a@x'), (2, 'b@x'), (3, 'c@x')");
        $this->pdo->exec("INSERT INTO orders (user_id) VALUES (1), (1), (3)"); // user 2 has no orders
    }

    public function testWhereExistsShape(): void
    {
        $sub = (new Query())->select([Raw::of('1')])->from('orders');
        [$sql, $bindings] = (new Query())
            ->select(['id'])->from('users')->whereExists($sub)->toSql();

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE EXISTS (SELECT 1 FROM `orders`)',
            $sql,
        );
        $this->assertSame([], $bindings);
    }

    public function testWhereExistsCorrelatedFiltersUsersWithOrders(): void
    {
        $rows = $this->db->table('users')
            ->whereExists(
                (new Query())
                    ->select([Raw::of('1')])
                    ->from('orders', 'o')
                    ->where('o.user_id', '=', Raw::of('users.id')),
            )
            ->orderBy('id')
            ->get();

        $emails = array_map(fn ($r) => $r['email'], $rows);
        $this->assertSame(['a@x', 'c@x'], $emails);
    }

    public function testWhereNotExistsCorrelatedFindsUsersWithoutOrders(): void
    {
        $rows = $this->db->table('users')
            ->whereNotExists(
                (new Query())
                    ->select([Raw::of('1')])
                    ->from('orders', 'o')
                    ->where('o.user_id', '=', Raw::of('users.id')),
            )
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('b@x', $rows[0]['email']);
    }

    public function testWhereExistsMergesSubqueryBindings(): void
    {
        // Subquery contains a bound parameter; outer adds another.
        [$sql, $bindings] = (new Query())
            ->select(['id'])->from('users')
            ->where('email', 'LIKE', '%@x')
            ->whereExists(
                (new Query())
                    ->select([Raw::of('1')])
                    ->from('orders')
                    ->where('user_id', '>', 0),
            )
            ->toSql();

        $this->assertSame(['%@x', 0], $bindings);
        $this->assertStringContainsString('EXISTS', $sql);
    }

    public function testOrWhereExists(): void
    {
        [$sql] = (new Query())
            ->select(['id'])->from('users')
            ->where('email', '=', 'static')
            ->orWhereExists((new Query())->select([Raw::of('1')])->from('orders'))
            ->toSql();
        $this->assertStringContainsString('OR EXISTS (', $sql);
    }
}
