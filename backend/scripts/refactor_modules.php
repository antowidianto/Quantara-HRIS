<?php
/**
 * One-shot module refactor.
 *
 * Moves Controllers (Api/V1), Models, Libraries into per-module sub-folders,
 * updates the `namespace` declaration of each moved file, then walks the
 * entire backend tree and rewrites every `use` statement / FQCN reference
 * to point at the new location.
 *
 * Routes.php is updated separately because its controller references do not
 * use FQCN — they rely on the route-group `namespace` option.
 *
 * Run from backend/ (or anywhere) like:  php scripts/refactor_modules.php
 *
 * Idempotent: re-running after a successful run is a no-op (files already
 * at destination, namespaces already updated, use statements already fixed).
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────
// Paths
// ─────────────────────────────────────────────────────────────────
$backend = realpath(__DIR__ . '/..');
if ($backend === false) {
    fwrite(STDERR, "Cannot resolve backend root\n");
    exit(1);
}
chdir($backend);

$controllersBase = $backend . '/app/Controllers/Api/V1';
$modelsBase      = $backend . '/app/Models';
$librariesBase   = $backend . '/app/Libraries';
$routesFile      = $backend . '/app/Config/Routes.php';

// Module map source-of-truth lives in refactor_modules_maps.php so that
// _fixup.php and this script never drift.
[$ctrlClassToModule, $modelClassToModule, $libClassToModule] = require __DIR__ . '/refactor_modules_maps.php';

// ─────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────
function info(string $msg): void
{
    fwrite(STDOUT, $msg . "\n");
}

function flatten(array $map): array
{
    // module => [classes]   →   class => module
    $out = [];
    foreach ($map as $module => $classes) {
        foreach ($classes as $c) {
            if (isset($out[$c])) {
                throw new RuntimeException("Duplicate class assignment: {$c}");
            }
            $out[$c] = $module;
        }
    }
    return $out;
}

/**
 * Move a single class file into its module sub-folder and rewrite the
 * `namespace` line accordingly. Idempotent.
 */
function moveAndRenamespace(
    string $baseDir,
    string $oldNamespace,
    string $class,
    string $module,
    string $newNamespace
): bool {
    $oldPath = $baseDir . '/' . $class . '.php';
    $newDir  = $baseDir . '/' . $module;
    $newPath = $newDir . '/' . $class . '.php';

    if (!is_dir($newDir)) {
        mkdir($newDir, 0755, true);
    }

    // Already moved?
    if (is_file($newPath) && !is_file($oldPath)) {
        return false;
    }

    if (!is_file($oldPath)) {
        info("  ! missing source: {$oldPath}");
        return false;
    }

    $code = file_get_contents($oldPath);
    if ($code === false) {
        throw new RuntimeException("Read failed: {$oldPath}");
    }

    // Replace `namespace App\Foo;` with `namespace App\Foo\Module;`
    // Only if it currently equals oldNamespace exactly (avoid double-prefix).
    $code = preg_replace(
        '/^namespace\s+' . preg_quote($oldNamespace, '/') . '\s*;/m',
        'namespace ' . $newNamespace . ';',
        $code,
        1,
        $count
    );
    if ($count === 0) {
        info("  ! namespace not matched in {$oldPath} (expected `{$oldNamespace}`) — skipping move");
        return false;
    }

    if (file_put_contents($newPath, $code) === false) {
        throw new RuntimeException("Write failed: {$newPath}");
    }
    unlink($oldPath);
    return true;
}

/**
 * Walk all .php files under given roots and apply a callback to their content.
 */
function rewriteAllPhp(array $roots, callable $rewrite): int
{
    $changed = 0;
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;

            $path = $f->getPathname();
            $orig = file_get_contents($path);
            if ($orig === false) continue;
            $new = $rewrite($orig, $path);
            if ($new !== null && $new !== $orig) {
                file_put_contents($path, $new);
                $changed++;
            }
        }
    }
    return $changed;
}

// ─────────────────────────────────────────────────────────────────
// PHASE 1 — Move files & rewrite their own `namespace` lines
// ─────────────────────────────────────────────────────────────────
info("=== PHASE 1: Moving files & rewriting namespaces ===");

// $ctrlClassToModule / $modelClassToModule / $libClassToModule were already
// loaded (already flattened) from refactor_modules_maps.php.

$movedCtrls = 0;
foreach ($ctrlClassToModule as $class => $module) {
    $ok = moveAndRenamespace(
        $controllersBase,
        'App\\Controllers\\Api\\V1',
        $class,
        $module,
        'App\\Controllers\\Api\\V1\\' . $module
    );
    if ($ok) {
        info("  ✓ Controller: {$class} → {$module}/");
        $movedCtrls++;
    }
}
info("  Controllers moved: {$movedCtrls}");

$movedModels = 0;
foreach ($modelClassToModule as $class => $module) {
    $ok = moveAndRenamespace(
        $modelsBase,
        'App\\Models',
        $class,
        $module,
        'App\\Models\\' . $module
    );
    if ($ok) {
        info("  ✓ Model: {$class} → {$module}/");
        $movedModels++;
    }
}
info("  Models moved: {$movedModels}");

