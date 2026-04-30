# rxn-orm vs Eloquent — a researched comparison

A side-by-side study across **performance**, **syntax**, **complexity**, and **flexibility**, including the queries known to give ORMs trouble. All measurements are reproducible — run `php bench/run.php` and `php bench/complex.php` from a clean clone.

> **TL;DR:** rxn-orm is ~10× faster on hydration and inserts, ~1.6× faster on window-function queries, and **tied** on raw-SQL-bound complex workloads (recursive CTEs, conditional aggregates). Both ORMs use raw-SQL escape hatches for window functions, recursive CTEs, conditional aggregates, and bulk UPDATE-with-JOIN — the syntax difference between them comes down to "how clean is the escape hatch." Eloquent has built-in `union()` and `insertUsing()`; rxn-orm requires a 5-line workaround for those (filed as follow-ups).

---

## 1. Performance

Measured on PHP 8.3, in-memory SQLite, host-dependent.

### Bread-and-butter workloads (`bench/run.php`)

| Benchmark              | rxn-orm   | Eloquent | Ratio |
|------------------------|----------:|---------:|------:|
| Hydrate 10,000 rows    | **5 ms**  | 55 ms    | **11×** |
| Insert 1,000 rows (per-row) | **4 ms**  | 40 ms    | **10×** |
| Peak memory (hydrate)  | **10 MB** | 26 MB    | **2.6×** less |
| Eager-load 100×100     | **9.6 ms** | n/a   | (3.8× faster than naive N+1) |

Hydration is where Eloquent's per-row attribute pipeline (mutators, casts, magic accessors, `Collection` wrapping) shows up. rxn-orm hydrates into a flat `array<string, mixed>` then casts on `__get`, skipping the per-row overhead.

### Complex-query workloads (`bench/complex.php`)

| Benchmark | rxn-orm | Eloquent | Ratio |
|---|---:|---:|---:|
| Top-3 per user via window function (5k rows) | **13.4 ms** | 21.8 ms | **1.6×** |
| Revenue dashboard with conditional aggregates | 10.0 ms | 9.9 ms | tied |
| Per-row correlated subquery counts | 19.8 ms | 19.9 ms | tied |
| Recursive CTE walking a 1,000-node tree | 1.1 ms | 1.0 ms | tied (Eloquent slightly ahead) |

**Reading these:** when the query's SQL is the same, the ORMs are tied — execution time is dominated by SQLite, not by the builder. rxn-orm's win on window functions comes from the cheaper `Raw::of()` path vs Eloquent's `selectRaw()` indirection. The honest takeaway: **rxn-orm's perf advantage is largest for high-throughput simple workloads, smallest for analytics-style heavy SQL**.

---

## 2. Syntax — side by side

### Simple CRUD

```php
// rxn-orm
$user = User::find(42);
$user->name = 'Alice';
$user->save();

// Eloquent
$user = User::find(42);
$user->name = 'Alice';
$user->save();
```

**Verdict:** identical. Eloquent set the convention; we follow it.

### Eager loading

```php
// rxn-orm
$users = User::query()->with('posts.comments')->get();

// Eloquent
$users = User::with('posts.comments')->get();
```

**Verdict:** Eloquent saves one method call (`::with` vs `::query()->with`). Tradeoff: rxn-orm's form is explicit about which class you're querying — no `__callStatic` indirection.

### Self-join via column reference

```php
// rxn-orm
$db->table('comments', 'c')
    ->join('posts', 'p.id', '=', 'c.post_id', 'p')
    ->whereColumn('c.user_id', '=', 'p.user_id')
    ->get();

// Eloquent
DB::table('comments AS c')
    ->join('posts AS p', 'p.id', '=', 'c.post_id')
    ->whereColumn('c.user_id', '=', 'p.user_id')
    ->get();
```

**Verdict:** tied. `whereColumn(a, op, b)` ships in both — emits `a op b` with no parameter binding on either side.

### Window function (top-N per group)

```php
// rxn-orm
$inner = $db->table('posts')->select([
    'user_id', 'title', 'votes',
    Raw::of('ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY votes DESC) AS rk'),
]);
$db->query()
    ->select(['user_id', 'title', 'votes'])
    ->from($inner, 'ranked')
    ->where('rk', '<=', 3)
    ->get();

// Eloquent
DB::table('posts')
    ->fromSub(function ($q) {
        $q->select(['user_id', 'title', 'votes'])
          ->selectRaw('ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY votes DESC) AS rk')
          ->from('posts');
    }, 'ranked')
    ->where('rk', '<=', 3)
    ->get();
```

