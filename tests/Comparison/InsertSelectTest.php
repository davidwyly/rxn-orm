<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Comparison;

/**
 * INSERT ... SELECT — copy/transform rows from one table to another.
 * Useful for archive tables, materialized snapshots, and ETL.
 *
 *   // Eloquent: requires either DB::insertUsing($cols, $query) or raw SQL.
 *   //   DB::table('archive')->insertUsing(['id', 'title'],
 *   //     DB::table('posts')->where('published', false)->select('id', 'title'));
 *
 *   // rxn-orm: Insert builder doesn't currently expose a `fromQuery()`
 *   // method — the SELECT-source form is one of the gaps.
 *
 * **HONEST GAP:** rxn-orm doesn't have `Insert::fromQuery(Query)` yet.
 * Workaround: emit the SELECT and execute the INSERT ... SELECT
 * manually via Connection::statement(). Eloquent has `insertUsing`.
 *
 * Filed as a follow-up. ~30 LOC to add. The test below uses the
 * manual workaround.
 */
final class InsertSelectTest extends ComplexQueryTestCase
{
    public function testInsertSelectViaManualConcatenation(): void
    {
        // Create an archive table; copy unpublished posts into it.
        $this->pdo->exec('CREATE TABLE archived_posts (
            id INTEGER PRIMARY KEY,
            title TEXT NOT NULL,
            archived_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        [$selectSql, $bindings] = $this->db->table('posts')
            ->select(['id', 'title'])
            ->where('published', '=', 0)
            ->toSql();

        $this->db->statement(
            "INSERT INTO archived_posts (id, title) $selectSql",
            $bindings,
        );

        $rows = $this->db->table('archived_posts')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Epsilon', $rows[0]['title']);
    }
}
