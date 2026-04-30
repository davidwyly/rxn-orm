<?php declare(strict_types=1);

namespace Rxn\Orm\Migration;

use Rxn\Orm\Db\Connection;

/**
 * File-based migration runner. Migrations are plain `.sql` files in
 * a directory you pick, named `NNNN_description.sql` (the prefix
 * controls ordering — pad your sequence so they sort lexically).
 *
 * Forward migrations run their `.sql` body. Rollbacks look for a
 * sibling `NNNN_description.down.sql` and execute that — the runner
 * never tries to derive a down-script from your up-script. If a
 * rollback file is missing, that migration is left in place and the
 * runner reports it as such.
 *
 * Tracking lives in a single `rxn_migrations` table created on first
 * run:
 *   - `name`     — the file's basename without `.sql`
 *   - `batch`    — monotonic counter so you can roll back a chunk
 *   - `ran_at`   — UTC timestamp
 *
 * The runner is deliberately *not* a Schema DSL. Write SQL. Diffing
 * a Blueprint object across MySQL / Postgres / SQLite quirks is the
 * single biggest reason "lightweight" ORMs become heavyweight; we
 * dodge that entirely by handing you a `.sql` file and a tracker.
 *
 *   $runner = new Runner($db, __DIR__ . '/migrations');
 *   $runner->run();          // apply pending
 *   $runner->status();       // [['name' => '...', 'state' => 'applied'|'pending', 'batch' => N], ...]
 *   $runner->rollback(1);    // undo most recent batch
 */
class Runner
{
    public const TABLE = 'rxn_migrations';

    public function __construct(
        private readonly Connection $db,
        private readonly string $directory,
    ) {
        if (!is_dir($this->directory)) {
            throw new \InvalidArgumentException("Migrations directory not found: {$this->directory}");
        }
    }

    /**
     * Apply pending migrations. Pass $steps to limit how many to run
     * (useful for stepping through a deploy). Returns a per-file
     * result map: ['name' => 'applied'|'failed: msg'].
     *
     * @return array<string, string>
     */
    public function run(?int $steps = null): array
    {
        $this->ensureTable();
        $applied = $this->appliedNames();
        $pending = array_values(array_filter(
            $this->forwardFiles(),
            fn (string $f) => !in_array($this->basename($f), $applied, true),
        ));
        if ($steps !== null) {
            $pending = array_slice($pending, 0, $steps);
        }
        if ($pending === []) {
            return [];
        }
        $batch = $this->nextBatch();
        $results = [];
        foreach ($pending as $file) {
            $name = $this->basename($file);
            try {
                $this->execFile($file);
                $this->recordApplied($name, $batch);
                $results[$name] = 'applied';
            } catch (\Throwable $e) {
                $results[$name] = 'failed: ' . $e->getMessage();
                break;
            }
        }
        return $results;
    }

    /**
     * Roll back $batches of migrations (default 1 — the most recent
     * apply step). Each batch is undone in reverse order, looking for
     * the matching `.down.sql`. Migrations without a down-script are
     * skipped and reported as 'no-down-script'.
     *
     * @return array<string, string>
     */
    public function rollback(int $batches = 1): array
    {
        if ($batches < 1) {
            throw new \InvalidArgumentException('batches must be >= 1');
        }
        $this->ensureTable();

        // Find the most recent N distinct batches.
        $batchNumbers = $this->db
            ->table(self::TABLE)
            ->orderBy('batch', 'DESC')
            ->limit($batches * 1000) // generous; collapse below
            ->pluck('batch');
        $distinctBatches = array_values(array_unique($batchNumbers));
        $distinctBatches = array_slice($distinctBatches, 0, $batches);
        if ($distinctBatches === []) {
            return [];
        }

        // Names within those batches, newest-first.
        $names = $this->db
            ->table(self::TABLE)
            ->whereIn('batch', $distinctBatches)
            ->orderBy('id', 'DESC')
            ->pluck('name');

        $results = [];
        foreach ($names as $name) {
            $downFile = $this->directory . '/' . $name . '.down.sql';
            if (!is_file($downFile)) {
                $results[$name] = 'no-down-script';
                continue;
            }
            try {
                $this->execFile($downFile);
                $this->forgetApplied($name);
                $results[$name] = 'rolled-back';
            } catch (\Throwable $e) {
                $results[$name] = 'failed: ' . $e->getMessage();
                break;
            }
        }
        return $results;
    }

