<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model;

use Rxn\Orm\Model\Record;
use Rxn\Orm\Tests\Model\Fixtures\User;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class RecordTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            settings TEXT
        )');
        Record::clearConnections();
        Record::setConnection($this->db);
    }

    protected function tearDown(): void
    {
        Record::clearConnections();
        parent::tearDown();
    }

    public function testCreateInsertsAndHydrates(): void
    {
        $user = User::create(['email' => 'a@x', 'active' => 1]);
        $this->assertTrue($user->exists());
        $this->assertSame(1, $user->id());
        $this->assertSame('a@x', $user->email);
    }

    public function testFindReturnsHydratedInstance(): void
    {
        $this->pdo->exec("INSERT INTO users (email, active) VALUES ('a@x', 1)");
        $user = User::find(1);
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('a@x', $user->email);
        $this->assertSame(1, $user->id());
    }

    public function testFindReturnsNullWhenMissing(): void
    {
        $this->assertNull(User::find(999));
    }

    public function testFindOrFailThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        User::findOrFail(999);
    }

    public function testCastIntAndBoolOnRead(): void
    {
        $this->pdo->exec("INSERT INTO users (email, active) VALUES ('a@x', 1)");
        $user = User::find(1);
        $this->assertSame(1, $user->id);          // int cast
        $this->assertTrue($user->active);          // bool cast
    }

    public function testJsonCastRoundTrip(): void
    {
        $user = User::create([
            'email'    => 'j@x',
            'active'   => 1,
            'settings' => ['theme' => 'dark', 'size' => 14],
        ]);
        // Stored as JSON string in DB
        $row = $this->db->table('users')->find($user->id());
        $this->assertIsString($row['settings']);
        $this->assertSame('{"theme":"dark","size":14}', $row['settings']);

        // Retrieved as array via cast
        $reloaded = User::find($user->id());
        $this->assertSame(['theme' => 'dark', 'size' => 14], $reloaded->settings);
    }

    public function testSaveOnlyUpdatesDirtyColumns(): void
    {
        $this->pdo->exec("INSERT INTO users (email, active) VALUES ('a@x', 1)");
        $user = User::find(1);
        $this->assertSame([], $user->dirtyForUpdate());

        $user->email = 'changed@x';
        $this->assertSame(['email' => 'changed@x'], $user->dirtyForUpdate());

        $user->save();
        $reloaded = User::find(1);
        $this->assertSame('changed@x', $reloaded->email);
    }

    public function testSaveReturnsFalseWhenNothingDirty(): void
    {
        $this->pdo->exec("INSERT INTO users (email, active) VALUES ('a@x', 1)");
        $user = User::find(1);
        $this->assertFalse($user->save());
    }

    public function testDeleteRemovesRow(): void
    {
        $user = User::create(['email' => 'a@x', 'active' => 1]);
        $this->assertTrue($user->delete());
        $this->assertNull(User::find(1));
        $this->assertFalse($user->exists());
    }

    public function testRefreshPullsNewState(): void
    {
        $user = User::create(['email' => 'a@x', 'active' => 1]);
        $this->db->table('users')->where('id', '=', $user->id())->get(); // ensure connection used
        $this->pdo->exec("UPDATE users SET email = 'fresh@x' WHERE id = " . $user->id());
        $user->refresh();
        $this->assertSame('fresh@x', $user->email);
    }

    public function testFillRespectsFillableAllowlist(): void
    {
        $cls = new class extends \Rxn\Orm\Tests\Model\Fixtures\User {
            public const TABLE = 'users';
            protected static ?array $fillable = ['email'];
        };
        $u = new $cls();
        $u->fill(['email' => 'ok@x', 'active' => 0]);
        $this->assertSame('ok@x', $u->email);
        $this->assertNull($u->active); // active was filtered
    }

    public function testWhereStaticReturnsModelQuery(): void
    {
        $this->pdo->exec("INSERT INTO users (email, active) VALUES ('a@x', 1), ('b@x', 0)");
        $actives = User::where('active', '=', 1)->get();
        $this->assertCount(1, $actives);
        $this->assertSame('a@x', $actives[0]->email);
    }

    public function testAllReturnsAllRecords(): void
    {
        $this->pdo->exec("INSERT INTO users (email, active) VALUES ('a@x', 1), ('b@x', 0)");
        $this->assertCount(2, User::all());
    }

    public function testToArraySerializesAttributesAndCasts(): void
    {
        $this->pdo->exec("INSERT INTO users (email, active, settings) VALUES ('a@x', 1, '{\"k\":\"v\"}')");
        $user = User::find(1);
        $arr = $user->toArray();
        $this->assertSame(1, $arr['id']);
        $this->assertTrue($arr['active']);
        $this->assertSame(['k' => 'v'], $arr['settings']);
    }

    public function testThrowsWithoutConnection(): void
    {
        Record::clearConnections();
        $this->expectException(\LogicException::class);
        User::find(1);
    }
}
