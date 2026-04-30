<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Db;

use Rxn\Orm\Tests\Support\SqliteTestCase;

final class QueryListenerTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER)');
        $this->pdo->exec('INSERT INTO t (n) VALUES (1), (2), (3)');
    }

    public function testListenerReceivesSqlBindingsAndDuration(): void
    {
        $captured = [];
        $this->db->onQuery(function (string $sql, array $bindings, float $ms) use (&$captured) {
            $captured[] = [$sql, $bindings, $ms];
        });

        $this->db->table('t')->where('n', '>', 1)->get();

        $this->assertCount(1, $captured);
        $this->assertStringContainsString('SELECT', $captured[0][0]);
        $this->assertSame([1], $captured[0][1]);
        $this->assertGreaterThanOrEqual(0.0, $captured[0][2]);
        $this->assertIsFloat($captured[0][2]);
    }

    public function testListenerFiresForWrites(): void
    {
        $events = [];
        $this->db->onQuery(function ($sql) use (&$events) {
            $events[] = $sql;
        });

        $this->db->run((new \Rxn\Orm\Builder\Insert())->into('t')->row(['n' => 4]));
        $this->db->run((new \Rxn\Orm\Builder\Update())->table('t')->set(['n' => 5])->where('id', '=', 1));
        $this->db->run((new \Rxn\Orm\Builder\Delete())->from('t')->where('id', '=', 2));

        $this->assertCount(3, $events);
        $this->assertStringContainsString('INSERT', $events[0]);
        $this->assertStringContainsString('UPDATE', $events[1]);
        $this->assertStringContainsString('DELETE', $events[2]);
    }

    public function testListenerCanBeRemoved(): void
    {
        $count = 0;
        $this->db->onQuery(function () use (&$count) {
            $count++;
        });
        $this->db->table('t')->get();
        $this->assertSame(1, $count);

        $this->db->onQuery(null);
        $this->db->table('t')->get();
        $this->assertSame(1, $count); // unchanged
    }
}