$movedLibs = 0;
foreach ($libClassToModule as $class => $module) {
    $ok = moveAndRenamespace(
        $librariesBase,
        'App\\Libraries',
        $class,
        $module,
        'App\\Libraries\\' . $module
    );
    if ($ok) {
        info("  ✓ Library: {$class} → {$module}/");
        $movedLibs++;
    }
}
info("  Libraries moved: {$movedLibs}");

// ─────────────────────────────────────────────────────────────────
// PHASE 2 — Build replacement table & rewrite all .php files
// ─────────────────────────────────────────────────────────────────
info("\n=== PHASE 2: Rewriting `use` statements & FQCN references ===");

/**
 * Build a list of [oldFQCN, newFQCN] pairs. We will run replacements on each
 * file, in two flavours:
 *   - escaped form (double-quoted strings):   App\\Controllers\\Api\\V1\\X
 *   - bare form (PHP code & single-quoted):   App\Controllers\Api\V1\X
 */
$pairs = [];
foreach ($ctrlClassToModule as $class => $module) {
    $pairs[] = [
        'App\\Controllers\\Api\\V1\\' . $class,
        'App\\Controllers\\Api\\V1\\' . $module . '\\' . $class,
    ];
}
foreach ($modelClassToModule as $class => $module) {
    $pairs[] = [
        'App\\Models\\' . $class,
        'App\\Models\\' . $module . '\\' . $class,
    ];
}
foreach ($libClassToModule as $class => $module) {
    $pairs[] = [
        'App\\Libraries\\' . $class,
        'App\\Libraries\\' . $module . '\\' . $class,
    ];
}

// Sort pairs by descending source length to avoid accidentally rewriting
// `App\Models\Foo` when `App\Models\FooBar` already exists in the same map.
usort($pairs, static fn($a, $b) => strlen($b[0]) - strlen($a[0]));

$roots = [
    $backend . '/app',
    $backend . '/tests',
    $backend . '/scripts',
    $backend . '/public',
];

$fileChanged = rewriteAllPhp($roots, function (string $code, string $path) use ($pairs, $routesFile) {
    if (realpath($path) === realpath($routesFile)) {
        // Routes.php gets a special treatment in PHASE 3.
        return null;
    }

    foreach ($pairs as [$old, $new]) {
        // Bare form (PHP code, single-quoted strings).
        // Match the OLD FQCN only when it is NOT followed by another
        // namespace separator + identifier — i.e. this must be the final
        // segment, not a prefix of something deeper. This prevents repeat
        // replacement that would yield `App\Foo\Module\Module\Class`.
        $bareOld = $old;
        $bareNew = $new;
        $code = preg_replace(
            '/(?<![A-Za-z0-9_\\\\])' . preg_quote($bareOld, '/') . '(?![A-Za-z0-9_\\\\])/',
            $bareNew,
            $code
        );

        // Escaped form (double-quoted strings):  App\\Models\\X  →  App\\Models\\Module\\X
        // In PHP source, that is literally  "App\\\\Models\\\\X"  (4 backslashes).
        $escOld = str_replace('\\', '\\\\', $bareOld);
        $escNew = str_replace('\\', '\\\\', $bareNew);
        $code = preg_replace(
            '/(?<![A-Za-z0-9_\\\\])' . preg_quote($escOld, '/') . '(?![A-Za-z0-9_\\\\])/',
            $escNew,
            $code
        );
    }

    return $code;
});

info("  Files rewritten: {$fileChanged}");

// ─────────────────────────────────────────────────────────────────
// PHASE 3 — Routes.php: prefix controller strings with module path
// ─────────────────────────────────────────────────────────────────
info("\n=== PHASE 3: Updating Routes.php controller strings ===");

$routes = file_get_contents($routesFile);
if ($routes === false) {
    throw new RuntimeException("Could not read Routes.php");
}

$routesChanges = 0;
foreach ($ctrlClassToModule as $class => $module) {
    // Match the controller name when it appears as the first segment of a
    // controller-string — usually preceded by a quote or `=>` and followed
    // by `::method`.  We protect against double-prefix by ensuring the
    // character right before $class is either a quote, a space, or `>`,
    // and NEVER a backslash (which would mean it's already prefixed).
    $pattern = '/(?<![A-Za-z0-9_\\\\])' . preg_quote($class, '/') . '::/';
    $replace = $module . '\\' . $class . '::';
    $routes  = preg_replace($pattern, $replace, $routes, -1, $n);
    if ($n > 0) {
        info("  ✓ Routes: {$class} → {$module}\\{$class} (×{$n})");
        $routesChanges += $n;
    }
}

// Same for the resource() option `'controller' => 'XController'`
foreach ($ctrlClassToModule as $class => $module) {
    $pattern = "/'controller'\\s*=>\\s*'(?!\\\\?{$module}\\\\)" . preg_quote($class, '/') . "'/";
    $replace = "'controller' => '{$module}\\\\{$class}'";
    $routes  = preg_replace($pattern, $replace, $routes, -1, $n);
    if ($n > 0) {
        info("  ✓ Routes: 'controller'={$class} → '{$module}\\{$class}' (×{$n})");
        $routesChanges += $n;
    }
}

if ($routesChanges > 0) {
    file_put_contents($routesFile, $routes);
}
info("  Routes.php replacements: {$routesChanges}");

info("\n=== DONE ===");
info("Run `composer dump-autoload` next, then `php spark routes` and `composer stan` to verify.");