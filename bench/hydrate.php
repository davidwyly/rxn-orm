<?php declare(strict_types=1);

/**
 * Hydration benchmark: how long to load N rows into model objects?
 *
 * This is Eloquent's biggest known pain point at scale — its
 * `Collection` + attribute-cast pipeline does a lot of per-row work.
 * Doctrine's UoW is even heavier. Raw PDO is the floor.
 *
 * Run:   php bench/hydrate.php [rows]
 * Default: 10,000 rows.
 */

require __DIR__ . '/bootstrap.php';

use Rxn\Orm\Db\Connection;
use Rxn\Orm\Model\Record;

$rows = (int)($argv[1] ?? 10_000);
$pdo  = bench_make_pdo($rows);

bench_print_header("Hydrate $rows rows from `posts`");

// -- Raw PDO baseline --------------------------------------------------
$baselineMs = bench_time(function () use ($pdo) {
    $stmt = $pdo->query('SELECT * FROM posts');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 0) {
        throw new RuntimeException('expected rows');
    }
});
echo bench_format_row('raw PDO (assoc array)', $baselineMs, null, memory_get_peak_usage(true)) . "\n";

// -- rxn-orm: Connection terminals (returns arrays, not models) --------
$rxnAssocMs = bench_time(function () use ($pdo) {
    $db = new Connection($pdo);
    $rows = $db->table('posts')->get();
    if (count($rows) === 0) {
        throw new RuntimeException('expected rows');
    }
});
echo bench_format_row('rxn-orm Connection', $rxnAssocMs, $baselineMs, memory_get_peak_usage(true)) . "\n";

// -- rxn-orm: Record (full hydration) ----------------------------------
class BenchPost extends Record
{
    public const TABLE = 'posts';
    protected static array $casts = ['id' => 'int', 'user_id' => 'int', 'views' => 'int', 'published' => 'bool'];
}
Record::clearConnections();
Record::setConnection(new Connection($pdo));

$rxnRecordMs = bench_time(function () {
    $models = BenchPost::all();
    if (count($models) === 0) {
        throw new RuntimeException('expected models');
    }
});
echo bench_format_row('rxn-orm Record', $rxnRecordMs, $baselineMs, memory_get_peak_usage(true)) . "\n";

// -- Eloquent --------------------------------------------------------
if (bench_has_eloquent()) {
    $capsule = bench_eloquent_capsule($pdo);

    $eloquent = new class () extends \Illuminate\Database\Eloquent\Model {
        protected $table = 'posts';
        public $timestamps = false;
        protected $casts = ['id' => 'int', 'user_id' => 'int', 'views' => 'int', 'published' => 'bool'];
        protected $guarded = [];
    };
    $cls = get_class($eloquent);

    $eloquentMs = bench_time(function () use ($cls) {
        $models = $cls::all();
        if (count($models) === 0) {
            throw new RuntimeException('expected models');
        }
    });
    echo bench_format_row('Eloquent', $eloquentMs, $baselineMs, memory_get_peak_usage(true)) . "\n";
} else {
    echo "| Eloquent              | (not installed — composer require illuminate/database) |\n";
}

// -- Doctrine ORM ----------------------------------------------------
if (bench_has_doctrine()) {
    // Doctrine setup is much heavier — skip if not needed.
    // Doctrine entity setup intentionally elided for v1; comparing
    // it fairly requires schema generation that adds noise to the
    // benchmark. Run via dedicated script when interested.
    echo "| Doctrine ORM          | (skipped — see bench/doctrine.php for full setup) |\n";
} else {
    echo "| Doctrine ORM          | (not installed) |\n";
}

echo "\nMemory column reflects PHP peak across all steps; lower is better.\n";
