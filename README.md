# rxn-orm

[![Latest Version](https://img.shields.io/packagist/v/davidwyly/rxn-orm.svg)](https://packagist.org/packages/davidwyly/rxn-orm)
[![PHP Version](https://img.shields.io/packagist/php-v/davidwyly/rxn-orm.svg)](https://packagist.org/packages/davidwyly/rxn-orm)
[![License](https://img.shields.io/packagist/l/davidwyly/rxn-orm.svg)](LICENSE)

A lightweight ORM for PHP 8.1+. Three layers, each usable on its own:

1. **`Rxn\Orm\Builder`** — Composable SQL builders. `(new Query())->...->toSql()` returns `[string $sql, array $bindings]` you can hand to any PDO. Zero dependencies beyond `ext-pdo`.
2. **`Rxn\Orm\Db\Connection`** — Thin wrapper over a PDO you provide. Adds terminals (`get/first/find/value/pluck/exists/count/paginate/chunk/cursor`), nested transactions via savepoints, read/write split, query profiling, and execution helpers for the write builders.
3. **`Rxn\Orm\Model\Record`** — Active-record base class. Hydrating reads, dirty-aware writes, casts, eager loading (`with('orders.items')`) that kills N+1 in one extra query per relation. Soft deletes, auto-timestamps, `belongsToMany` with attach/detach/sync.

Plus an opt-in **`Rxn\Orm\Migration\Runner`** for raw `.sql` migration files.

---

> **Deep dive:** see [`docs/COMPARISON.md`](docs/COMPARISON.md) for a researched side-by-side study against Eloquent — performance, syntax, complexity, flexibility, and 13 known ORM-killer query patterns with verdicts.

## Numbers

Measured on PHP 8.3 against in-memory SQLite (`bench/run.php`, repeatable). The interesting line is **rxn-orm Record vs Eloquent**:

| Benchmark              | rxn-orm Record | Eloquent | Speedup |
|------------------------|---------------:|---------:|---------|
| Hydrate 10,000 rows    | **5.0 ms**     | 54.5 ms  | **11×** |
| Insert 1,000 rows      | **3.9 ms**     | 37.0 ms  | **10×** |
| Peak memory (hydrate)  | **10 MB**      | 26 MB    | **2.6× less** |
| Eager-load 100 × 100   | **9.4 ms** (2 queries) | — | 3.4× faster than naive N+1 |

Connection-layer terminals (skipping hydration) match raw PDO at the floor — the builder doesn't add measurable overhead. Run `php bench/run.php` to reproduce on your machine.

---

## Why

- **Honest size.** ~3.6k lines, no Service Container, no facades, no global state beyond explicit `setConnection()`.
- **No magic.** Methods are real methods. No `__call`/`__callStatic`, no naming-convention guessing, no runtime macro registry. PHPStan and your IDE see what you see.
- **Fast.** ~10× faster than Eloquent on hydration and inserts. Connection-layer overhead is essentially zero.
- **Fiber-safe.** Connection holds no static state — pair one Connection per fiber and you have full PHP 8.1 fiber / Swoole / AMP concurrency. Eloquent's facades and global container are hostile to this; ours simply isn't an issue.
- **Composable builders.** A `Query` can appear inside a `WHERE ... IN (...)`, a `FROM (...) AS alias`, or as a `SELECT (...) AS col` expression.
- **Safe by default.** Identifiers are escaped; operators are whitelisted; `DELETE` and `UPDATE` without a `WHERE` are refused unless you opt in explicitly.
- **Driver-aware where it matters.** Portable `upsert()` for MySQL / Postgres / SQLite. `RETURNING` for Postgres + SQLite.

## Install

```
composer require davidwyly/rxn-orm
```

Requires **PHP 8.1+** and **ext-pdo**. Works with MySQL, PostgreSQL, and SQLite.

---

## Layer 1 — SQL builders only

Every builder implements `Rxn\Orm\Builder\Buildable` and returns `[string $sql, array $bindings]` from `toSql()`:

```php
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Raw;

[$sql, $bindings] = (new Query())
    ->select(['u.id', 'u.email'])
    ->selectSubquery(
        (new Query())->select([Raw::of('COUNT(*)')])->from('orders')
            ->where('user_id', '=', Raw::of('u.id')),
        'order_count'
    )
    ->from('users', 'u')
    ->leftJoin('roles', 'r.id', '=', 'u.role_id', 'r')
    ->where('u.active', '=', 1)
    ->andWhereIn('u.role_id',
        (new Query())->select(['id'])->from('roles')->where('name', 'LIKE', 'admin%'))
    ->orderBy('u.id', 'DESC')
    ->limit(50)
    ->toSql();

$stmt = $pdo->prepare($sql);
$stmt->execute($bindings);
```

A `Delete` or `Update` with no `WHERE` is refused. Opt in with `->allowEmptyWhere()` when you really mean to wipe a table.

### Portable upsert

```php
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Raw;

(new Insert())
    ->into('counters')
    ->row(['key' => 'pageviews', 'value' => 1])
    ->upsert(['key'], ['value' => Raw::of('counters.value + 1')])
    ->setConnection($db)
    ->execute();
```

Emits `ON DUPLICATE KEY UPDATE` on MySQL, `ON CONFLICT (...) DO UPDATE SET ... = EXCLUDED.col` on Postgres / SQLite. Driver detected from the attached Connection.

---

## Layer 2 — Connection (execution)

Bring your own PDO; `Connection` doesn't manage credentials.

```php
use Rxn\Orm\Db\Connection;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
$db  = new Connection($pdo);

$users  = $db->table('users')->where('active', '=', 1)->limit(50)->get();
$first  = $db->table('users')->where('email', '=', 'a@x')->first();
$emails = $db->table('users')->pluck('email');
$exists = $db->table('users')->where('id', '=', 7)->exists();
$count  = $db->table('users')->where('active', '=', 1)->count();
```

### Read/write split

```php
$db = new Connection(writePdo: $primary, readPdo: $replica);
```

SELECTs route to the replica; writes hit the primary. Inside a transaction, *everything* hits the primary so you don't read uncommitted data from a stale replica.

### Profiling hook

```php
$db->onQuery(function (string $sql, array $bindings, float $ms) use ($logger) {
    if ($ms > 100) {
        $logger->warning('Slow query', ['sql' => $sql, 'ms' => $ms]);
    }
});
```

Zero overhead when not registered.

### Transactions

`transaction()` commits on success, rolls back on any exception, and uses real savepoints for nested calls — an inner block can roll back without aborting the outer transaction.

```php
$db->transaction(function (Connection $db) {
    $db->run((new Insert())->into('orders')->row(...));
    $db->transaction(function ($db) {
        // savepoint — rolls back independently
    });
});
```

### Row locking (job-queue pattern)

```php
// Worker claims up to 10 pending jobs without contending with other workers
$jobs = $db->transaction(function (Connection $db) {
    return $db->table('jobs')
        ->where('status', '=', 'pending')
        ->orderBy('id')
        ->limit(10)
        ->lockForUpdate()
        ->skipLocked()        // workers stride past each other
        ->get();
});
```

Driver-aware: emits `FOR UPDATE SKIP LOCKED` on MySQL 8+ / Postgres, `NOWAIT` if you prefer immediate failure over waiting (`->noWait()`). On SQLite the entire lock clause is a silent no-op (no row-level locking concept). On MySQL, `sharedLock()->skipLocked()` automatically uses `FOR SHARE` rather than the legacy `LOCK IN SHARE MODE` (which doesn't accept the modifier).

### Pagination, chunking, cursors

```php
$page = $db->table('posts')->orderBy('id', 'ASC')->paginate(perPage: 20, page: 3);
// ['data' => [...], 'total' => 412, 'page' => 3, 'perPage' => 20, 'lastPage' => 21]

$db->table('events')->orderBy('id', 'ASC')->chunk(500, function (array $rows) {
    foreach ($rows as $event) { /* process */ }
    // return false to stop early
});

foreach ($db->table('huge_log')->cursor() as $row) {
    // streamed one row at a time
}
```

---

## Layer 3 — Records (active record)

```php
use Rxn\Orm\Model\Record;
use Rxn\Orm\Model\Relation;

class User extends Record {
    public const TABLE      = 'users';
    public const CREATED_AT = 'created_at';   // auto-timestamps (opt-in)
    public const UPDATED_AT = 'updated_at';
    public const DELETED_AT = 'deleted_at';   // soft deletes (opt-in)

    protected static array $casts = [
        'id'       => 'int',
        'active'   => 'bool',
        'settings' => 'json',
        'role'     => 'enum:App\\Role',
    ];

    protected static ?array $fillable = ['email', 'name', 'settings'];

    public function posts(): Relation     { return $this->hasMany(Post::class, 'user_id'); }
    public function profile(): Relation   { return $this->hasOne(Profile::class, 'user_id'); }
    public function roles(): Relation     { return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id'); }
}

Record::setConnection($db);

$user = User::find(42);
$user->name = 'Alice';
$user->save();                  // UPDATE only changed columns
$user->delete();                // soft-delete (UPDATE deleted_at)
$user->forceDelete();           // bypass soft-delete
$user->restore();               // un-delete

$created = User::create(['email' => 'a@x', 'active' => true]);
$active  = User::where('active', '=', 1)->orderBy('id')->limit(20)->get();
```

### Eager loading — the N+1 killer

```php
$users = User::query()->with('posts.comments')->get();
// 3 queries total: users → posts → comments

foreach ($users as $u) {
    foreach ($u->posts as $post) {
        foreach ($post->comments as $comment) {
            // already loaded, no extra queries
        }
    }
}
```

Dotted paths nest; positional args run in parallel: `with('posts', 'profile')`.

### belongsToMany

```php
$user->attach('roles', $roleId);                                  // single
$user->attach('roles', [$id1, $id2], ['granted_at' => $now]);     // bulk + pivot data
$user->detach('roles', $roleId);
$user->detach('roles');                                            // detach all
$user->sync('roles', [1, 2, 3]);                                  // diff & apply
```

Eager loading via `with('roles')` issues a single query that JOINs through the pivot table, regardless of result-set size.

### Soft deletes

```php
class Post extends Record {
    public const DELETED_AT = 'deleted_at';
}

Post::all();                                  // alive rows only
Post::query()->withTrashed()->get();          // include deleted
Post::query()->onlyTrashed()->get();          // only deleted
$post->delete();                              // soft — UPDATE deleted_at
$post->forceDelete();                         // hard — DELETE
$post->restore();                             // clear deleted_at
$post->trashed();                             // bool
```

### Lifecycle hooks

Override and call `parent::*()`. No global event dispatcher, no observers — just methods on your model.

```php
class User extends Record {
    protected function beforeSave(): void {
        if (!isset($this->password_hash) && isset($this->password)) {
            $this->password_hash = password_hash($this->password, PASSWORD_DEFAULT);
        }
    }
    // also: afterSave(), beforeDelete(), afterDelete()
}
```

`beforeSave()` runs before INSERT *and* UPDATE — branch on `$this->exists` to differentiate.

### Casts

Built-in: `int`, `float`, `bool`, `string`, `json` / `array`, `datetime`, `date`, `enum:Class`. Reads return PHP-typed values; writes serialize back to DB-friendly strings/ints. `json` round-trips arrays cleanly.

### Per-model connections

```php
Record::setConnection($readReplica);                       // default for everything
Record::setConnection($auditDb, AuditLog::class);          // override for one model
```

### Query scopes (no magic)

Eloquent has `scopeActive()` magic via `__call`. Our equivalent: just write a **static method on your Record that returns a `ModelQuery`**. Composes the same way, no magic, IDE-completable.

```php
class Post extends Record {
    public const TABLE = 'posts';

    public static function published(): ModelQuery {
        return static::query()->where('published', '=', true);
    }

    public static function popular(int $minVotes = 100): ModelQuery {
        return static::query()->where('votes', '>=', $minVotes);
    }

    public static function byAuthor(int $userId): ModelQuery {
        return static::query()->where('user_id', '=', $userId);
    }
}

// Compose:
Post::published()->where('user_id', '=', 7)->orderBy('votes', 'DESC')->get();
Post::popular(500)->with('comments')->get();
```

This is the entire "scopes" feature. No registration, no `__call`, no Larastan stub needed — just methods.

---

## Migrations

```php
use Rxn\Orm\Migration\Runner;

$runner = new Runner($db, __DIR__ . '/migrations');

$runner->status();      // [['name' => '0001_create_users', 'state' => 'pending', 'batch' => null], ...]
$runner->run();         // apply all pending
$runner->run(steps: 1); // apply just one
$runner->rollback(1);   // undo most recent batch
```

Migration files are plain `.sql`, named `NNNN_description.sql` with optional `NNNN_description.down.sql` siblings for rollback. Tracking lives in a `rxn_migrations` table created on first run. **No Schema DSL** — write the SQL you want; we just sequence and track it. (The Blueprint-style DSLs are where lightweight ORMs become heavyweight; we deliberately don't go there.)

---

## Lessons from Eloquent

| Eloquent feature | rxn-orm decision |
|---|---|
| Fluent terminals (`first/get/find/value/pluck/exists/count`) | ✅ kept |
| Eager loading (`with`) — kills N+1 | ✅ kept |
| Casts (json, datetime, enum) | ✅ kept |
| Pagination, chunk, cursor | ✅ kept |
| Active-record + relations | ✅ kept |
| `belongsToMany` + attach/detach/sync | ✅ kept |
| Soft deletes | ✅ kept |
| Auto-timestamps (`created_at` / `updated_at`) | ✅ kept |
| Lifecycle hooks (`beforeSave` etc.) | ✅ kept (as overridable methods, not a dispatcher) |
| Nested transactions w/ savepoints | ✅ kept |
| Read/write connection split | ✅ kept |
| Query listener (`DB::listen()`) | ✅ kept (`Connection::onQuery()`) |
| Mass-assignment guard (`$fillable`) | ✅ kept (allowlist only — no `$guarded`) |
| Portable upsert | ✅ kept |
| Migrations | ✅ kept (raw SQL files; no Schema DSL) |
| UNION / UNION ALL | ✅ kept (`union()` / `unionAll()`) |
| INSERT ... SELECT | ✅ kept (`Insert::fromQuery()`) |
| `whereColumn()` | ✅ kept |
| `lockForUpdate()` / `sharedLock()` | ✅ kept (driver-aware) |
| `skipLocked()` / `noWait()` | ✅ kept (chains after a lock) |
| `Collection` class | ❌ skipped — PHP arrays are fine |
| `__call`/`__callStatic` magic | ❌ skipped — every method is real |
| Facades / global service container | ❌ skipped — bring your own PDO |
| Global event dispatcher / observers | ❌ skipped — override hooks instead |
| Query macros (runtime registration) | ❌ skipped — just write a function |
| `morphMany` / polymorphic relations | ❌ skipped — usually a code smell; build with the SQL layer |
| `hasManyThrough` | ⏭️ deferred — niche; do with the builder |
| Schema builder DSL | ⏭️ deferred — write SQL migrations |

The cut-line is "magic vs. method." Anything you can't read in source and trace to its definition was deliberately skipped.

## How rxn-orm compares

| Dimension | rxn-orm | Eloquent | Doctrine ORM | Doctrine DBAL | Cycle ORM | Medoo |
|---|---|---|---|---|---|---|
| LOC | ~3.6k | ~30k+ | ~60k+ | ~25k | ~40k | ~2k |
| Style | AR + builder | AR + builder | DataMapper + UoW | builder only | DataMapper | builder only |
| Magic level | none | heavy | annotation-heavy | none | attribute-heavy | none |
| Hydrate 10k rows | ~5 ms | ~55 ms | slowest | n/a | similar to Eloquent | n/a |
| Eager loading | ✅ | ✅ best in class | ✅ DQL fetch joins | ❌ | ✅ | ❌ |
| Soft deletes / timestamps | ✅ | ✅ | via traits | ❌ | ✅ | ❌ |
| Migrations | ✅ raw SQL | ✅ DSL | ✅ DSL | ❌ | ✅ DSL | ❌ |
| Many-to-many | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ |
| Polymorphic | ❌ | ✅ | ✅ | ❌ | ✅ | ❌ |
| Read/write split | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Static-analysis friendly | ✅ generics | ⚠️ Larastan | ✅ | ✅ | ✅ | ✅ |
| Runtime deps beyond ext-pdo | 0 | huge (Illuminate) | many | few | few | 0 |
| Cold-start cost | ~free | seconds | seconds + metadata | low | moderate | ~free |
| Battle-tested in prod | new | yes | yes | yes | yes | some |

**Use rxn-orm if** you want Eloquent-like DX in a non-Laravel project (Slim, Mezzio, Swoole, AMP, plain PHP, AWS Lambda), you care about cold-start, you want to read your ORM's source, or you're allergic to facades.

**Don't use rxn-orm if** you need polymorphic relations, you're already deep in Laravel (just use Eloquent), or you need production-grade battle-testing today (it's pre-1.0).

## Fiber-safety

`Connection` and the builders hold no static state. `Record::setConnection()` is per-fiber-safe if you set the connection inside the fiber:

```php
$fiber = new Fiber(function () use ($pdo) {
    $db = new Connection($pdo);
    Record::setConnection($db);
    User::where('active', '=', 1)->count();
});
```

Per-class connection bindings (`Record::setConnection($db, User::class)`) live in a single static array on the Record class. If you need full fiber isolation across model classes, use `Record::clearConnections()` at fiber start and re-bind. The default-Connection slot is shared.

---

## Feature summary

**Builders.** SELECT with JOINs, GROUP BY, HAVING, ORDER BY, LIMIT, OFFSET. WHERE/AND/OR with nested groups, operators `= != <> < <= > >= IN LIKE BETWEEN REGEXP` and negations, `whereIn(array | subquery)`, `whereIsNull`. Subqueries in WHERE, FROM, and SELECT positions. Portable `upsert()` and MySQL `onDuplicateKeyUpdate()`. `returning(...)` on Insert/Update/Delete. `Raw::of(...)` escape hatch. Empty-WHERE guard.

**Connection.** `run/select/selectOne/value/pluck/exists/count/insert/update/delete/statement/lastInsertId/transaction/beginTransaction/commit/rollBack/transactionDepth/onQuery/getDriver/getReadPdo`. Read/write split.

**Query terminals.** `get/first/find/value/pluck/exists/count/paginate/chunk/cursor`.

**Record.** `find/findOrFail/all/first/where/query/create/save/delete/forceDelete/restore/refresh/fill/toArray/id/exists/trashed/dirtyForUpdate/setRelation/getRelation/hasRelation/getRawAttribute`. Lifecycle hooks: `beforeSave/afterSave/beforeDelete/afterDelete`. Casts. Auto-timestamps via `CREATED_AT`/`UPDATED_AT` constants. Soft deletes via `DELETED_AT` constant. Mass-assignment via `$fillable`. Relations: `hasMany / hasOne / belongsTo / belongsToMany` + `attach/detach/sync`. Eager loading with arbitrarily-nested `with()` paths.

**ModelQuery.** `with`, `withTrashed`, `onlyTrashed`. Hydrating `get/first/find` returning instances of the model class.

**Migration\Runner.** `run/rollback/status/reset`. Raw `.sql` files. `rxn_migrations` tracking table.

## Non-goals

- Schema builder / migrations DSL (use raw SQL migrations).
- Polymorphic / through-relations (build them with the SQL layer).
- A query result `Collection` class (PHP arrays + `array_*` are sufficient).
- Global event dispatcher / observers (use the override hooks).
- Connection management, pooling, replica routing beyond a second PDO (bring your own).
- Query optimization. The SQL you build is the SQL you get.

## Testing

```
composer install
vendor/bin/phpunit
```

171 tests, 349 assertions. Runs against in-memory SQLite by default. Set `RXN_ORM_MYSQL_DSN` / `RXN_ORM_PGSQL_DSN` env vars to also exercise the driver-specific integration tests. CI matrix runs PHP 8.1/8.2/8.3 against SQLite, MySQL 8, and Postgres 16.

## Benchmarks

```
composer install                   # installs illuminate/database for comparison
php bench/run.php
```

Numbers above are reproducible on any PHP 8.3 install; YMMV by host.

## Versioning

Semantic versioning. Pre-1.0 releases may still make breaking changes; pin to a minor range (`^0.3`) until 1.0.

## Contributing

Issues and pull requests welcome at <https://github.com/davidwyly/rxn-orm>.

## License

MIT &copy; David Wyly. See [LICENSE](LICENSE).
