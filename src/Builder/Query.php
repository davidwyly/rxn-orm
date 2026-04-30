<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Builder;
use Rxn\Orm\Builder\Query\From;
use Rxn\Orm\Builder\Query\Join;
use Rxn\Orm\Builder\Query\Select;

/**
 * Fluent SELECT query builder. Accumulates commands into
 * $this->commands and bindings into $this->bindings; call toSql()
 * to materialize the final (string $sql, array $bindings) tuple,
 * or pass the Query to Database::run() to execute in one call.
 */
class Query extends Builder implements Buildable
{
    use HasWhere;
    use HasConnection;

    /** Row-level lock to apply: 'update', 'shared', or null. */
    private ?string $lock = null;

    /**
     * Queued UNION / UNION ALL clauses, in order. Each entry is
     *   ['kind' => 'distinct'|'all', 'sql' => string, 'bindings' => array]
     * The base query's WHERE/GROUP BY/HAVING apply to the first
     * SELECT only; ORDER BY/LIMIT/OFFSET apply to the COMBINED
     * result (each leaf gets wrapped in parens at toSql time).
     *
     * @var array<int, array{kind: string, sql: string, bindings: array}>
     */
    private array $unions = [];

    /**
     * Set the SELECT column list. Calling with explicit columns
     * REPLACES any prior selection — this matches user intent when
     * coming off `Connection::table()` (which seeds `SELECT *`) and
     * matches Eloquent's behavior. To append columns to an existing
     * selection use `addSelect()` or `selectSubquery()`.
     */
    public function select(array $columns = ['*'], bool $distinct = false): Query
    {
        if ($columns !== ['*']) {
            unset($this->commands['SELECT'], $this->commands['SELECT DISTINCT']);
        }
        $select = new Select();
        $select->set($columns, $distinct);
        $this->loadCommands($select);
        return $this;
    }

    /**
     * Append columns to the existing SELECT list (additive). Use this
     * when you want to keep prior columns and add more — e.g. when
     * stitching together a query in pieces.
     */
    public function addSelect(array $columns, bool $distinct = false): Query
    {
        $select = new Select();
        $select->set($columns, $distinct);
        $this->loadCommands($select);
        return $this;
    }

    /**
     * Append a subquery as a SELECT column.
     *
     *   $sub = (new Query())->select([Raw::of('COUNT(*)')])
     *       ->from('orders')
     *       ->where('user_id', '=', Raw::of('u.id'));
     *   $q->select(['u.id'])->selectSubquery($sub, 'order_count')->from('users', 'u');
     *
     * Bindings from the subquery are merged into the outer Query's
     * binding list at call time, so invoke selectSubquery before
     * methods whose placeholders appear later in the resulting SQL
     * (typically: before where() / groupBy() / etc).
     */
    public function selectSubquery(Buildable $subquery, string $alias): Query
    {
        if ($alias === '') {
            throw new \InvalidArgumentException('selectSubquery requires a non-empty alias');
        }
        [$sub_sql, $sub_bindings] = $subquery->toSql();
        $command = isset($this->commands['SELECT DISTINCT']) ? 'SELECT DISTINCT' : 'SELECT';
        $this->commands[$command][] = '(' . $sub_sql . ') AS `' . trim($alias, '`') . '`';
        foreach ($sub_bindings as $b) {
            $this->bindings[] = $b;
        }
        return $this;
    }

    /**
     * @param string|Buildable $table bare identifier, or a nested
     *                                Buildable whose SQL becomes `(subquery) AS alias`.
     */
    public function from($table, ?string $alias = null): Query
    {
        if ($table instanceof Buildable) {
            if ($alias === null || $alias === '') {
                throw new \InvalidArgumentException('FROM subquery requires an alias');
            }
            [$sub_sql, $sub_bindings] = $table->toSql();
            $this->commands['FROM'][] = '(' . $sub_sql . ') AS `' . trim($alias, '`') . '`';
            foreach ($sub_bindings as $b) {
                $this->bindings[] = $b;
            }
            return $this;
        }
        $from = new From();
        $from->set($table, $alias);
        $this->loadCommands($from);
        $this->loadTableAliases($from);
        return $this;
    }

