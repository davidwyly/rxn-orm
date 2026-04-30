<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

/**
 * Shared WHERE-clause API for Query, Update, and Delete.
 *
 * Stores conditions on $this->commands['WHERE'] as an ordered list
 * of ['op' => AND|OR, 'expr' => string] / ['op', 'group' => entries]
 * entries, and accumulates placeholders into $this->bindings.
 * Consumers either pass themselves to `QueryParser` (which emits the
 * WHERE clause) or implement equivalent rendering.
 */
trait HasWhere
{
    public const WHERE_OPERATORS = [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'IN', 'NOT IN', 'LIKE', 'NOT LIKE',
        'BETWEEN', 'REGEXP', 'NOT REGEXP',
    ];

    /**
     * @param mixed $value
     * @return static
     */
    public function where(string $field, string $operator, $value, ?callable $callback = null, string $type = 'and')
    {
        $this->assertWhereOperator($operator);
        if ($callback !== null) {
            $this->addGroup($type, $field, $operator, $value, $callback);
            return $this;
        }
        $expr = $this->buildCondition($field, $operator, $value);
        $this->commands['WHERE'][] = ['op' => $this->normalizeOp($type), 'expr' => $expr];
        return $this;
    }

    /**
     * @param mixed $value
     * @return static
     */
    public function andWhere(string $field, string $operator, $value, ?callable $callback = null)
    {
        return $this->where($field, $operator, $value, $callback, 'and');
    }

    /**
     * @param mixed $value
     * @return static
     */
    public function and(string $field, string $operator, $value, ?callable $callback = null)
    {
        return $this->andWhere($field, $operator, $value, $callback);
    }

    /**
     * @param mixed $value
     * @return static
     */
    public function orWhere(string $field, string $operator, $value, ?callable $callback = null)
    {
        return $this->where($field, $operator, $value, $callback, 'or');
    }

    /**
     * @param mixed $value
     * @return static
     */
    public function or(string $field, string $operator, $value, ?callable $callback = null)
    {
        return $this->orWhere($field, $operator, $value, $callback);
    }

    /**
     * @param array<int, mixed>|Buildable $values literal list, or a
     *                                            Buildable subquery the engine evaluates as the IN-list.
     * @return static
     */
    public function whereIn(string $field, array|Buildable $values, string $type = 'and')
    {
        return $this->where($field, 'IN', $values, null, $type);
    }

    /**
     * @param array<int, mixed>|Buildable $values
     * @return static
     */
    public function whereNotIn(string $field, array|Buildable $values, string $type = 'and')
    {
        return $this->where($field, 'NOT IN', $values, null, $type);
    }

    /** @param array<int, mixed>|Buildable $v @return static */
    public function andWhereIn(string $f, array|Buildable $v): static
    {
        return $this->whereIn($f, $v, 'and');
    }
    /** @param array<int, mixed>|Buildable $v @return static */
    public function andWhereNotIn(string $f, array|Buildable $v): static
    {
        return $this->whereNotIn($f, $v, 'and');
    }
    /** @param array<int, mixed>|Buildable $v @return static */
    public function orWhereIn(string $f, array|Buildable $v): static
    {
        return $this->whereIn($f, $v, 'or');
    }
    /** @param array<int, mixed>|Buildable $v @return static */
    public function orWhereNotIn(string $f, array|Buildable $v): static
    {
        return $this->whereNotIn($f, $v, 'or');
    }

    /** @return static */
    public function whereIsNull(string $field, string $type = 'and')
    {
        $this->commands['WHERE'][] = ['op' => $this->normalizeOp($type), 'expr' => $this->cleanReference($field) . ' IS NULL'];
        return $this;
    }

    /** @return static */
    public function whereIsNotNull(string $field, string $type = 'and')
    {
        $this->commands['WHERE'][] = ['op' => $this->normalizeOp($type), 'expr' => $this->cleanReference($field) . ' IS NOT NULL'];
        return $this;
    }

    /** @return static */ public function andWhereIsNull(string $f)
    {
        return $this->whereIsNull($f, 'and');
    }
    /** @return static */ public function andWhereIsNotNull(string $f)
    {
        return $this->whereIsNotNull($f, 'and');
    }
    /** @return static */ public function orWhereIsNull(string $f)
    {
        return $this->whereIsNull($f, 'or');
    }
    /** @return static */ public function orWhereIsNotNull(string $f)
    {
        return $this->whereIsNotNull($f, 'or');
    }

