<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Resource\Fixture;

use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Test DTO for partial updates. All fields nullable; the handler's
 * `dtoToRow($dto, partial: true)` skips nulls so unset fields
 * aren't overwritten.
 */
final class UpdateWidget implements RequestDto
{
    public ?string $name = null;
    public ?int    $price = null;
    public ?string $status = null;
}
