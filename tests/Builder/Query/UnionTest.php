<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Builder\Query;

use Rxn\Orm\Builder\Query;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class UnionTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, region TEXT)');
        $this->pdo->exec('CREATE TABLE prospects (id INTEGER PRIMARY KEY, name TEXT, region TEXT)');
        $this->pdo->exec("INSERT INTO customers (name, region) VALUES
            ('alice', 'NA'), ('bob', 'EU'), ('carol', 'NA')");
        $this->pdo->exec("INSERT INTO prospects (name, region) VALUES
            ('dave', 'NA'), ('eve', 'EU'), ('alice', 'NA')");
    }

    public function testUnionShape(): void
    {
        $a = (new Query())->select(['name'])->from('customers')->where('region', '=', 'NA');
        $b = (new Query())->select(['name'])->from('prospects')->where('region', '=', 'NA');

        [$sql, $bindings] = (new Query())
            ->select(['name'])->from('customers')->where('region', '=', 'NA')
            ->union($b)
            ->toSql();

        $this->assertSame(
            'SELECT `name` FROM `customers` WHERE `region` = ? UNION SELECT `name` FROM `prospects` WHERE `region` = ?',
            $sql,
        );
        $this->assertSame(['NA', 'NA'], $bindings);
    }

    public function testUnionDeduplicates(): void
    {
        // 'alice' appears in both customers (NA) and prospects (NA) — UNION dedupes.
        $b = $this->db->table('prospects')->select(['name'])->where('region', '=', 'NA');
        $rows = $this->db->table('customers')->select(['name'])->where('region', '=', 'NA')
            ->union($b)
            ->orderBy('name')
            ->get();

        $names = array_map(fn ($r) => $r['name'], $rows);
        $this->assertSame(['alice', 'carol', 'dave'], $names);
    }

    public function testUnionAllPreservesDuplicates(): void
    {
        $b = $this->db->table('prospects')->select(['name'])->where('region', '=', 'NA');
        $rows = $this->db->table('customers')->select(['name'])->where('region', '=', 'NA')
            ->unionAll($b)
            ->orderBy('name')
            ->get();

        $names = array_map(fn ($r) => $r['name'], $rows);
        // 'alice' twice + 'carol' + 'dave'
        $this->assertSame(['alice', 'alice', 'carol', 'dave'], $names);
    }

    public function testOrderByAppliesToCombinedResult(): void
    {
        $b = $this->db->table('prospects')->select(['name']);
        $rows = $this->db->table('customers')->select(['name'])
            ->unionAll($b)
            ->orderBy('name', 'DESC')
            ->limit(3)
            ->get();

        $names = array_map(fn ($r) => $r['name'], $rows);
        $this->assertCount(3, $names);
        // Combined sorted desc: eve, dave, carol, bob, alice, alice
        $this->assertSame(['eve', 'dave', 'carol'], $names);
    }

    public function testMultipleUnionsChain(): void
    {
        $b = $this->db->table('prospects')->select(['name'])->where('region', '=', 'NA');
        $c = $this->db->table('prospects')->select(['name'])->where('region', '=', 'EU');
        $rows = $this->db->table('customers')->select(['name'])->where('region', '=', 'NA')
            ->unionAll($b)
            ->unionAll($c)
            ->orderBy('name')
            ->get();

        $names = array_map(fn ($r) => $r['name'], $rows);
        $this->assertSame(['alice', 'alice', 'carol', 'dave', 'eve'], $names);
    }

    public function testBindingsMergeInOrder(): void
    {
        // Each side has a bound parameter; outer adds limit (literal).
        $b = (new Query())->select(['name'])->from('prospects')->where('region', '=', 'EU');
        [, $bindings] = (new Query())
            ->select(['name'])->from('customers')->where('region', '=', 'NA')
            ->unionAll($b)
            ->limit(10)
            ->toSql();
        $this->assertSame(['NA', 'EU'], $bindings);
    }
}
