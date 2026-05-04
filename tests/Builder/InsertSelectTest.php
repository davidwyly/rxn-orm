<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder;

use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class InsertSelectTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE posts (
            id INTEGER PRIMARY KEY,
            title TEXT NOT NULL,
            published INTEGER NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE archived_posts (
            id INTEGER PRIMARY KEY,
            title TEXT NOT NULL
        )');
        $this->pdo->exec("INSERT INTO posts (id, title, published) VALUES
            (1, 'live-1', 1),
            (2, 'draft-1', 0),
            (3, 'draft-2', 0)");
    }

    public function testInsertFromQueryShape(): void
    {
        $select = (new Query())->select(['id', 'title'])->from('posts')
            ->where('published', '=', 0);

        [$sql, $bindings] = (new Insert())
            ->into('archived_posts')
            ->columns(['id', 'title'])
            ->fromQuery($select)
            ->toSql();

        $this->assertSame(
            'INSERT INTO `archived_posts` (`id`, `title`) SELECT `id`, `title` FROM `posts` WHERE `published` = ?',
            $sql,
        );
        $this->assertSame([0], $bindings);
    }

    public function testInsertFromQueryExecutesAgainstSqlite(): void
    {
        (new Insert())
            ->into('archived_posts')
            ->columns(['id', 'title'])
            ->fromQuery($this->db->table('posts')->select(['id', 'title'])->where('published', '=', 0))
            ->setConnection($this->db)
            ->execute();

        $rows = $this->db->table('archived_posts')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('draft-1', $rows[0]['title']);
        $this->assertSame('draft-2', $rows[1]['title']);
    }

    public function testInsertFromQueryRequiresColumns(): void
    {
        $insert = (new Insert())
            ->into('archived_posts')
            ->fromQuery($this->db->table('posts')->select(['id', 'title']));
        $this->expectException(\LogicException::class);
        $insert->toSql();
    }

    public function testInsertFromQueryAndRowAreMutuallyExclusive(): void
    {
        $insert = (new Insert())->into('archived_posts')->row(['id' => 1, 'title' => 'x']);
        $this->expectException(\LogicException::class);
        $insert->fromQuery($this->db->table('posts')->select(['id', 'title']));
    }

    public function testRowAfterFromQueryIsRefused(): void
    {
        $insert = (new Insert())->into('archived_posts')
            ->columns(['id', 'title'])
            ->fromQuery($this->db->table('posts')->select(['id', 'title']));
        $this->expectException(\LogicException::class);
        $insert->row(['id' => 1, 'title' => 'x']);
    }

    public function testInsertFromQueryWithIgnore(): void
    {
        // Pre-seed an existing row to trigger conflict.
        $this->pdo->exec("INSERT INTO archived_posts (id, title) VALUES (2, 'pre-existing')");

        (new Insert())
            ->into('archived_posts')
            ->columns(['id', 'title'])
            ->fromQuery($this->db->table('posts')->select(['id', 'title'])->where('published', '=', 0))
            ->ignore()
            ->setConnection($this->db)
            ->execute();

        $rows = $this->db->table('archived_posts')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        // The pre-existing row 2 should be untouched
        $this->assertSame('pre-existing', $rows[0]['title']);
        $this->assertSame('draft-2', $rows[1]['title']);
    }
}
