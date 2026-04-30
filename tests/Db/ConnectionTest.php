<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Db;

use Rxn\Orm\Builder\Delete;
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Update;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class ConnectionTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1
        )');
        $this->pdo->exec("INSERT INTO users (email, active) VALUES
            ('a@x', 1), ('b@x', 1), ('c@x', 0)");
    }

    public function testRunSelectReturnsRows(): void
    {
        $rows = $this->db->run((new Query())->select()->from('users'));
        $this->assertCount(3, $rows);
        $this->assertSame('a@x', $rows[0]['email']);
    }

    public function testTableSugarStartsAQuery(): void
    {
        $rows = $this->db->table('users')->where('active', '=', 1)->get();
        $this->assertCount(2, $rows);
    }

    public function testInsertReturnsAffectedCountAndLastInsertId(): void
    {
        $affected = $this->db->run(
            (new Insert())->into('users')->row(['email' => 'd@x', 'active' => 1])
        );
        $this->assertSame(1, $affected);
        $this->assertSame('4', $this->db->lastInsertId());
    }

    public function testUpdateReturnsAffectedCount(): void
    {
        $affected = $this->db->run(
            (new Update())->table('users')->set(['active' => 0])->where('email', '=', 'a@x')
        );
        $this->assertSame(1, $affected);
    }

    public function testDeleteReturnsAffectedCount(): void
    {
        $affected = $this->db->run(
            (new Delete())->from('users')->where('active', '=', 0)
        );
        $this->assertSame(1, $affected);
    }

    public function testTransactionCommitsOnSuccess(): void
    {
        $this->db->transaction(function () {
            $this->db->run((new Insert())->into('users')->row(['email' => 'tx@x', 'active' => 1]));
        });
        $this->assertSame(4, $this->db->table('users')->count());
    }

    public function testTransactionRollsBackOnException(): void
    {
        try {
            $this->db->transaction(function () {
                $this->db->run((new Insert())->into('users')->row(['email' => 'tx@x', 'active' => 1]));
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(3, $this->db->table('users')->count());
    }

    public function testNestedTransactionInnerRollsBackButOuterCommits(): void
    {
        $this->db->transaction(function () {
            $this->db->run((new Insert())->into('users')->row(['email' => 'outer@x', 'active' => 1]));
            try {
                $this->db->transaction(function () {
                    $this->db->run((new Insert())->into('users')->row(['email' => 'inner@x', 'active' => 1]));
                    throw new \RuntimeException('inner boom');
                });
            } catch (\RuntimeException) {
                // swallow — outer should still commit
            }
        });

        $emails = $this->db->table('users')->pluck('email');
        $this->assertContains('outer@x', $emails);
        $this->assertNotContains('inner@x', $emails);
    }

    public function testTransactionDepthTracking(): void
    {
        $this->assertSame(0, $this->db->transactionDepth());
        $this->db->beginTransaction();
        $this->db->beginTransaction();
        $this->assertSame(2, $this->db->transactionDepth());
        $this->db->rollBack();
        $this->assertSame(1, $this->db->transactionDepth());
        $this->db->commit();
        $this->assertSame(0, $this->db->transactionDepth());
    }

    public function testStatementExecutesRawSql(): void
    {
        $stmt = $this->db->statement('SELECT COUNT(*) AS c FROM users WHERE active = ?', [1]);
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(2, (int)$row['c']);
    }
}
