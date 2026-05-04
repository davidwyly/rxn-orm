<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\EdgeCases;

use Rxn\Orm\Builder\Query;
use Rxn\Orm\Tests\Support\FakeDriverConnection;
use Rxn\Orm\Tests\Support\SqliteTestCase;

/**
 * JSON path-extraction edges. Compare with Laravel's
 * `testJsonPathEscaping`, `testMySqlUpdateWrappingJsonPathArrayIndex`
 * series.
 *
 * Currently rxn-orm's `column->key->subkey` shorthand requires
 * each segment to match `[A-Za-z_][A-Za-z0-9_]*`. That's strict —
 * it rejects array-index access (`items->0->name`) and Unicode keys.
 * The test suite here documents the current behavior; expanding
 * the grammar to support indices is filed as a follow-up.
 *
 * The strict matcher IS injection-safe: a path like
 * `settings'); DROP TABLE--` is rejected at validation time.
 */
final class JsonPathEdgesTest extends SqliteTestCase
{
    public function testInjectionAttemptInJsonPathRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->table('prefs')->where("settings->theme'); DROP TABLE", '=', 'x')->toSql();
    }

    public function testIntegerArrayIndexEmitsBracketNotation(): void
    {
        // SQLite/MySQL: `$[0].name`. Postgres: `->0->>'name'`.
        [$mysqlSql] = $this->db->table('prefs')
            ->where('items->0->name', '=', 'x')
            ->toSql();
        $this->assertStringContainsString("JSON_EXTRACT(`items`, '$[0].name')", $mysqlSql);
    }

    public function testPostgresArrayIndexEmitsUnquotedArrow(): void
    {
        [$sql] = (new Query())->select(['id'])->from('prefs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'pgsql'))
            ->where('items->0->name', '=', 'x')
            ->toSql();
        // After applyQuoting (`→") in Connection::execute, but we're
        // pre-execute here so backticks remain.
        $this->assertStringContainsString("`items`->0->>'name'", $sql);
    }

    public function testTrailingArrayIndex(): void
    {
        [$sql] = $this->db->table('prefs')
            ->where('tags->0', '=', 'php')
            ->toSql();
        $this->assertStringContainsString("JSON_EXTRACT(`tags`, '$[0]')", $sql);
    }

    public function testArrayIndexExecutesAgainstSqlite(): void
    {
        $this->pdo->exec('CREATE TABLE feeds (id INTEGER PRIMARY KEY, items TEXT NOT NULL)');
        $this->pdo->exec("INSERT INTO feeds (items) VALUES
            ('[{\"name\":\"alpha\"},{\"name\":\"beta\"}]'),
            ('[{\"name\":\"gamma\"}]')");

        $rows = $this->db->table('feeds')
            ->where('items->0->name', '=', 'alpha')
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int)$rows[0]['id']);
    }

    public function testUnicodeKeyRejected(): void
    {
        // Same restriction.
        $this->expectException(\InvalidArgumentException::class);
        $this->db->table('prefs')->where('settings->日本語', '=', 'x')->toSql();
    }

    public function testKeyWithUnderscoreAccepted(): void
    {
        $this->pdo->exec('CREATE TABLE prefs (id INTEGER PRIMARY KEY, settings TEXT)');
        [$sql] = $this->db->table('prefs')
            ->where('settings->user_name', '=', 'alice')
            ->toSql();
        $this->assertStringContainsString("JSON_EXTRACT(`settings`, '$.user_name')", $sql);
    }

    public function testMysqlAndPostgresEmitDifferentForms(): void
    {
        [$mysqlSql] = (new Query())->select(['id'])->from('prefs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'mysql'))
            ->where('settings->theme', '=', 'dark')
            ->toSql();
        $this->assertStringContainsString("JSON_EXTRACT(`settings`, '$.theme')", $mysqlSql);

        [$pgSql] = (new Query())->select(['id'])->from('prefs')
            ->setConnection(new FakeDriverConnection($this->pdo, 'pgsql'))
            ->where('settings->user->name', '=', 'a')
            ->toSql();
        $this->assertStringContainsString("`settings`->'user'->>'name'", $pgSql);
    }

    public function testFieldWithoutArrowFallsThroughUnchanged(): void
    {
        // Sanity check: `settings` alone (no `->`) is just a normal
        // identifier reference.
        [$sql] = $this->db->table('prefs')->where('settings', '=', 'x')->toSql();
        $this->assertStringContainsString('`settings` = ?', $sql);
        $this->assertStringNotContainsString('JSON_EXTRACT', $sql);
    }
}
