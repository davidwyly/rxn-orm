<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model;

use PDO;
use Rxn\Orm\Db\Connection;
use Rxn\Orm\Model\Record;
use Rxn\Orm\Tests\Model\Fixtures\Post;
use Rxn\Orm\Tests\Model\Fixtures\Profile;
use Rxn\Orm\Tests\Model\Fixtures\User;
use Rxn\Orm\Tests\Support\CountingPdo;
use Rxn\Orm\Tests\Support\SqliteTestCase;

/**
 * Tests for the relation system. Eager-loading correctness is checked
 * by counting prepared statements via a custom PDO subclass — the
 * canonical "did we hit N+1?" smoke test.
 */
final class RelationTest extends SqliteTestCase
{
    private CountingPdo $countingPdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->countingPdo = new CountingPdo('sqlite::memory:');
        $this->countingPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db = new Connection($this->countingPdo);
        $this->pdo = $this->countingPdo;

        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            settings TEXT
        )');
        $this->pdo->exec('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            body TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            bio TEXT NOT NULL
        )');

        $this->pdo->exec("INSERT INTO users (email) VALUES ('a@x'), ('b@x'), ('c@x')");
        $this->pdo->exec("INSERT INTO posts (user_id, title) VALUES
            (1, 'a-post-1'), (1, 'a-post-2'),
            (2, 'b-post-1'),
            (3, 'c-post-1'), (3, 'c-post-2'), (3, 'c-post-3')");
        $this->pdo->exec("INSERT INTO comments (post_id, body) VALUES
            (1, 'c1'), (1, 'c2'),
            (3, 'c3'),
            (4, 'c4'), (4, 'c5')");
        $this->pdo->exec("INSERT INTO profiles (user_id, bio) VALUES
            (1, 'A bio'), (2, 'B bio')");

        Record::clearConnections();
        Record::setConnection($this->db);
        $this->countingPdo->resetCount();
    }

    protected function tearDown(): void
    {
        Record::clearConnections();
        parent::tearDown();
    }

    public function testHasManyEagerLoadsInOneExtraQuery(): void
    {
        $users = User::query()->with('posts')->get();
        $this->assertCount(3, $users);

        // 1 query for users + 1 for posts = 2 prepared statements.
        $this->assertSame(2, $this->countingPdo->prepareCount());

        $byEmail = [];
        foreach ($users as $u) {
            $byEmail[$u->email] = array_map(fn ($p) => $p->title, $u->posts);
        }
        $this->assertSame(['a-post-1', 'a-post-2'], $byEmail['a@x']);
        $this->assertSame(['b-post-1'], $byEmail['b@x']);
        $this->assertSame(['c-post-1', 'c-post-2', 'c-post-3'], $byEmail['c@x']);
    }

    public function testWithoutEagerLoadingIsNPlusOne(): void
    {
        // Demonstration: without with(), accessing $user->posts() lazily
        // would require N additional queries. Here we just verify that
        // omitting with() doesn't pre-load posts.
        $users = User::query()->get();
        $this->assertCount(3, $users);
        $this->assertFalse($users[0]->hasRelation('posts'));
        $this->assertNull($users[0]->getRelation('posts'));
    }

    public function testBelongsToEagerLoadsParents(): void
    {
        $posts = Post::query()->with('user')->get();
        $this->assertCount(6, $posts);
        $this->assertSame(2, $this->countingPdo->prepareCount());

        $this->assertSame('a@x', $posts[0]->user->email);
        $this->assertSame('b@x', $posts[2]->user->email);
        $this->assertSame('c@x', $posts[3]->user->email);
    }

    public function testHasOneEagerLoadsSingleRelated(): void
    {
        $users = User::query()->with('profile')->get();
        $this->assertSame(2, $this->countingPdo->prepareCount());

        $this->assertSame('A bio', $users[0]->profile->bio);
        $this->assertSame('B bio', $users[1]->profile->bio);
        $this->assertNull($users[2]->profile); // user 3 has no profile row
    }

    public function testNestedEagerLoad(): void
    {
        $users = User::query()->with('posts.comments')->get();
        // 3 queries: users → posts → comments
        $this->assertSame(3, $this->countingPdo->prepareCount());

        // user 1 has posts 1 and 2; comments are on posts 1, 3, 4
        $a = $users[0];
        $this->assertCount(2, $a->posts);
        $this->assertCount(2, $a->posts[0]->comments); // post 1 → c1, c2
        $this->assertCount(0, $a->posts[1]->comments); // post 2 → none
    }

    public function testMultipleTopLevelRelations(): void
    {
        $users = User::query()->with('posts', 'profile')->get();
        // users + posts + profiles = 3 queries
        $this->assertSame(3, $this->countingPdo->prepareCount());

        $this->assertCount(2, $users[0]->posts);
        $this->assertSame('A bio', $users[0]->profile->bio);
    }

    public function testLazyRelationAccessViaQueryFor(): void
    {
        $user = User::find(1);
        $relation = $user->posts();
        $this->assertInstanceOf(\Rxn\Orm\Model\Relation::class, $relation);

        // Use Relation::queryFor to fetch lazily for this one user.
        $posts = $relation->queryFor(['id' => 1])->get();
        $this->assertCount(2, $posts);
    }
}
