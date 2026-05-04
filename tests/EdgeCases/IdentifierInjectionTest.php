<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\EdgeCases;

use Rxn\Orm\Builder\Query;
use Rxn\Orm\Tests\Support\SqliteTestCase;

/**
 * Identifier safety. The builder sanitizes column/table/alias names
 * with `Builder::filterReference()` (strips backticks, whitespace,
 * keeps the first run of word/dot/underscore/hyphen chars). The
 * goal isn't full SQL-injection defense — values always go through
 * `?` placeholders — but to make sure a malicious identifier can't
 * escape the backtick wrapping into an executable suffix.
 *
 * Compares against Laravel's `testBasicTableWrappingProtectsQuotationMarks`
 * (which tests quote-doubling); our approach is "filter + escape"
 * rather than "escape", so the test surface differs but the goal
 * (no break-out from the identifier) is the same.
 */
final class IdentifierInjectionTest extends SqliteTestCase
{
    public function testBacktickInIdentifierIsStripped(): void
    {
        [$sql] = (new Query())->select(['id'])->from('users')
            ->where('`order`', '=', 1)
            ->toSql();
        // Backtick filtered out — emerges as plain `order`.
        $this->assertStringContainsString('`order` = ?', $sql);
        $this->assertStringNotContainsString('``', $sql);
    }

    public function testWhitespaceInIdentifierIsStripped(): void
    {
        // `filterReference` strips spaces and keeps the first word run.
        [$sql] = (new Query())->select(['id'])->from('users')
            ->where('user name', '=', 'x')
            ->toSql();
        // `user name` collapses to `username` (whitespace stripped).
        $this->assertStringContainsString('`username` = ?', $sql);
    }

    public function testInjectionAttemptViaIdentifierIsContained(): void
    {
        // The classic break-out attempt.
        [$sql] = (new Query())->select(['id'])->from('users')
            ->where('id; DROP TABLE users;--', '=', 1)
            ->toSql();
        // Filter only keeps [\p{L}\_\.\-\`0-9]+ — semicolons / spaces
        // cut off the match. The result is just `id`.
        $this->assertStringContainsString('`id` = ?', $sql);
        $this->assertStringNotContainsString('DROP', $sql);
    }

    public function testReservedKeywordAsColumnIsBacktickQuoted(): void
    {
        // `order`, `group`, `select` are SQL reserved words. Backtick-
        // quoting (or "double-quoting" on Postgres via Connection::applyQuoting)
        // makes them legal column references.
        $this->pdo->exec('CREATE TABLE k (id INTEGER PRIMARY KEY, "order" INTEGER, "group" TEXT)');
        $this->pdo->exec("INSERT INTO k (id, \"order\", \"group\") VALUES (1, 5, 'a'), (2, 3, 'b')");

        $rows = $this->db->table('k')
            ->where('order', '=', 5)
            ->where('group', '=', 'a')
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int)$rows[0]['id']);
    }

    public function testDottedReservedKeywordIdentifiers(): void
    {
        [$sql] = (new Query())->select(['id'])->from('orders', 'o')
            ->where('o.order', '=', 1)
            ->toSql();
        // table.col split is preserved — both parts get backticks.
        $this->assertStringContainsString('`o`.`order` = ?', $sql);
    }

    public function testEmptyIdentifierRefuses(): void
    {
        // After filtering, an empty identifier produces backtick-empty —
        // SQL-invalid but contained. Test the filter result.
        [$sql] = (new Query())->select(['id'])->from('users')
            ->where(';;;', '=', 1)
            ->toSql();
        $this->assertStringContainsString('`` = ?', $sql);
    }
}
