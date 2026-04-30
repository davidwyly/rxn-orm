<?php declare(strict_types=1);

/**
 * Eager-load benchmark: 100 parents × ~100 children. Compares the
 * naive N+1 access pattern (1 + 100 queries) against eager loading
 * (2 queries). The point isn't ORM-vs-ORM speed here — it's to prove
 * our `with()` actually eliminates N+1.
 *
 * Run:  php bench/eager.php
 */

require __DIR__ . '/bootstrap.php';

use Rxn\Orm\Db\Connection;
use Rxn\Orm\Model\Record;
use Rxn\Orm\Model\Relation;

$pdo = bench_make_pdo(10_000); // 100 users × 100 posts each

class EagerUser extends Record {
    public const TABLE = 'users';
    public function posts(): Relation {
        return $this->hasMany(EagerPost::class, 'user_id');
    }
}
class EagerPost extends Record {
    public const TABLE = 'posts';
}
Record::clearConnections();
Record::setConnection(new Connection($pdo));

bench_print_header("Load 100 users + each user's posts (10,000 total rows)");

// -- N+1 access: lazy fetch per user -----------------------------------
$nPlusOneMs = bench_time(function () {
    $users = EagerUser::all();           // 1 query
    $totalPosts = 0;
    foreach ($users as $u) {
        $relation = $u->posts();          // Relation object
        $posts = $relation->queryFor($u->toArray())->get(); // +1 query per user
        $totalPosts += count($posts);
    }
    if ($totalPosts === 0) throw new RuntimeException('expected posts');
});
echo bench_format_row('rxn-orm N+1 (101 q)', $nPlusOneMs, null) . "\n";

// -- Eager loaded: 2 queries total -------------------------------------
$eagerMs = bench_time(function () {
    $users = EagerUser::query()->with('posts')->get(); // 2 queries
    $totalPosts = 0;
    foreach ($users as $u) {
        $totalPosts += count($u->posts);
    }
    if ($totalPosts === 0) throw new RuntimeException('expected posts');
});
echo bench_format_row('rxn-orm eager (2 q)', $eagerMs, $nPlusOneMs) . "\n";

$speedup = $nPlusOneMs / max($eagerMs, 0.001);
echo "\nEager loading is " . sprintf('%.1fx', $speedup) . " faster.\n";
