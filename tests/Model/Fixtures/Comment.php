<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model\Fixtures;

use Rxn\Orm\Model\Record;
use Rxn\Orm\Model\Relation;

class Comment extends Record
{
    public const TABLE = 'comments';

    protected static array $casts = [
        'id'      => 'int',
        'post_id' => 'int',
    ];

    public function post(): Relation
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
