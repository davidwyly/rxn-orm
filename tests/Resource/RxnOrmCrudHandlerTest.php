<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Resource;

use Rxn\Orm\Tests\Resource\Fixture\CreateWidget;
use Rxn\Orm\Tests\Resource\Fixture\SearchWidgets;
use Rxn\Orm\Tests\Resource\Fixture\UpdateWidget;
use Rxn\Orm\Tests\Resource\Fixture\WidgetCrud;
use Rxn\Orm\Tests\Support\SqliteTestCase;

/**
 * End-to-end tests for `RxnOrmCrudHandler`. Drives every
 * `CrudHandler` method against a live in-memory SQLite database
 * — proves the abstract class's default behavior produces real
 * SQL that real engines accept and execute.
 *
 * The contract on trial:
 *
 *   1. `create()` runs an INSERT, then re-reads the row by
 *      `lastInsertId()` so server-side defaults (auto-increment
 *      id, default columns) come back to the caller.
 *   2. `read()` returns the matching row or null. PK column is
 *      configurable (subclass overrides `PK`).
 *   3. `update()` returns null on missing-id (registrar maps to
 *      404), applies only non-null DTO fields (PATCH semantics),
 *      and re-reads to return the post-update row.
 *   4. `delete()` returns true on rowcount > 0, false on missing
 *      (registrar maps to 204 / 404).
 *   5. `search()` calls `applyFilter()` to translate the DTO
 *      into WHERE clauses; default pass-through returns every
 *      row.
 *   6. Subclass-only setup: extend, set `TABLE` constant, the
 *      five methods work without further override.
 *   7. Empty `TABLE` constant fails loud.
 */
