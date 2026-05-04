<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Model\Fixtures;

use Rxn\Orm\Model\Record;

/**
 * Test fixture exercising lifecycle hooks. Each hook records its
 * invocation in a public log array so tests can assert ordering.
 */
class Hookable extends Record
{
    public const TABLE = 'hookable';

    /** @var string[] */
    public array $log = [];

    protected function beforeSave(): void
    {
        $this->log[] = $this->exists ? 'beforeUpdate' : 'beforeInsert';
    }

    protected function afterSave(): void
    {
        $this->log[] = 'afterSave';
    }

    protected function beforeDelete(): void
    {
        $this->log[] = 'beforeDelete';
    }

    protected function afterDelete(): void
    {
        $this->log[] = 'afterDelete';
    }
}
