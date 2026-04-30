<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Comparison;

/**
 * Self-joins: when a table joins to itself. The classic "find users
 * who commented on their own posts" or "employees with their managers."
 *
 *   // Eloquent:
 *   DB::table('comments AS c')
 *       ->join('posts AS p', 'p.id', '=', 'c.post_id')
 *       ->whereColumn('c.user_id', '=', 'p.user_id')
 *       ->select('c.id', 'p.title', 'c.body')->get();
 *
 *   // rxn-orm:
 *   $db->table('comments', 'c')
 *       ->join('posts', 'p.id', '=', 'c.post_id', 'p')
 *       ->where('c.user_id', '=', Raw::of('p.user_id'))
 *       ->select(['c.id', 'p.title', 'c.body'])->get();
 *
 * Eloquent has `whereColumn` to express "this column = that column"
 * without binding. rxn-orm uses Raw::of() on the value side, which
 * is one extra import but works identically. **Verdict: very close;
 * Eloquent's `whereColumn` is slightly more discoverable.**
 *
 * (We may add `whereColumn()` as sugar in a future pass — it's ~10
 * LOC and a real ergonomics win.)
 */
final class SelfJoinTest extends ComplexQueryTestCase
{
    public function testFindCommentsByPostAuthor(): void
    {
        $rows = $this->db->table('comments', 'c')
            ->join('posts', 'p.id', '=', 'c.post_id', 'p')
            ->where('c.user_id', '=', \Rxn\Orm\Builder\Raw::of('p.user_id'))
            ->select(['c.id', 'p.title', 'c.body'])
            ->get();

        // alice (id=1) commented on her own post Gamma (id=3)
        $this->assertCount(1, $rows);
        $this->assertSame('Gamma', $rows[0]['title']);
        $this->assertSame('self-comment', $rows[0]['body']);
    }

    public function testTreeSelfJoinFindParentName(): void
    {
        // Find every category with its parent's name
        $rows = $this->db->table('categories', 'c')
            ->join('categories', 'p.id', '=', 'c.parent_id', 'p')
            ->select([
                'c.name', 'p.name AS parent_name',
            ])
            ->orderBy('c.id')
            ->get();

        $byChild = [];
        foreach ($rows as $r) {
            $byChild[$r['name']] = $r['parent_name'];
        }
        $this->assertSame('Tech', $byChild['PHP']);
        $this->assertSame('Database', $byChild['SQLite']);
        $this->assertSame('Database', $byChild['Postgres']);
        $this->assertSame('Root', $byChild['Music']);
    }
}
