<?php declare(strict_types=1);

namespace Rxn\Orm\Model;

use Rxn\Orm\Builder\Delete;
use Rxn\Orm\Builder\Insert;
use Rxn\Orm\Builder\Update;
use Rxn\Orm\Db\Connection;

/**
 * @phpstan-consistent-constructor
 *
 * Lightweight active-record base for models that map 1:1 to a table.
 *
 * Subclasses declare TABLE / PK constants and (optionally) relations
 * as instance methods returning Relation objects. Reads and writes go
 * through an explicitly-bound Connection — there's no service
 * container, no facades, no global state beyond `setConnection()`.
 *
 *   class User extends Record {
 *       public const TABLE = 'users';
 *       protected static array $casts = ['settings' => 'json'];
 *
 *       public function orders(): Relation {
 *           return $this->hasMany(Order::class, 'user_id');
 *       }
 *   }
 *
 *   Record::setConnection($db);
 *   $user = User::find(42);
 *   $user->name = 'Alice';
 *   $user->save();
 *
 * Reads return hydrated instances; writes are dirty-aware so UPDATE
 * only sends columns that actually changed since the last read/save.
 */
abstract class Record
{
    /** Subclasses MUST override. */
    public const TABLE = null;

    /** Primary key column. Override to change. */
    public const PK = 'id';

    /**
     * Auto-timestamp column names. Override either constant with a
     * column name (e.g. `'created_at'`) to enable. UTC ISO-8601 strings
     * are written, format `Y-m-d H:i:s`, accepted natively by MySQL,
     * Postgres, and SQLite.
     *
     *   class Post extends Record {
     *       public const TABLE = 'posts';
     *       public const CREATED_AT = 'created_at';
     *       public const UPDATED_AT = 'updated_at';
     *   }
     */
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    /**
     * Soft-delete column. When non-null, `delete()` becomes an UPDATE
     * setting this column to NOW (instead of issuing a DELETE), and
     * default queries auto-filter `WHERE col IS NULL`. Use
     * `forceDelete()` for the real DELETE; `restore()` to undelete;
     * `ModelQuery::withTrashed()` / `onlyTrashed()` to scope queries.
     */
    public const DELETED_AT = null;

    /**
     * Per-class connection bindings. A subclass can pin its own
     * Connection (e.g. for a different database) by calling
     * `Order::setConnection($writeDb)`.
     *
     * @var array<class-string, Connection>
     */
    private static array $connections = [];

    /** Default connection used when no per-class binding is set. */
    private static ?Connection $defaultConnection = null;

    /**
     * Mass-assignable column allowlist. Null = no restriction.
     * Direct property writes (`$user->admin = true`) are not gated;
     * this only restricts `fill()` / `create()` / `update()` from
     * untrusted input arrays.
     *
     * @var string[]|null
     */
    protected static ?array $fillable = null;

    /**
     * Cast specifications. Keys are column names, values are one of:
     *   'int' | 'float' | 'bool' | 'string'
     *   'json' | 'array' (alias for json)
     *   'datetime' | 'date'
     *   'enum:Fully\\Qualified\\EnumClass'
     *
     * @var array<string, string>
     */
    protected static array $casts = [];

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /**
     * Snapshot of attributes as last seen by the DB (for dirty diffing).
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /** True once the record has been read from / written to the DB. */
    protected bool $exists = false;

