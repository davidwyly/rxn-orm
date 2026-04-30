<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Db\Connection;

/**
 * Exercises portable upsert against real MySQL / Postgres engines
 * when their DSN env vars are present (set by CI). Skipped silently
 * otherwise so local `phpunit` runs stay green without a database
 * stack.
 *
 * The same logic is unit-tested against SQLite + a fake-driver
 * Connection in tests/Builder/UpsertTest.php; this file's job is to
 * prove the SQL we emit is *parseable* by the real engines.
 */
final class UpsertDriversTest extends TestCase
{
    public function testMysqlUpsertRoundTrip(): void
    {
        $dsn  = getenv('RXN_ORM_MYSQL_DSN');
        $user = getenv('RXN_ORM_MYSQL_USER') ?: 'root';
        $pass = getenv('RXN_ORM_MYSQL_PASS') ?: '';
        if (!$dsn) {
            $this->markTestSkipped('RXN_ORM_MYSQL_DSN not set');
        }

        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DROP TABLE IF EXISTS rxn_upsert_test');
        $pdo->exec('CREATE TABLE rxn_upsert_test (
            k VARCHAR(64) PRIMARY KEY,
            v INT NOT NULL
        )');

        $db = new Connection($pdo);
        (new Insert())->into('rxn_upsert_test')->row(['k' => 'a', 'v' => 1])
            ->upsert(['k'], ['v'])->setConnection($db)->execute();
        (new Insert())->into('rxn_upsert_test')->row(['k' => 'a', 'v' => 99])
            ->upsert(['k'], ['v'])->setConnection($db)->execute();

        $row = $db->table('rxn_upsert_test')->find('a', 'k');
        $this->assertSame(99, (int)$row['v']);
        $pdo->exec('DROP TABLE rxn_upsert_test');
    }

    public function testPostgresUpsertRoundTrip(): void
    {
        $dsn  = getenv('RXN_ORM_PGSQL_DSN');
        $user = getenv('RXN_ORM_PGSQL_USER') ?: 'postgres';
        $pass = getenv('RXN_ORM_PGSQL_PASS') ?: '';
        if (!$dsn) {
            $this->markTestSkipped('RXN_ORM_PGSQL_DSN not set');
        }

        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DROP TABLE IF EXISTS rxn_upsert_test');
        $pdo->exec('CREATE TABLE rxn_upsert_test (
            k VARCHAR(64) PRIMARY KEY,
            v INT NOT NULL
        )');

        // Connection translates the builder's backticks → "double quotes"
        // automatically when getDriver() returns 'pgsql'.
        $db = new Connection($pdo);

        (new Insert())->into('rxn_upsert_test')->row(['k' => 'a', 'v' => 1])
            ->upsert(['k'], ['v'])->setConnection($db)->execute();
        (new Insert())->into('rxn_upsert_test')->row(['k' => 'a', 'v' => 99])
            ->upsert(['k'], ['v'])->setConnection($db)->execute();

        $row = $db->table('rxn_upsert_test')->find('a', 'k');
        $this->assertSame(99, (int)$row['v']);
        $pdo->exec('DROP TABLE rxn_upsert_test');
    }
}
