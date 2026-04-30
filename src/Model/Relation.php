<?php declare(strict_types=1);

namespace Rxn\Orm\Model;

/**
 * Value object describing the shape of a relation between two
 * Record subclasses. Records create Relations from instance methods:
 *
 *   public function orders(): Relation {
 *       return $this->hasMany(Order::class, 'user_id');
 *   }
 *
 * The Relation itself doesn't run SQL — ModelQuery::with() inspects
 * Relations to build the eager-load queries, and a single-record
 * accessor uses Relation::queryFor() for the lazy fetch path.
 */
final class Relation
{
    public const HAS_MANY        = 'hasMany';
    public const HAS_ONE         = 'hasOne';
    public const BELONGS_TO      = 'belongsTo';
    public const BELONGS_TO_MANY = 'belongsToMany';

    /**
     * @param self::HAS_MANY|self::HAS_ONE|self::BELONGS_TO|self::BELONGS_TO_MANY $kind
     * @param class-string<Record> $parent
     * @param class-string<Record> $related
     * @param string|null $pivotTable for BELONGS_TO_MANY: the pivot table name
     * @param string|null $parentPivotKey for BELONGS_TO_MANY: pivot column referencing parent (e.g. 'user_id')
     * @param string|null $relatedPivotKey for BELONGS_TO_MANY: pivot column referencing related (e.g. 'role_id')
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $parent,
        public readonly string $related,
        public readonly string $foreignKey,
        public readonly string $localKey,
        public readonly ?string $pivotTable = null,
        public readonly ?string $parentPivotKey = null,
        public readonly ?string $relatedPivotKey = null,
    ) {
    }

    /**
     * For lazy access (`$user->orders()->queryFor($user)->get()`):
     * build a Query against the related class filtered by a single
     * parent record's local-side value.
     *
     * @param array<string, mixed> $parentAttributes
     */
    public function queryFor(array $parentAttributes): ModelQuery
    {
        /** @var class-string<Record> $relatedClass */
        $relatedClass = $this->related;
        $query = $relatedClass::query();

        if ($this->kind === self::BELONGS_TO) {
            $value = $parentAttributes[$this->foreignKey] ?? null;
            $query->where($this->localKey, '=', $value)->limit(1);
            return $query;
        }
        if ($this->kind === self::BELONGS_TO_MANY) {
            $value = $parentAttributes[$this->localKey] ?? null;
            $relatedTable = $relatedClass::tableName();
            $query->join(
                $this->pivotTable,
                $this->pivotTable . '.' . $this->relatedPivotKey,
                '=',
                $relatedTable . '.' . $relatedClass::PK,
            )->where($this->pivotTable . '.' . $this->parentPivotKey, '=', $value);
            return $query;
        }
        $value = $parentAttributes[$this->localKey] ?? null;
        $query->where($this->foreignKey, '=', $value);
        if ($this->kind === self::HAS_ONE) {
            $query->limit(1);
        }
        return $query;
    }

    /**
     * Eager-load entry points: returns the attribute name on the
     * parent that holds the lookup value, and the column on the
     * related table to match it against. The orientation flips for
     * BELONGS_TO since the FK lives on the parent there.
     *
     * Not applicable for BELONGS_TO_MANY (pivot lookup); the eager
     * loader handles that case directly via the pivot table.
     *
     * @return array{0: string, 1: string} [parentLookupKey, relatedMatchKey]
     */
    public function lookupKeys(): array
    {
        if ($this->kind === self::BELONGS_TO) {
            return [$this->foreignKey, $this->localKey];
        }
        return [$this->localKey, $this->foreignKey];
    }
}