    /**
     * Append `EXISTS (subquery)`. Useful for correlated subqueries
     * where you want to filter parent rows by the existence of any
     * matching child row, without joining and de-duplicating.
     *
     *   $q->where('u.active', '=', 1)->whereExists(
     *       (new Query())->select([Raw::of('1')])
     *           ->from('orders', 'o')
     *           ->where('o.user_id', '=', Raw::of('u.id'))
     *   );
     *
     * @return static
     */
    public function whereExists(Buildable $subquery, string $type = 'and')
    {
        return $this->appendExists($subquery, false, $type);
    }

    /** @return static */
    public function whereNotExists(Buildable $subquery, string $type = 'and')
    {
        return $this->appendExists($subquery, true, $type);
    }

    /** @return static */ public function andWhereExists(Buildable $sub)
    {
        return $this->whereExists($sub, 'and');
    }
    /** @return static */ public function andWhereNotExists(Buildable $sub)
    {
        return $this->whereNotExists($sub, 'and');
    }
    /** @return static */ public function orWhereExists(Buildable $sub)
    {
        return $this->whereExists($sub, 'or');
    }
    /** @return static */ public function orWhereNotExists(Buildable $sub)
    {
        return $this->whereNotExists($sub, 'or');
    }

    /**
     * Compare just the date portion of a column. Emits
     * `DATE(field) op ?`, which is portable across MySQL, Postgres,
     * and SQLite — all three implement DATE() identically.
     *
     * (whereYear / whereMonth aren't shipped because their portable
     * forms diverge: MySQL uses YEAR(), Postgres uses EXTRACT(YEAR ...),
     * SQLite uses STRFTIME. Use Raw::of(...) for those cases.)
     *
     * @return static
     */
    public function whereDate(string $field, string $operator, mixed $value, string $type = 'and')
    {
        $this->assertWhereOperator($operator);
        $expr = 'DATE(' . $this->cleanReference($field) . ') ' . strtoupper($operator) . ' ?';
        $this->bindings[] = $value;
        $this->commands['WHERE'][] = [
            'op'   => $this->normalizeOp($type),
            'expr' => $expr,
        ];
        return $this;
    }

    /** @return static */ public function andWhereDate(string $f, string $op, mixed $v)
    {
        return $this->whereDate($f, $op, $v, 'and');
    }
    /** @return static */ public function orWhereDate(string $f, string $op, mixed $v)
    {
        return $this->whereDate($f, $op, $v, 'or');
    }

    /**
     * Compare two columns directly: emits `field1 op field2` with no
     * parameter binding on either side. The honest replacement for
     * `where('a', '=', Raw::of('b'))` — slightly more discoverable
     * and matches Eloquent's `whereColumn` sugar.
     *
     *   $q->whereColumn('p.user_id', '=', 'c.user_id')
     *
     * @return static
     */
    public function whereColumn(string $first, string $operator, string $second, string $type = 'and')
    {
        $this->assertWhereOperator($operator);
        $expr = $this->cleanReference($first) . ' ' . strtoupper($operator) . ' ' . $this->cleanReference($second);
        $this->commands['WHERE'][] = [
            'op'   => $this->normalizeOp($type),
            'expr' => $expr,
        ];
        return $this;
    }

    /** @return static */ public function andWhereColumn(string $a, string $op, string $b)
    {
        return $this->whereColumn($a, $op, $b, 'and');
    }
    /** @return static */ public function orWhereColumn(string $a, string $op, string $b)
    {
        return $this->whereColumn($a, $op, $b, 'or');
    }

    /** @return static */
    private function appendExists(Buildable $subquery, bool $negate, string $type)
    {
        [$sub_sql, $sub_bindings] = $subquery->toSql();
        foreach ($sub_bindings as $b) {
            $this->bindings[] = $b;
        }
        $keyword = $negate ? 'NOT EXISTS' : 'EXISTS';
        $this->commands['WHERE'][] = [
            'op'   => $this->normalizeOp($type),
            'expr' => $keyword . ' (' . $sub_sql . ')',
        ];
        return $this;
    }

    private function assertWhereOperator(string $operator): void
    {
        $normalized = strtoupper($operator);
        $allowed    = array_map('strtoupper', self::WHERE_OPERATORS);
        if (!in_array($normalized, $allowed, true)) {
            throw new \InvalidArgumentException("Unsupported WHERE operator '$operator'");
        }
    }

    private function normalizeOp(string $type): string
    {
        return strtolower($type) === 'or' ? 'OR' : 'AND';
    }