    /**
     * Eager-loaded relation results keyed by relation method name.
     *
     * @var array<string, mixed>
     */
    protected array $loaded = [];

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        if ($attributes !== []) {
            foreach ($attributes as $k => $v) {
                $this->attributes[$k] = $v;
            }
        }
    }

    // -- connection wiring -------------------------------------------

    /**
     * Bind a Connection. With no $modelClass, this becomes the default
     * for every Record subclass. With one, only that subclass uses it.
     *
     * @param class-string<Record>|null $modelClass
     */
    public static function setConnection(Connection $connection, ?string $modelClass = null): void
    {
        if ($modelClass === null) {
            self::$defaultConnection = $connection;
            return;
        }
        self::$connections[$modelClass] = $connection;
    }

    public static function getConnection(): Connection
    {
        $cls = static::class;
        if (isset(self::$connections[$cls])) {
            return self::$connections[$cls];
        }
        if (self::$defaultConnection !== null) {
            return self::$defaultConnection;
        }
        throw new \LogicException(
            'No Connection bound for ' . $cls . '. Call Record::setConnection($db) ' .
            'or ' . $cls . '::setConnection($db, ' . $cls . '::class).',
        );
    }

    /** Reset bindings — primarily for tests. */
    public static function clearConnections(): void
    {
        self::$connections        = [];
        self::$defaultConnection  = null;
    }

    public static function tableName(): string
    {
        if (static::TABLE === null) {
            throw new \LogicException('Define const TABLE on ' . static::class);
        }
        return static::TABLE;
    }

    // -- query construction ------------------------------------------

    /**
     * Start a hydrating SELECT query against this model's table.
     *
     * @return ModelQuery<static>
     */
    public static function query(): ModelQuery
    {
        $query = new ModelQuery(static::class);
        $query->setConnection(static::getConnection());
        $query->select()->from(static::tableName());
        return $query;
    }

    /**
     * Sugar for `static::query()->where($field, $op, $value)`.
     *
     * @return ModelQuery<static>
     */
    public static function where(string $field, string $operator, mixed $value): ModelQuery
    {
        return static::query()->where($field, $operator, $value);
    }

    /**
     * @return array<int, static>
     */
    public static function all(): array
    {
        /** @var array<int, static> */
        return static::query()->get();
    }

    /**
     * @return static|null
     */
    public static function find(mixed $id): ?static
    {
        /** @var static|null */
        return static::query()->where(static::PK, '=', $id)->limit(1)->first();
    }

    /**
     * @return static
     */
    public static function findOrFail(mixed $id): static
    {
        $found = static::find($id);
        if ($found === null) {
            throw new \RuntimeException(static::class . ' #' . var_export($id, true) . ' not found');
        }
        return $found;
    }

    /**
     * @return static|null
     */
    public static function first(): ?static
    {
        /** @var static|null */
        return static::query()->first();
    }

    /**
     * Insert a new row and return the hydrated instance. Subject to
     * the $fillable allowlist when set.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public static function create(array $attributes): static
    {
        $instance = new static();
        $instance->fill($attributes);
        $instance->save();
        return $instance;
    }

    // -- hydration ---------------------------------------------------

    /**
     * Wrap an already-fetched DB row. Marks the instance as existing
     * and snapshots its $original state for dirty tracking.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->attributes = $row;
        $instance->original   = $row;
        $instance->exists     = true;
        return $instance;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return static[]
     */
    public static function hydrate(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $instance = new static();
            $instance->attributes = $row;
            $instance->original   = $row;
            $instance->exists     = true;
            $out[] = $instance;
        }
        return $out;
    }

    // -- attribute access --------------------------------------------

    /**
     * Apply mass-assignable filter then write attributes. Returns
     * $this for chaining.
     */
    /** @param array<string, mixed> $attributes */
    public function fill(array $attributes): static
    {
        $allowed = static::$fillable;
        foreach ($attributes as $k => $v) {
            if ($allowed !== null && !in_array($k, $allowed, true)) {
                continue;
            }
            $this->attributes[$k] = $v;
        }
        return $this;
    }

    public function id(): mixed
    {
        return $this->attributes[static::PK] ?? null;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->attributes as $k => $v) {
            $out[$k] = $this->castFromStorage($k, $v);
        }
        // Loaded relations: serialize arrays of records / single records.
        foreach ($this->loaded as $name => $value) {
            if (is_array($value)) {
                $out[$name] = array_map(
                    fn ($r) => $r instanceof Record ? $r->toArray() : $r,
                    $value,
                );
                continue;
            }
            $out[$name] = $value instanceof Record ? $value->toArray() : $value;
        }
        return $out;
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->loaded)) {
            return $this->loaded[$name];
        }
        if (!array_key_exists($name, $this->attributes)) {
            return null;
        }
        return $this->castFromStorage($name, $this->attributes[$name]);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes)
            || array_key_exists($name, $this->loaded);
    }

    /** Raw stored value of $name (no cast applied). Internal helper for the eager loader. */
    public function getRawAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Remove a synthetic attribute (e.g. the `rxn_pivot_parent` column
     * the BELONGS_TO_MANY eager loader injects) so it doesn't leak
     * into toArray() / persistence.
     */
    public function forgetRawAttribute(string $name): void
    {
        unset($this->attributes[$name], $this->original[$name]);
    }

    /** Attach an eager-loaded relation result. Used by ModelQuery::with(). */
    public function setRelation(string $name, mixed $value): void
    {
        $this->loaded[$name] = $value;
    }

    public function getRelation(string $name): mixed
    {
        return $this->loaded[$name] ?? null;
    }

    public function hasRelation(string $name): bool
    {
        return array_key_exists($name, $this->loaded);
    }

    // -- persistence -------------------------------------------------

    /**
     * INSERT if new, UPDATE-dirty if existing. Returns true when the
     * statement actually wrote a row, false when nothing was dirty.
     *
     * Order of operations:
     *   1. beforeSave()         (override to mutate / validate)
     *   2. apply auto-timestamps
     *   3. emit INSERT or UPDATE
     *   4. afterSave()
     */
    public function save(): bool
    {
        $this->beforeSave();
        $this->applyTimestamps();
        $connection = static::getConnection();

        if (!$this->exists) {
            $serialized = $this->serializeAttributes($this->attributes);
            $insert = (new Insert())->into(static::tableName())->row($serialized);
            $connection->insert($insert);
            if (!isset($this->attributes[static::PK])) {
                $id = $connection->lastInsertId();
                if ($id !== '' && $id !== '0') {
                    $this->attributes[static::PK] = $this->castIdFromDriver($id);
                }
            }
            $this->original = $this->attributes;
            $this->exists   = true;
            $this->afterSave();
            return true;
        }

        $dirty = $this->dirtyForUpdate();
        if ($dirty === []) {
            $this->afterSave();
            return false;
        }
        $serializedDirty = $this->serializeAttributes($dirty);
        $update = (new Update())
            ->table(static::tableName())
            ->set($serializedDirty)
            ->where(static::PK, '=', $this->attributes[static::PK]);
        $connection->update($update);
        $this->original = $this->attributes;
        $this->afterSave();
        return true;
    }

    /**
     * Re-fetch the row from the DB and refresh attributes / original
     * snapshot. Returns $this.
     */
    public function refresh(): static
    {
        if (!$this->exists) {
            throw new \LogicException('Cannot refresh a record that has not been saved');
        }
        $row = static::getConnection()
            ->table(static::tableName())
            ->where(static::PK, '=', $this->attributes[static::PK])
            ->first();
        if ($row === null) {
            throw new \RuntimeException(static::class . ' #' . $this->attributes[static::PK] . ' no longer exists');
        }
        $this->attributes = $row;
        $this->original   = $row;
        $this->loaded     = [];
        return $this;
    }

    /**
     * Delete the record. Honours soft deletes when DELETED_AT is set
     * — the row is kept and its deleted_at column is stamped instead.
     * Use forceDelete() to bypass.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $this->beforeDelete();
        $deletedAtCol = static::DELETED_AT;
        if ($deletedAtCol !== null) {
            $now = gmdate('Y-m-d H:i:s');
            $this->attributes[$deletedAtCol] = $now;
            $this->original[$deletedAtCol]   = $now;
            $update = (new Update())
                ->table(static::tableName())
                ->set([$deletedAtCol => $now])
                ->where(static::PK, '=', $this->attributes[static::PK]);
            static::getConnection()->update($update);
            $this->afterDelete();
            return true;
        }
        $delete = (new Delete())
            ->from(static::tableName())
            ->where(static::PK, '=', $this->attributes[static::PK]);
        static::getConnection()->delete($delete);
        $this->exists = false;
        $this->afterDelete();
        return true;
    }

    /**
     * Bypass soft-delete and remove the row outright. No-op when the
     * model isn't soft-deleted (use delete()).
     */
    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $this->beforeDelete();
        $delete = (new Delete())
            ->from(static::tableName())
            ->where(static::PK, '=', $this->attributes[static::PK]);
        static::getConnection()->delete($delete);
        $this->exists = false;
        $this->afterDelete();
        return true;
    }

    /**
     * Restore a soft-deleted record by clearing its DELETED_AT column.
     * Throws when the model isn't soft-deletable.
     */
    public function restore(): bool
    {
        $col = static::DELETED_AT;
        if ($col === null) {
            throw new \LogicException('Soft deletes not enabled on ' . static::class);
        }
        if (!$this->exists) {
            return false;
        }
        $this->attributes[$col] = null;
        return $this->save();
    }

    public function trashed(): bool
    {
        $col = static::DELETED_AT;
        return $col !== null && $this->attributes[$col] !== null && isset($this->attributes[$col]);
    }

    // -- lifecycle hooks ---------------------------------------------

    /**
     * Override to run code before save() emits its INSERT or UPDATE.
     * `$this->exists` is false for an upcoming INSERT, true for an
     * UPDATE — branch on it to differentiate. Throw to abort the save.
     */
    protected function beforeSave(): void
    {
    }

    /**
     * Override to run code after save() succeeds. `$this->exists` is
     * always true here; check `dirtyForUpdate()` was empty if you
     * need to distinguish "no-op save" from "actual write."
     */
    protected function afterSave(): void
    {
    }

    /** Override to run code before delete()/forceDelete(). Throw to abort. */
    protected function beforeDelete(): void
    {
    }

    /** Override to run code after delete()/forceDelete() succeeds. */
    protected function afterDelete(): void
    {
    }

    /**
     * Stamp CREATED_AT / UPDATED_AT into $this->attributes prior to
     * serialization. Skips columns the user has already set on this
     * save cycle so explicit assignments win.
     */
    private function applyTimestamps(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $created = static::CREATED_AT;
        $updated = static::UPDATED_AT;
        if (!$this->exists && $created !== null) {
            // INSERT: set created_at if user hasn't already.
            if (!array_key_exists($created, $this->attributes) || $this->attributes[$created] === null) {
                $this->attributes[$created] = $now;
            }
        }
        if ($updated !== null) {
            // Both INSERT and UPDATE refresh updated_at, unless the user
            // explicitly set a different value mid-save (override wins).
            $existingMatchesOriginal = array_key_exists($updated, $this->attributes)
                && array_key_exists($updated, $this->original)
                && $this->attributes[$updated] === $this->original[$updated];
            if (!$this->exists || $existingMatchesOriginal || !array_key_exists($updated, $this->attributes)) {
                $this->attributes[$updated] = $now;
            }
        }
    }

    /**
     * Columns whose live value differs from the original snapshot.
     * Compares the *serialized* form so that user-friendly
     * assignments (`$u->active = true` when the DB stored 1) don't
     * register as dirty just because the PHP type changed. Excludes
     * the PK (which should never change via save()).
     *
     * @return array<string, mixed>
     */
    public function dirtyForUpdate(): array
    {
        $serialized = $this->serializeAttributes($this->attributes);
        $dirty = [];
        foreach ($this->attributes as $k => $v) {
            if ($k === static::PK) {
                continue;
            }
            $current = $serialized[$k] ?? null;
            if (!array_key_exists($k, $this->original) || $this->original[$k] !== $current) {
                $dirty[$k] = $v;
            }
        }
        return $dirty;
    }

    // -- relation factories ------------------------------------------

    /**
     * @param class-string<Record> $relatedClass
     */
    public function hasMany(string $relatedClass, string $foreignKey, ?string $localKey = null): Relation
    {
        return new Relation(
            kind: Relation::HAS_MANY,
            parent: static::class,
            related: $relatedClass,
            foreignKey: $foreignKey,
            localKey: $localKey ?? static::PK,
        );
    }

    /**
     * @param class-string<Record> $relatedClass
     */
    public function hasOne(string $relatedClass, string $foreignKey, ?string $localKey = null): Relation
    {
        return new Relation(
            kind: Relation::HAS_ONE,
            parent: static::class,
            related: $relatedClass,
            foreignKey: $foreignKey,
            localKey: $localKey ?? static::PK,
        );
    }

    /**
     * @param class-string<Record> $ownerClass
     */
    public function belongsTo(string $ownerClass, string $foreignKey, ?string $ownerKey = null): Relation
    {
        return new Relation(
            kind: Relation::BELONGS_TO,
            parent: static::class,
            related: $ownerClass,
            foreignKey: $foreignKey,
            localKey: $ownerKey ?? $ownerClass::PK,
        );
    }

    /**
     * Many-to-many through a pivot table.
     *
     *   class User extends Record {
     *       public function roles(): Relation {
     *           return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
     *       }
     *   }
     *
     * Eager-loadable via `User::query()->with('roles')->get()` — fires
     * exactly one extra query, JOINing through the pivot. Use the
     * matching attach/detach/sync methods to mutate pivot membership.
     *
     * @param class-string<Record> $relatedClass
     */
    public function belongsToMany(
        string $relatedClass,
        string $pivotTable,
        string $parentPivotKey,
        string $relatedPivotKey,
        ?string $localKey = null,
    ): Relation {
        return new Relation(
            kind: Relation::BELONGS_TO_MANY,
            parent: static::class,
            related: $relatedClass,
            foreignKey: $relatedPivotKey,
            localKey: $localKey ?? static::PK,
            pivotTable: $pivotTable,
            parentPivotKey: $parentPivotKey,
            relatedPivotKey: $relatedPivotKey,
        );
    }

    // -- many-to-many mutations --------------------------------------

    /**
     * Insert pivot rows associating this record with one or more
     * related IDs. `$pivotData` is merged into every inserted row;
     * use it for extra columns like `granted_at`, `role_label`, etc.
     *
     * @param int|string|array<int, int|string> $ids
     * @param array<string, mixed> $pivotData
     */
    /**
     * @param int|string|array<int, int|string> $ids
     * @param array<string, mixed> $pivotData
     */
    public function attach(string $relationName, int|string|array $ids, array $pivotData = []): void
    {
        $relation = $this->resolveBelongsToMany($relationName);
        [$pivotTable, $parentPivotKey, $relatedPivotKey] = self::pivotKeys($relation);
        $ids = is_array($ids) ? $ids : [$ids];
        if ($ids === []) {
            return;
        }
        $parentValue = $this->attributes[$relation->localKey] ?? null;
        if ($parentValue === null) {
            throw new \LogicException('Cannot attach: parent record has no ' . $relation->localKey);
        }
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = $pivotData + [
                $parentPivotKey  => $parentValue,
                $relatedPivotKey => $id,
            ];
        }
        $insert = (new Insert())->into($pivotTable)->rows($rows);
        static::getConnection()->insert($insert);
    }

    /**
     * Remove pivot rows. Pass null to detach every related record.
     * Returns the number of pivot rows removed.
     *
     * @param int|string|array<int, int|string>|null $ids
     */
    public function detach(string $relationName, int|string|array|null $ids = null): int
    {
        $relation = $this->resolveBelongsToMany($relationName);
        [$pivotTable, $parentPivotKey, $relatedPivotKey] = self::pivotKeys($relation);
        $parentValue = $this->attributes[$relation->localKey] ?? null;
        if ($parentValue === null) {
            return 0;
        }
        $delete = (new Delete())
            ->from($pivotTable)
            ->where($parentPivotKey, '=', $parentValue);
        if ($ids !== null) {
            $idList = is_array($ids) ? $ids : [$ids];
            if ($idList === []) {
                return 0;
            }
            $delete->whereIn($relatedPivotKey, $idList);
        } else {
            // Detaching all — explicit opt-in needed by Delete builder.
            $delete->allowEmptyWhere(false); // we do have a WHERE
        }
        $result = static::getConnection()->delete($delete);
        return is_int($result) ? $result : 0;
    }

    /**
     * Reconcile pivot membership to exactly $ids. Detaches anything
     * not in $ids, attaches anything in $ids that isn't already
     * present. Returns ['attached' => [...], 'detached' => [...]].
     *
     * @param array<int, int|string> $ids
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>}
     */
    public function sync(string $relationName, array $ids): array
    {
        $relation = $this->resolveBelongsToMany($relationName);
        [$pivotTable, $parentPivotKey, $relatedPivotKey] = self::pivotKeys($relation);
        $parentValue = $this->attributes[$relation->localKey] ?? null;
        if ($parentValue === null) {
            throw new \LogicException('Cannot sync: parent record has no ' . $relation->localKey);
        }
        $current = static::getConnection()
            ->table($pivotTable)
            ->where($parentPivotKey, '=', $parentValue)
            ->pluck($relatedPivotKey);

        $toAttach = array_values(array_diff($ids, $current));
        $toDetach = array_values(array_diff($current, $ids));

        if ($toDetach !== []) {
            $this->detach($relationName, $toDetach);
        }
        if ($toAttach !== []) {
            $this->attach($relationName, $toAttach);
        }
        return ['attached' => $toAttach, 'detached' => $toDetach];
    }

    private function resolveBelongsToMany(string $relationName): Relation
    {
        if (!method_exists($this, $relationName)) {
            throw new \LogicException(static::class . ' has no ' . $relationName . '() method');
        }
        $relation = $this->{$relationName}();
        if (!$relation instanceof Relation) {
            throw new \LogicException($relationName . '() must return a Relation');
        }
        if ($relation->kind !== Relation::BELONGS_TO_MANY) {
            throw new \LogicException($relationName . ' is not a belongsToMany relation');
        }
        return $relation;
    }

    /**
     * Narrow Relation's nullable pivot fields once for a relation
     * already verified as BELONGS_TO_MANY. Returns [pivotTable,
     * parentPivotKey, relatedPivotKey].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function pivotKeys(Relation $relation): array
    {
        $pt = $relation->pivotTable
            ?? throw new \LogicException('Internal: belongsToMany relation missing pivot table');
        $pk = $relation->parentPivotKey
            ?? throw new \LogicException('Internal: belongsToMany relation missing parentPivotKey');
        $rk = $relation->relatedPivotKey
            ?? throw new \LogicException('Internal: belongsToMany relation missing relatedPivotKey');
        return [$pt, $pk, $rk];
    }

    // -- casting -----------------------------------------------------

    /**
     * Translate stored value → public value at read time. Already-cast
     * values pass through unchanged so user-set attributes survive
     * a subsequent __get without churning through json_decode etc.
     */
    protected function castFromStorage(string $key, mixed $value): mixed
    {
        $type = static::$casts[$key] ?? null;
        if ($type === null || $value === null) {
            return $value;
        }
        return match (true) {
            $type === 'int'                            => is_int($value) ? $value : (int)$value,
            $type === 'float'                          => is_float($value) ? $value : (float)$value,
            $type === 'bool', $type === 'boolean'      => is_bool($value) ? $value : (bool)$value,
            $type === 'string'                         => is_string($value) ? $value : (string)$value,
            $type === 'json', $type === 'array'        => is_string($value) ? json_decode($value, true) : $value,
            $type === 'datetime'                       => $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string)$value),
            $type === 'date'                           => $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string)$value),
            str_starts_with($type, 'enum:')            => $this->castEnum(substr($type, 5), $value),
            default                                    => $value,
        };
    }

    /**
     * Translate public value → DB-bindable value at write time.
     * Inverse of castFromStorage; also normalizes unset / object
     * shapes so PDO can bind them as scalars.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function serializeAttributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $k => $v) {
            $type = static::$casts[$k] ?? null;
            if ($v === null) {
                $out[$k] = null;
                continue;
            }
            $out[$k] = match (true) {
                $type === 'json', $type === 'array' => is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $type === 'datetime'                => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : (string)$v,
                $type === 'date'                    => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string)$v,
                $type !== null && str_starts_with($type, 'enum:') => match (true) {
                    $v instanceof \BackedEnum => $v->value,
                    $v instanceof \UnitEnum   => $v->name,
                    default                   => $v,
                },
                $type === 'bool', $type === 'boolean'             => (int)(bool)$v,
                default                                            => $v,
            };
        }
        return $out;
    }

    private function castEnum(string $enumClass, mixed $value): mixed
    {
        if (!enum_exists($enumClass)) {
            return $value;
        }
        if ($value instanceof $enumClass) {
            return $value;
        }
        // Backed enums use ::from(); pure enums use ::cases() lookup by name.
        $reflection = new \ReflectionEnum($enumClass);
        if ($reflection->isBacked()) {
            /** @var class-string<\BackedEnum> $enumClass */
            return $enumClass::from($value);
        }
        foreach ($reflection->getCases() as $case) {
            if ($case->getName() === $value) {
                return $case->getValue();
            }
        }
        return $value;
    }

    /**
     * lastInsertId() returns string. Cast to int when the PK is
     * cast as int (or when no cast is declared and the string is
     * numeric — most common case).
     */
    private function castIdFromDriver(string $id): mixed
    {
        $cast = static::$casts[static::PK] ?? null;
        if ($cast === 'int' || $cast === null) {
            if (ctype_digit($id) || (str_starts_with($id, '-') && ctype_digit(substr($id, 1)))) {
                return (int)$id;
            }
        }
        return $id;
    }
}