    public function joinCustom(string $table, callable $callable, ?string $alias = null, string $type = 'inner'): Query
    {
        $join = new Join();
        $join->set($table, $callable, $alias, $type);
        $this->loadCommands($join);
        $this->loadBindings($join);
        $this->loadTableAliases($join);
        return $this;
    }

    public function join(string $table, string $first_operand, string $operator, $second_operand, ?string $alias = null): Query
    {
        return $this->innerJoin($table, $first_operand, $operator, $second_operand, $alias);
    }

    public function innerJoin(string $table, string $first_operand, string $operator, $second_operand, ?string $alias = null): Query
    {
        return $this->simpleJoin('inner', $table, $first_operand, $operator, $second_operand, $alias);
    }

    public function leftJoin(string $table, string $first_operand, string $operator, $second_operand, ?string $alias = null): Query
    {
        return $this->simpleJoin('left', $table, $first_operand, $operator, $second_operand, $alias);
    }

    public function rightJoin(string $table, string $first_operand, string $operator, $second_operand, ?string $alias = null): Query
    {
        return $this->simpleJoin('right', $table, $first_operand, $operator, $second_operand, $alias);
    }

    private function simpleJoin(string $type, string $table, string $first_operand, string $operator, $second_operand, ?string $alias): Query
    {
        return $this->joinCustom($table, function (Join $join) use ($first_operand, $operator, $second_operand, $alias) {
            if (!empty($alias)) {
                $join->as($alias);
            }
            $join->on($first_operand, $operator, $second_operand);
        }, $alias, $type);
    }

    public function whereId($id, string $id_key = 'id'): Query
    {
        return $this->where($id_key, '=', $id);
    }

    /**
     * @param string|Raw ...$fields
     */
    public function groupBy(...$fields): Query
    {
        foreach ($fields as $field) {
            $this->commands['GROUP BY'][] = $this->cleanReference($field);
        }
        return $this;
    }

    public function having(string $expression): Query
    {
        $this->commands['HAVING'][] = $expression;
        return $this;
    }

