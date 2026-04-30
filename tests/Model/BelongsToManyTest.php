<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model;

use PDO;
use Rxn\Orm\Db\Connection;
use Rxn\Orm\Model\Record;
use Rxn\Orm\Tests\Support\CountingPdo;
use Rxn\Orm\Tests\Model\Fixtures\Tag;
use Rxn\Orm\Tests\Model\Fixtures\TaggedPost;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class BelongsToManyTest extends SqliteTestCase
{
    private CountingPdo $countingPdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->countingPdo = new CountingPdo('sqlite::memory:');
        $this->countingPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db  = new Connection($this->countingPdo);
        $this->pdo = $this->countingPdo;

        $this->pdo->exec('CREATE TABLE tagged_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE post_tag (
            post_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            granted_at TEXT,
            PRIMARY KEY (post_id, tag_id)
        )');

        $this->pdo->exec("INSERT INTO tagged_posts (title) VALUES ('p1'), ('p2'), ('p3')");
        $this->pdo->exec("INSERT INTO tags (name) VALUES ('php'), ('sql'), ('orm'), ('php8')");
        $this->pdo->exec("INSERT INTO post_tag (post_id, tag_id) VALUES
            (1, 1), (1, 2),
            (2, 2), (2, 3),
            (3, 4)");

        Record::clearConnections();
        Record::setConnection($this->db);
        $this->countingPdo->resetCount();
    }

    protected function tearDown(): void
    {
        Record::clearConnections();
        parent::tearDown();
    }

    public function testEagerLoadIssuesExactlyTwoQueries(): void
    {
        $posts = TaggedPost::query()->with('tags')->get();
        $this->assertSame(2, $this->countingPdo->prepareCount());
        $this->assertCount(3, $posts);

        $tagsByPost = [];
        foreach ($posts as $p) {
            $tagsByPost[$p->title] = array_map(fn ($t) => $t->name, $p->tags);
        }
        $this->assertSame(['php', 'sql'], $tagsByPost['p1']);
        $this->assertSame(['sql', 'orm'], $tagsByPost['p2']);
        $this->assertSame(['php8'], $tagsByPost['p3']);
    }

    public function testEagerLoadDoesNotLeakPivotColumnIntoToArray(): void
    {
        $posts = TaggedPost::query()->with('tags')->get();
        $tag = $posts[0]->tags[0];
        $arr = $tag->toArray();
        $this->assertArrayNotHasKey('rxn_pivot_parent', $arr);
        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('name', $arr);
    }

    public function testLazyAccessViaQueryFor(): void
    {
        $post = TaggedPost::find(1);
        $relation = $post->tags();
        $tags = $relation->queryFor($post->toArray())->get();
        $names = array_map(fn ($t) => $t->name, $tags);
        $this->assertSame(['php', 'sql'], $names);
    }

    public function testReverseDirectionWorks(): void
    {
        // Tag 'sql' is on posts 1 and 2.
        $tag = Tag::find(2);
        $tags = Tag::query()->with('posts')->get();

        $byName = [];
        foreach ($tags as $t) {
            $byName[$t->name] = array_map(fn ($p) => $p->title, $t->posts);
        }
        $this->assertSame(['p1'], $byName['php']);
        $this->assertSame(['p1', 'p2'], $byName['sql']);
        $this->assertSame(['p2'], $byName['orm']);
        $this->assertSame(['p3'], $byName['php8']);
    }

    public function testAttachAddsPivotRow(): void
    {
        $post = TaggedPost::find(3);
        $post->attach('tags', 1); // tag 'php' to post 'p3'

        $count = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM post_tag WHERE post_id = 3 AND tag_id = 1"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testAttachManyIds(): void
    {
        $post = TaggedPost::find(3);
        $post->attach('tags', [1, 2]);

        $rows = $this->pdo->query("SELECT tag_id FROM post_tag WHERE post_id = 3 ORDER BY tag_id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([1, 2, 4], $rows);
    }

    public function testAttachWithPivotData(): void
    {
        $post = TaggedPost::find(3);
        $post->attach('tags', 1, ['granted_at' => '2025-01-01 12:00:00']);

        $row = $this->pdo->query(
            "SELECT granted_at FROM post_tag WHERE post_id = 3 AND tag_id = 1"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2025-01-01 12:00:00', $row['granted_at']);
    }

    public function testDetachSpecificIds(): void
    {
        $post = TaggedPost::find(1);
        $detached = $post->detach('tags', 2);
        $this->assertSame(1, $detached);

        $remaining = $this->pdo->query("SELECT tag_id FROM post_tag WHERE post_id = 1")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([1], $remaining);
    }

    public function testDetachAllWhenNoIds(): void
    {
        $post = TaggedPost::find(1);
        $post->detach('tags');
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM post_tag WHERE post_id = 1")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testSyncReconcilesMembership(): void
    {
        $post = TaggedPost::find(1); // currently tags 1, 2
        $diff = $post->sync('tags', [2, 3, 4]); // keep 2, drop 1, add 3 + 4

        sort($diff['attached']);
        sort($diff['detached']);
        $this->assertSame(['3', '4'], array_map('strval', $diff['attached']));
        $this->assertSame(['1'], array_map('strval', $diff['detached']));

        $current = $this->pdo->query("SELECT tag_id FROM post_tag WHERE post_id = 1 ORDER BY tag_id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([2, 3, 4], $current);
    }

    public function testAttachRequiresParentToBeSaved(): void
    {
        $post = new TaggedPost();
        $this->expectException(\LogicException::class);
        $post->attach('tags', 1);
    }

    public function testAttachOnNonRelationMethodThrows(): void
    {
        $post = TaggedPost::find(1);
        $this->expectException(\LogicException::class);
        $post->attach('nonExistentMethod', 1);
    }
}
