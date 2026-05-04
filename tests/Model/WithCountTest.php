<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model;

use PDO;
use Rxn\Orm\Db\Connection;
use Rxn\Orm\Model\Record;
use Rxn\Orm\Tests\Model\Fixtures\TaggedPost;
use Rxn\Orm\Tests\Model\Fixtures\User;
use Rxn\Orm\Tests\Support\CountingPdo;

/**
 * withCount() injects correlated COUNT subqueries into the parent
 * SELECT, exposing `<relation>_count` on each hydrated record. The
 * critical property is *no extra query at access time*, regardless of
 * result-set size — verified here by counting prepare() calls.
 */
final class WithCountTest extends \PHPUnit\Framework\TestCase
{
    private CountingPdo $pdo;
    private Connection $db;

    protected function setUp(): void
    {
        $this->pdo = new CountingPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db  = new Connection($this->pdo);

        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            active INTEGER,
            settings TEXT
        )');
        $this->pdo->exec('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL
        )');
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
            PRIMARY KEY (post_id, tag_id)
        )');
        $this->pdo->exec("INSERT INTO users (email) VALUES ('a@x'), ('b@x'), ('c@x')");
        $this->pdo->exec("INSERT INTO posts (user_id, title) VALUES
            (1, 'p1'), (1, 'p2'), (1, 'p3'),
            (3, 'p4')");
        $this->pdo->exec("INSERT INTO tagged_posts (title) VALUES ('p1'), ('p2')");
        $this->pdo->exec("INSERT INTO tags (name) VALUES ('php'), ('sql'), ('orm')");
        $this->pdo->exec("INSERT INTO post_tag (post_id, tag_id) VALUES (1, 1), (1, 2), (1, 3), (2, 1)");

        Record::clearConnections();
        Record::setConnection($this->db);
        $this->pdo->resetCount();
    }

    protected function tearDown(): void
    {
        Record::clearConnections();
    }

    public function testHasManyCountIsExposedAsAttribute(): void
    {
        $users = User::query()->withCount('posts')->orderBy('id')->get();
        $this->assertSame(1, $this->pdo->prepareCount()); // exactly one query

        $this->assertSame(3, (int)$users[0]->posts_count);
        $this->assertSame(0, (int)$users[1]->posts_count);
        $this->assertSame(1, (int)$users[2]->posts_count);
    }

    public function testBelongsToManyCountUsesPivotTable(): void
    {
        $posts = TaggedPost::query()->withCount('tags')->orderBy('id')->get();
        $this->assertSame(1, $this->pdo->prepareCount());

        $this->assertSame(3, (int)$posts[0]->tags_count);
        $this->assertSame(1, (int)$posts[1]->tags_count);
    }

    public function testWithCountAndWithCanCombine(): void
    {
        $users = User::query()->withCount('posts')->with('posts')->orderBy('id')->get();
        // 2 queries: parent SELECT (with count subquery) + posts SELECT
        $this->assertSame(2, $this->pdo->prepareCount());

        $this->assertSame(3, (int)$users[0]->posts_count);
        $this->assertCount(3, $users[0]->posts);
    }

    public function testWithCountSurvivesCloning(): void
    {
        // Clone happens inside first() — make sure withCount is preserved.
        $user = User::query()->withCount('posts')->orderBy('id')->first();
        $this->assertSame(3, (int)$user->posts_count);
    }
}
