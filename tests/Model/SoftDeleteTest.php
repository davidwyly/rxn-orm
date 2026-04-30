<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model;

use Rxn\Orm\Model\Record;
use Rxn\Orm\Tests\Model\Fixtures\SoftPost;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class SoftDeleteTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE soft_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            deleted_at TEXT
        )');
        $this->pdo->exec("INSERT INTO soft_posts (title, deleted_at) VALUES
            ('alive-1', NULL),
            ('alive-2', NULL),
            ('dead-1',  '2025-01-01 00:00:00'),
            ('dead-2',  '2025-01-02 00:00:00')");

        Record::clearConnections();
        Record::setConnection($this->db);
    }

    protected function tearDown(): void
    {
        Record::clearConnections();
        parent::tearDown();
    }

    public function testDefaultQueryHidesSoftDeletedRows(): void
    {
        $posts = SoftPost::all();
        $titles = array_map(fn ($p) => $p->title, $posts);
        $this->assertSame(['alive-1', 'alive-2'], $titles);
    }

    public function testWithTrashedShowsAll(): void
    {
        $posts = SoftPost::query()->withTrashed()->get();
        $this->assertCount(4, $posts);
    }

    public function testOnlyTrashedShowsOnlyDeleted(): void
    {
        $posts = SoftPost::query()->onlyTrashed()->get();
        $titles = array_map(fn ($p) => $p->title, $posts);
        $this->assertSame(['dead-1', 'dead-2'], $titles);
    }

    public function testDeleteSetsTimestampInsteadOfRemoving(): void
    {
        $post = SoftPost::find(1);
        $this->assertTrue($post->delete());

        // Row still exists in the table (raw, bypassing the scope)
        $row = $this->db->table('soft_posts')->find(1);
        $this->assertNotNull($row);
        $this->assertNotNull($row['deleted_at']);

        // But default-scoped queries no longer see it
        $this->assertNull(SoftPost::find(1));
        $this->assertSame(1, SoftPost::query()->count());
    }

    public function testForceDeleteRemovesRow(): void
    {
        $post = SoftPost::find(1);
        $this->assertTrue($post->forceDelete());

        $row = $this->db->table('soft_posts')->find(1);
        $this->assertNull($row);
    }

    public function testRestoreClearsDeletedAt(): void
    {
        $post = SoftPost::query()->withTrashed()->where('id', '=', 3)->first();
        $this->assertNotNull($post->deleted_at);

        $this->assertTrue($post->restore());

        $reloaded = SoftPost::find(3);
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->deleted_at);
    }

    public function testTrashedReturnsTrueAfterDelete(): void
    {
        $post = SoftPost::find(1);
        $this->assertFalse($post->trashed());

        $post->delete();
        $this->assertTrue($post->trashed());
    }

    public function testFindRespectsScope(): void
    {
        $this->assertNotNull(SoftPost::find(1));
        $this->assertNull(SoftPost::find(3));      // soft-deleted, hidden
        $this->assertNotNull(SoftPost::query()->withTrashed()->find(3));
    }

    public function testCountRespectsScope(): void
    {
        $this->assertSame(2, SoftPost::query()->count());
        $this->assertSame(4, SoftPost::query()->withTrashed()->count());
        $this->assertSame(2, SoftPost::query()->onlyTrashed()->count());
    }

    public function testRestoreThrowsOnNonSoftDeletedModel(): void
    {
        // Use the existing User fixture which has no DELETED_AT
        Record::clearConnections();
        Record::setConnection($this->db);
        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            active INTEGER,
            settings TEXT
        )');
        $u = \Rxn\Orm\Tests\Model\Fixtures\User::create(['email' => 'a@x', 'active' => 1]);
        $this->expectException(\LogicException::class);
        $u->restore();
    }
}
