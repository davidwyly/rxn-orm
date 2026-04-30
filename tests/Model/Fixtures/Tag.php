<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model\Fixtures;

use Rxn\Orm\Model\Record;
use Rxn\Orm\Model\Relation;

class Tag extends Record
{
    public const TABLE = 'tags';

    public function posts(): Relation
    {
        return $this->belongsToMany(TaggedPost::class, 'post_tag', 'tag_id', 'post_id');
    }
}
