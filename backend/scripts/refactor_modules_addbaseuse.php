<?php
/**
 * Inject `use` statements for base controllers (ApiController,
 * MasterCrudController, MasterControllers) into every moved controller.
 *
 * After PHASE 1 of refactor_modules.php, controllers are namespaced like
 * `App\Controllers\Api\V1\Auth`, but their `extends ApiController` line
 * no longer resolves to the base class at `App\Controllers\Api\V1`.
 * We add a top-of-file `use App\Controllers\Api\V1\ApiController;` (and
 * the two siblings as needed) wherever the source still references those
 * bare names.
 *
 * Idempotent: the script checks if the `use` is already present.
 */

declare(strict_types=1);

$backend = realpath(__DIR__ . '/..');
$root    = $backend . '/app/Controllers/Api/V1';

$baseClasses = [
    'ApiController'         => 'App\\Controllers\\Api\\V1\\ApiController',
    'MasterCrudController'  => 'App\\Controllers\\Api\\V1\\MasterCrudController',
    'MasterControllers'     => 'App\\Controllers\\Api\\V1\\MasterControllers',
];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$changed = 0;

foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;

    // Skip the base classes themselves — they live at V1 root, no need.
    $name = $f->getBasename('.php');
    if (isset($baseClasses[$name])) continue;

    $code = file_get_contents($f->getPathname());
    if ($code === false) continue;
    $orig = $code;

    // Only act on files that have `namespace App\Controllers\Api\V1\<Module>;`
    // (i.e. moved controllers — depth > V1 root).
    if (!preg_match('/^namespace\s+App\\\\Controllers\\\\Api\\\\V1\\\\[A-Za-z0-9_]+\s*;/m', $code)) {
        continue;
    }

    foreach ($baseClasses as $short => $fqcn) {
        // Does this controller extend the bare short name?
        if (!preg_match('/\bextends\s+' . preg_quote($short, '/') . '\b/', $code)) {
            continue;
        }
        // Is the use already there (any form)?
        if (preg_match('/^use\s+' . preg_quote($fqcn, '/') . '\s*;/m', $code)) {
            continue;
        }
        // Is there an aliased use that already resolves the short name?
        // Pattern: `use Some\Other as ApiController;`
        if (preg_match('/^use\s+[\\\\A-Za-z0-9_]+\s+as\s+' . preg_quote($short, '/') . '\s*;/m', $code)) {
            continue;
        }

        // Insert `use ...;` right after the namespace line.
        $code = preg_replace(
            '/^(namespace\s+App\\\\Controllers\\\\Api\\\\V1\\\\[A-Za-z0-9_]+\s*;\s*\R)/m',
            "$1use {$fqcn};\n",
            $code,
            1
        );
    }

    if ($code !== $orig) {
        file_put_contents($f->getPathname(), $code);
        $changed++;
        echo "  ✓ {$f->getPathname()}\n";
    }
}
echo "Files updated: {$changed}\n";