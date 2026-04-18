# rxn-orm

[![Latest Version](https://img.shields.io/packagist/v/davidwyly/rxn-orm.svg)](https://packagist.org/packages/davidwyly/rxn-orm)
[![PHP Version](https://img.shields.io/packagist/php-v/davidwyly/rxn-orm.svg)](https://packagist.org/packages/davidwyly/rxn-orm)
[![License](https://img.shields.io/packagist/l/davidwyly/rxn-orm.svg)](LICENSE)

A fluent, dependency-free SQL builder for PHP 8.1+. Produces
`[string $sql, array $bindings]` tuples you can feed to any
PDO connection. No connection pool, no schema layer, no hydration,
no magic — just SQL generation.

Extracted from the [Rxn](https://github.com/davidwyly/rxn) framework
so it can be used on its own.

## Why

- **Composable.** Builders nest. A `Query` can appear inside a
  `WHERE ... IN (...)`, a `FROM (...) AS alias`, or as a
  `SELECT (...) AS col` expression.
- **Safe by default.** Identifiers are escaped; operators are
  whitelisted; `DELETE` and `UPDATE` without a `WHERE` are refused
  unless you opt in explicitly.
- **Driver-aware where it matters.** Upsert for MySQL,
  `RETURNING` for PostgreSQL and SQLite.
- **Small.** ~2k lines of source, zero runtime dependencies beyond
  `ext-pdo`. Tests run in &lt;100ms against in-memory SQLite.

## Install

```
composer require davidwyly/rxn-orm
```

Requires **PHP 8.1+** and **ext-pdo**. Works with MySQL, PostgreSQL,
and SQLite; other PDO drivers will work for anything that doesn't
rely on driver-specific SQL (upsert, `RETURNING`).

## Usage

Every builder implements `Rxn\Orm\Builder\Buildable` and returns
`[string $sql, array $bindings]` from `toSql()`, where `$bindings`
is a positional (`?`) array. Pass the pair to PDO:

```php
[$sql, $bindings] = $builder->toSql();
$stmt = $pdo->prepare($sql);
$stmt->execute($bindings);
```

### SELECT

```php
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Raw;

$q = (new Query())
    ->select(['u.id', 'u.email'])
    ->selectSubquery(
        (new Query())
            ->select([Raw::of('COUNT(*)')])
            ->from('orders')
            ->where('user_id', '=', Raw::of('u.id')),
        'order_count'
    )
    ->from('users', 'u')
    ->leftJoin('roles', 'r.id', '=', 'u.role_id', 'r')
    ->where('u.active', '=', 1)
    ->andWhereIn('u.role_id',
        (new Query())
            ->select(['id'])
            ->from('roles')
            ->where('name', 'LIKE', 'admin%'))
    ->orderBy('u.id', 'DESC')
    ->limit(50);
```

### INSERT (with upsert)

```php
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Raw;

[$sql, $b] = (new Insert())
    ->into('counters')
    ->row(['key' => 'pageviews', 'value' => 1])
    ->onDuplicateKeyUpdate(['value' => Raw::of('value + 1')])
    ->toSql();
```

### UPDATE

```php
use Rxn\Orm\Builder\Update;
use Rxn\Orm\Builder\Raw;

[$sql, $b] = (new Update())
    ->table('users')
    ->set(['role' => 'admin', 'updated_at' => Raw::of('NOW()')])
    ->where('id', '=', 42)
    ->toSql();
```

### DELETE

```php
use Rxn\Orm\Builder\Delete;

[$sql, $b] = (new Delete())
    ->from('users')
    ->where('deleted_at', '<', '2025-01-01')
    ->toSql();
```

A `Delete` or `Update` with no `WHERE` is refused. Opt in with
`->allowEmptyWhere()` when you really mean to wipe a table.

### RETURNING (PostgreSQL / SQLite)

```php
[$sql, $b] = (new Insert())
    ->into('users')
    ->row(['email' => 'a@b.c'])
    ->returning(['id', 'created_at'])
    ->toSql();
```

Available on `Insert`, `Update`, and `Delete`.

### Raw expressions

`Raw::of(...)` bypasses identifier escaping for a single expression.
Use it for aggregates, function calls, and column references inside
correlated subqueries:

```php
Raw::of('COUNT(DISTINCT user_id)')
Raw::of('NOW()')
Raw::of('u.id')   // correlated column from outer query
```

## Feature summary

- SELECT with INNER / LEFT / RIGHT JOIN, multi-column GROUP BY,
  HAVING, ORDER BY, LIMIT, OFFSET.
- WHERE / AND / OR with nested groups via closures.
- Operators: `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `IN`, `NOT IN`,
  `LIKE`, `NOT LIKE`, `BETWEEN`, `REGEXP`, `NOT REGEXP`.
- `whereIsNull`, `whereIsNotNull`, `whereIn`, `whereNotIn` (array or
  subquery).
- Subqueries in `WHERE`, `FROM`, and `SELECT` positions.
- `Insert::onDuplicateKeyUpdate` (MySQL upsert).
- `returning(...)` on Insert / Update / Delete.
- `Raw::of(...)` escape hatch.
- Empty-WHERE guard on destructive statements.

## Non-goals

- Connection management, transactions, migrations, schema
  introspection, or result hydration. Pair this with whatever PDO
  wrapper you prefer.
- A full ActiveRecord / DataMapper ORM. This is a builder.
- Query optimization. The SQL you build is the SQL you get.

## Testing

```
composer install
vendor/bin/phpunit
```

68 tests, 132 assertions. Runs against an in-memory SQLite PDO; no
external database required.

## Versioning

Semantic versioning. Pre-1.0 releases may still make breaking
changes; pin to a minor range (`^0.1`) until 1.0.

## Contributing

Issues and pull requests welcome at
<https://github.com/davidwyly/rxn-orm>.

## License

MIT &copy; David Wyly. See [LICENSE](LICENSE).
