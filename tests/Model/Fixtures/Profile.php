<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model\Fixtures;

use Rxn\Orm\Model\Record;

class Profile extends Record
{
    public const TABLE = 'profiles';

    protected static array $casts = [
        'id'      => 'int',
        'user_id' => 'int',
    ];
}
