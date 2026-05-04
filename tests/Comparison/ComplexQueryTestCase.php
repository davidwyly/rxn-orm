<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Comparison;

use PDO;
use PHPUnit\Framework\TestCase;
use Rxn\Orm\Db\Connection;

/**
 * Shared schema for the comparison-vs-Eloquent test suite. Mirrors
 * a small "blog with analytics" domain so a single fixture set can
 * exercise tree traversal, window functions, conditional aggregates,
 * top-N-per-group, etc.
 *
 * Each test file in this directory documents the equivalent Eloquent
 * code in a per-test docblock so reviewers can assess "how much
 * fluency does each ORM offer for this pattern?" rather than just
 * "does it work?".
 */
abstract class ComplexQueryTestCase extends TestCase
{
    protected PDO $pdo;
    protected Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db  = new Connection($this->pdo);

        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE posts (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            votes INTEGER NOT NULL,
            published INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE comments (
            id INTEGER PRIMARY KEY,
            post_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            body TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            parent_id INTEGER,
            name TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE sales (
            id INTEGER PRIMARY KEY,
            region TEXT NOT NULL,
            product TEXT NOT NULL,
            amount INTEGER NOT NULL,
            sold_at TEXT NOT NULL
        )');

        $this->seed();
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES
            (1, 'alice'), (2, 'bob'), (3, 'carol'), (4, 'dave')");

        $this->pdo->exec("INSERT INTO posts (id, user_id, title, votes, published, created_at) VALUES
            (1, 1, 'Alpha',   42, 1, '2025-01-01'),
            (2, 1, 'Beta',    17, 1, '2025-01-02'),
            (3, 1, 'Gamma',   88, 1, '2025-01-03'),
            (4, 2, 'Delta',   12, 1, '2025-01-04'),
            (5, 2, 'Epsilon', 55, 0, '2025-01-05'),
            (6, 3, 'Zeta',    33, 1, '2025-01-06'),
            (7, 3, 'Eta',     21, 1, '2025-01-07')");

        $this->pdo->exec("INSERT INTO comments (id, post_id, user_id, body) VALUES
            (1, 1, 2, 'nice'),
            (2, 1, 3, 'agreed'),
            (3, 2, 4, 'meh'),
            (4, 3, 1, 'self-comment'),
            (5, 6, 1, 'cool')");

        // Tree: Root → Tech → (PHP, Database → SQLite, Postgres)
        $this->pdo->exec("INSERT INTO categories (id, parent_id, name) VALUES
            (1, NULL, 'Root'),
            (2, 1,    'Tech'),
            (3, 2,    'PHP'),
            (4, 2,    'Database'),
            (5, 4,    'SQLite'),
            (6, 4,    'Postgres'),
            (7, 1,    'Music')");

        $this->pdo->exec("INSERT INTO sales (id, region, product, amount, sold_at) VALUES
            (1, 'NA', 'A', 100, '2025-Q1'),
            (2, 'NA', 'B', 200, '2025-Q1'),
            (3, 'NA', 'A',  50, '2025-Q2'),
            (4, 'EU', 'A', 300, '2025-Q1'),
            (5, 'EU', 'B', 150, '2025-Q1'),
            (6, 'EU', 'B',  75, '2025-Q2'),
            (7, 'AS', 'A', 400, '2025-Q1')");
    }
}
