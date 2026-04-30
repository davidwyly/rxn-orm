<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder\Query;

use Rxn\Orm\Builder\Query;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class WhereDateTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE events (
            id INTEGER PRIMARY KEY,
            label TEXT,
            occurred_at TEXT
        )');
        $this->pdo->exec("INSERT INTO events (label, occurred_at) VALUES
            ('a', '2025-01-15 09:00:00'),
            ('b', '2025-01-15 14:00:00'),
            ('c', '2025-01-16 10:00:00'),
            ('d', '2025-02-01 12:00:00')");
    }

    public function testWhereDateShape(): void
    {
        [$sql, $bindings] = (new Query())
            ->select(['id'])->from('events')
            ->whereDate('occurred_at', '=', '2025-01-15')
            ->toSql();

        $this->assertSame(
            'SELECT `id` FROM `events` WHERE DATE(`occurred_at`) = ?',
            $sql,
        );
        $this->assertSame(['2025-01-15'], $bindings);
    }

    public function testWhereDateMatchesAcrossTimes(): void
    {
        $rows = $this->db->table('events')
            ->whereDate('occurred_at', '=', '2025-01-15')
            ->orderBy('id')
            ->get();
        $labels = array_map(fn ($r) => $r['label'], $rows);
        $this->assertSame(['a', 'b'], $labels);
    }

    public function testWhereDateRange(): void
    {
        $rows = $this->db->table('events')
            ->whereDate('occurred_at', '>=', '2025-01-16')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testOrWhereDate(): void
    {
        [$sql] = (new Query())->select(['id'])->from('events')
            ->where('label', '=', 'a')
            ->orWhereDate('occurred_at', '=', '2025-02-01')
            ->toSql();
        $this->assertStringContainsString('OR DATE(`occurred_at`) = ?', $sql);
    }
}
