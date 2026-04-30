<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use Rxn\Orm\Db\Connection;

/**
 * Base for tests that need a real PDO. Each test gets a fresh
 * in-memory SQLite database (no inter-test bleed) wrapped in a
 * Connection. Subclasses populate schema in setUp() via $this->pdo.
 *
 * SQLite is convenient for unit tests because it's a real engine
 * (so the SQL we emit actually parses and executes) but spins up
 * in microseconds and disappears when the connection drops.
 */
abstract class SqliteTestCase extends TestCase
{
    protected PDO $pdo;
    protected Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite uses backtick identifier quoting only in compatibility
        // mode; by default it tolerates backticks identically to "..".
        // The builder emits backticks, which SQLite accepts.
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db  = new Connection($this->pdo);
    }
}
