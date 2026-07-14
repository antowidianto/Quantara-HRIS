<?php

declare(strict_types=1);

/**
 * Fail fast when CodeIgniter migration discovery would be ambiguous.
 *
 * Usage: php scripts/check-migrations.php
 */

$migrationDirectory = realpath(__DIR__ . '/../app/Database/Migrations');
if ($migrationDirectory === false) {
    fwrite(STDERR, "Migration directory was not found.\n");
    exit(2);
}

$files = glob($migrationDirectory . DIRECTORY_SEPARATOR . '*.php') ?: [];
$versions = [];
$classes = [];
$errors = [];

foreach ($files as $file) {
    $basename = basename($file);
    if (! preg_match('/^(\d{4}-\d{2}-\d{2}-\d{6})_(.+)\.php$/', $basename, $match)) {
        $errors[] = "Invalid migration filename: {$basename}";
        continue;
    }

    $version = $match[1];
    $versions[$version][] = $basename;

    $contents = file_get_contents($file);
    if ($contents === false) {
        $errors[] = "Unable to read migration: {$basename}";
        continue;
    }

    if (! preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+Migration\b/', $contents, $classMatch)) {
        $errors[] = "Migration class extending Migration was not found: {$basename}";
        continue;
    }

    $classes[$classMatch[1]][] = $basename;

    $upBody = migrationMethodBody($contents, 'up');
    if ($upBody === null) {
        $errors[] = "Migration up() method was not found: {$basename}";
        continue;
    }
    $destructivePatterns = [
        '/->\s*(dropTable|dropColumn|renameColumn)\s*\(/i',
        '/\b(DROP\s+TABLE|DROP\s+COLUMN|TRUNCATE\s+TABLE|DELETE\s+FROM)\b/i',
        '/\bALTER\s+TABLE\b[^;]+\bDROP\b/is',
    ];
    foreach ($destructivePatterns as $pattern) {
        if (preg_match($pattern, $upBody) && ! str_contains($contents, '@roll-forward-reviewed')) {
            $errors[] = "Destructive operation in up() requires @roll-forward-reviewed and a release runbook: {$basename}";
            break;
        }
    }
}

foreach ($versions as $version => $versionFiles) {
    if (count($versionFiles) > 1) {
        $errors[] = "Duplicate migration version {$version}: " . implode(', ', $versionFiles);
    }
}

foreach ($classes as $class => $classFiles) {
    if (count($classFiles) > 1) {
        $errors[] = "Duplicate migration class {$class}: " . implode(', ', $classFiles);
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Migration integrity check failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

printf(
    "Migration integrity check passed: %d files, %d unique versions, %d unique classes.\n",
    count($files),
    count($versions),
    count($classes),
);

/** Extract one method body without confusing the down() rollback body. */
function migrationMethodBody(string $php, string $method): ?string
{
    $tokens = token_get_all($php);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                if (strcasecmp($tokens[$j][1], $method) !== 0) {
                    break;
                }
                while ($j < $count && $tokens[$j] !== '{') {
                    $j++;
                }
                if ($j >= $count) {
                    return null;
                }
                $depth = 1;
                $body = '';
                for ($j++; $j < $count && $depth > 0; $j++) {
                    $token = $tokens[$j];
                    $text = is_array($token) ? $token[1] : $token;
                    if ($text === '{') {
                        $depth++;
                    } elseif ($text === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $body .= $text;
                }
                return $body;
            }
        }
    }
    return null;
}
