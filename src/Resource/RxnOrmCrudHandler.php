<?php declare(strict_types=1);

namespace Rxn\Orm\Resource;

use Rxn\Framework\Http\Binding\RequestDto;
use Rxn\Framework\Http\Resource\CrudHandler;
use Rxn\Orm\Builder\Delete;
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Builder\Update;
use Rxn\Orm\Db\Connection;

/**
 * Default `CrudHandler` implementation backed by rxn-orm's SQL
 * builders. Subclasses set `TABLE` (and optionally `PK`) and get
 * a fully functional five-method handler — no SQL boilerplate.
 *
 *   final class ProductsCrud extends RxnOrmCrudHandler {}
 *
 *   ResourceRegistrar::register($router, '/products',
 *       new ProductsCrud($db),
 *       create: CreateProduct::class,
 *       update: UpdateProduct::class,
 *       search: SearchProducts::class,
 *   );
 *
 * Storage strategy:
 *
 *   - `create()` runs an INSERT, then re-reads the row by
 *     `lastInsertId()` so the wire shape includes server-side
 *     defaults (auto-increment id, timestamps, generated
 *     columns). Apps using DBs without `lastInsertId()` semantics
 *     (composite PKs, no-auto-increment) override `create()`.
 *   - `read()` and `find row before write` use the same
 *     single-row SELECT, scoped to the configured PK column.
 *   - `update()` reads first, returns null if nothing exists,
 *     then UPDATE-by-PK with only the populated DTO fields
 *     (partial PATCH semantics). Re-reads to return the post-
 *     update row.
 *   - `delete()` returns `true` only when a row was actually
 *     removed; the registrar maps that to 204 / 404 correctly.
 *   - `search()` runs the SELECT against the table; the default
 *     `applyFilter()` is a pass-through, so apps with filter
 *     DTOs override that one method (rather than re-writing all
 *     of `search()`).
 *
 * **Override hooks for customisation:**
 *
 *   - `applyFilter(Query $q, RequestDto $filter): Query` — turn
 *     filter DTO fields into `WHERE` clauses (the most common
 *     extension; default returns the unmodified query).
 *   - `dtoToRow(RequestDto $dto, bool $partial): array` —
 *     transform a DTO into the column map for INSERT/UPDATE.
 *     Default takes every public property as-is; override when
 *     DTO field names diverge from column names, or to skip
 *     framework-internal fields.
 *
 * The handler is intentionally storage-shaped, not domain-shaped.
 * Apps that want hydrated `Record` instances + relations write a
 * different handler that delegates to `Record::find()` and
 * `Record::toArray()`.
 */
abstract class RxnOrmCrudHandler implements CrudHandler
{
    /**
     * Database table name. Subclasses MUST override (the empty
     * default produces a clear runtime error rather than a silent
     * "no rows" result).
     */
    public const TABLE = '';

    /** Primary-key column name. Override for non-`id` PKs. */
    public const PK = 'id';

    public function __construct(protected readonly Connection $db) {}

    public function create(RequestDto $dto): array
    {
        $this->assertTable();
        $row = $this->dtoToRow($dto, partial: false);
        $insert = (new Insert())->into(static::TABLE)->row($row);
        $this->db->insert($insert);
        $id  = $this->db->lastInsertId();
        $row = $this->read($this->coercePk($id));
        if ($row === null) {
            // Edge case: insert succeeded but the row vanished
            // before we could read it back. Realistically this
            // means the test connection lost the data, or a
            // trigger nuked the row. Surface as a runtime error
            // rather than returning a half-real shape.
            throw new \RuntimeException(
                'RxnOrmCrudHandler: row inserted but could not be read back from ' . static::TABLE,
            );
        }
        return $row;
    }

    public function read(int|string $id): ?array
    {
        $this->assertTable();
        $row = $this->db->selectOne(
            (new Query())
                ->select(['*'])
                ->from(static::TABLE)
                ->where(static::PK, '=', $id)
                ->limit(1),
        );
        return $row;
    }

    public function update(int|string $id, RequestDto $dto): ?array
    {
        $this->assertTable();
        $existing = $this->read($id);
        if ($existing === null) {
            return null;
        }
        $patch = $this->dtoToRow($dto, partial: true);
        if ($patch === []) {
            // No fields to apply — return the row unchanged.
            // PATCH with an empty body is a no-op, not a 404.
            return $existing;
        }
        $update = (new Update())
            ->table(static::TABLE)
            ->set($patch)
            ->where(static::PK, '=', $id);
        $this->db->update($update);
        return $this->read($id);
    }

    public function delete(int|string $id): bool
    {
        $this->assertTable();
        // Run as a single DELETE-and-check-affected. rowCount on a
        // DELETE that touched 0 rows is the missing-row signal we
        // need for the registrar's 204 / 404 mapping.
        $delete = (new Delete())
            ->from(static::TABLE)
            ->where(static::PK, '=', $id);
        $affected = $this->db->delete($delete);
        return is_int($affected) ? $affected > 0 : $affected !== [];
    }

    public function search(?RequestDto $filter): array
    {
        $this->assertTable();
        $query = (new Query())->select(['*'])->from(static::TABLE);
        if ($filter !== null) {
            $query = $this->applyFilter($query, $filter);
        }
        return $this->db->select($query);
    }

    /**
     * Hook for subclasses to translate a search DTO into `WHERE`
     * conditions on the query. Default: pass-through (every row).
     *
     * Override pattern:
     *
     *   protected function applyFilter(Query $q, RequestDto $filter): Query
     *   {
     *       if ($filter instanceof SearchProducts) {
     *           if ($filter->status !== null) {
     *               $q->where('status', '=', $filter->status);
     *           }
     *           if ($filter->q !== null) {
     *               $q->where('name', 'LIKE', "%{$filter->q}%");
     *           }
     *       }
     *       return $q;
     *   }
     */
    protected function applyFilter(Query $query, RequestDto $filter): Query
    {
        return $query;
    }

    /**
     * Hook for subclasses to transform a DTO into the column map
     * for INSERT (`partial: false`, every field included) or
     * UPDATE (`partial: true`, only non-null fields included —
     * "PATCH semantics").
     *
     * Default uses every public property as a column of the same
     * name. Override when DTO field names diverge from column
     * names (camelCase ↔ snake_case), or to skip framework-
     * internal fields.
     *
     * @return array<string, mixed>
     */
    protected function dtoToRow(RequestDto $dto, bool $partial): array
    {
        $row = [];
        foreach (get_object_vars($dto) as $name => $value) {
            if ($partial && $value === null) {
                // PATCH: omit unspecified fields so the UPDATE
                // doesn't accidentally overwrite columns the
                // client didn't intend to touch.
                continue;
            }
            $row[$name] = $value;
        }
        return $row;
    }

    /**
     * Coerce `lastInsertId()`'s string return into the PK shape.
     * Numeric IDs (auto-increment) round-trip via `(int)`; string
     * PKs (UUID, slug) stay as strings.
     */
    private function coercePk(string $id): int|string
    {
        return ctype_digit($id) ? (int) $id : $id;
    }

    private function assertTable(): void
    {
        if (static::TABLE === '') {
            throw new \LogicException(
                static::class . ' must override the TABLE constant',
            );
        }
    }
}