**Verdict:** essentially identical complexity. rxn-orm composes via `Raw::of` inside the SELECT array (one fewer layer); Eloquent's closure-based `fromSub` is more idiomatic Laravel.

### Conditional aggregate dashboard

```php
// rxn-orm
$db->table('sales')->select([
    'region',
    Raw::of("SUM(CASE WHEN product = 'A' THEN amount ELSE 0 END) AS total_a"),
    Raw::of("SUM(CASE WHEN product = 'B' THEN amount ELSE 0 END) AS total_b"),
    Raw::of('SUM(amount) AS total'),
])->groupBy('region')->get();

// Eloquent
DB::table('sales')->selectRaw("region,
    SUM(CASE WHEN product = 'A' THEN amount ELSE 0 END) AS total_a,
    SUM(CASE WHEN product = 'B' THEN amount ELSE 0 END) AS total_b,
    SUM(amount) AS total")->groupBy('region')->get();
```

**Verdict:** Eloquent slightly more compact (one `selectRaw` blob). rxn-orm's per-column `Raw::of` is more navigable for refactoring. Personal preference.

### Recursive CTE

```php
// Both ORMs:
$db->select('WITH RECURSIVE descendants AS (
    SELECT id, parent_id FROM nodes WHERE id = ?
    UNION ALL
    SELECT n.id, n.parent_id FROM nodes n
    INNER JOIN descendants d ON n.parent_id = d.id
) SELECT * FROM descendants', [$rootId]);
```

**Verdict:** identical. Neither ORM exposes recursive CTE syntax fluently. Both honestly delegate to raw SQL with bound params.

### UNION

```php
// rxn-orm
$aQuery->union($bQuery)->orderBy('name')->get();
$aQuery->unionAll($bQuery)->limit(100)->get();

// Eloquent
$aQuery->union($bQuery)->orderBy('name')->get();
```

**Verdict:** tied. Both ORMs have built-in `union()` / `unionAll()`. ORDER BY/LIMIT/OFFSET on the outer query apply to the combined result.

### INSERT ... SELECT

```php
// rxn-orm
(new Insert())->into('archive')
    ->columns(['id', 'title'])
    ->fromQuery($db->table('posts')->select(['id', 'title'])->where('archived', '=', 1))
    ->setConnection($db)
    ->execute();

// Eloquent
DB::table('archive')->insertUsing(['id', 'title'],
    DB::table('posts')->select(['id', 'title'])->where('archived', 1));
```

**Verdict:** tied. Both ORMs ship the SELECT-source insert form.

### Many-to-many: attach / detach / sync

```php
// rxn-orm
$user->attach('roles', $roleId, ['granted_at' => $now]);
$user->detach('roles', $roleId);
$user->sync('roles', [1, 2, 3]);

// Eloquent
$user->roles()->attach($roleId, ['granted_at' => $now]);
$user->roles()->detach($roleId);
$user->roles()->sync([1, 2, 3]);
```

**Verdict:** Eloquent calls these on the *relation object* (`$user->roles()->...`), rxn-orm calls them on the *parent record* with the relation name as a string. Eloquent's chain reads better but requires the relation to be a fluent QueryBuilder under the hood — heavier abstraction.

### Soft deletes / timestamps / casts

```php
// Both ORMs (identical patterns):
class Post extends Record/Model {
    public const TABLE = 'posts';                  // protected $table = 'posts' in Eloquent
    public const CREATED_AT = 'created_at';        // same in Eloquent
    public const UPDATED_AT = 'updated_at';
    public const DELETED_AT = 'deleted_at';        // SoftDeletes trait in Eloquent
    protected static array $casts = ['settings' => 'json'];  // protected $casts in Eloquent
}
```

**Verdict:** functionally identical, modulo `const` (rxn-orm) vs `protected $property` (Eloquent). Eloquent uses a `SoftDeletes` trait for soft-deletion behavior; we just look at the constant.

---

## 3. Complexity

### Lines of code (the entire ORM)

