<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder;

use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class InsertOrIgnoreTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE keys (k TEXT PRIMARY KEY, v INTEGER NOT NULL)');
    }

    public function testIgnoreSkipsConflictingRowsOnSqlite(): void
    {
        $this->pdo->exec("INSERT INTO keys (k, v) VALUES ('a', 1)");

        // Without ignore() this would throw a UNIQUE-violation error.
        (new Insert())
            ->into('keys')
            ->row(['k' => 'a', 'v' => 999])
            ->ignore()
            ->setConnection($this->db)
            ->execute();

        $row = $this->db->table('keys')->find('a', 'k');
        $this->assertSame(1, (int)$row['v']); // original row untouched
    }

    public function testIgnoreEmitsMysqlInsertIgnore(): void
    {
        [$sql] = (new Insert())
            ->into('keys')
            ->row(['k' => 'a', 'v' => 1])
            ->ignore()
            ->setConnection(new FakeDriverConnection($this->pdo, 'mysql'))
            ->toSql();
        $this->assertStringStartsWith('INSERT IGNORE INTO `keys`', $sql);
    }

    public function testIgnoreEmitsPostgresOnConflict(): void
    {
        [$sql] = (new Insert())
            ->into('keys')
            ->row(['k' => 'a', 'v' => 1])
            ->ignore()
            ->setConnection(new FakeDriverConnection($this->pdo, 'pgsql'))
            ->toSql();
        $this->assertStringEndsWith('ON CONFLICT DO NOTHING', $sql);
    }

    public function testIgnoreEmitsSqliteOnConflict(): void
    {
        [$sql] = (new Insert())
            ->into('keys')
            ->row(['k' => 'a', 'v' => 1])
            ->ignore()
            ->setConnection($this->db)
            ->toSql();
        $this->assertStringEndsWith('ON CONFLICT DO NOTHING', $sql);
    }

    public function testIgnoreRequiresConnection(): void
    {
        $this->expectException(\LogicException::class);
        (new Insert())->into('keys')->row(['k' => 'a', 'v' => 1])->ignore()->toSql();
    }

    public function testIgnoreAndUpsertAreMutuallyExclusive(): void
    {
        $this->expectException(\LogicException::class);
        (new Insert())
            ->into('keys')
            ->row(['k' => 'a', 'v' => 1])
            ->ignore()
            ->upsert(['k'], ['v']);
    }

    public function testUpsertAndIgnoreAreMutuallyExclusiveOtherDirection(): void
    {
        $this->expectException(\LogicException::class);
        (new Insert())
            ->into('keys')
            ->row(['k' => 'a', 'v' => 1])
            ->upsert(['k'], ['v'])
            ->ignore();
    }

    public function testIgnoreOnUnknownDriverThrows(): void
    {
        $insert = (new Insert())
            ->into('keys')
            ->row(['k' => 'a', 'v' => 1])
            ->ignore()
            ->setConnection(new FakeDriverConnection($this->pdo, 'oracle'));
        $this->expectException(\LogicException::class);
        $insert->toSql();
    }
}
