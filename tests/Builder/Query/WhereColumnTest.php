<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder\Query;

use Rxn\Orm\Builder\Query;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class WhereColumnTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, ref_id INTEGER)');
        $this->pdo->exec('INSERT INTO users (id, ref_id) VALUES (1, 1), (2, 2), (3, 99)');
    }

    public function testWhereColumnEmitsBareComparison(): void
    {
        [$sql, $bindings] = (new Query())
            ->select(['id'])->from('users')
            ->whereColumn('id', '=', 'ref_id')
            ->toSql();

        $this->assertSame('SELECT `id` FROM `users` WHERE `id` = `ref_id`', $sql);
        $this->assertSame([], $bindings);
    }

    public function testWhereColumnExecutesAgainstSqlite(): void
    {
        $rows = $this->db->table('users')->whereColumn('id', '=', 'ref_id')->orderBy('id')->get();
        $ids = array_map(fn ($r) => $r['id'], $rows);
        $this->assertSame([1, 2], $ids);
    }

    public function testWhereColumnWithDottedRefs(): void
    {
        [$sql] = (new Query())->select(['id'])->from('users', 'u')
            ->whereColumn('u.id', '!=', 'u.ref_id')->toSql();
        $this->assertStringContainsString('`u`.`id` != `u`.`ref_id`', $sql);
    }

    public function testOrWhereColumn(): void
    {
        [$sql] = (new Query())->select(['id'])->from('users')
            ->where('id', '=', 1)
            ->orWhereColumn('id', '=', 'ref_id')
            ->toSql();
        $this->assertStringContainsString('OR `id` = `ref_id`', $sql);
    }

    public function testWhereColumnUnsupportedOperatorRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->select(['id'])->from('users')->whereColumn('id', 'XOR', 'ref_id');
    }
}
