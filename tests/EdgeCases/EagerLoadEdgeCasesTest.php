<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\EdgeCases;

use PDO;
use Rxn\Orm\Db\Connection;
use Rxn\Orm\Model\Record;
use Rxn\Orm\Model\Relation;
use Rxn\Orm\Tests\Support\CountingPdo;

/**
 * Eager-loading edges, mirroring Laravel's
 * `testGetMethodDoesntHydrateEagerRelationsWhenNoResultsAreReturned`
 * and the self-referential relation tests
 * (`testWithExistsOnSelfRelated`).
 *
 * Critical invariants:
 *   1. Empty parent set must NOT trigger any extra eager-load query.
 *   2. Self-referential `hasMany`/`belongsTo` (e.g. Category->Category)
 *      works — the eager loader can match a record to itself if the
 *      foreign key points there.
 *   3. Parents whose foreign key is null get an empty array (HAS_*) or
 *      null (BELONGS_TO) — not the entire related set.
 */
final class EagerLoadEdgeCasesTest extends \PHPUnit\Framework\TestCase
{
    private CountingPdo $pdo;
    private Connection $db;

    protected function setUp(): void
    {
        $this->pdo = new CountingPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db  = new Connection($this->pdo);

        $this->pdo->exec('CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER,
            name TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            title TEXT NOT NULL
        )');

        Record::clearConnections();
        Record::setConnection($this->db);
    }

    protected function tearDown(): void
    {
        Record::clearConnections();
    }

    public function testEmptyParentSetSkipsEagerLoadQuery(): void
    {
        // Seed nothing — parent set comes back empty.
        $this->pdo->resetCount();

        $users = EdgeCaseUser::query()->with('posts')->get();
        $this->assertSame([], $users);

        // Only the parent SELECT should have run; no posts query.
        $this->assertSame(1, $this->pdo->prepareCount());
    }

    public function testParentsWithNullForeignKeyGetEmptyChildSet(): void
    {
        // Two users — one with no posts. Eager-load posts.
        $this->pdo->exec("INSERT INTO users (email) VALUES ('a@x'), ('b@x')");
        $this->pdo->exec("INSERT INTO posts (user_id, title) VALUES (1, 'p1'), (1, 'p2')");

        $users = EdgeCaseUser::query()->with('posts')->orderBy('id')->get();
        $this->assertCount(2, $users[0]->posts);
        $this->assertSame([], $users[1]->posts);
    }

    public function testBelongsToWithNullForeignKeyResolvesToNull(): void
    {
        $this->pdo->exec("INSERT INTO users (email) VALUES ('a@x')");
        // post 1: legitimate link; post 2: NULL user_id
        $this->pdo->exec("INSERT INTO posts (user_id, title) VALUES (1, 'has-user'), (NULL, 'orphan')");

        $posts = EdgeCasePost::query()->with('user')->orderBy('id')->get();
        $this->assertNotNull($posts[0]->user);
        $this->assertSame('a@x', $posts[0]->user->email);
        $this->assertNull($posts[1]->user);
    }

    public function testSelfReferentialBelongsToParent(): void
    {
        // Tree: Root, Tech (parent=Root), PHP (parent=Tech).
        $this->pdo->exec("INSERT INTO categories (parent_id, name) VALUES
            (NULL, 'Root'), (1, 'Tech'), (2, 'PHP')");

        // Each category eager-loads its parent (a Category).
        $cats = EdgeCaseCategory::query()->with('parent')->orderBy('id')->get();

        $this->assertNull($cats[0]->parent);                       // Root
        $this->assertSame('Root', $cats[1]->parent->name);          // Tech → Root
        $this->assertSame('Tech', $cats[2]->parent->name);          // PHP → Tech
    }

    public function testSelfReferentialHasManyChildren(): void
    {
        $this->pdo->exec("INSERT INTO categories (parent_id, name) VALUES
            (NULL, 'Root'), (1, 'Tech'), (1, 'Music'), (2, 'PHP')");

        $cats = EdgeCaseCategory::query()->with('children')->orderBy('id')->get();
        $childNames = array_map(fn ($c) => $c->name, $cats[0]->children); // Root's kids
        $this->assertSame(['Tech', 'Music'], $childNames);

        $childNames = array_map(fn ($c) => $c->name, $cats[1]->children); // Tech's kids
        $this->assertSame(['PHP'], $childNames);

        $this->assertSame([], $cats[3]->children); // PHP — leaf
    }

    public function testNestedSelfReferentialEagerLoad(): void
    {
        $this->pdo->exec("INSERT INTO categories (parent_id, name) VALUES
            (NULL, 'Root'), (1, 'Tech'), (2, 'PHP'), (3, 'PHP8')");

        // Three queries: root level + children + grandchildren.
        $this->pdo->resetCount();
        $cats = EdgeCaseCategory::query()->with('children.children')->orderBy('id')->get();
        $this->assertSame(3, $this->pdo->prepareCount());

        $rootChildren = $cats[0]->children;
        $this->assertSame(['Tech'], array_map(fn ($c) => $c->name, $rootChildren));
        $this->assertSame(['PHP'], array_map(fn ($c) => $c->name, $rootChildren[0]->children));
    }
}

class EdgeCaseUser extends Record
{
    public const TABLE = 'users';

    public function posts(): Relation
    {
        return $this->hasMany(EdgeCasePost::class, 'user_id');
    }
}

class EdgeCasePost extends Record
{
    public const TABLE = 'posts';

    public function user(): Relation
    {
        return $this->belongsTo(EdgeCaseUser::class, 'user_id');
    }
}

class EdgeCaseCategory extends Record
{
    public const TABLE = 'categories';

    public function parent(): Relation
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): Relation
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
