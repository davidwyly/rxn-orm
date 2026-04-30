<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Comparison;

use Rxn\Orm\Builder\Raw;
use Rxn\Orm\Builder\Update;

/**
 * Bulk operations: UPDATE/DELETE based on a join or correlated
 * subquery. The "promote everyone whose total purchases exceed $X"
 * use case.
 *
 *   // Eloquent: requires raw SQL or join() chained on update —
 *   // dialect varies by driver (MySQL UPDATE ... JOIN, Postgres
 *   // UPDATE ... FROM, SQLite has neither).
 *
 *   // rxn-orm: same constraint — UPDATE-with-JOIN is dialect-specific
 *   // and cannot be portably expressed by either ORM.
 *
 * The portable workaround is a **correlated subquery in the SET
 * clause** (or in WHERE). We support this via Raw::of in set() values:
 *
 *   $update->set(['cached_total' => Raw::of('(SELECT SUM(...) FROM ...)')]);
 *
 * **Verdict: tied. Both ORMs require Raw fragments for any UPDATE
 * that pulls values from another table.** The honest answer for
 * portable bulk UPDATE is "use a correlated subquery in SET" —
 * this test demonstrates rxn-orm's path.
 */
final class BulkUpdateTest extends ComplexQueryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('ALTER TABLE users ADD COLUMN comment_count INTEGER NOT NULL DEFAULT 0');
    }

    public function testBulkUpdateViaCorrelatedSubquery(): void
    {
        // Update users.comment_count to the actual COUNT(*) from comments.
        // Portable across MySQL / Postgres / SQLite.
        (new Update())
            ->table('users')
            ->set(['comment_count' => Raw::of(
                '(SELECT COUNT(*) FROM comments WHERE comments.user_id = users.id)',
            )])
            ->allowEmptyWhere() // we want EVERY row updated
            ->setConnection($this->db)
            ->execute();

        $rows = $this->db->table('users')->orderBy('id')->get();
        // alice: 1 comment (#4 on Gamma), bob: 1 (#1), carol: 1 (#2),
        // dave: 1 (#3). Recheck: comments are
        //   (1, 1, 2, 'nice'),    -- post 1, by user 2 (bob)
        //   (2, 1, 3, 'agreed'),  -- by user 3 (carol)
        //   (3, 2, 4, 'meh'),     -- by user 4 (dave)
        //   (4, 3, 1, 'self'),    -- by user 1 (alice)
        //   (5, 6, 1, 'cool'),    -- by user 1 (alice)
        // → alice: 2, bob: 1, carol: 1, dave: 1
        $this->assertSame(2, (int)$rows[0]['comment_count']);
        $this->assertSame(1, (int)$rows[1]['comment_count']);
        $this->assertSame(1, (int)$rows[2]['comment_count']);
        $this->assertSame(1, (int)$rows[3]['comment_count']);
    }

    public function testBulkUpdateWhereInSubquery(): void
    {
        // Mark all posts authored by users with at least 2 posts as "popular"
        $this->pdo->exec('ALTER TABLE posts ADD COLUMN tag TEXT');

        (new Update())
            ->table('posts')
            ->set(['tag' => 'popular'])
            ->whereIn(
                'user_id',
                $this->db->query()
                    ->select(['user_id'])
                    ->from('posts')
                    ->groupBy('user_id')
                    ->having('COUNT(*) >= 2'),
            )
            ->setConnection($this->db)
            ->execute();

        $tagged = (int)$this->db->table('posts')->where('tag', '=', 'popular')->count();
        // alice has 3, bob has 2, carol has 2 — all 7 posts get tagged
        $this->assertSame(7, $tagged);
    }
}
