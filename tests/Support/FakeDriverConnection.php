<?php declare(strict_types=1);

namespace Rxn\Orm\Tests\Support;

use PDO;
use Rxn\Orm\Db\Connection;

/**
 * Connection subclass that overrides driver detection so tests can
 * verify driver-specific SQL emission (Postgres `EXCLUDED`, MySQL
 * `LOCK IN SHARE MODE`, etc.) without spinning up the actual engine.
 *
 * The underlying PDO is whatever the test passes — usually an
 * in-memory SQLite — but `getDriver()` returns the override string
 * and `applyQuoting()` will translate identifiers to match.
 */
final class FakeDriverConnection extends Connection
{
    public function __construct(PDO $pdo, private string $driverOverride)
    {
        parent::__construct($pdo);
    }

    public function getDriver(): string
    {
        return $this->driverOverride;
    }
}
