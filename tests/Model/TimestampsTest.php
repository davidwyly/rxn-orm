<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model;

use Rxn\Orm\Model\Record;
use Rxn\Orm\Tests\Model\Fixtures\Timestamped;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class TimestampsTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE timestamped (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        Record::clearConnections();
        Record::setConnection($this->db);
    }

    protected function tearDown(): void
    {
        Record::clearConnections();
        parent::tearDown();
    }

    public function testInsertStampsBothColumns(): void
    {
        $t = Timestamped::create(['label' => 'a']);
        $row = $this->db->table('timestamped')->find($t->id());
        $this->assertNotNull($row['created_at']);
        $this->assertNotNull($row['updated_at']);
        // Format: "Y-m-d H:i:s"
        $this->assertMatchesRegularExpression('#^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$#', $row['created_at']);
    }

    public function testUpdateOnlyStampsUpdatedAt(): void
    {
        $t = Timestamped::create(['label' => 'a']);
        $createdInitial = $t->created_at;
        $updatedInitial = $t->updated_at;

        // Sleep just enough that updated_at would change if it gets re-stamped.
        sleep(1);

        $t->label = 'b';
        $t->save();

        $row = $this->db->table('timestamped')->find($t->id());
        $this->assertSame($createdInitial, $row['created_at']);
        $this->assertNotSame($updatedInitial, $row['updated_at']);
        $this->assertGreaterThan($updatedInitial, $row['updated_at']);
    }

    public function testExplicitTimestampNotOverwrittenOnInsert(): void
    {
        $t = Timestamped::create([
            'label'      => 'a',
            'created_at' => '2020-01-01 00:00:00',
        ]);
        $row = $this->db->table('timestamped')->find($t->id());
        $this->assertSame('2020-01-01 00:00:00', $row['created_at']);
        // updated_at still auto-stamped (not explicitly provided)
        $this->assertNotSame('2020-01-01 00:00:00', $row['updated_at']);
    }
}
