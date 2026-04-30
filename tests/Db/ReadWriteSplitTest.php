<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Db;

use PDO;
use PHPUnit\Framework\TestCase;
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Db\Connection;

/**
 * Verifies SELECTs route to the read PDO and writes to the primary,
 * except inside a transaction where everything must hit the primary
 * (replicas would otherwise return stale data for read-after-write
 * within the same transaction).
 *
 * We use two completely separate in-memory SQLite databases so we can
 * detect routing by checking which one the row actually appears in.
 */
final class ReadWriteSplitTest extends TestCase
{
    private PDO $writePdo;
    private PDO $readPdo;
    private Connection $db;

    protected function setUp(): void
    {
        $this->writePdo = new PDO('sqlite::memory:');
        $this->readPdo  = new PDO('sqlite::memory:');
        foreach ([$this->writePdo, $this->readPdo] as $p) {
            $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $p->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, label TEXT)');
        }
        // Distinguish the two by seeding different rows.
        $this->writePdo->exec("INSERT INTO t (label) VALUES ('write-side')");
        $this->readPdo->exec("INSERT INTO t (label) VALUES ('read-side')");

        $this->db = new Connection($this->writePdo, $this->readPdo);
    }

    public function testSelectsRouteToReadReplica(): void
    {
        $rows = $this->db->table('t')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('read-side', $rows[0]['label']);
    }

    public function testWritesGoToPrimary(): void
    {
        $this->db->run((new Insert())->into('t')->row(['label' => 'fresh']));

        // Primary now has 2 rows; replica untouched.
        $primaryCount = (int)$this->writePdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
        $replicaCount = (int)$this->readPdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
        $this->assertSame(2, $primaryCount);
        $this->assertSame(1, $replicaCount);
    }

    public function testSelectsInsideTransactionRouteToPrimary(): void
    {
        $this->db->transaction(function (Connection $db) {
            $db->run((new Insert())->into('t')->row(['label' => 'tx-row']));
            // Read inside the transaction must see our just-inserted row,
            // which only exists on the primary — not the replica.
            $rows = $db->table('t')->orderBy('id', 'DESC')->get();
            $this->assertSame('tx-row', $rows[0]['label']);
            $this->assertSame('write-side', $rows[1]['label']);
        });
    }

    public function testWithoutReadPdoSelectsHitWriteConnection(): void
    {
        $singlePdo = new PDO('sqlite::memory:');
        $singlePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $singlePdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, label TEXT)');
        $singlePdo->exec("INSERT INTO t (label) VALUES ('single')");

        $db = new Connection($singlePdo);
        $rows = $db->table('t')->get();
        $this->assertSame('single', $rows[0]['label']);
        $this->assertSame($singlePdo, $db->getReadPdo());
    }

    public function testGetReadPdoReturnsPrimaryDuringTransaction(): void
    {
        $this->assertSame($this->readPdo, $this->db->getReadPdo());
        $this->db->beginTransaction();
        try {
            $this->assertSame($this->writePdo, $this->db->getReadPdo());
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame($this->readPdo, $this->db->getReadPdo());
    }
}