| ORM | Source LOC | Notes |
|---|---:|---|
| rxn-orm | **~3,800** | reads in an afternoon |
| Eloquent (illuminate/database) | ~30,000 | including database, query builder, schema, migrations |
| Doctrine ORM + DBAL | ~85,000 | combined |
| Cycle ORM | ~40,000 | |

### Layers / classes touched on a typical `User::find(42)`

| ORM | Classes traversed | Magic involved? |
|---|---:|---|
| rxn-orm | 4 (Record → ModelQuery → Query → Connection) | None |
| Eloquent | 12+ (Model → Builder → QueryBuilder → Connection → Grammar → Processor → Manager → Container → Facade → Resolver → ...) | `__call`, `__callStatic`, facades |

### Runtime dependencies (production install)

| ORM | Direct deps | Transitive |
|---|---:|---:|
| rxn-orm | 1 (`ext-pdo`) | 0 |
| Eloquent | 13 illuminate/* + others | 25+ |

---

## 4. Flexibility — the killer-query test

A 13-pattern test suite (`tests/Comparison/`) exercises the queries that historically trip up ORMs. Verdicts below.

| Pattern | rxn-orm | Eloquent | Verdict |
|---|---|---|---|
| Recursive CTE | raw SQL via `$db->select()` | raw SQL via `DB::select()` | tied |
| Window function (`ROW_NUMBER`, `RANK`) | `Raw::of()` inside `select([])` | `selectRaw()` | tied — rxn-orm slightly cleaner |
| Conditional aggregate (`SUM(CASE WHEN…)`) | `Raw::of()` per column | `selectRaw('…')` blob | tied; preference |
| Self-join with column comparison | `whereColumn('a', '=', 'b')` | `whereColumn('a', '=', 'b')` | tied |
| Subquery in FROM (derived table) | `from($subquery, 'alias')` | `fromSub($closure, 'alias')` | tied |
| Subquery in WHERE | `whereExists($sub)`, `whereIn($field, $sub)` | `whereExists($closure)`, `whereIn($field, $sub)` | tied |
| Correlated subquery in SELECT | `selectSubquery($sub, 'alias')` | `selectSub($sub, 'alias')` | tied |
| `withCount('relation')` | ✅ built-in | ✅ built-in | tied |
| GROUP BY + HAVING | ✅ built-in | ✅ built-in | tied |
| UNION / UNION ALL | ✅ `union()` / `unionAll()` | ✅ `union()` built-in | tied |
| INSERT … SELECT | ✅ `Insert::fromQuery($q)` | ✅ `insertUsing()` built-in | tied |
| Bulk UPDATE via correlated subquery | `set([col => Raw::of('(SELECT …)')])` | same idiom | tied |
| Bulk UPDATE with JOIN | dialect-specific raw SQL | dialect-specific raw SQL | tied (no portable solution) |
| `WHERE NOT IN (subquery returning NULL)` gotcha | unguarded — same trap | unguarded — same trap | tied (both ORMs let you shoot yourself) |
| NULL-safe equality (`IS NOT DISTINCT FROM`) | raw SQL | raw SQL | tied |
| JSON path (`settings->theme`) | ✅ built-in (driver-aware) | ✅ built-in | tied |
| Portable upsert | ✅ `upsert($keys, $cols)` | ✅ `upsert(...)` | tied |
| `INSERT IGNORE` / `ON CONFLICT DO NOTHING` | ✅ `ignore()` (driver-aware) | ✅ `insertOrIgnore()` | tied |
| `FOR UPDATE` / shared lock | ✅ `lockForUpdate()` / `sharedLock()` (driver-aware) | ✅ same | tied |
| `SKIP LOCKED` (Postgres/MySQL 8.0+) | ✅ `->skipLocked()` chains after the lock | ❌ not built-in; raw SQL | **rxn-orm wins** |
| `NOWAIT` | ✅ `->noWait()` | ❌ not built-in; raw SQL | **rxn-orm wins** |
| `DISTINCT ON` (Postgres) | raw SQL | raw SQL | tied (driver-specific feature) |
| Polymorphic relations | not built-in | `morphTo`, `morphMany` | **Eloquent wins** (deliberate skip on our side) |
| `hasManyThrough` | not built-in | built-in | **Eloquent wins** (deliberate skip) |

**Score:** Eloquent wins on **2** patterns (polymorphic relations, `hasManyThrough`) — both deliberate skips on our side. Every other pattern in this matrix is tied.

UNION, INSERT...SELECT, `whereColumn`, and `FOR UPDATE` / `FOR SHARE` were all gaps in earlier rxn-orm versions; the v0.4 series closed them.

---

## 5. Where rxn-orm shines (non-Eloquent dimensions)

### Static analysis

```php
$user = User::find(42);
$user-> // ←── IDE / PHPStan see User properties; no Larastan needed.
```

rxn-orm's `find/all/first/where/create` are typed `static` via PHP 8 generics. Eloquent's static methods are `__callStatic` proxies — without [Larastan](https://github.com/larastan/larastan), your IDE and PHPStan see `Builder` returns, not `User`.

### Cold-start cost

rxn-orm: zero. Construct a `Connection`, you're done.
Eloquent: requires Capsule + container + service-provider booting (~50ms in real apps). Matters for AWS Lambda, CLI tools, cron scripts, Swoole / RoadRunner workers.

### Fiber / Swoole / AMP safety

rxn-orm: **safe**. Connection holds no static state; one Connection per fiber and you're done.
Eloquent: **hostile** — facades + global container make per-fiber isolation a pain (workarounds exist; none are clean).

### Reading the source

rxn-orm: ~3.8k lines, an afternoon. You can audit every code path.
Eloquent: ~30k lines + dependencies. Tracing a bug from `User::where(...)->get()` involves `__call`, `__callStatic`, `Macroable`, the Container, the EventManager, the Schema-cache, ...

### Cost of the empty-WHERE guard

rxn-orm refuses `Update`/`Delete` with no WHERE by default — must `->allowEmptyWhere()` to opt in. Eloquent does not. We've prevented at least one career-ending mistake per shipped consumer here.

---

## 6. Where rxn-orm loses (be honest)

| Area | Why it matters | Mitigation |
|---|---|---|
| **No production track record** | Pre-1.0; battle-testing takes years. | CI matrix runs MySQL 8 + Postgres 16 + SQLite × PHP 8.1/8.2/8.3. |
| **No polymorphic / through relations** | Some schemas rely on these. | Deliberate skip — usually a code smell; build with two `belongsTo` if needed. |
| **No Schema DSL** | Migrations are raw SQL files. | Deliberate — Schema DSLs are where lightweight ORMs become heavyweight. |
| **No event observers** | Side-effects need to live in `beforeSave`/`afterSave` overrides. | Honest tradeoff: explicit > magical. |

---

## 7. Recommendation matrix

| If you... | Use |
|---|---|
| Build on Laravel | **Eloquent** — first-party integration, the ecosystem expects it. |
| Build a non-Laravel HTTP app (Slim, Mezzio, Symfony minus Doctrine) | **rxn-orm** — cleaner, faster, no facades. |
| Build a CLI / cron / AWS Lambda / Cloudflare Worker | **rxn-orm** — cold-start matters. |
| Build a Swoole / RoadRunner / AMP service | **rxn-orm** — fiber-safe by construction. |
| Need polymorphic relations / heavy schema introspection | **Eloquent** or **Doctrine** — neither is rxn-orm's territory. |
| Want to read your ORM end-to-end | **rxn-orm** (3.8k lines) or **Medoo** (2k, just a builder). |
| Need bulletproof production maturity today | **Eloquent** (the safe pick) or **Doctrine** (battle-tested). |
| Heavy DataMapper-style domain modeling with rich entities | **Doctrine ORM** or **Cycle ORM**. |

---

## Methodology

- **Performance numbers** from `bench/run.php` and `bench/complex.php`, PHP 8.3, in-memory SQLite. Each scenario is reproducible from a clean clone.
- **Killer-query verdicts** from `tests/Comparison/` — every pattern is a passing PHPUnit test that asserts both SQL output *and* execution result. Each test docblock includes the equivalent Eloquent code for direct comparison.
- **LOC counts** via `find src -name '*.php' | xargs wc -l`. For Eloquent, `find vendor/illuminate/database -name '*.php' | xargs wc -l` after a fresh `composer require illuminate/database`.
- **No cherry-picking**: Eloquent's `whereColumn`, `union`, `insertUsing`, `lockForUpdate`, polymorphic relations are real wins listed above and added to the rxn-orm follow-up list.

This document will go out of date. Re-run `bench/run.php` + `bench/complex.php` and verify before quoting.
