<?php declare(strict_types=1);

namespace Rxn\Orm;

use Rxn\Orm\Builder\Raw;

/**
 * Shared scaffolding for SELECT / INSERT / UPDATE / DELETE builders.
 *
 * Subclasses (Query, Insert, Update, Delete) accumulate state into
 * `$commands` and `$bindings` then call `toSql()` to materialize a
 * `[string $sql, array $bindings]` tuple. The protected helpers here
 * are the building blocks every builder shares: identifier escaping,
 * command/binding accumulation, and table-alias tracking.
 */
abstract class Builder
{
    /**
     * Per-clause command list. Keyed by SQL keyword
     * (`SELECT`, `FROM`, `WHERE`, etc.); each value is the ordered
     * list of fragments that QueryParser stitches into the final SQL.
     *
     * Public for trait composition — HasWhere/HasConnection and the
     * Query subclasses read/write this directly.
     *
     * @var array<string, array<int|string, mixed>>
     */
    public array $commands = [];

    /**
     * Positional `?` binding stream, accumulated in emit order.
     *
     * @var array<int|string, mixed>
     */
    public array $bindings = [];

    /**
     * Table → alias map. Populated by `from()` / `join()` so a later
     * pass could rewrite identifiers; currently not exercised but
     * cheap to maintain alongside the other state.
     *
     * @var array<string, string>
     */
    public array $table_aliases = [];

    /**
     * Translate an identifier or Raw expression to its SQL form. Raw
     * passes through verbatim; strings are filtered for valid chars
     * then backtick-quoted (Postgres translation happens later, at
     * Connection level).
     *
     * @param string|Raw $reference
     */
    protected function cleanReference(string|Raw $reference): string
    {
        if ($reference instanceof Raw) {
            return $reference->sql;
        }
        $filtered = $this->filterReference($reference);
        return $this->escapeReference($filtered);
    }

    /**
     * Strip whitespace and backticks; keep the first run of valid
     * identifier characters. Defensive against malformed input —
     * the operator allowlist + this filter are what keep raw user
     * strings out of the emitted SQL.
     */
    protected function filterReference(string $operand): string
    {
        $operand = (string)preg_replace('#[\`\s]#', '', $operand);
        preg_match('#[\p{L}\_\.\-\`0-9]+#', $operand, $matches);
        return $matches[0] ?? '';
    }

    /**
     * Backtick-quote an identifier, splitting on a single dot for
     * `table.column` qualified references.
     */
    protected function escapeReference(string $operand): string
    {
        $parts = explode('.', $operand);
        if (count($parts) === 2) {
            return "`{$parts[0]}`.`{$parts[1]}`";
        }
        return "`$operand`";
    }

    /** Append a fragment to the named command bucket. */
    protected function addCommand(string $command, mixed $value): void
    {
        $this->commands[$command][] = $value;
    }

    /**
     * Merge another builder's $commands into ours. Used when a
     * sub-builder (Select / From / Join) hands its accumulated state
     * back to the parent Query.
     */
    protected function loadCommands(Builder $builder): void
    {
        $this->commands = array_merge_recursive($this->commands, $builder->commands);
    }

    /** Append another builder's bindings (in order) to ours. */
    protected function loadBindings(Builder $builder): void
    {
        foreach ($builder->bindings as $b) {
            $this->bindings[] = $b;
        }
    }

    /** Inherit the other builder's table-alias map. */
    protected function loadTableAliases(Builder $builder): void
    {
        $this->table_aliases = array_merge($this->table_aliases, $builder->table_aliases);
    }
}
