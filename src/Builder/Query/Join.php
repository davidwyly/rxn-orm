<?php declare(strict_types=1);

namespace Rxn\Orm\Builder\Query;

use Rxn\Orm\Builder;

/**
 * Internal sub-builder used by Query::joinCustom() to accumulate the
 * pieces of a single JOIN clause (target table, alias, ON-conditions).
 * Once `set()` returns, the parent Query merges this Join's commands
 * + table-aliases via `loadCommands()` / `loadTableAliases()`.
 *
 *   $join = new Join();
 *   $join->set('orders', fn(Join $j) => $j->on('o.user_id', '=', 'u.id'), 'o', 'inner');
 *   // $join->commands['INNER JOIN']['orders'] = ['AS' => [...], 'ON' => [...]]
 */
class Join extends Builder
{
    public const JOIN_COMMANDS = [
        'inner' => 'INNER JOIN',
        'left'  => 'LEFT JOIN',
        'right' => 'RIGHT JOIN',
    ];

    public ?string $table = null;
    public ?string $alias = null;

    /** @var array<string, array<int, string>> e.g. ['ON' => [...], 'AS' => [...]] */
    public array $modifiers = [];

    /**
     * Configure this join: target table, body callback (where the
     * caller adds `on()` clauses), optional alias, and join kind.
     *
     * @param callable(Join): void $callable
     */
    public function set(string $table, callable $callable, ?string $alias = null, string $type = 'inner'): void
    {
        if (!array_key_exists($type, self::JOIN_COMMANDS)) {
            throw new \InvalidArgumentException("Unknown join type '$type'");
        }
        $this->table = $table;
        if ($alias !== null && $alias !== '') {
            $this->as($alias);
        }
        $command = self::JOIN_COMMANDS[$type];
        $callable($this);
        // Push the accumulated modifiers under [command][table] so the
        // QueryParser can render `<JOIN> table AS alias ON …`.
        $this->commands[$command][$table] = $this->modifiers;
    }

    public function as(string $alias): self
    {
        $this->alias = $alias;
        $cleanAlias  = $this->cleanReference($alias);
        $existing    = $this->modifiers['AS'] ?? [];
        if (!in_array($cleanAlias, $existing, true)) {
            $this->modifiers['AS'][] = $cleanAlias;
            if ($this->table !== null) {
                $this->table_aliases[$this->table] = $alias;
            }
        }
        return $this;
    }

    /**
     * Add an ON-clause condition. Both operands are treated as
     * column references (escaped, never bound), since JOIN ... ON
     * comparisons are over identifiers, not values.
     *
     * @param string|\Rxn\Orm\Builder\Raw $second
     */
    public function on(string $first, string $condition, string|\Rxn\Orm\Builder\Raw $second): self
    {
        $left  = $this->cleanReference($first);
        $right = $this->cleanReference($second);
        $this->modifiers['ON'][] = "$left $condition $right";
        return $this;
    }
}