    /**
     * @param string|Raw $field
     */
    public function orderBy($field, string $direction = 'ASC'): Query
    {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new \InvalidArgumentException("orderBy direction must be ASC or DESC, got '$direction'");
        }
        $this->commands['ORDER BY'][] = $this->cleanReference($field) . ' ' . $direction;
        return $this;
    }

    public function limit(int $count): Query
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('limit must be non-negative');
        }
        $this->commands['LIMIT'] = [$count];
        return $this;
    }

    public function offset(int $count): Query
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('offset must be non-negative');
        }
        $this->commands['OFFSET'] = [$count];
        return $this;
    }

    /**
     * Append a UNION (DISTINCT) of $other to this query. Both queries
     * must have the same number of result columns in the same order.
     * Bindings from $other merge into the combined query's binding
     * stream in order.
     *
     * ORDER BY / LIMIT / OFFSET on the outer query apply to the
     * COMBINED result — each side gets wrapped in parens, then the
     * outer clauses appear at the end.
     */
    public function union(Buildable $other): Query
    {
        return $this->addUnion($other, 'distinct');
    }

    /** Same as union() but uses UNION ALL — duplicates preserved. */
    public function unionAll(Buildable $other): Query
    {
        return $this->addUnion($other, 'all');
    }

    private function addUnion(Buildable $other, string $kind): Query
    {
        [$sql, $bindings] = $other->toSql();
        $this->unions[] = ['kind' => $kind, 'sql' => $sql, 'bindings' => $bindings];
        return $this;
    }

    /**
     * Acquire a row-level write lock. Driver-aware:
     *   - MySQL/MariaDB: `FOR UPDATE`
     *   - Postgres:      `FOR UPDATE`
     *   - SQLite:        no-op (no row-level locking concept)
     *
     * Only meaningful inside a transaction. Outside a transaction
     * the lock is released the moment the statement completes.
     */
    public function lockForUpdate(): Query
    {
        $this->lock = 'update';
        return $this;
    }

    /**
     * Acquire a row-level read (shared) lock. Driver-aware:
     *   - MySQL:    `LOCK IN SHARE MODE`
     *   - MariaDB:  `LOCK IN SHARE MODE`
     *   - Postgres: `FOR SHARE`
     *   - SQLite:   no-op
     */
    public function sharedLock(): Query
    {
        $this->lock = 'shared';
        return $this;
    }

    /**
     * Materialize the builder state into a single SQL string +
     * positional-bindings array.
     *
     * @return array{0: string, 1: array}
     */
    public function toSql(): array
    {
        if ($this->unions !== []) {
            return $this->toSqlWithUnions();
        }
        $parser = new QueryParser($this);
        $sql    = $parser->getSql();
        if ($this->lock !== null) {
            $sql .= $this->renderLockClause();
        }
        return [$sql, array_values($this->bindings)];
    }

    /**
     * Render the UNION form: `(base) UNION (other) ... ORDER BY ... LIMIT ...`.
     * The base's ORDER BY / LIMIT / OFFSET are stripped (so they don't
     * accidentally apply only to the first SELECT) and re-emitted at
     * the end against the combined result. Locks are attached to the
     * combined statement, not the base.
     *
     * @return array{0: string, 1: array}
     */
    private function toSqlWithUnions(): array
    {
        // Render the base WITHOUT outer-result clauses so they don't
        // accidentally bind only to the first SELECT.
        $baseClone = clone $this;
        $baseClone->unions = [];
        $baseClone->lock   = null;
        unset(
            $baseClone->commands['ORDER BY'],
            $baseClone->commands['LIMIT'],
            $baseClone->commands['OFFSET'],
        );
        [$baseSql, $baseBindings] = $baseClone->toSql();

        // No parens around each side — SQLite rejects them; MySQL and
        // Postgres accept both forms. Sub-queries with their own
        // ORDER BY/LIMIT aren't valid in a UNION leaf per SQL standard
        // anyway; users who need that should compose via from(subquery).
        $parts    = [$baseSql];
        $bindings = $baseBindings;
        foreach ($this->unions as $u) {
            $glue   = $u['kind'] === 'all' ? 'UNION ALL' : 'UNION';
            $parts[] = $glue . ' ' . $u['sql'];
            foreach ($u['bindings'] as $b) {
                $bindings[] = $b;
            }
        }
        $sql = implode(' ', $parts);

        // Outer clauses against the combined result.
        if (!empty($this->commands['ORDER BY'])) {
            $sql .= ' ORDER BY ' . implode(', ', $this->commands['ORDER BY']);
        }
        if (!empty($this->commands['LIMIT'])) {
            $sql .= ' LIMIT ' . $this->commands['LIMIT'][0];
        }
        if (!empty($this->commands['OFFSET'])) {
            $sql .= ' OFFSET ' . $this->commands['OFFSET'][0];
        }
        if ($this->lock !== null) {
            $sql .= $this->renderLockClause();
        }
        return [$sql, $bindings];
    }

    /**
     * Resolve the driver-specific row-locking suffix. Silent no-op
     * for SQLite or when no Connection is attached — locking outside
     * a known driver is meaningless.
     */
    private function renderLockClause(): string
    {
        $driver = $this->connection?->getDriver();
        return match ([$driver, $this->lock]) {
            ['mysql',   'update'] => ' FOR UPDATE',
            ['mariadb', 'update'] => ' FOR UPDATE',
            ['pgsql',   'update'] => ' FOR UPDATE',
            ['mysql',   'shared'] => ' LOCK IN SHARE MODE',
            ['mariadb', 'shared'] => ' LOCK IN SHARE MODE',
            ['pgsql',   'shared'] => ' FOR SHARE',
            default               => '', // sqlite + unknown drivers
        };
    }

    // -- terminal methods (require an attached Connection) -------------

    /**
     * Execute and return all matching rows as associative arrays.
     * Subclasses (notably ModelQuery) may narrow the element type to
     * a hydrated object — the loose `array` return is intentional.
     *
     * @return array<int, mixed>
     */
    public function get(): array
    {
        return $this->requireConnection(__FUNCTION__)->select($this);
    }

    /**
     * Execute and return the first matching row, or null. Returns
     * `array<string, mixed>|null` from a plain Query; subclasses
     * (e.g. ModelQuery) may return hydrated objects, hence the loose
     * `mixed` return type — the array shape is the docblock contract.
     *
     * @return array<string, mixed>|null
     */
    public function first(): mixed
    {
        $clone = clone $this;
        $clone->limit(1);
        $rows = $clone->requireConnection(__FUNCTION__)->select($clone);
        return $rows[0] ?? null;
    }

    /**
     * Find a row by primary key. Same return-type caveat as first().
     *
     * @return array<string, mixed>|null
     */
    public function find(mixed $id, string $pk = 'id'): mixed
    {
        $clone = clone $this;
        $clone->where($pk, '=', $id)->limit(1);
        $rows = $clone->requireConnection(__FUNCTION__)->select($clone);
        return $rows[0] ?? null;
    }

    /**
     * Execute and return the value of a single column from the first
     * matching row.
     */
    public function value(string $column): mixed
    {
        $row = $this->first();
        return $row === null ? null : ($row[$column] ?? null);
    }

    /**
     * Execute and return a column from every matching row, optionally
     * keyed by another column.
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        return $this->requireConnection(__FUNCTION__)->pluck($this, $column, $key);
    }

    public function exists(): bool
    {
        return $this->requireConnection(__FUNCTION__)->exists($this);
    }

    public function count(string $column = '*'): int
    {
        return $this->requireConnection(__FUNCTION__)->count($this, $column);
    }

    /**
     * Run two queries: a count for the total, and a windowed SELECT
     * for the page. Returns
     *   ['data' => array, 'total' => int, 'page' => int,
     *    'perPage' => int, 'lastPage' => int]
     *
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int, lastPage: int}
     */
    public function paginate(int $perPage, int $page = 1): array
    {
        if ($perPage < 1) {
            throw new \InvalidArgumentException('perPage must be >= 1');
        }
        if ($page < 1) {
            throw new \InvalidArgumentException('page must be >= 1');
        }
        $connection = $this->requireConnection(__FUNCTION__);
        $total = $connection->count($this);
        $clone = clone $this;
        $clone->limit($perPage)->offset(($page - 1) * $perPage);
        return [
            'data'     => $connection->select($clone),
            'total'    => $total,
            'page'     => $page,
            'perPage'  => $perPage,
            'lastPage' => (int)max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Process the result set in fixed-size chunks. Re-runs the query
     * each iteration with an increasing OFFSET. Return false from
     * $callback to stop early.
     *
     * @param callable(array<int, array<string, mixed>>): mixed $callback
     */
    public function chunk(int $size, callable $callback): void
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('chunk size must be >= 1');
        }
        $this->requireConnection(__FUNCTION__);
        $page = 1;
        do {
            $clone = clone $this;
            $clone->limit($size)->offset(($page - 1) * $size);
            $rows = $clone->get();
            if ($rows === []) {
                return;
            }
            if ($callback($rows) === false) {
                return;
            }
            $page++;
        } while (count($rows) === $size);
    }

    /**
     * Stream rows one at a time via a generator. Uses a single
     * unbuffered statement; suitable for large result sets where
     * loading everything into memory would be wasteful.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(): \Generator
    {
        $connection = $this->requireConnection(__FUNCTION__);
        [$sql, $bindings] = $this->toSql();
        $stmt = $connection->selectStatement($sql, $bindings);
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            yield $row;
        }
    }
}
