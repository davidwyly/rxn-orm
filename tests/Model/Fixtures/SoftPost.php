<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model\Fixtures;

use Rxn\Orm\Model\Record;

class SoftPost extends Record
{
    public const TABLE       = 'soft_posts';
    public const DELETED_AT  = 'deleted_at';
}
