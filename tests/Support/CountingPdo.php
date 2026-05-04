<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Support;

use PDO;
use PDOStatement;

/**
 * PDO subclass that counts prepare() calls. Lets eager-loading tests
 * assert exact query counts (the canonical "did we hit N+1?" check).
 */
final class CountingPdo extends PDO
{
    private int $count = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->count++;
        return parent::prepare($query, $options);
    }

    public function resetCount(): void
    {
        $this->count = 0;
    }

    public function prepareCount(): int
    {
        return $this->count;
    }
}
