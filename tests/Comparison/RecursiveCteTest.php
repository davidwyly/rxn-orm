<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Comparison;

use Rxn\Orm\Builder\Raw;

/**
 * Recursive CTEs — tree traversal.
 *
 * NEITHER Eloquent NOR rxn-orm has native recursive-CTE syntax.
 * Both fall back to raw SQL. Compare the cleanliness of the
 * fallback path:
 *
 *   // Eloquent:
 *   $rows = DB::select("WITH RECURSIVE descendants AS (
 *       SELECT id, parent_id, name FROM categories WHERE id = ?
 *       UNION ALL
 *       SELECT c.id, c.parent_id, c.name FROM categories c
 *       INNER JOIN descendants d ON c.parent_id = d.id
 *   ) SELECT * FROM descendants", [4]);
 *
 *   // rxn-orm:
 *   $rows = $db->select("WITH RECURSIVE descendants AS (...)", [4]);
 *
 * Both ORMs are equivalent here: a string + bindings.
 *
 * **Verdict:** TIE. Recursive CTEs are below both ORMs' abstraction
 * levels; both delegate to raw SQL. The honest answer is "use raw
 * SQL with bound parameters" — anything else is fake fluency.
 */
final class RecursiveCteTest extends ComplexQueryTestCase
{
    public function testFindAllDescendantsOfDatabaseCategory(): void
    {
        $sql = "WITH RECURSIVE descendants AS (
            SELECT id, parent_id, name FROM categories WHERE id = ?
            UNION ALL
            SELECT c.id, c.parent_id, c.name FROM categories c
            INNER JOIN descendants d ON c.parent_id = d.id
        )
        SELECT name FROM descendants ORDER BY id";

        $rows = $this->db->select($sql, [4]);
        $names = array_map(fn ($r) => $r['name'], $rows);
        // Database (id=4) and its children SQLite, Postgres
        $this->assertSame(['Database', 'SQLite', 'Postgres'], $names);
    }

    public function testWalkAncestryUpward(): void
    {
        // Find all ancestors of "SQLite" up to root
        $sql = "WITH RECURSIVE ancestry AS (
            SELECT id, parent_id, name, 0 AS depth FROM categories WHERE name = ?
            UNION ALL
            SELECT c.id, c.parent_id, c.name, a.depth + 1
            FROM categories c
            INNER JOIN ancestry a ON c.id = a.parent_id
        )
        SELECT name, depth FROM ancestry ORDER BY depth";

        $rows = $this->db->select($sql, ['SQLite']);
        $names = array_map(fn ($r) => $r['name'], $rows);
        $this->assertSame(['SQLite', 'Database', 'Tech', 'Root'], $names);
    }
}