    /**
     * Return one entry per migration file: name, state (applied|pending),
     * batch (when applied).
     *
     * @return array<int, array{name: string, state: string, batch: ?int}>
     */
    public function status(): array
    {
        $this->ensureTable();
        $appliedRows = $this->db->table(self::TABLE)->pluck('batch', 'name');
        $rows = [];
        foreach ($this->forwardFiles() as $file) {
            $name = $this->basename($file);
            $rows[] = [
                'name'  => $name,
                'state' => array_key_exists($name, $appliedRows) ? 'applied' : 'pending',
                'batch' => array_key_exists($name, $appliedRows) ? (int)$appliedRows[$name] : null,
            ];
        }
        return $rows;
    }

    /**
     * Roll every applied migration back. Useful in dev when you want
     * a clean slate.
     *
     * @return array<string, string>
     */
    public function reset(): array
    {
        $this->ensureTable();
        $count = $this->db->table(self::TABLE)->count();
        if ($count === 0) {
            return [];
        }
        // Pass an absurdly large batch count to drain everything.
        return $this->rollback(PHP_INT_MAX);
    }

    // -- internals ---------------------------------------------------

    private function ensureTable(): void
    {
        $driver = $this->db->getDriver();
        $autoincrement = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'mysql', 'mariadb' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'pgsql'  => 'BIGSERIAL PRIMARY KEY',
            default  => 'INTEGER PRIMARY KEY',
        };
        $sql = "CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            id $autoincrement,
            name VARCHAR(255) NOT NULL UNIQUE,
            batch INTEGER NOT NULL,
            ran_at VARCHAR(32) NOT NULL
        )";
        $this->db->statement($sql);
    }

    /**
     * @return string[] absolute paths to forward .sql files (excluding *.down.sql)
     */
    private function forwardFiles(): array
    {
        $all = glob($this->directory . '/*.sql') ?: [];
        $forward = array_filter($all, fn (string $f) => !str_ends_with($f, '.down.sql'));
        sort($forward, SORT_STRING);
        return array_values($forward);
    }

    private function basename(string $file): string
    {
        return basename($file, '.sql');
    }

    /**
     * @return string[]
     */
    private function appliedNames(): array
    {
        return array_values($this->db->table(self::TABLE)->pluck('name'));
    }

    private function nextBatch(): int
    {
        // Bypass the builder for an aggregate-only SELECT — mixing `*`
        // and MAX() without GROUP BY trips strict-mode MySQL/Postgres,
        // and we don't need the builder's escaping for a literal table.
        $row = $this->db->selectOne('SELECT COALESCE(MAX(batch), 0) AS b FROM ' . self::TABLE);
        return ((int)($row['b'] ?? 0)) + 1;
    }

    private function recordApplied(string $name, int $batch): void
    {
        (new \Rxn\Orm\Builder\Insert())
            ->into(self::TABLE)
            ->row([
                'name'   => $name,
                'batch'  => $batch,
                'ran_at' => gmdate('Y-m-d H:i:s'),
            ])
            ->setConnection($this->db)
            ->execute();
    }

    private function forgetApplied(string $name): void
    {
        (new \Rxn\Orm\Builder\Delete())
            ->from(self::TABLE)
            ->where('name', '=', $name)
            ->setConnection($this->db)
            ->execute();
    }

    /**
     * Execute a .sql file. Statements may be separated by semicolons
     * — the file is passed to PDO::exec() which most drivers accept
     * as a multi-statement script. Files containing strings with
     * embedded semicolons should be authored as one statement per
     * file when targeting picky drivers.
     */
    private function execFile(string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Could not read migration: $file");
        }
        $this->db->getPdo()->exec($sql);
    }
}
