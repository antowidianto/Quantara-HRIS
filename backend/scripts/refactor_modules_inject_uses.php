<?php
/**
 * Auto-inject `use` statements for bare cross-module references.
 *
 * After moving a class into a sub-namespace, any place that referenced
 * a SIBLING class by its short name now resolves to the local sub-namespace
 * (which is wrong). Example: BpjsCalculator (now Payroll/) does
 * `CacheService::TTL_HOUR` — that bare name now resolves to
 * `App\Libraries\Payroll\CacheService` which doesn't exist.
 *
 * This script:
 *   1. Builds a class→FQCN lookup from refactor_modules_maps.php.
 *   2. Walks every PHP file under app/ tests/ scripts/ public/.
 *   3. For each file, looks at its current namespace and existing `use`
 *      statements. Then scans the body for bare references to known
 *      class short-names. If a referenced short-name resolves to a
 *      different namespace than the file's own, AND no matching `use`
 *      is present, AND no conflicting alias exists, append a `use`.
 *
 * Idempotent.
 */

declare(strict_types=1);

// Some HRIS source files are quite large; bump PCRE limits up front.
ini_set('pcre.backtrack_limit', '20000000');
ini_set('pcre.recursion_limit', '20000000');

$backend = realpath(__DIR__ . '/..');

[$ctrl, $models, $libs] = require __DIR__ . '/refactor_modules_maps.php';

// short_name → FQCN
$shortToFqcn = [];
foreach ($ctrl as $cls => $mod) {
    $shortToFqcn[$cls][] = "App\\Controllers\\Api\\V1\\{$mod}\\{$cls}";
}
foreach ($models as $cls => $mod) {
    $shortToFqcn[$cls][] = "App\\Models\\{$mod}\\{$cls}";
}
foreach ($libs as $cls => $mod) {
    $shortToFqcn[$cls][] = "App\\Libraries\\{$mod}\\{$cls}";
}

// We also need to know the BASE namespace of each map so we can see when
// a bare name in `App\Libraries\X` refers to a class that lives in
// `App\Libraries\Y` (different sub-namespace at same level).
function pickFqcn(array $candidates, string $currentNs): ?string
{
    // Prefer a candidate that shares the top-2 segments (Controllers/Models/
    // Libraries) with the current file's namespace.
    $head = explode('\\', $currentNs);
    $prefix = implode('\\', array_slice($head, 0, 3)); // e.g. App\Libraries
    foreach ($candidates as $fq) {
        if (str_starts_with($fq, $prefix . '\\')) {
            return $fq;
        }
    }
    // Fallback: first candidate.
    return $candidates[0] ?? null;
}

$roots = [
    $backend . '/app',
    $backend . '/tests',
    $backend . '/scripts',
    $backend . '/public',
];

$changed = 0;
$it = function (string $root) {
    if (!is_dir($root)) return [];
    return new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
};

