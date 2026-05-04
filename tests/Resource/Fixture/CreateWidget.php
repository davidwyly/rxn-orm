<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Resource\Fixture;

use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Test DTO for create. Public properties match the test schema's
 * column names so `RxnOrmCrudHandler::dtoToRow()`'s default
 * (one-property-per-column) drops in cleanly.
 */
final class CreateWidget implements RequestDto
{
    public string $name = '';
    public int    $price = 0;
    public string $status = 'draft';
}
