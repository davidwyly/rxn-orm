<?php declare(strict_types=1);

/**
 * Run every benchmark in sequence. Each script is a separate process
 * so memory readings aren't polluted by earlier runs.
 *
 *   php bench/run.php
 */

$scripts = ['hydrate.php', 'insert.php', 'eager.php'];
$dir = __DIR__;

echo "# rxn-orm benchmarks\n";
echo 'PHP ' . PHP_VERSION . ' — SQLite in-memory — ' . gmdate('Y-m-d H:i:s') . " UTC\n";

foreach ($scripts as $script) {
    $path = "$dir/$script";
    echo "\n---\n";
    passthru("php $path");
}