    /**
     * @param mixed $value
     */
    private function buildCondition(string $field, string $operator, $value): string
    {
        $operator = strtoupper($operator);
        $field    = $this->resolveJsonField($field);
        if ($operator === 'IN' || $operator === 'NOT IN') {
            // Subquery form: WHERE col IN (SELECT ...)
            if ($value instanceof Buildable) {
                [$sub_sql, $sub_bindings] = $value->toSql();
                foreach ($sub_bindings as $b) {
                    $this->bindings[] = $b;
                }
                return $field . ' ' . $operator . ' (' . $sub_sql . ')';
            }
            if (!is_array($value) || $value === []) {
                throw new \InvalidArgumentException("$operator requires a non-empty array or a Buildable subquery");
            }
            $placeholders = [];
            foreach ($value as $v) {
                $placeholders[]   = '?';
                $this->bindings[] = $v;
            }
            return $field . ' ' . $operator . ' (' . implode(', ', $placeholders) . ')';
        }
        if ($operator === 'BETWEEN') {
            if (!is_array($value) || count($value) !== 2) {
                throw new \InvalidArgumentException('BETWEEN requires [low, high]');
            }
            $this->bindings[] = $value[0];
            $this->bindings[] = $value[1];
            return $field . ' BETWEEN ? AND ?';
        }
        // Raw on the value side emits verbatim (useful for
        // correlated subqueries referencing an outer alias).
        if ($value instanceof Raw) {
            return $field . ' ' . $operator . ' ' . $value->sql;
        }
        // Scalar subquery on the value side: SELECT that returns
        // exactly one row / column, e.g. `x = (SELECT MAX(y) FROM z)`.
        if ($value instanceof Buildable) {
            [$sub_sql, $sub_bindings] = $value->toSql();
            foreach ($sub_bindings as $b) {
                $this->bindings[] = $b;
            }
            return $field . ' ' . $operator . ' (' . $sub_sql . ')';
        }
        $this->bindings[] = $value;
        return $field . ' ' . $operator . ' ?';
    }

    /**
     * If $field uses the `column->key->subkey` JSON-path shortcut,
     * expand it into the right driver-specific extraction. Otherwise
     * fall through to the normal identifier escape.
     *
     * Supported drivers (auto-detected via the attached Connection):
     *   - mysql / mariadb / sqlite — JSON_EXTRACT(col, '$.path')
     *   - pgsql                    — col->'a'->>'b'
     *
     * If no Connection is attached we default to the JSON_EXTRACT form
     * (which MySQL 5.7+ and SQLite 3.38+ both support).
     *
     * Path keys must be `[A-Za-z_][A-Za-z0-9_]*`. For exotic keys use
     * `Raw::of('...')` directly.
     */
    private function resolveJsonField(string $field): string
    {
        if (!str_contains($field, '->')) {
            return $this->cleanReference($field);
        }
        $parts = explode('->', $field);
        $col   = array_shift($parts);
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)) {
                throw new \InvalidArgumentException(
                    "Invalid JSON path segment '$part' — keys must match [A-Za-z_][A-Za-z0-9_]*. " .
                    'Use Raw::of() for exotic key names.',
                );
            }
        }
        $colRef = $this->cleanReference($col);
        $driver = $this->detectDriverForJsonPath();

        if ($driver === 'pgsql') {
            $expr = $colRef;
            $last = array_pop($parts);
            foreach ($parts as $intermediate) {
                $expr .= "->'" . $intermediate . "'";
            }
            $expr .= "->>'" . $last . "'";
            return $expr;
        }
        // mysql, mariadb, sqlite, or unknown: use the portable JSON_EXTRACT form.
        $path = '$.' . implode('.', $parts);
        return "JSON_EXTRACT($colRef, '$path')";
    }

    private function detectDriverForJsonPath(): ?string
    {
        // The trait is only mixed into builders that also use
        // HasConnection (Query/Update/Delete), so $this->connection
        // exists. The null check guards the not-yet-attached case.
        return $this->connection?->getDriver();
    }

    /**
     * @param mixed $value
     */
    private function addGroup(string $type, string $field, string $operator, $value, callable $callback): void
    {
        $sub = new Query();
        $sub->where($field, $operator, $value);
        $callback($sub);
        $this->commands['WHERE'][] = [
            'op'    => $this->normalizeOp($type),
            'group' => $sub->commands['WHERE'] ?? [],
        ];
        foreach ($sub->bindings as $b) {
            $this->bindings[] = $b;
        }
    }
}
