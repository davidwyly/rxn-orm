<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\EdgeCases;

use Rxn\Orm\Builder\Query;
use Rxn\Orm\Tests\Support\SqliteTestCase;

/**
 * Operator handling. Compare with Laravel's `testOrderByInvalidDirectionParam`
 * + `testPrepareValueAndOperatorExpectException`.
 *
 * rxn-orm normalizes operator case (`HasWhere::assertWhereOperator`
 * uppercases for the allowlist check) and emits the user's case
 * verbatim — so `where('x', 'like', ...)` and `where('x', 'LIKE', ...)`
 * both work. orderBy direction is uppercased, validated, and
 * normalized.
 */
final class OperatorCaseTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec("INSERT INTO t VALUES (1, 'alpha'), (2, 'beta')");
    }

    public function testLowercaseLikeAccepted(): void
    {
        $rows = $this->db->table('t')->where('name', 'like', 'alpha%')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('alpha', $rows[0]['name']);
    }

    public function testMixedCaseLikeAccepted(): void
    {
        $rows = $this->db->table('t')->where('name', 'LiKe', 'alpha%')->get();
        $this->assertCount(1, $rows);
    }

    public function testLowercaseInAccepted(): void
    {
        $rows = $this->db->table('t')->where('id', 'in', [1, 2])->orderBy('id')->get();
        $this->assertCount(2, $rows);
    }

    public function testUnsupportedOperatorRejectedRegardlessOfCase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->select(['id'])->from('t')->where('id', 'XOR', 1);
    }

    public function testInvalidOrderByDirectionRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->select(['id'])->from('t')->orderBy('id', 'asec');
    }

    public function testOrderByDirectionLowercaseNormalizedToUpper(): void
    {
        [$sql] = (new Query())->select(['id'])->from('t')->orderBy('id', 'desc')->toSql();
        $this->assertStringContainsString('ORDER BY `id` DESC', $sql);
    }

    public function testNegativeLimitRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->select(['id'])->from('t')->limit(-1);
    }

    public function testNegativeOffsetRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->select(['id'])->from('t')->offset(-5);
    }
}
