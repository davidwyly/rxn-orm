<?php declare(strict_types=1);

namespace Rxn\Orm\Model;

use Rxn\Orm\Builder\Query;

/**
 * Query subclass that hydrates results into Record instances and
 * supports eager loading via with().
 *
 *   $users = User::query()->with('orders.items')->where('active', '=', 1)->get();
 *
 * Returns User[] (not array[]) from get/first/find. The eager loader
 * issues one extra SELECT per top-level relation regardless of result
 * size — the standard fix for the N+1 trap.
 *
 * Inherits all builder methods (where, join, orderBy, limit, etc.)
 * from Query and overrides only the read terminals so they hydrate.
 *
 * @template T of Record
 */
class ModelQuery extends Query
{
    /** @var class-string<T> */
    private string $modelClass;

    /** @var string[] dotted relation paths queued for eager load */
    private array $eagerLoads = [];

    /**
     * Soft-delete visibility:
     *   'default' — auto-WHERE deleted_at IS NULL (when model has DELETED_AT)
     *   'with'    — no soft-delete filter (rows with non-null deleted_at included)
     *   'only'    — only deleted rows
     */
    private string $softDeleteScope = 'default';

    /** @param class-string<T> $modelClass */
    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
    }

    /** @return class-string<T> */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /** Include soft-deleted rows in the result set. */
    public function withTrashed(): static
    {
        $this->softDeleteScope = 'with';
        return $this;
    }

    /** Return only soft-deleted rows. */
    public function onlyTrashed(): static
    {
        $this->softDeleteScope = 'only';
        return $this;
    }

    /**
     * Override toSql() to apply the soft-delete scope. Implemented as
     * a clone-and-delegate so the parent's WHERE rendering doesn't
     * need to know about soft deletes — they're just an extra
     * predicate stitched in at emit time.
     *
     * @return array{0: string, 1: array}
     */
    public function toSql(): array
    {
        $col = $this->modelClass::DELETED_AT;
        if ($col === null || $this->softDeleteScope === 'with') {
            return parent::toSql();
        }
        $clone = clone $this;
        $clone->softDeleteScope = 'with'; // prevent infinite recursion
        if ($this->softDeleteScope === 'only') {
            $clone->whereIsNotNull($col);
        } else {
            $clone->whereIsNull($col);
        }
        return $clone->toSql();
    }

    /**
     * Queue one or more relation paths for eager loading. Nested
     * paths use dot notation: `with('orders.items')` loads the
     * user's orders, then loads each order's items in a single
     * follow-up query.
     */
    public function with(string ...$relations): static
    {
        foreach ($relations as $r) {
            if ($r === '') {
                continue;
            }
            if (!in_array($r, $this->eagerLoads, true)) {
                $this->eagerLoads[] = $r;
            }
        }
        return $this;
    }

    /**
     * Execute the SELECT and return Record instances. Triggers any
     * queued eager loads after hydration.
     *
     * @return array<int, T>
     */
    public function get(): array
    {
        $rows = $this->requireConnection(__FUNCTION__)->select($this);
        /** @var array<int, T> $models */
        $models = $this->modelClass::hydrate($rows);
        if ($this->eagerLoads !== [] && $models !== []) {
            $this->eagerLoad($models, $this->eagerLoads);
        }
        return $models;
    }

    /**
     * @return T|null
     */
    public function first(): ?Record
    {
        $clone = clone $this;
        $clone->limit(1);
        $models = $clone->get();
        return $models[0] ?? null;
    }

    /**
     * @return T|null
     */
    public function find(mixed $id, string $pk = 'id'): ?Record
    {
        $clone = clone $this;
        $clone->where($pk, '=', $id)->limit(1);
        $models = $clone->get();
        return $models[0] ?? null;
    }

    // -- eager loading -----------------------------------------------

    /**
     * Walk the eager-load list. Top-level relations get one query
     * each; nested paths recurse onto the loaded children.
     *
     * @param Record[] $parents
     * @param string[] $paths
     */
    private function eagerLoad(array $parents, array $paths): void
    {
        // Group paths by top-level relation: 'orders.items', 'orders.shipments'
        // -> ['orders' => ['items', 'shipments']]
        $byTop = [];
        foreach ($paths as $path) {
            $parts  = explode('.', $path, 2);
            $top    = $parts[0];
            $nested = $parts[1] ?? '';
            $byTop[$top][] = $nested;
        }

        foreach ($byTop as $relationName => $nestedPaths) {
            $this->loadRelation($parents, $relationName, array_values(array_filter($nestedPaths, fn ($p) => $p !== '')));
        }
    }

    /**
     * @param Record[] $parents
     * @param string[] $nestedPaths
     */
    private function loadRelation(array $parents, string $relationName, array $nestedPaths): void
    {
        $sample = $parents[0];
        if (!method_exists($sample, $relationName)) {
            throw new \LogicException(
                'Relation ' . $relationName . ' is not defined on ' . get_class($sample)
            );
        }
        $relation = $sample->$relationName();
        if (!$relation instanceof Relation) {
            throw new \LogicException(
                $relationName . '() must return a ' . Relation::class . ' instance, got '
                . (is_object($relation) ? get_class($relation) : gettype($relation))
            );
        }

        if ($relation->kind === Relation::BELONGS_TO_MANY) {
            $this->loadBelongsToMany($parents, $relationName, $relation, $nestedPaths);
            return;
        }

        [$parentLookupKey, $relatedMatchKey] = $relation->lookupKeys();

        // Collect distinct parent lookup values.
        $keys = [];
        foreach ($parents as $p) {
            $val = $p->getRawAttribute($parentLookupKey);
            if ($val !== null && !isset($keys[$val])) {
                $keys[$val] = true;
            }
        }
        if ($keys === []) {
            $this->attachEmpty($parents, $relationName, $relation);
            return;
        }

        /** @var class-string<Record> $relatedClass */
        $relatedClass = $relation->related;
        $relatedQuery = $relatedClass::query()->whereIn($relatedMatchKey, array_keys($keys));
        if ($nestedPaths !== []) {
            $relatedQuery->with(...$nestedPaths);
        }
        /** @var Record[] $relatedRecords */
        $relatedRecords = $relatedQuery->get();

        // Index related records.
        $byKey = [];
        if ($relation->kind === Relation::BELONGS_TO) {
            foreach ($relatedRecords as $r) {
                $k = $r->getRawAttribute($relatedMatchKey);
                if ($k !== null) {
                    $byKey[$k] = $r;
                }
            }
        } else {
            foreach ($relatedRecords as $r) {
                $k = $r->getRawAttribute($relatedMatchKey);
                if ($k === null) {
                    continue;
                }
                $byKey[$k][] = $r;
            }
        }

        // Attach to parents.
        foreach ($parents as $p) {
            $key = $p->getRawAttribute($parentLookupKey);
            if ($relation->kind === Relation::BELONGS_TO) {
                $p->setRelation($relationName, $byKey[$key] ?? null);
                continue;
            }
            if ($relation->kind === Relation::HAS_ONE) {
                $bucket = $byKey[$key] ?? [];
                $p->setRelation($relationName, $bucket[0] ?? null);
                continue;
            }
            $p->setRelation($relationName, $byKey[$key] ?? []);
        }
    }

    /**
     * @param Record[] $parents
     */
    private function attachEmpty(array $parents, string $relationName, Relation $relation): void
    {
        $empty = ($relation->kind === Relation::HAS_MANY || $relation->kind === Relation::BELONGS_TO_MANY)
            ? []
            : null;
        foreach ($parents as $p) {
            $p->setRelation($relationName, $empty);
        }
    }

    /**
     * Eager-load a many-to-many relation in one query. Selects every
     * related row whose pivot row references any parent ID, plus the
     * pivot's parent column under the alias `rxn_pivot_parent` so we
     * can group results without an extra round-trip.
     *
     * @param Record[] $parents
     * @param string[] $nestedPaths
     */
    private function loadBelongsToMany(array $parents, string $relationName, Relation $relation, array $nestedPaths): void
    {
        // Parent values come from the local key (typically the PK).
        $parentLookupKey = $relation->localKey;
        $keys = [];
        foreach ($parents as $p) {
            $val = $p->getRawAttribute($parentLookupKey);
            if ($val !== null && !isset($keys[$val])) {
                $keys[$val] = true;
            }
        }
        if ($keys === []) {
            $this->attachEmpty($parents, $relationName, $relation);
            return;
        }

        /** @var class-string<Record> $relatedClass */
        $relatedClass = $relation->related;
        $relatedTable = $relatedClass::tableName();
        $pivotParentRef = $relation->pivotTable . '.' . $relation->parentPivotKey;

        // Record::query() pre-populates SELECT * — replace it with an
        // explicit list that includes the pivot's parent reference
        // under a synthetic alias so we can group results without an
        // extra round-trip.
        $relatedQuery = $relatedClass::query();
        unset($relatedQuery->commands['SELECT'], $relatedQuery->commands['SELECT DISTINCT']);
        $relatedQuery->select([
            $relatedTable . '.*',
            $pivotParentRef => 'rxn_pivot_parent',
        ]);
        $relatedQuery->join(
            $relation->pivotTable,
            $relation->pivotTable . '.' . $relation->relatedPivotKey,
            '=',
            $relatedTable . '.' . $relatedClass::PK,
        );
        $relatedQuery->whereIn($pivotParentRef, array_keys($keys));

        if ($nestedPaths !== []) {
            $relatedQuery->with(...$nestedPaths);
        }

        /** @var Record[] $relatedRecords */
        $relatedRecords = $relatedQuery->get();

        // Group by the pivot's parent reference so we can attach the
        // right set to each parent. The synthetic column was hydrated
        // into $attributes by Record::hydrate; remove it after grouping
        // so it doesn't leak into toArray() output.
        $byParent = [];
        foreach ($relatedRecords as $r) {
            $parentKey = $r->getRawAttribute('rxn_pivot_parent');
            if ($parentKey === null) {
                continue;
            }
            $r->forgetRawAttribute('rxn_pivot_parent');
            $byParent[$parentKey][] = $r;
        }

        foreach ($parents as $p) {
            $key = $p->getRawAttribute($parentLookupKey);
            $p->setRelation($relationName, $byParent[$key] ?? []);
        }
    }
}
