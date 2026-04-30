<?php declare(strict_types=1);

/**
 * PHP-CS-Fixer baseline. Applies PSR-12 + a small handful of hand-picked
 * rules that match the existing rxn-orm style. CI runs `--dry-run` on
 * every push; run locally without the flag to apply fixes.
 *
 *   vendor/bin/php-cs-fixer fix
 *   vendor/bin/php-cs-fixer fix --dry-run --diff   # preview
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bench'])
    ->exclude(['fixtures'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        // PSR-12 wants `<?php` on its own line, then a blank line,
        // then `declare(strict_types=1)`. We use the compact form
        // `<?php declare(strict_types=1);` everywhere intentionally.
        'blank_line_after_opening_tag' => false,
        'array_syntax'                 => ['syntax' => 'short'],
        'phpdoc_align'                 => ['align' => 'left'],
        'no_unused_imports'            => true,
        'ordered_imports'              => ['sort_algorithm' => 'alpha'],
        'trailing_comma_in_multiline'  => ['elements' => ['arrays', 'arguments', 'parameters']],
        // Suppress aggressive Yoda-style flips — we read left-to-right.
        'yoda_style'                   => false,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
