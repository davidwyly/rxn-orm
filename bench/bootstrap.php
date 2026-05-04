<?php declare(strict_types=1);

/**
 * Shared scaffolding for the benchmark scripts.
 *
 * Builds a fresh in-memory SQLite database with N rows, returns a PDO
 * handle. Detects whether eloquent / doctrine are installed and lets
 * each bench script skip those rivals when they aren't available.
 */

require __DIR__ . '/../vendor/autoload.php';

function bench_make_pdo(int $rows = 10_000): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE posts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        views INTEGER NOT NULL,
        published INTEGER NOT NULL,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        email TEXT NOT NULL,
        name TEXT NOT NULL
    )');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO posts (id, user_id, title, body, views, published, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
    );
    $now = gmdate('Y-m-d H:i:s');
    for ($i = 1; $i <= $rows; $i++) {
        $stmt->execute([
            $i,
            ($i % 100) + 1,
            "Post #$i",
            "Body of post $i — lorem ipsum dolor sit amet, consectetur adipiscing elit.",
            $i * 7,
            $i % 2,
            $now,
        ]);
    }
    $pdo->commit();

    $pdo->beginTransaction();
    $userStmt = $pdo->prepare('INSERT INTO users (id, email, name) VALUES (?, ?, ?)');
    for ($i = 1; $i <= 100; $i++) {
        $userStmt->execute([$i, "user$i@example.com", "User #$i"]);
    }
    $pdo->commit();

    return $pdo;
}

function bench_time(callable $fn): float
{
    $start = hrtime(true);
    $fn();
    return (hrtime(true) - $start) / 1_000_000; // milliseconds
}

function bench_format_row(string $name, float $ms, ?float $baseline = null, int $memBytes = 0): string
{
    $rel = $baseline !== null && $baseline > 0
        ? sprintf('%.2fx', $ms / $baseline)
        : 'baseline';
    $mem = $memBytes > 0 ? sprintf('%.1f MB', $memBytes / 1_048_576) : '—';
    return sprintf("| %-22s | %8.1f ms | %-9s | %-8s |", $name, $ms, $rel, $mem);
}

function bench_print_header(string $title): void
{
    echo "\n## $title\n\n";
    echo "| ORM                    |     Time | Relative  | Peak mem |\n";
    echo "|------------------------|----------|-----------|----------|\n";
}

function bench_has_eloquent(): bool
{
    return class_exists(\Illuminate\Database\Capsule\Manager::class);
}

function bench_has_doctrine(): bool
{
    return class_exists(\Doctrine\ORM\EntityManager::class);
}

function bench_eloquent_capsule(PDO $pdo): \Illuminate\Database\Capsule\Manager
{
    // Eloquent uses its own connection pool — but we want the same
    // in-memory database. The trick is to plug our PDO into Capsule's
    // connection resolver instead of letting it create one.
    $capsule = new \Illuminate\Database\Capsule\Manager();
    $capsule->addConnection([
        'driver'   => 'sqlite',
        'database' => ':memory:',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    // Replace Eloquent's PDO with ours so it sees the same data.
    $capsule->getConnection()->setPdo($pdo);
    $capsule->getConnection()->setReadPdo($pdo);
    return $capsule;
}
