<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model\Fixtures;

use Rxn\Orm\Model\Record;
use Rxn\Orm\Model\Relation;

class Post extends Record
{
    public const TABLE = 'posts';

    protected static array $casts = [
        'id'      => 'int',
        'user_id' => 'int',
    ];

    public function user(): Relation
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): Relation
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}
