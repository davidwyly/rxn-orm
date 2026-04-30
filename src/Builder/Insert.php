<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;

/**
 * Fluent INSERT builder. Supports single-row and multi-row inserts;
 * the column list is the union of every row's keys (first-seen
 * order), and rows that omit a column are bound as null so callers
 * don't have to pre-normalise heterogeneous inputs.
 *
 *   [$sql, $bindings] = (new Insert())
 *       ->into('users')
 *       ->row(['email' => 'a@example.com', 'role' => 'admin'])
 *       ->row(['email' => 'b@example.com', 'role' => 'member'])
 *       ->toSql();
 *   // INSERT INTO `users` (`email`, `role`) VALUES (?, ?), (?, ?)
 *
 *   $database->run($insert);
 *
 * Raw values in a row emit verbatim without a placeholder:
 *   $insert->row(['email' => 'x', 'created_at' => Raw::of('NOW()')]);
 */
final class Insert extends Builder implements Buildable
{
    use HasConnection;

    private ?string $table = null;

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    /** @var array<string, mixed>|null */
    private ?array $on_duplicate = null;

    /** @var array{0: array<int, string>, 1: array<int|string, mixed>}|null portable upsert config: [uniqueKeys, updates] */
    private ?array $upsert = null;

    /** True when insertOrIgnore was requested. */
    private bool $ignore = false;

    /** Source SELECT for INSERT...SELECT (mutually exclusive with row()/rows()). */
    private ?Buildable $sourceQuery = null;

    /** Target column list for INSERT...SELECT. */
    private array $sourceColumns = [];

    /** @var string[] */
    private array $returning = [];

