<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model;

use Rxn\Orm\Model\Record;
use Rxn\Orm\Tests\Model\Fixtures\Hookable;
use Rxn\Orm\Tests\Support\SqliteTestCase;

final class HooksTest extends SqliteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE hookable (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT
        )');
        Record::clearConnections();
        Record::setConnection($this->db);
    }

    protected function tearDown(): void
    {
        Record::clearConnections();
        parent::tearDown();
    }

    public function testInsertFiresBeforeAndAfter(): void
    {
        $h = new Hookable();
        $h->label = 'a';
        $h->save();
        $this->assertSame(['beforeInsert', 'afterSave'], $h->log);
    }

    public function testUpdateFiresBeforeAndAfter(): void
    {
        $h = new Hookable();
        $h->label = 'a';
        $h->save();
        $h->log = [];

        $h->label = 'b';
        $h->save();
        $this->assertSame(['beforeUpdate', 'afterSave'], $h->log);
    }

    public function testDeleteFiresBeforeAndAfter(): void
    {
        $h = new Hookable();
        $h->label = 'a';
        $h->save();
        $h->log = [];

        $h->delete();
        $this->assertSame(['beforeDelete', 'afterDelete'], $h->log);
    }

    public function testNoOpSaveStillFiresAfterSave(): void
    {
        $h = new Hookable();
        $h->label = 'a';
        $h->save();
        $h->log = [];

        // Save with no dirty attributes
        $this->assertFalse($h->save());
        // beforeSave still ran, afterSave still ran — even though no SQL was emitted
        $this->assertSame(['beforeUpdate', 'afterSave'], $h->log);
    }
}