final class RxnOrmCrudHandlerTest extends SqliteTestCase
{
    private WidgetCrud $crud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec(
            "CREATE TABLE widgets (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                name   TEXT    NOT NULL,
                price  INTEGER NOT NULL DEFAULT 0,
                status TEXT    NOT NULL DEFAULT 'draft'
            )"
        );
        $this->crud = new WidgetCrud($this->db);
    }

    public function testCreateInsertsRowAndReturnsItWithGeneratedId(): void
    {
        $dto         = new CreateWidget();
        $dto->name   = 'Widget';
        $dto->price  = 9;
        $dto->status = 'published';

        $row = $this->crud->create($dto);

        $this->assertSame(1, $row['id'], 'auto-increment PK must come back from create');
        $this->assertSame('Widget',   $row['name']);
        $this->assertSame(9,          $row['price']);
        $this->assertSame('published', $row['status']);
    }

    public function testCreateRespectsServerSideDefaults(): void
    {
        // The schema defaults `status` to 'draft' and `price` to
        // 0. The DTO defaults match, but the test verifies the
        // create flow re-reads the row so any server-applied
        // defaults (e.g. timestamps in real schemas) come back.
        $dto       = new CreateWidget();
        $dto->name = 'Defaulted';

        $row = $this->crud->create($dto);

        $this->assertSame('draft', $row['status']);
        $this->assertSame(0,        $row['price']);
    }

    public function testReadReturnsRowOrNull(): void
    {
        $created = $this->crud->create($this->dto('Found', 5));

        $hit  = $this->crud->read($created['id']);
        $miss = $this->crud->read(999);

        $this->assertNotNull($hit);
        $this->assertSame('Found', $hit['name']);
        $this->assertNull($miss);
    }

    public function testUpdateAppliesPartialFieldsOnly(): void
    {
        $created = $this->crud->create($this->dto('Original', 10, 'draft'));

        $patch         = new UpdateWidget();
        $patch->name   = 'Renamed';

        $updated = $this->crud->update($created['id'], $patch);

        $this->assertNotNull($updated);
        $this->assertSame('Renamed', $updated['name']);
        // Other columns untouched because the DTO left them null.
        $this->assertSame(10,        $updated['price']);
        $this->assertSame('draft',   $updated['status']);
    }

    public function testUpdateOnMissingIdReturnsNull(): void
    {
        $patch       = new UpdateWidget();
        $patch->name = 'No-op';

        $this->assertNull($this->crud->update(999, $patch));
    }

    public function testUpdateWithEmptyPatchReturnsExistingRowUnchanged(): void
    {
        // PATCH with all-null fields is a no-op on the DB side
        // (no UPDATE issued — the SET clause would be empty,
        // which most engines reject). The handler treats this as
        // "nothing to do, return the row as-is" rather than
        // 404'ing or erroring.
        $created = $this->crud->create($this->dto('Untouched', 7));

        $empty = new UpdateWidget(); // all properties null

        $result = $this->crud->update($created['id'], $empty);

        $this->assertNotNull($result);
        $this->assertSame('Untouched', $result['name']);
        $this->assertSame(7,           $result['price']);
    }

    public function testDeleteReturnsTrueOnSuccessAndFalseOnMissing(): void
    {
        $created = $this->crud->create($this->dto('ToDelete', 1));

        $this->assertTrue($this->crud->delete($created['id']));
        $this->assertNull($this->crud->read($created['id']), 'deleted row must be gone');
        $this->assertFalse($this->crud->delete(999), 'second delete on missing id is false');
    }

    public function testSearchWithoutFilterReturnsEveryRow(): void
    {
        $this->crud->create($this->dto('A', 1));
        $this->crud->create($this->dto('B', 2));
        $this->crud->create($this->dto('C', 3));

        $rows = $this->crud->search(null);

        $this->assertCount(3, $rows);
    }

    public function testSearchAppliesFilterFromDto(): void
    {
        $this->crud->create($this->dto('Apple',  1, 'published'));
        $this->crud->create($this->dto('Banana', 2, 'published'));
        $this->crud->create($this->dto('Cherry', 3, 'draft'));

        $filter         = new SearchWidgets();
        $filter->status = 'published';

        $rows = $this->crud->search($filter);

        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Apple',  $names);
        $this->assertContains('Banana', $names);
    }

    public function testSearchCombinesMultipleFilterFields(): void
    {
        $this->crud->create($this->dto('Apple',  1, 'published'));
        $this->crud->create($this->dto('Apricot', 2, 'published'));
        $this->crud->create($this->dto('Banana', 3, 'published'));

        $filter         = new SearchWidgets();
        $filter->status = 'published';
        $filter->q      = 'Ap';

        $rows = $this->crud->search($filter);

        $this->assertCount(2, $rows);
        $this->assertSame(['Apple', 'Apricot'], array_column($rows, 'name'));
    }

    public function testEmptyTableConstantThrows(): void
    {
        // Any subclass that forgot to set TABLE must blow up
        // with a clear LogicException, not silently produce
        // empty results.
        $broken = new class($this->db) extends \Rxn\Orm\Resource\RxnOrmCrudHandler {
            // TABLE deliberately not overridden.
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/TABLE constant/');
        $broken->search(null);
    }

    public function testCustomPkColumn(): void
    {
        // Apps with non-`id` primary keys override the PK
        // constant. Verify it actually flows through to the
        // emitted WHERE clause.
        $this->pdo->exec(
            "CREATE TABLE entities (
                uuid TEXT PRIMARY KEY,
                label TEXT NOT NULL
            )"
        );
        $this->pdo->exec(
            "INSERT INTO entities (uuid, label) VALUES ('abc-123', 'Alpha')"
        );

        $custom = new class($this->db) extends \Rxn\Orm\Resource\RxnOrmCrudHandler {
            public const TABLE = 'entities';
            public const PK    = 'uuid';
        };

        $row = $custom->read('abc-123');
        $this->assertNotNull($row);
        $this->assertSame('Alpha', $row['label']);

        $this->assertNull($custom->read('does-not-exist'));
    }

    private function dto(string $name, int $price, string $status = 'draft'): CreateWidget
    {
        $dto         = new CreateWidget();
        $dto->name   = $name;
        $dto->price  = $price;
        $dto->status = $status;
        return $dto;
    }
}
