<?php declare(strict_types=1);

/**
 * Complex-query benchmarks: the queries that *don't* show up in
 * "select 10k rows" comparisons but matter every day in real apps.
 *
 *   php bench/complex.php
 *
 * Each scenario is run against rxn-orm and Eloquent on the same
 * in-memory SQLite database. The point isn't only "who's faster"
 * — it's "who can express this at all without dropping to raw SQL,
 * and what's the LOC cost?"
 */

require __DIR__ . '/bootstrap.php';

use Rxn\Orm\Builder\Raw;
use Rxn\Orm\Db\Connection;

function bench_complex_seed(PDO $pdo, int $rows = 5_000): void
{
    $pdo->exec('CREATE TABLE posts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        votes INTEGER NOT NULL,
        published INTEGER NOT NULL,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE sales (
        id INTEGER PRIMARY KEY,
        region TEXT NOT NULL,
        product TEXT NOT NULL,
        amount INTEGER NOT NULL
    )');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO posts (id, user_id, title, votes, published, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    for ($i = 1; $i <= $rows; $i++) {
        $stmt->execute([$i, ($i % 50) + 1, "Post $i", random_int(1, 1000), $i % 2, '2025-01-01']);
    }
    $stmt = $pdo->prepare('INSERT INTO sales (id, region, product, amount) VALUES (?, ?, ?, ?)');
    $regions = ['NA', 'EU', 'AS', 'SA'];
    $products = ['A', 'B', 'C'];
    for ($i = 1; $i <= $rows; $i++) {
        $stmt->execute([$i, $regions[$i % 4], $products[$i % 3], random_int(10, 1000)]);
    }
    $pdo->commit();
}

// --- Scenario 1: Top-N per group via window function ---

bench_print_header('Top-3 posts per user (window function over 5,000 posts × 50 users)');

$rxnTime = bench_time(function () {
    $pdo = new PDO('sqlite::memory:');
    bench_complex_seed($pdo);
    $db = new Connection($pdo);
    $inner = $db->table('posts')->select([
        'user_id', 'title', 'votes',
        Raw::of('ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY votes DESC) AS rk'),
    ]);
    $rows = $db->query()->select(['user_id', 'title', 'votes'])
        ->from($inner, 'ranked')
        ->where('rk', '<=', 3)
        ->orderBy('user_id')->orderBy('votes', 'DESC')
        ->get();
    if (count($rows) !== 150) {
        throw new RuntimeException('expected 150 rows, got ' . count($rows));
    }
});
echo bench_format_row('rxn-orm', $rxnTime, null) . "\n";

if (bench_has_eloquent()) {
    $elTime = bench_time(function () {
        $pdo = new PDO('sqlite::memory:');
        bench_complex_seed($pdo);
        $capsule = bench_eloquent_capsule($pdo);
        // Eloquent has no native window-function builder; use selectRaw.
        $rows = $capsule->getConnection()->table('posts')
            ->fromSub(function ($q) {
                $q->select(['user_id', 'title', 'votes'])
                  ->selectRaw('ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY votes DESC) AS rk')
                  ->from('posts');
            }, 'ranked')
            ->where('rk', '<=', 3)
            ->orderBy('user_id')->orderBy('votes', 'desc')
            ->get();
        if (count($rows) !== 150) {
            throw new RuntimeException('expected 150 rows, got ' . count($rows));
        }
    });
    echo bench_format_row('Eloquent', $elTime, $rxnTime) . "\n";
}

// --- Scenario 2: Conditional aggregate dashboard ---

bench_print_header('Revenue by region with per-product breakdown (5,000 sales)');

$rxnTime = bench_time(function () {
    $pdo = new PDO('sqlite::memory:');
    bench_complex_seed($pdo);
    $db = new Connection($pdo);
    $rows = $db->table('sales')
        ->select([
            'region',
            Raw::of("SUM(CASE WHEN product = 'A' THEN amount ELSE 0 END) AS total_a"),
            Raw::of("SUM(CASE WHEN product = 'B' THEN amount ELSE 0 END) AS total_b"),
            Raw::of("SUM(CASE WHEN product = 'C' THEN amount ELSE 0 END) AS total_c"),
            Raw::of('SUM(amount) AS total'),
        ])
        ->groupBy('region')
        ->orderBy('region')
        ->get();
    if (count($rows) !== 4) {
        throw new RuntimeException('expected 4 region rows');
    }
});
echo bench_format_row('rxn-orm', $rxnTime, null) . "\n";

