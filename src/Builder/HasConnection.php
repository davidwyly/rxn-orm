<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

use Rxn\Orm\Db\Connection;

/**
 * Carries an optional Connection on a builder so that terminal
 * methods like get/first/count/execute can run the SQL directly
 * instead of forcing the caller to call toSql() and hand the pair
 * to a PDO themselves. Builders that never set a Connection still
 * work as pure SQL generators — toSql() is unaffected.
 */
trait HasConnection
{
    protected ?Connection $connection = null;

    /** @return static */
    public function setConnection(Connection $connection)
    {
        $this->connection = $connection;
        return $this;
    }

    public function getConnection(): ?Connection
    {
        return $this->connection;
    }

    protected function requireConnection(string $method): Connection
    {
        if ($this->connection === null) {
            throw new \LogicException(
                static::class . "::$method requires a Connection. " .
                'Call setConnection() first, or use Connection::table()/query() to start the builder.'
            );
        }
        return $this->connection;
    }
}