foreach ($roots as $root) {
    foreach ($it($root) as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->getExtension() !== 'php') continue;
        $path = $f->getPathname();

        $code = file_get_contents($path);
        if ($code === false) continue;
        $orig = $code;

        // Get the file's own namespace.
        if (!preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $code, $m)) {
            continue;
        }
        $currentNs = $m[1];

        // Collect existing imports — both `use Foo\Bar;` and `use Foo\Bar as Baz;`
        // We map short alias → FQCN.
        $imports = [];
        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $code, $um, PREG_SET_ORDER)) {
            foreach ($um as $u) {
                $fq    = $u[1];
                $alias = $u[2] ?? substr($fq, strrpos($fq, '\\') + 1 ?: 0);
                $imports[$alias] = $fq;
            }
        }

        // Strip strings & comments to avoid false positives.
        // PCRE on huge files can return null when the JIT/backtrack
        // limit is hit — fall back to the raw code in that case.
        $stripped = @preg_replace([
            '/\/\*.*?\*\//s',          // block comments
            '/\/\/[^\n]*/',            // line comments
            '/#[^\n]*/',               // shell-style comments
            "/'(?:\\\\.|[^'\\\\])*'/", // single-quoted strings
            '/"(?:\\\\.|[^"\\\\])*"/', // double-quoted strings
        ], '', $code);
        if (!is_string($stripped)) {
            $stripped = $code;
        }

        // Patterns that hint a bare class name was used.
        // We capture the short name (group 1).
        $classPatterns = [
            '/\bnew\s+([A-Z][A-Za-z0-9_]*)\s*\(/',                // new Foo(
            '/(?<![A-Za-z0-9_\\\\])([A-Z][A-Za-z0-9_]*)::/',      // Foo::method
            '/\bextends\s+([A-Z][A-Za-z0-9_]*)\b/',               // extends Foo
            '/\bimplements\s+([A-Z][A-Za-z0-9_]*)/',              // implements Foo
            '/\binstanceof\s+([A-Z][A-Za-z0-9_]*)\b/',            // instanceof Foo
            '/\(\s*([A-Z][A-Za-z0-9_]*)\s+\$[A-Za-z0-9_]+/',      // (Foo $x
            '/,\s*([A-Z][A-Za-z0-9_]*)\s+\$[A-Za-z0-9_]+/',       // , Foo $x
            '/:\s*\??([A-Z][A-Za-z0-9_]*)\s*[\{\)]/',             // : Foo {  or  : Foo )
        ];

        $referenced = [];
        foreach ($classPatterns as $rx) {
            if (preg_match_all($rx, $stripped, $mm)) {
                foreach ($mm[1] as $name) {
                    $referenced[$name] = true;
                }
            }
        }

        $additions = [];
        foreach (array_keys($referenced) as $short) {
            if (!isset($shortToFqcn[$short])) continue;          // not a moved class
            if (isset($imports[$short])) continue;                // already imported (any alias)

            // If the file ALREADY lives at the destination namespace, nothing to do.
            $candidate = pickFqcn($shortToFqcn[$short], $currentNs);
            if ($candidate === null) continue;
            if ("{$currentNs}\\{$short}" === $candidate) continue; // own class
            // Skip if file's own class equals candidate (defining file).
            if (preg_match('/^(?:final\s+|abstract\s+)?class\s+' . preg_quote($short, '/') . '\b/m', $code)) {
                continue;
            }

            $additions[$short] = $candidate;
        }

        if (!$additions) continue;

        // Build the use block — append after existing use statements (or
        // after the namespace line if there are no use statements).
        $useBlock = "";
        foreach ($additions as $short => $fq) {
            $useBlock .= "use {$fq};\n";
        }

        if (preg_match('/(^use\s+[A-Za-z0-9_\\\\]+(?:\s+as\s+[A-Za-z0-9_]+)?\s*;\s*\R)+/m', $code, $blockMatch, PREG_OFFSET_CAPTURE)) {
            // Append after the LAST use line we can locate.
            // Easier: find the last use ; line by scanning.
            $lastPos = 0;
            if (preg_match_all('/^use\s+[A-Za-z0-9_\\\\]+(?:\s+as\s+[A-Za-z0-9_]+)?\s*;\s*\R/m', $code, $um2, PREG_OFFSET_CAPTURE)) {
                $last = end($um2[0]);
                $lastPos = $last[1] + strlen($last[0]);
                $code = substr($code, 0, $lastPos) . $useBlock . substr($code, $lastPos);
            }
        } else {
            // No existing use: insert after namespace ; line.
            $code = preg_replace(
                '/^(namespace\s+[A-Za-z0-9_\\\\]+\s*;\s*\R)/m',
                "$1\n" . $useBlock,
                $code,
                1
            );
        }

        if ($code !== $orig) {
            file_put_contents($path, $code);
            $changed++;
            echo "  ✓ {$path}: +" . count($additions) . " use(s) — " . implode(', ', array_keys($additions)) . "\n";
        }
    }
}

echo "Files updated: {$changed}\n";