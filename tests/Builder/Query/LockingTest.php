<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder\Query;

use Rxn\Orm\Tests\Support\FakeDriverConnection;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class LockingTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, status TEXT)');
        $this->pdo->exec("INSERT INTO jobs (id, status) VALUES (1, 'pending')");
    }

    public function testMysqlForUpdate(): void
    {
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'mysql'))
            ->where('status', '=', 'pending')
            ->lockForUpdate()
            ->toSql();
        $this->assertStringEndsWith('FOR UPDATE', $sql);
    }

    public function testMysqlSharedLock(): void
    {
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'mysql'))
            ->sharedLock()
            ->toSql();
        $this->assertStringEndsWith('LOCK IN SHARE MODE', $sql);
    }

    public function testPostgresForUpdate(): void
    {
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'pgsql'))
            ->lockForUpdate()
            ->toSql();
        $this->assertStringEndsWith('FOR UPDATE', $sql);
    }

    public function testPostgresSharedLockEmitsForShare(): void
    {
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'pgsql'))
            ->sharedLock()
            ->toSql();
        $this->assertStringEndsWith('FOR SHARE', $sql);
    }

    public function testSqliteSilentNoOp(): void
    {
        // Attached to a real SQLite Connection (sqlite driver). Lock
        // is intentionally not emitted; SQLite has no row-level locks
        // and would reject the syntax.
        [$sql] = $this->db->table('jobs')->lockForUpdate()->toSql();
        $this->assertStringNotContainsString('FOR UPDATE', $sql);
        $this->assertStringNotContainsString('LOCK IN', $sql);
    }

    public function testNoConnectionSilentNoOp(): void
    {
        [$sql] = (new \Rxn\Orm\Builder\Query())
            ->select(['id'])->from('jobs')
            ->lockForUpdate()
            ->toSql();
        $this->assertStringNotContainsString('FOR UPDATE', $sql);
    }

    public function testLockComesAfterLimit(): void
    {
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'mysql'))
            ->where('status', '=', 'pending')
            ->orderBy('id')
            ->limit(10)
            ->lockForUpdate()
            ->toSql();
        // FOR UPDATE must come at the very end of the statement.
        $this->assertStringEndsWith('FOR UPDATE', $sql);
        // And LIMIT precedes it, not the other way around.
        $this->assertGreaterThan(strpos($sql, 'LIMIT'), strpos($sql, 'FOR UPDATE'));
    }

    public function testLockExecutesAgainstSqlite(): void
    {
        // Even though SQLite emits nothing, the query still runs.
        $rows = $this->db->table('jobs')->lockForUpdate()->get();
        $this->assertCount(1, $rows);
    }

    // -- SKIP LOCKED / NOWAIT ----------------------------------------

    public function testMysqlForUpdateSkipLocked(): void
    {
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'mysql'))
            ->where('status', '=', 'pending')
            ->lockForUpdate()->skipLocked()
            ->toSql();
        $this->assertStringEndsWith('FOR UPDATE SKIP LOCKED', $sql);
    }

    public function testPostgresForUpdateSkipLocked(): void
    {
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'pgsql'))
            ->lockForUpdate()->skipLocked()
            ->toSql();
        $this->assertStringEndsWith('FOR UPDATE SKIP LOCKED', $sql);
    }

    public function testMysqlForUpdateNoWait(): void
    {
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'mysql'))
            ->lockForUpdate()->noWait()
            ->toSql();
        $this->assertStringEndsWith('FOR UPDATE NOWAIT', $sql);
    }

    public function testPostgresForUpdateNoWait(): void
    {
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'pgsql'))
            ->lockForUpdate()->noWait()
            ->toSql();
        $this->assertStringEndsWith('FOR UPDATE NOWAIT', $sql);
    }

    public function testMysqlSharedLockSwitchesToForShareUnderSkipLocked(): void
    {
        // LOCK IN SHARE MODE doesn't accept SKIP LOCKED — must use
        // the FOR SHARE form on MySQL 8+ to get the modifier.
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'mysql'))
            ->sharedLock()->skipLocked()
            ->toSql();
        $this->assertStringEndsWith('FOR SHARE SKIP LOCKED', $sql);
        $this->assertStringNotContainsString('LOCK IN SHARE MODE', $sql);
    }

    public function testMysqlSharedLockKeepsLegacyFormWithoutModifier(): void
    {
        // Without skipLocked()/noWait() the legacy form is fine and
        // still works on older MySQL.
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'mysql'))
            ->sharedLock()
            ->toSql();
        $this->assertStringEndsWith('LOCK IN SHARE MODE', $sql);
    }

    public function testPostgresSharedLockNoWait(): void
    {
        [$sql] = $this->db->table('jobs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'pgsql'))
            ->sharedLock()->noWait()
            ->toSql();
        $this->assertStringEndsWith('FOR SHARE NOWAIT', $sql);
    }

    public function testSkipLockedSqliteRemainsNoOp(): void
    {
        // SQLite has no row-locking; nothing should be emitted at all.
        [$sql] = $this->db->table('jobs')->lockForUpdate()->skipLocked()->toSql();
        $this->assertStringNotContainsString('FOR UPDATE', $sql);
        $this->assertStringNotContainsString('SKIP LOCKED', $sql);
    }

    public function testSkipLockedWithoutPriorLockThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->db->table('jobs')->skipLocked();
    }

    public function testNoWaitWithoutPriorLockThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->db->table('jobs')->noWait();
    }

    public function testSkipLockedExecutesAgainstSqlite(): void
    {
        // SQLite ignores the modifier; the SELECT still runs.
        $rows = $this->db->table('jobs')->lockForUpdate()->skipLocked()->get();
        $this->assertCount(1, $rows);
    }
}
