<?php
/**
 * Fix-up pass for refactor_modules.php.
 *
 * The first pass excluded FQCN matches preceded by `\` (intended to
 * prevent double-prefix), but that also blocked legitimate absolute
 * references like `\App\Libraries\X::method()` inside namespaced files.
 *
 * This pass only flips a leading `\` into part of the FQCN — it's
 * otherwise the same replacement table.
 */

declare(strict_types=1);

$backend = realpath(__DIR__ . '/..');
chdir($backend);

[$ctrlClassToModule, $modelClassToModule, $libClassToModule] = require __DIR__ . '/refactor_modules_maps.php';

$pairs = [];
foreach ($ctrlClassToModule as $class => $module) {
    $pairs[] = ['App\\Controllers\\Api\\V1\\' . $class,
                'App\\Controllers\\Api\\V1\\' . $module . '\\' . $class];
}
foreach ($modelClassToModule as $class => $module) {
    $pairs[] = ['App\\Models\\' . $class,
                'App\\Models\\' . $module . '\\' . $class];
}
foreach ($libClassToModule as $class => $module) {
    $pairs[] = ['App\\Libraries\\' . $class,
                'App\\Libraries\\' . $module . '\\' . $class];
}
usort($pairs, static fn($a, $b) => strlen($b[0]) - strlen($a[0]));

$roots  = [$backend . '/app', $backend . '/tests', $backend . '/scripts', $backend . '/public'];
$routes = $backend . '/app/Config/Routes.php';

$changed = 0;
foreach ($roots as $root) {
    if (!is_dir($root)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') continue;
        if (realpath($f->getPathname()) === realpath($routes)) continue;

        $orig = file_get_contents($f->getPathname());
        $code = $orig;
        if ($code === false) continue;

        foreach ($pairs as [$old, $new]) {
            // Bare form, allow leading `\`.  We require the OLD FQCN to be
            // followed by something that is NOT a word char or backslash —
            // that guards against double-prefix (the new FQCN inserts an
            // extra `\Module\` after the OLD prefix, so re-running the
            // pattern against the rewritten code never finds OLD again).
            $code = preg_replace(
                '/(?<![A-Za-z0-9_])' . preg_quote($old, '/') . '(?![A-Za-z0-9_\\\\])/',
                $new,
                $code
            );

            // Escaped form (double-quoted PHP string literals).
            $escOld = str_replace('\\', '\\\\', $old);
            $escNew = str_replace('\\', '\\\\', $new);
            $code = preg_replace(
                '/(?<![A-Za-z0-9_])' . preg_quote($escOld, '/') . '(?![A-Za-z0-9_\\\\])/',
                $escNew,
                $code
            );
        }

        if ($code !== $orig) {
            file_put_contents($f->getPathname(), $code);
            $changed++;
            echo "  ✓ {$f->getPathname()}\n";
        }
    }
}
echo "Files rewritten: {$changed}\n";