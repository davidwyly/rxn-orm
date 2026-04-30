<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder;

use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Raw;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class UpsertTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE counters (
            key TEXT PRIMARY KEY,
            value INTEGER NOT NULL DEFAULT 0
        )');
    }

    public function testUpsertEmitsSqliteOnConflict(): void
    {
        // First call: row doesn't exist, plain INSERT semantics.
        $insert = (new Insert())
            ->into('counters')
            ->row(['key' => 'pageviews', 'value' => 1])
            ->upsert(['key'], ['value'])
            ->setConnection($this->db);

        [$sql] = $insert->toSql();
        $this->assertStringContainsString('ON CONFLICT (`key`) DO UPDATE SET `value` = excluded.`value`', $sql);

        $insert->execute();
        $row = $this->db->table('counters')->find('pageviews', 'key');
        $this->assertSame(1, (int)$row['value']);
    }

    public function testUpsertActuallyUpdatesOnConflict(): void
    {
        // Pre-seed
        $this->pdo->exec("INSERT INTO counters (key, value) VALUES ('pageviews', 5)");

        (new Insert())
            ->into('counters')
            ->row(['key' => 'pageviews', 'value' => 99])
            ->upsert(['key'], ['value'])
            ->setConnection($this->db)
            ->execute();

        $row = $this->db->table('counters')->find('pageviews', 'key');
        $this->assertSame(99, (int)$row['value']);
        $this->assertSame(1, $this->db->table('counters')->count());
    }

    public function testUpsertWithRawIncrement(): void
    {
        $this->pdo->exec("INSERT INTO counters (key, value) VALUES ('pageviews', 5)");

        // Explicit per-column expression: value = current + incoming
        (new Insert())
            ->into('counters')
            ->row(['key' => 'pageviews', 'value' => 1])
            ->upsert(['key'], ['value' => Raw::of('counters.value + 1')])
            ->setConnection($this->db)
            ->execute();

        $row = $this->db->table('counters')->find('pageviews', 'key');
        $this->assertSame(6, (int)$row['value']);
    }

    public function testUpsertOnPostgresEmitsExcludedUppercase(): void
    {
        // We can't actually run against pgsql here, but we can stub
        // the driver name to verify the SQL we'd emit. Easiest: build
        // the Insert against a fake connection that reports 'pgsql'.
        $insert = (new Insert())
            ->into('counters')
            ->row(['key' => 'k', 'value' => 1])
            ->upsert(['key'], ['value'])
            ->setConnection(new FakeDriverConnection($this->pdo, 'pgsql'));

        [$sql] = $insert->toSql();
        $this->assertStringContainsString('ON CONFLICT (`key`) DO UPDATE SET `value` = EXCLUDED.`value`', $sql);
    }

    public function testUpsertOnMysqlEmitsOnDuplicateKeyUpdate(): void
    {
        $insert = (new Insert())
            ->into('counters')
            ->row(['key' => 'k', 'value' => 1])
            ->upsert(['key'], ['value'])
            ->setConnection(new FakeDriverConnection($this->pdo, 'mysql'));

        [$sql] = $insert->toSql();
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', $sql);
    }

    public function testUpsertWithoutConnectionThrows(): void
    {
        $this->expectException(\LogicException::class);
        (new Insert())
            ->into('counters')
            ->row(['key' => 'k', 'value' => 1])
            ->upsert(['key'], ['value'])
            ->toSql();
    }

    public function testUpsertOnUnknownDriverThrows(): void
    {
        $insert = (new Insert())
            ->into('counters')
            ->row(['key' => 'k', 'value' => 1])
            ->upsert(['key'], ['value'])
            ->setConnection(new FakeDriverConnection($this->pdo, 'oracle'));

        $this->expectException(\LogicException::class);
        $insert->toSql();
    }

    public function testUpsertAndOnDuplicateKeyAreMutuallyExclusive(): void
    {
        $this->expectException(\LogicException::class);
        (new Insert())
            ->into('counters')
            ->row(['key' => 'k', 'value' => 1])
            ->onDuplicateKeyUpdate(['value' => Raw::of('value + 1')])
            ->upsert(['key'], ['value']);
    }
}

/**
 * Minimal Connection subclass that overrides driver detection.
 * Lets the upsert tests verify Postgres/MySQL syntax without
 * actually requiring those engines.
 */
final class FakeDriverConnection extends \Rxn\Orm\Db\Connection
{
    public function __construct(\PDO $pdo, private string $driverOverride)
    {
        parent::__construct($pdo);
    }

    public function getDriver(): string
    {
        return $this->driverOverride;
    }
}
