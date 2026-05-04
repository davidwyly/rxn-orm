<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model\Fixtures;

use Rxn\Orm\Model\Record;
use Rxn\Orm\Model\Relation;

class User extends Record
{
    public const TABLE = 'users';

    protected static array $casts = [
        'id'       => 'int',
        'active'   => 'bool',
        'settings' => 'json',
    ];

    public function posts(): Relation
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    public function profile(): Relation
    {
        return $this->hasOne(Profile::class, 'user_id');
    }
}
