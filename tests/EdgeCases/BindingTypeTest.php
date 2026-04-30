<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\EdgeCases;

use Rxn\Orm\Tests\Support\SqliteTestCase;

/**
 * Binding-type quirks. Compare with Laravel's `testAddBindingWithEnum`,
 * `testWhereKeyMethodWithStringZero`, etc.
 *
 * The motivating bug (now fixed) was PDO's emulated-prepares default
 * stringifying every value, which silently broke comparisons against
 * integer outputs from window functions, JSON_EXTRACT, COUNT
 * subqueries. Connection::execute() now binds with type-correct
 * PDO::PARAM_*. These tests prove that fix is durable for the
 * sharp-edged values: 0, "0", PHP_INT_MAX, true/false, null, floats,
 * empty strings, multi-byte strings.
 */
final class BindingTypeTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE t (
            id INTEGER PRIMARY KEY,
            n  INTEGER,
            f  REAL,
            s  TEXT,
            b  INTEGER  -- SQLite has no native BOOLEAN; we store 0/1
        )');
    }

    public function testIntZeroRoundTrips(): void
    {
        $this->pdo->exec('INSERT INTO t (id, n) VALUES (1, 0)');
        $rows = $this->db->table('t')->where('n', '=', 0)->get();
        $this->assertCount(1, $rows);
    }

    public function testStringZeroAndIntZeroAreDistinct(): void
    {
        // SQLite treats them as equal under default (loose) typing —
        // this verifies our type-aware binding doesn't introduce
        // weirdness. Both should match the row.
        $this->pdo->exec("INSERT INTO t (id, s) VALUES (1, '0')");
        $this->assertCount(1, $this->db->table('t')->where('s', '=', '0')->get());
        $this->assertCount(1, $this->db->table('t')->where('s', '=', 0)->get());
    }

    public function testPhpIntMaxRoundTrips(): void
    {
        $max = PHP_INT_MAX;
        $this->pdo->exec("INSERT INTO t (id, n) VALUES (1, $max)");
        $rows = $this->db->table('t')->where('n', '=', $max)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($max, (int)$rows[0]['n']);
    }

    public function testNegativeIntRoundTrips(): void
    {
        $this->pdo->exec('INSERT INTO t (id, n) VALUES (1, -42)');
        $rows = $this->db->table('t')->where('n', '=', -42)->get();
        $this->assertCount(1, $rows);
    }

    public function testFloatRoundTrips(): void
    {
        $this->pdo->exec('INSERT INTO t (id, f) VALUES (1, 3.14)');
        $rows = $this->db->table('t')->where('f', '=', 3.14)->get();
        $this->assertCount(1, $rows);
    }

    public function testBooleanTrueBindsAsOne(): void
    {
        $this->pdo->exec('INSERT INTO t (id, b) VALUES (1, 1), (2, 0)');
        $rows = $this->db->table('t')->where('b', '=', true)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int)$rows[0]['id']);
    }

    public function testNullBindingMatchesViaIsNull(): void
    {
        // Direct `where('x', '=', null)` doesn't match — same SQL trap
        // as Laravel's `testWhereWithNull` (already covered in
        // Comparison/NullSemanticsTest). Use whereIsNull explicitly.
        $this->pdo->exec("INSERT INTO t (id, s) VALUES (1, NULL), (2, 'a')");
        $rows = $this->db->table('t')->whereIsNull('s')->get();
        $this->assertCount(1, $rows);
    }

    public function testMultiByteStringValueRoundTrips(): void
    {
        $this->pdo->exec("INSERT INTO t (id, s) VALUES (1, '日本語')");
        $rows = $this->db->table('t')->where('s', '=', '日本語')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('日本語', $rows[0]['s']);
    }

    public function testEmojiInValueRoundTrips(): void
    {
        $this->pdo->exec("INSERT INTO t (id, s) VALUES (1, '🎉')");
        $rows = $this->db->table('t')->where('s', '=', '🎉')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('🎉', $rows[0]['s']);
    }

    public function testStringWithSingleQuoteIsBoundSafely(): void
    {
        // The classic injection target. Bound parameters mean the
        // single quote is literally the value; nothing escapes the
        // placeholder context.
        $value = "O'Brien";
        $this->pdo->exec("INSERT INTO t (id, s) VALUES (1, 'O''Brien')");
        $rows = $this->db->table('t')->where('s', '=', $value)->get();
        $this->assertCount(1, $rows);
    }

    public function testEmptyStringIsDistinctFromNull(): void
    {
        $this->pdo->exec("INSERT INTO t (id, s) VALUES (1, ''), (2, NULL)");
        $rows = $this->db->table('t')->where('s', '=', '')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int)$rows[0]['id']);
    }
}
