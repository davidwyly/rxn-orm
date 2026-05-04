<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\EdgeCases;

use Rxn\Orm\Tests\Support\SqliteTestCase;

/**
 * Pagination edges. Compare with Laravel's `testPaginateWhenNoResults`
 * (total=0 returns an empty paginator without dividing by zero).
 *
 * We also clamp `perPage` and `page` to >=1 — Laravel doesn't, which
 * means `paginate(0)` divides by zero. The honest move is to refuse.
 */
final class PaginationEdgeCasesTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)');
    }

    public function testEmptyTableReturnsEmptyPaginator(): void
    {
        $page = $this->db->table('items')->paginate(perPage: 10, page: 1);
        $this->assertSame(0, $page['total']);
        $this->assertSame(1, $page['lastPage']);   // ceil(0/10) is 0; we clamp to 1
        $this->assertSame([], $page['data']);
    }

    public function testPageBeyondLastReturnsEmptyData(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->pdo->exec("INSERT INTO items (id, label) VALUES ($i, 'l')");
        }
        $page = $this->db->table('items')->orderBy('id')->paginate(perPage: 10, page: 3);
        $this->assertSame(5, $page['total']);
        $this->assertSame(1, $page['lastPage']);
        $this->assertSame(3, $page['page']);
        $this->assertSame([], $page['data']);
    }

    public function testPerPageGreaterThanTotalReturnsAllRows(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->pdo->exec("INSERT INTO items (id, label) VALUES ($i, 'l')");
        }
        $page = $this->db->table('items')->orderBy('id')->paginate(perPage: 100, page: 1);
        $this->assertCount(3, $page['data']);
        $this->assertSame(3, $page['total']);
        $this->assertSame(1, $page['lastPage']);
    }

    public function testPerPageZeroIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->table('items')->paginate(perPage: 0, page: 1);
    }

    public function testPageZeroIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->table('items')->paginate(perPage: 10, page: 0);
    }

    public function testNegativePageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->table('items')->paginate(perPage: 10, page: -1);
    }

    public function testLastPageRoundsUp(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->pdo->exec("INSERT INTO items (id, label) VALUES ($i, 'l')");
        }
        // 11 / 5 = 2.2 → lastPage = 3
        $page = $this->db->table('items')->orderBy('id')->paginate(perPage: 5, page: 3);
        $this->assertSame(11, $page['total']);
        $this->assertSame(3, $page['lastPage']);
        $this->assertCount(1, $page['data']);  // 11th item only
    }
}
