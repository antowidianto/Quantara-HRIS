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
