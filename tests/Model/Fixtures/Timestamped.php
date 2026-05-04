<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model\Fixtures;

use Rxn\Orm\Model\Record;

class Timestamped extends Record
{
    public const TABLE       = 'timestamped';
    public const CREATED_AT  = 'created_at';
    public const UPDATED_AT  = 'updated_at';
}
