<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Db;

use Rxn\Orm\Tests\Support\SqliteTestCase;

final class TerminalsTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            views INTEGER NOT NULL DEFAULT 0,
            published INTEGER NOT NULL DEFAULT 0
        )');
        $this->pdo->exec("INSERT INTO posts (title, views, published) VALUES
            ('alpha', 10, 1),
            ('beta',  20, 1),
            ('gamma', 30, 0),
            ('delta', 40, 1),
            ('epsilon', 50, 0)");
    }

    public function testGetReturnsAllRows(): void
    {
        $rows = $this->db->table('posts')->get();
        $this->assertCount(5, $rows);
    }

    public function testFirstAppliesLimitOne(): void
    {
        $row = $this->db->table('posts')->orderBy('views', 'ASC')->first();
        $this->assertNotNull($row);
        $this->assertSame('alpha', $row['title']);
    }

    public function testFirstReturnsNullWhenEmpty(): void
    {
        $row = $this->db->table('posts')->where('title', '=', 'nope')->first();
        $this->assertNull($row);
    }

    public function testFindByPrimaryKey(): void
    {
        $row = $this->db->table('posts')->find(2);
        $this->assertSame('beta', $row['title']);
    }

    public function testValueReturnsScalarFromFirstRow(): void
    {
        $title = $this->db->table('posts')->orderBy('views', 'DESC')->value('title');
        $this->assertSame('epsilon', $title);
    }

    public function testPluckReturnsListOrMap(): void
    {
        $list = $this->db->table('posts')->orderBy('id', 'ASC')->pluck('title');
        $this->assertSame(['alpha', 'beta', 'gamma', 'delta', 'epsilon'], $list);

        $map = $this->db->table('posts')->orderBy('id', 'ASC')->pluck('title', 'id');
        $this->assertSame([1 => 'alpha', 2 => 'beta', 3 => 'gamma', 4 => 'delta', 5 => 'epsilon'], $map);
    }

    public function testExistsReflectsResultPresence(): void
    {
        $this->assertTrue($this->db->table('posts')->where('published', '=', 1)->exists());
        $this->assertFalse($this->db->table('posts')->where('title', '=', 'nope')->exists());
    }

    public function testCountWithAndWithoutFilter(): void
    {
        $this->assertSame(5, $this->db->table('posts')->count());
        $this->assertSame(3, $this->db->table('posts')->where('published', '=', 1)->count());
    }

    public function testCountWrapsSubqueryUnderGroupBy(): void
    {
        // GROUP BY collapses the 3 published rows to 1 row (one group).
        // The wrap-as-derived-table approach means count() returns the
        // number of result rows = 1, which matches the user's intent
        // ("how many groups did my GROUP BY produce?"). The naive
        // SELECT-list rewrite would have returned 3, the row count
        // *before* grouping — almost never what you want.
        $count = $this->db->table('posts')
            ->where('published', '=', 1)
            ->groupBy('published')
            ->count();
        $this->assertSame(1, $count);
    }

    public function testPaginateReturnsWindow(): void
    {
        $page = $this->db->table('posts')->orderBy('id', 'ASC')->paginate(2, 2);
        $this->assertSame(5, $page['total']);
        $this->assertSame(2, $page['perPage']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(3, $page['lastPage']);
        $this->assertCount(2, $page['data']);
        $this->assertSame('gamma', $page['data'][0]['title']);
    }

    public function testChunkIteratesInBatches(): void
    {
        $seen = [];
        $this->db->table('posts')->orderBy('id', 'ASC')->chunk(2, function (array $rows) use (&$seen) {
            foreach ($rows as $r) {
                $seen[] = $r['title'];
            }
        });
        $this->assertSame(['alpha', 'beta', 'gamma', 'delta', 'epsilon'], $seen);
    }

    public function testChunkStopsWhenCallbackReturnsFalse(): void
    {
        $seen = [];
        $this->db->table('posts')->orderBy('id', 'ASC')->chunk(2, function (array $rows) use (&$seen) {
            foreach ($rows as $r) {
                $seen[] = $r['title'];
            }
            return false;
        });
        $this->assertSame(['alpha', 'beta'], $seen);
    }

    public function testCursorYieldsRows(): void
    {
        $titles = [];
        foreach ($this->db->table('posts')->orderBy('id', 'ASC')->cursor() as $row) {
            $titles[] = $row['title'];
        }
        $this->assertSame(['alpha', 'beta', 'gamma', 'delta', 'epsilon'], $titles);
    }

    public function testTerminalThrowsWithoutConnection(): void
    {
        $detached = (new \Rxn\Orm\Builder\Query())->select()->from('posts');
        $this->expectException(\LogicException::class);
        $detached->first();
    }
}
