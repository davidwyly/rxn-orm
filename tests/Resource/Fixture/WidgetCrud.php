<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Resource\Fixture;

use Rxn\Framework\Http\Binding\RequestDto;
use Rxn\Orm\Builder\Query;
use Rxn\Orm\Resource\RxnOrmCrudHandler;

/**
 * Concrete subclass exercising the "extend a class, set TABLE
 * constant, done" ergonomic. The test verifies every CrudHandler
 * method works with this minimal subclass.
 *
 * The `applyFilter()` override demonstrates how apps wire a
 * search DTO into actual `WHERE` clauses — the default
 * pass-through would still pass tests, but the override exists
 * because in real apps that's where the per-resource search
 * shape lives.
 */
final class WidgetCrud extends RxnOrmCrudHandler
{
    public const TABLE = 'widgets';

    protected function applyFilter(Query $query, RequestDto $filter): Query
    {
        if (!$filter instanceof SearchWidgets) {
            return $query;
        }
        if ($filter->status !== null) {
            $query->where('status', '=', $filter->status);
        }
        if ($filter->q !== null && $filter->q !== '') {
            $query->where('name', 'LIKE', "%{$filter->q}%");
        }
        return $query;
    }
}
