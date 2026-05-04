<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model\Fixtures;

use Rxn\Orm\Model\Record;
use Rxn\Orm\Model\Relation;

class TaggedPost extends Record
{
    public const TABLE = 'tagged_posts';

    public function tags(): Relation
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
    }
}