    public function into(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * MySQL upsert: follow the INSERT with
     *   ON DUPLICATE KEY UPDATE col = val, ...
     * Values participate in the bindings stream after the row
     * placeholders. Raw values (e.g. Raw::of('VALUES(col)')) emit
     * verbatim, matching the INSERT helper API.
     *
     * @param array<string, mixed> $assignments
     */
    public function onDuplicateKeyUpdate(array $assignments): self
    {
        if ($assignments === []) {
            throw new \InvalidArgumentException('onDuplicateKeyUpdate requires at least one assignment');
        }
        $this->on_duplicate = $assignments;
        return $this;
    }

    /**
     * Portable upsert. Emits the right syntax for the attached
     * Connection's driver:
     *   - MySQL:    INSERT ... ON DUPLICATE KEY UPDATE col = VALUES(col)
     *   - Postgres: INSERT ... ON CONFLICT (uniqueKeys) DO UPDATE SET col = EXCLUDED.col
     *   - SQLite:   INSERT ... ON CONFLICT (uniqueKeys) DO UPDATE SET col = excluded.col
     *
     * `$updateColumns` accepts two shapes (and a mix):
     *   ['value', 'updated_at']                           // set to incoming value
     *   ['value' => Raw::of('counters.value + 1')]        // explicit expression
     *
     * `$uniqueKeys` is required for Postgres / SQLite. MySQL ignores
     * it (relying on declared UNIQUE indexes) but you should still
     * pass the columns for portability.
     *
     * @param string[] $uniqueKeys columns the conflict is keyed on
     * @param array<int|string, mixed> $updateColumns
     */
    public function upsert(array $uniqueKeys, array $updateColumns): self
    {
        if ($uniqueKeys === []) {
            throw new \InvalidArgumentException('upsert requires at least one unique key column');
        }
        if ($updateColumns === []) {
            throw new \InvalidArgumentException('upsert requires at least one update column');
        }
        if ($this->on_duplicate !== null) {
            throw new \LogicException('upsert() and onDuplicateKeyUpdate() are mutually exclusive');
        }
        if ($this->ignore) {
            throw new \LogicException('upsert() and insertOrIgnore() / ignore() are mutually exclusive');
        }
        $this->upsert = [array_values($uniqueKeys), $updateColumns];
        return $this;
    }

    /**
     * Skip rows that violate a unique constraint instead of raising
     * an error. Driver-aware:
     *   - MySQL:    INSERT IGNORE INTO ...
     *   - Postgres: INSERT INTO ... ON CONFLICT DO NOTHING
     *   - SQLite:   INSERT INTO ... ON CONFLICT DO NOTHING
     *
     * Mutually exclusive with upsert() and onDuplicateKeyUpdate().
     */
    public function ignore(): self
    {
        if ($this->upsert !== null || $this->on_duplicate !== null) {
            throw new \LogicException('ignore() is mutually exclusive with upsert() / onDuplicateKeyUpdate()');
        }
        $this->ignore = true;
        return $this;
    }

    /**
     * Append a RETURNING clause (PostgreSQL / SQLite). MySQL will
     * reject the statement; callers are responsible for knowing
     * their driver supports it.
     *
     * @param string|Raw ...$columns
     */
    public function returning(...$columns): self
    {
        foreach ($columns as $col) {
            $this->returning[] = $col instanceof Raw ? $col->sql : '`' . trim((string)$col, '`') . '`';
        }
        return $this;
    }

    public function hasReturning(): bool
    {
        return $this->returning !== [];
    }

    /**
     * Run this INSERT against the attached Connection. Returns
     * RETURNING rows when returning() was used, otherwise the
     * affected-row count from PDO.
     *
     * @return int|array<int, array<string, mixed>>
     */
    public function execute(): int|array
    {
        return $this->requireConnection(__FUNCTION__)->insert($this);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function row(array $row): self
    {
        if ($row === []) {
            throw new \InvalidArgumentException('Insert::row requires a non-empty [column => value] map');
        }
        if ($this->sourceQuery !== null) {
            throw new \LogicException('row()/rows() and fromQuery() are mutually exclusive');
        }
        $this->rows[] = $row;
        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function rows(array $rows): self
    {
        foreach ($rows as $row) {
            $this->row($row);
        }
        return $this;
    }

    /**
     * INSERT INTO target (cols) SELECT ... FROM source.
     *
     *   $archive = (new Insert())
     *       ->into('archive_posts')
     *       ->columns(['id', 'title'])
     *       ->fromQuery(
     *           $db->table('posts')->select(['id', 'title'])->where('archived', '=', 1)
     *       );
     *   $archive->setConnection($db)->execute();
     *
     * Mutually exclusive with row() / rows(). The SELECT must return
     * the same number of columns as `columns()` declares, in the
     * same order — that's a SQL constraint, not enforced here.
     */
    public function fromQuery(Buildable $source): self
    {
        if ($this->rows !== []) {
            throw new \LogicException('fromQuery() and row()/rows() are mutually exclusive');
        }
        $this->sourceQuery = $source;
        return $this;
    }

    /**
     * Declare the target column list for INSERT...SELECT. Required
     * when using fromQuery() so the emitted SQL is unambiguous about
     * which columns the SELECT result populates.
     *
     * @param string[] $columns
     */
    public function columns(array $columns): self
    {
        if ($columns === []) {
            throw new \InvalidArgumentException('columns() requires at least one column');
        }
        $this->sourceColumns = array_values($columns);
        return $this;
    }

    public function toSql(): array
    {
        if ($this->table === null) {
            throw new \LogicException('Insert::into must be called before toSql');
        }

        // INSERT...SELECT path
        if ($this->sourceQuery !== null) {
            return $this->renderInsertSelect();
        }

        if ($this->rows === []) {
            throw new \LogicException('Insert requires at least one row (or a fromQuery)');
        }

        // Union of column names across every row, first-seen order.
        $columns = [];
        foreach ($this->rows as $row) {
            foreach ($row as $col => $_) {
                if (!in_array($col, $columns, true)) {
                    $columns[] = $col;
                }
            }
        }

        $escapedTable   = '`' . trim($this->table, '`') . '`';
        $escapedColumns = array_map(fn ($c) => '`' . trim((string)$c, '`') . '`', $columns);

        $valueRows = [];
        $bindings  = [];
        foreach ($this->rows as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                if ($value instanceof Raw) {
                    $placeholders[] = $value->sql;
                    continue;
                }
                $placeholders[] = '?';
                $bindings[]     = $value;
            }
            $valueRows[] = '(' . implode(', ', $placeholders) . ')';
        }

        $sql = 'INSERT INTO ' . $escapedTable
             . ' (' . implode(', ', $escapedColumns) . ')'
             . ' VALUES ' . implode(', ', $valueRows);

        if ($this->on_duplicate !== null) {
            $assignments = [];
            foreach ($this->on_duplicate as $col => $value) {
                $escapedCol = '`' . trim((string)$col, '`') . '`';
                if ($value instanceof Raw) {
                    $assignments[] = "$escapedCol = " . $value->sql;
                    continue;
                }
                $assignments[] = "$escapedCol = ?";
                $bindings[]    = $value;
            }
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
        }

        if ($this->upsert !== null) {
            [$upsertSql, $upsertBindings] = $this->renderUpsert();
            $sql .= ' ' . $upsertSql;
            foreach ($upsertBindings as $b) {
                $bindings[] = $b;
            }
        }

        if ($this->ignore) {
            $sql = $this->applyIgnore($sql);
        }

        if ($this->returning !== []) {
            $sql .= ' RETURNING ' . implode(', ', $this->returning);
        }

        return [$sql, $bindings];
    }

    /**
     * Driver-specific handling for the ignore-conflicts request:
     * MySQL needs `INSERT IGNORE INTO`; Postgres/SQLite append
     * `ON CONFLICT DO NOTHING`.
     */
    private function applyIgnore(string $sql): string
    {
        if ($this->connection === null) {
            throw new \LogicException(
                'ignore() requires an attached Connection so the right driver syntax can be emitted. ' .
                'Call setConnection($db) before toSql().',
            );
        }
        $driver = $this->connection->getDriver();
        return match ($driver) {
            'mysql', 'mariadb' => preg_replace('/^INSERT INTO/', 'INSERT IGNORE INTO', $sql, 1),
            'pgsql', 'sqlite'  => $sql . ' ON CONFLICT DO NOTHING',
            default            => throw new \LogicException(
                "ignore() has no portable form for driver '$driver'.",
            ),
        };
    }

    /**
     * Build the driver-specific upsert tail: ON DUPLICATE KEY UPDATE
     * for MySQL, ON CONFLICT (...) DO UPDATE SET for Postgres/SQLite.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function renderUpsert(): array
    {
        if ($this->connection === null) {
            throw new \LogicException(
                'upsert() requires an attached Connection so the right driver syntax can be emitted. ' .
                'Call setConnection($db) before toSql(), or use the MySQL-only onDuplicateKeyUpdate() instead.',
            );
        }
        $driver = $this->connection->getDriver();
        return match ($driver) {
            'mysql', 'mariadb' => $this->renderUpsertMysql(),
            'pgsql'            => $this->renderUpsertOnConflict('EXCLUDED'),
            'sqlite'           => $this->renderUpsertOnConflict('excluded'),
            default            => throw new \LogicException(
                "upsert() has no portable form for driver '$driver'. " .
                'Use Insert::onDuplicateKeyUpdate() (MySQL) or build the SQL yourself.',
            ),
        };
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function renderUpsertMysql(): array
    {
        [$_uniqueKeys, $updateColumns] = $this->upsert;
        $assignments = [];
        $bindings    = [];
        foreach ($updateColumns as $key => $value) {
            if (is_int($key)) {
                $col = (string)$value;
                $escaped = '`' . trim($col, '`') . '`';
                $assignments[] = "$escaped = VALUES($escaped)";
                continue;
            }
            $escaped = '`' . trim($key, '`') . '`';
            if ($value instanceof Raw) {
                $assignments[] = "$escaped = " . $value->sql;
                continue;
            }
            $assignments[] = "$escaped = ?";
            $bindings[]    = $value;
        }
        return ['ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments), $bindings];
    }

    /**
     * Postgres + SQLite share `ON CONFLICT (...) DO UPDATE SET`. The
     * pseudo-table name differs in case (`EXCLUDED` vs `excluded`)
     * but is otherwise identical.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function renderUpsertOnConflict(string $excludedAlias): array
    {
        [$uniqueKeys, $updateColumns] = $this->upsert;
        $escapedKeys = array_map(fn ($k) => '`' . trim((string)$k, '`') . '`', $uniqueKeys);

        $assignments = [];
        $bindings    = [];
        foreach ($updateColumns as $key => $value) {
            if (is_int($key)) {
                $col = (string)$value;
                $escaped = '`' . trim($col, '`') . '`';
                $assignments[] = "$escaped = $excludedAlias.$escaped";
                continue;
            }
            $escaped = '`' . trim($key, '`') . '`';
            if ($value instanceof Raw) {
                $assignments[] = "$escaped = " . $value->sql;
                continue;
            }
            $assignments[] = "$escaped = ?";
            $bindings[]    = $value;
        }
        $sql = 'ON CONFLICT (' . implode(', ', $escapedKeys) . ') DO UPDATE SET '
             . implode(', ', $assignments);
        return [$sql, $bindings];
    }

    /**
     * Emit `INSERT INTO target (cols) <selectSql>` for the
     * fromQuery() / columns() form. Bindings come from the source
     * SELECT — we don't add any of our own.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function renderInsertSelect(): array
    {
        if ($this->sourceColumns === []) {
            throw new \LogicException(
                'fromQuery() requires columns() to be set so the target column list is explicit',
            );
        }
        $escapedTable   = '`' . trim((string)$this->table, '`') . '`';
        $escapedColumns = array_map(
            fn (string $c) => '`' . trim($c, '`') . '`',
            $this->sourceColumns,
        );
        [$selectSql, $bindings] = $this->sourceQuery->toSql();

        $sql = 'INSERT INTO ' . $escapedTable
             . ' (' . implode(', ', $escapedColumns) . ')'
             . ' ' . $selectSql;

        if ($this->ignore) {
            $sql = $this->applyIgnore($sql);
        }
        if ($this->returning !== []) {
            $sql .= ' RETURNING ' . implode(', ', $this->returning);
        }
        return [$sql, $bindings];
    }
}
