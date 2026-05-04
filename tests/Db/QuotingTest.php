<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Db;

use PDO;
use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Db\Connection;
use Rxn\Orm\Tests\Support\FakeDriverConnection as FakeDriverConn;

/**
 * Validates that Connection translates the builder's MySQL-style
 * backticks into Postgres-style double quotes when the driver is
 * pgsql. We can't easily spin up a real Postgres in unit tests, so
 * we use a fake-driver Connection (subclass overriding getDriver)
 * and assert on the SQL that would be sent to PDO::prepare.
 */
final class QuotingTest extends TestCase
{
    public function testPostgresGetsDoubleQuotedIdentifiers(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $db  = new FakeDriverConn($pdo, 'pgsql');

        [$sql] = (new Query())->select(['u.id', 'u.email'])->from('users', 'u')
            ->where('u.active', '=', 1)->toSql();

        $translated = $db->applyQuoting($sql);
        $this->assertStringNotContainsString('`', $translated);
        $this->assertStringContainsString('"u"."id"', $translated);
        $this->assertStringContainsString('"users" AS "u"', $translated);
        $this->assertStringContainsString('"u"."active" = ?', $translated);
    }

    public function testMysqlPassesThroughUnchanged(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $db  = new FakeDriverConn($pdo, 'mysql');

        $sql = 'SELECT `id` FROM `users` WHERE `active` = ?';
        $this->assertSame($sql, $db->applyQuoting($sql));
    }

    public function testSqlitePassesThroughUnchanged(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $db  = new FakeDriverConn($pdo, 'sqlite');

        $sql = 'SELECT `id` FROM `users` WHERE `active` = ?';
        $this->assertSame($sql, $db->applyQuoting($sql));
    }

    public function testUpsertOnConflictTranslatesExcludedAlias(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $db  = new FakeDriverConn($pdo, 'pgsql');

        [$sql] = (new Insert())->into('counters')
            ->row(['key' => 'k', 'value' => 1])
            ->upsert(['key'], ['value'])
            ->setConnection($db)
            ->toSql();

        $translated = $db->applyQuoting($sql);
        $this->assertStringContainsString('ON CONFLICT ("key") DO UPDATE SET "value" = EXCLUDED."value"', $translated);
    }

    public function testEndToEndSelectAgainstFakePostgresDriver(): void
    {
        // Even with the fake driver the underlying PDO is SQLite, which
        // *also* accepts double quotes for identifiers. So this proves
        // the translated SQL actually parses/executes — not just that
        // the swap happened.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $pdo->exec("INSERT INTO users (id, email) VALUES (1, 'a@x'), (2, 'b@x')");

        $db = new FakeDriverConn($pdo, 'pgsql');
        $rows = $db->table('users')->where('id', '=', 1)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('a@x', $rows[0]['email']);
    }
}
