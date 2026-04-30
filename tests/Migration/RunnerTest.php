<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Migration;

use Rxn\Orm\Migration\Runner;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class RunnerTest extends SqliteTestCase
{
    private string $fixtures;
    private Runner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = __DIR__ . '/fixtures';
        $this->runner   = new Runner($this->db, $this->fixtures);
    }

    public function testStatusListsAllMigrationsInitiallyPending(): void
    {
        $status = $this->runner->status();
        $this->assertCount(3, $status);
        foreach ($status as $row) {
            $this->assertSame('pending', $row['state']);
            $this->assertNull($row['batch']);
        }
        $this->assertSame('0001_create_widgets', $status[0]['name']);
        $this->assertSame('0002_add_widgets_color', $status[1]['name']);
        $this->assertSame('0003_no_down', $status[2]['name']);
    }

    public function testRunAppliesAllPendingInOneBatch(): void
    {
        $results = $this->runner->run();
        $this->assertSame(
            ['0001_create_widgets' => 'applied', '0002_add_widgets_color' => 'applied', '0003_no_down' => 'applied'],
            $results
        );

        // Side-effect: the widgets table now exists with the color column
        $this->pdo->exec("INSERT INTO widgets (name, color) VALUES ('cog', 'red')");
        $row = $this->pdo->query('SELECT name, color FROM widgets')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('cog', $row['name']);
        $this->assertSame('red', $row['color']);

        // All three share the same batch number
        $batches = array_map(fn ($r) => $r['batch'], $this->runner->status());
        $this->assertSame([1, 1, 1], $batches);
    }

    public function testRunWithStepsAppliesOnlyN(): void
    {
        $results = $this->runner->run(steps: 1);
        $this->assertSame(['0001_create_widgets' => 'applied'], $results);

        $status = $this->runner->status();
        $this->assertSame('applied', $status[0]['state']);
        $this->assertSame('pending', $status[1]['state']);
        $this->assertSame('pending', $status[2]['state']);
    }

    public function testRunIsIdempotent(): void
    {
        $this->runner->run();
        $second = $this->runner->run();
        $this->assertSame([], $second);
    }

    public function testRunInIncrementalBatchesAssignsDistinctBatchNumbers(): void
    {
        $this->runner->run(steps: 1); // batch 1
        $this->runner->run(steps: 1); // batch 2
        $this->runner->run();         // batch 3 (just 0003)

        $rows = $this->runner->status();
        $batches = array_column($rows, 'batch');
        $this->assertSame([1, 2, 3], $batches);
    }

    public function testRollbackUndoesMostRecentBatch(): void
    {
        $this->runner->run(steps: 1); // applies 0001
        $this->runner->run(steps: 1); // applies 0002 in batch 2

        $rolled = $this->runner->rollback(1);
        $this->assertSame(['0002_add_widgets_color' => 'no-down-script'], $rolled);
        // 0002 has no down-script; report as such, leave it in place

        $status = $this->runner->status();
        $applied = array_filter($status, fn ($r) => $r['state'] === 'applied');
        $this->assertCount(2, $applied); // both still recorded; we only skip the SQL exec
    }

    public function testRollbackExecutesMatchingDownScript(): void
    {
        $this->runner->run(steps: 1); // batch 1 — 0001
        $this->runner->rollback(1);

        // 0001 has a .down.sql that drops widgets
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotContains('widgets', $tables);
        $this->assertSame('pending', $this->runner->status()[0]['state']);
    }

    public function testTrackingTableIsCreatedIfMissing(): void
    {
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotContains(Runner::TABLE, $tables);

        $this->runner->status();
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains(Runner::TABLE, $tables);
    }

    public function testRollbackNothingWhenNoApplied(): void
    {
        $this->assertSame([], $this->runner->rollback(1));
    }

    public function testInvalidDirectoryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Runner($this->db, '/nonexistent/path/12345');
    }
}