if (bench_has_eloquent()) {
    $elTime = bench_time(function () {
        $pdo = new PDO('sqlite::memory:');
        bench_complex_seed($pdo);
        $capsule = bench_eloquent_capsule($pdo);
        $rows = $capsule->getConnection()->table('sales')
            ->selectRaw("region,
                SUM(CASE WHEN product = 'A' THEN amount ELSE 0 END) AS total_a,
                SUM(CASE WHEN product = 'B' THEN amount ELSE 0 END) AS total_b,
                SUM(CASE WHEN product = 'C' THEN amount ELSE 0 END) AS total_c,
                SUM(amount) AS total")
            ->groupBy('region')
            ->orderBy('region')
            ->get();
        if (count($rows) !== 4) {
            throw new RuntimeException('expected 4 region rows');
        }
    });
    echo bench_format_row('Eloquent', $elTime, $rxnTime) . "\n";
}

// --- Scenario 3: Correlated subquery for per-row count ---

bench_print_header('Per-user post count via correlated subquery (50 users)');

$rxnTime = bench_time(function () {
    $pdo = new PDO('sqlite::memory:');
    bench_complex_seed($pdo);
    $pdo->exec("CREATE TABLE u (id INTEGER PRIMARY KEY)");
    $pdo->exec("INSERT INTO u SELECT DISTINCT user_id FROM posts");
    $db = new Connection($pdo);
    $rows = $db->table('u')
        ->select([
            'id',
            Raw::of('(SELECT COUNT(*) FROM posts WHERE posts.user_id = u.id) AS post_count'),
            Raw::of('(SELECT MAX(votes) FROM posts WHERE posts.user_id = u.id) AS top_votes'),
        ])
        ->orderBy('id')
        ->get();
    if (count($rows) !== 50) {
        throw new RuntimeException('expected 50');
    }
});
echo bench_format_row('rxn-orm', $rxnTime, null) . "\n";

if (bench_has_eloquent()) {
    $elTime = bench_time(function () {
        $pdo = new PDO('sqlite::memory:');
        bench_complex_seed($pdo);
        $pdo->exec("CREATE TABLE u (id INTEGER PRIMARY KEY)");
        $pdo->exec("INSERT INTO u SELECT DISTINCT user_id FROM posts");
        $capsule = bench_eloquent_capsule($pdo);
        $rows = $capsule->getConnection()->table('u')
            ->select('id')
            ->selectSub('SELECT COUNT(*) FROM posts WHERE posts.user_id = u.id', 'post_count')
            ->selectSub('SELECT MAX(votes) FROM posts WHERE posts.user_id = u.id', 'top_votes')
            ->orderBy('id')
            ->get();
        if (count($rows) !== 50) {
            throw new RuntimeException('expected 50');
        }
    });
    echo bench_format_row('Eloquent', $elTime, $rxnTime) . "\n";
}

// --- Scenario 4: Recursive CTE ---

bench_print_header('Recursive CTE: walk a 1,000-node tree');

$rxnTime = bench_time(function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE nodes (id INTEGER PRIMARY KEY, parent_id INTEGER)');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO nodes (id, parent_id) VALUES (?, ?)');
    $stmt->execute([1, null]);
    for ($i = 2; $i <= 1_000; $i++) {
        $stmt->execute([$i, intdiv($i, 2)]); // binary-tree-ish layout
    }
    $pdo->commit();
    $db = new Connection($pdo);
    $rows = $db->select(
        'WITH RECURSIVE descendants AS (
            SELECT id, parent_id FROM nodes WHERE id = ?
            UNION ALL
            SELECT n.id, n.parent_id FROM nodes n INNER JOIN descendants d ON n.parent_id = d.id
        ) SELECT * FROM descendants',
        [1],
    );
    if (count($rows) !== 1000) {
        throw new RuntimeException('expected 1000 descendants');
    }
});
echo bench_format_row('rxn-orm', $rxnTime, null) . "\n";

if (bench_has_eloquent()) {
    $elTime = bench_time(function () {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE nodes (id INTEGER PRIMARY KEY, parent_id INTEGER)');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO nodes (id, parent_id) VALUES (?, ?)');
        $stmt->execute([1, null]);
        for ($i = 2; $i <= 1_000; $i++) {
            $stmt->execute([$i, intdiv($i, 2)]);
        }
        $pdo->commit();
        $capsule = bench_eloquent_capsule($pdo);
        $rows = $capsule->getConnection()->select(
            'WITH RECURSIVE descendants AS (
                SELECT id, parent_id FROM nodes WHERE id = ?
                UNION ALL
                SELECT n.id, n.parent_id FROM nodes n INNER JOIN descendants d ON n.parent_id = d.id
            ) SELECT * FROM descendants',
            [1],
        );
        if (count($rows) !== 1000) {
            throw new RuntimeException('expected 1000 descendants');
        }
    });
    echo bench_format_row('Eloquent', $elTime, $rxnTime) . "\n";
}

echo "\nNumbers above include the per-run schema setup + seed (consistent\n";
echo "across both ORMs). For raw query-execution-only timings, comment\n";
echo "out the seeds and pass a pre-populated PDO.\n";
