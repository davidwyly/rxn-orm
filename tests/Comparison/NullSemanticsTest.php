<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Comparison;

use Rxn\Orm\Builder\Raw;

/**
 * The NULL-handling pitfalls — every ORM stumbles into these.
 *
 *   1. `WHERE col NOT IN (subquery returning NULL)` returns ZERO rows
 *      because `x != NULL` is UNKNOWN, not TRUE. This is THE classic
 *      SQL gotcha. NEITHER ORM warns you about it.
 *
 *   2. `=` doesn't match NULL — must use `IS NULL`. Both ORMs require
 *      `whereIsNull` / `whereNull`.
 *
 *   3. NULL-safe equality (`<=>` MySQL, `IS NOT DISTINCT FROM`
 *      Postgres/SQLite). Neither ORM offers fluent support.
 *
 * This test file documents the gotchas via assertions so devs see
 * the failure modes, not just the happy path.
 */
final class NullSemanticsTest extends ComplexQueryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Categories table has parent_id = NULL for the root.
        // Build a "find all categories whose id is NOT IN (set of parents)"
        // query — i.e., leaf categories. The set of parents includes NULL
        // values which makes NOT IN return zero rows naively.
    }

    public function testNotInWithNullsReturnsEmpty_TheGotcha(): void
    {
        // Subquery returns parent_ids — but root's parent_id is NULL.
        // NOT IN (1, 2, 4, NULL) evaluates to UNKNOWN for every row,
        // so zero results. THIS IS THE BUG everyone hits.
        $rows = $this->db->select("
            SELECT id, name FROM categories
            WHERE id NOT IN (SELECT parent_id FROM categories)
        ");
        $this->assertSame([], $rows, 'NOT IN with NULL is the classic SQL trap');
    }

    public function testNotInWithNullsCorrected(): void
    {
        // The fix: filter NULLs out of the subquery, OR use NOT EXISTS
        // (which has clean NULL semantics).
        $rows = $this->db->table('categories')
            ->whereNotExists(
                $this->db->table('categories', 'p')
                    ->where('p.parent_id', '=', Raw::of('categories.id'))
                    ->select([Raw::of('1')]),
            )
            ->orderBy('id')
            ->get();

        $names = array_map(fn ($r) => $r['name'], $rows);
        // Leaf categories (no children): PHP, SQLite, Postgres, Music
        $this->assertSame(['PHP', 'SQLite', 'Postgres', 'Music'], $names);
    }

    public function testWhereEqualsNullDoesNotMatch(): void
    {
        // `= NULL` returns no rows. `IS NULL` is the only correct form.
        $bad = $this->db->table('categories')->where('parent_id', '=', null)->get();
        $this->assertSame([], $bad, 'WHERE col = NULL is always UNKNOWN');

        $good = $this->db->table('categories')->whereIsNull('parent_id')->get();
        $this->assertCount(1, $good);
        $this->assertSame('Root', $good[0]['name']);
    }

    public function testNullSafeEqualityRequiresRaw(): void
    {
        // SQLite: IS NOT DISTINCT FROM (or just IS for NULL handling).
        // Both rxn-orm and Eloquent fall back to raw expressions here.
        $count = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM categories WHERE parent_id IS NOT DISTINCT FROM ?",
            [null],
        );
        $this->assertSame(1, (int)$count['c']);
    }
}
