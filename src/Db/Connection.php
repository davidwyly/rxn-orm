<?php declare(strict_types=1);

namespace Rxn\Orm\Db;

use PDO;
use PDOStatement;
use Rxn\Orm\Builder\Buildable;
use Rxn\Orm\Builder\Delete;
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Update;

/**
 * Thin executor that wires Buildable instances to a PDO handle.
 *
 * Construct with an already-configured \PDO. Connection makes no
 * decisions about credentials, drivers, or pooling — bring your own
 * connection. The library stays lightweight by depending only on
 * ext-pdo and never reaching into env vars or service containers.
 *
 * **Fiber safety.** Connection holds no static state; every instance
 * is independent. Pair one Connection per fiber and the library is
 * fully concurrency-safe under PHP 8.1+ fibers, Swoole, and AMP.
 *
 *   $pdo = new \PDO('sqlite::memory:');
 *   $db  = new Connection($pdo);
 *   $db->table('users')->where('active', '=', 1)->get();
 *
 * **Read/write split.** Pass a second PDO as $readPdo to route
 * SELECTs to a replica while writes hit the primary. Inside a
 * transaction, everything routes to the write connection — you never
 * want to read uncommitted data from a replica.
 *
 *   $db = new Connection(writePdo: $primary, readPdo: $replica);
 *
 * **Profiling.** Register an onQuery callback to receive every
 * statement after execution, with bindings and elapsed time. Useful
 * for slow-query hunting and structured logging.
 *
 *   $db->onQuery(fn ($sql, $bindings, $ms) => $logger->info($sql, ['ms' => $ms]));
 */
class Connection
{
    private PDO $pdo;

    /** Replica connection for SELECTs; falls back to $pdo when null. */
    private ?PDO $readPdo;

    private int $transactionDepth = 0;

    /** @var (callable(string, array, float): void)|null */
    private $queryListener = null;

