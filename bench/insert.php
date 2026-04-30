<?php declare(strict_types=1);

/**
 * Insert benchmark: how long to insert N rows in a transaction?
 *
 * Run:  php bench/insert.php [rows]
 * Default: 1,000 rows.
 */

require __DIR__ . '/bootstrap.php';

use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Db\Connection;
use Rxn\Orm\Model\Record;

$rows = (int)($argv[1] ?? 1_000);

function fresh_pdo_for_insert(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE posts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL
    )');
    return $pdo;
}

bench_print_header("Insert $rows rows into `posts` (single transaction)");

// -- Raw PDO baseline --------------------------------------------------
$baselineMs = bench_time(function () use ($rows) {
    $pdo = fresh_pdo_for_insert();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO posts (id, user_id, title, body) VALUES (?, ?, ?, ?)');
    for ($i = 1; $i <= $rows; $i++) {
        $stmt->execute([$i, ($i % 100) + 1, "T$i", "Body of $i"]);
    }
    $pdo->commit();
});
echo bench_format_row('raw PDO + prepare',  $baselineMs, null) . "\n";

// -- rxn-orm Connection (per-row Insert) ------------------------------
$rxnPerRowMs = bench_time(function () use ($rows) {
    $db = new Connection(fresh_pdo_for_insert());
    $db->transaction(function (Connection $db) use ($rows) {
        for ($i = 1; $i <= $rows; $i++) {
            $db->insert((new Insert())->into('posts')->row([
                'id' => $i, 'user_id' => ($i % 100) + 1, 'title' => "T$i", 'body' => "Body of $i",
            ]));
        }
    });
});
echo bench_format_row('rxn-orm per-row',    $rxnPerRowMs, $baselineMs) . "\n";

// -- rxn-orm Connection (single batch Insert) -------------------------
$rxnBatchMs = bench_time(function () use ($rows) {
    $db = new Connection(fresh_pdo_for_insert());
    $batch = [];
    for ($i = 1; $i <= $rows; $i++) {
        $batch[] = ['id' => $i, 'user_id' => ($i % 100) + 1, 'title' => "T$i", 'body' => "Body of $i"];
    }
    $db->insert((new Insert())->into('posts')->rows($batch));
});
echo bench_format_row('rxn-orm batch',      $rxnBatchMs, $baselineMs) . "\n";

// -- rxn-orm Record::create (per-row, full lifecycle) -----------------
class InsertBenchPost extends Record {
    public const TABLE = 'posts';
}
Record::clearConnections();
$db = new Connection(fresh_pdo_for_insert());
Record::setConnection($db);

$rxnRecordMs = bench_time(function () use ($db, $rows) {
    $db->transaction(function () use ($rows) {
        for ($i = 1; $i <= $rows; $i++) {
            InsertBenchPost::create(['id' => $i, 'user_id' => ($i % 100) + 1, 'title' => "T$i", 'body' => "Body of $i"]);
        }
    });
});
echo bench_format_row('rxn-orm Record',     $rxnRecordMs, $baselineMs) . "\n";

// -- Eloquent ---------------------------------------------------------
if (bench_has_eloquent()) {
    $capsule = bench_eloquent_capsule(fresh_pdo_for_insert());

    $eloquent = new class extends \Illuminate\Database\Eloquent\Model {
        protected $table = 'posts';
        public $timestamps = false;
        protected $guarded = [];
    };
    $cls = get_class($eloquent);

    $elMs = bench_time(function () use ($cls, $rows, $capsule) {
        $capsule->getConnection()->transaction(function () use ($cls, $rows) {
            for ($i = 1; $i <= $rows; $i++) {
                $cls::create(['id' => $i, 'user_id' => ($i % 100) + 1, 'title' => "T$i", 'body' => "Body of $i"]);
            }
        });
    });
    echo bench_format_row('Eloquent',          $elMs, $baselineMs) . "\n";
} else {
    echo "| Eloquent              | (not installed) |\n";
}
