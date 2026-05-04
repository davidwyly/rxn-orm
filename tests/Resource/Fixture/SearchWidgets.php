<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Resource\Fixture;

use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Test DTO for search. Fields are all optional — apps that need
 * a more sophisticated filter shape extend with attributes
 * (`#[InSet]`, `#[Min]`, etc.) on the framework side.
 */
final class SearchWidgets implements RequestDto
{
    public ?string $status = null;
    public ?string $q = null;
}