    public function __construct(PDO $pdo, ?PDO $readPdo = null)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($readPdo !== null) {
            $readPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        $this->pdo     = $pdo;
        $this->readPdo = $readPdo;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * The PDO used for SELECTs (the read replica when one was
     * supplied, the write connection otherwise). Inside a transaction
     * always returns the write connection — replicas may lag behind
     * uncommitted local writes.
     */
    public function getReadPdo(): PDO
    {
        if ($this->readPdo === null || $this->transactionDepth > 0) {
            return $this->pdo;
        }
        return $this->readPdo;
    }

    /**
     * Detect the driver string ('mysql', 'pgsql', 'sqlite', etc.).
     * Used by features that need driver-specific SQL (savepoints,
     * RETURNING, upsert).
     */
    public function getDriver(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Register a callback fired after every statement executes.
     * Signature: fn(string $sql, array $bindings, float $durationMs).
     * Pass null to remove. Zero overhead when not registered.
     */
    public function onQuery(?callable $listener): void
    {
        $this->queryListener = $listener;
    }

    // -- entry points -------------------------------------------------

    /**
     * Start a Query against $table. Sugar for `(new Query())->select()->from($table)`
     * with this connection pre-attached so terminal methods work.
     */
    public function table(string $table, ?string $alias = null): Query
    {
        return $this->query()->select()->from($table, $alias);
    }

    /**
     * Empty Query bound to this connection. Use when you want to
     * compose the SELECT yourself.
     */
    public function query(): Query
    {
        return (new Query())->setConnection($this);
    }

    // -- generic dispatch ---------------------------------------------

    /**
     * Execute any Buildable. Returns rows for SELECT, affected count
     * for write statements (or RETURNING rows when the builder
     * declared a returning() clause).
     *
     * @return array<int, array<string, mixed>>|int
     */
    public function run(Buildable $builder): array|int
    {
        if ($builder instanceof Query) {
            return $this->select($builder);
        }
        if ($builder instanceof Insert) {
            return $this->insert($builder);
        }
        if ($builder instanceof Update) {
            return $this->update($builder);
        }
        if ($builder instanceof Delete) {
            return $this->delete($builder);
        }
        // Unknown Buildable: behave like a write — execute and return affected rows.
        [$sql, $bindings] = $builder->toSql();
        return $this->writeStatement($sql, $bindings)->rowCount();
    }

    // -- read terminals -----------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function select(Query|string $sqlOrQuery, array $bindings = []): array
    {
        [$sql, $bindings] = $this->resolve($sqlOrQuery, $bindings);
        $stmt = $this->readStatement($sql, $bindings);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows === false ? [] : $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selectOne(Query|string $sqlOrQuery, array $bindings = []): ?array
    {
        [$sql, $bindings] = $this->resolve($sqlOrQuery, $bindings);
        $stmt = $this->readStatement($sql, $bindings);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function value(Query|string $sqlOrQuery, string $column, array $bindings = []): mixed
    {
        $row = $this->selectOne($sqlOrQuery, $bindings);
        if ($row === null) {
            return null;
        }
        return $row[$column] ?? null;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function pluck(Query|string $sqlOrQuery, string $column, ?string $key = null, array $bindings = []): array
    {
        $rows = $this->select($sqlOrQuery, $bindings);
        $out = [];
        foreach ($rows as $row) {
            if ($key === null) {
                $out[] = $row[$column] ?? null;
            } else {
                $out[$row[$key]] = $row[$column] ?? null;
            }
        }
        return $out;
    }

    public function exists(Query $query): bool
    {
        // Wrap the user's SELECT so we don't disturb its GROUP BY / DISTINCT.
        [$sql, $bindings] = $query->toSql();
        $wrapped = "SELECT EXISTS($sql) AS `e`";
        $row = $this->selectOne($wrapped, $bindings);
        return $row !== null && (int)($row['e'] ?? 0) === 1;
    }

    /**
     * Count matching rows. Wraps the user's SELECT in a derived table
     * so GROUP BY / DISTINCT / JOIN-row-multiplication produce the
     * intuitive count rather than the "rows per group" surprise the
     * naive `COUNT(*)` rewrite gives.
     */
    public function count(Query $query, string $column = '*'): int
    {
        [$sql, $bindings] = $query->toSql();
        $expr = $column === '*' ? 'COUNT(*)' : 'COUNT(' . self::quoteIdent($column) . ')';
        $countSql = "SELECT $expr AS `rxn_c` FROM ($sql) AS `rxn_t`";
        $row = $this->selectOne($countSql, $bindings);
        return $row === null ? 0 : (int)($row['rxn_c'] ?? 0);
    }

    // -- write terminals ----------------------------------------------

    /**
     * @return int|array<int, array<string, mixed>>
     */
    public function insert(Insert $insert): int|array
    {
        [$sql, $bindings] = $insert->toSql();
        $stmt = $this->writeStatement($sql, $bindings);
        if ($insert->hasReturning()) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows === false ? [] : $rows;
        }
        return $stmt->rowCount();
    }

    /**
     * @return int|array<int, array<string, mixed>>
     */
    public function update(Update $update): int|array
    {
        [$sql, $bindings] = $update->toSql();
        $stmt = $this->writeStatement($sql, $bindings);
        if ($update->hasReturning()) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows === false ? [] : $rows;
        }
        return $stmt->rowCount();
    }

    /**
     * @return int|array<int, array<string, mixed>>
     */
    public function delete(Delete $delete): int|array
    {
        [$sql, $bindings] = $delete->toSql();
        $stmt = $this->writeStatement($sql, $bindings);
        if ($delete->hasReturning()) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows === false ? [] : $rows;
        }
        return $stmt->rowCount();
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return $sequence === null
            ? $this->pdo->lastInsertId()
            : $this->pdo->lastInsertId($sequence);
    }

    // -- raw escape hatch ---------------------------------------------

    /**
     * Prepare-and-execute against the WRITE connection. Lower-level
     * than the typed terminals; useful for DDL or one-off SQL that
     * doesn't need the builder.
     */
    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        return $this->writeStatement($sql, $bindings);
    }

    // -- transactions -------------------------------------------------

    /**
     * Run $fn inside a transaction. Commits on success, rolls back on
     * any exception (re-thrown to the caller). Nested calls use real
     * savepoints, so partial work in an inner block can roll back
     * without aborting the outer transaction.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $this->beginTransaction();
        try {
            $result = $fn($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT rxn_sp_' . $this->transactionDepth);
        }
        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            throw new \LogicException('No active transaction to commit');
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT rxn_sp_' . $this->transactionDepth);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            throw new \LogicException('No active transaction to roll back');
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT rxn_sp_' . $this->transactionDepth);
        }
    }

    public function transactionDepth(): int
    {
        return $this->transactionDepth;
    }

    // -- helpers ------------------------------------------------------

    /**
     * @return array{0: string, 1: array}
     */
    private function resolve(Query|string $sqlOrQuery, array $bindings): array
    {
        if ($sqlOrQuery instanceof Query) {
            return $sqlOrQuery->toSql();
        }
        return [$sqlOrQuery, $bindings];
    }

    private function readStatement(string $sql, array $bindings): PDOStatement
    {
        return $this->execute($this->getReadPdo(), $sql, $bindings);
    }

    private function writeStatement(string $sql, array $bindings): PDOStatement
    {
        return $this->execute($this->pdo, $sql, $bindings);
    }

    /**
     * Single chokepoint for prepare+execute. Fires the onQuery hook
     * (if registered) with elapsed wall-clock time. The hook receives
     * the *original* SQL and bindings, before PDO emulation rewrites
     * placeholders, which is what humans want to see in logs.
     */
    private function execute(PDO $pdo, string $sql, array $bindings): PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        if ($this->queryListener === null) {
            $stmt->execute($bindings);
            return $stmt;
        }
        $start = hrtime(true);
        $stmt->execute($bindings);
        $durationMs = (hrtime(true) - $start) / 1_000_000;
        ($this->queryListener)($sql, $bindings, $durationMs);
        return $stmt;
    }

    private static function quoteIdent(string $ident): string
    {
        if (str_contains($ident, '.')) {
            [$t, $c] = explode('.', $ident, 2);
            return '`' . $t . '`.`' . $c . '`';
        }
        return '`' . $ident . '`';
    }
}
