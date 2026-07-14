<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controllerRoot = $root . '/app/Controllers';
$baselineFile = $root . '/security/controller-db-access-baseline.txt';
$pattern = '/Database::connect\s*\(|db_connect\s*\(|service\s*\(\s*[\'\"]database[\'\"]\s*\)/';

$baseline = [];
foreach (file($baselineFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$path, $count] = array_pad(explode('|', trim($line), 2), 2, null);
    if ($path === null || $count === null || ! ctype_digit($count)) {
        fwrite(STDERR, "Invalid controller DB baseline row: {$line}\n");
        exit(2);
    }
    $baseline[$path] = (int) $count;
}

$current = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllerRoot));
foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') continue;
    $contents = file_get_contents($file->getPathname());
    if ($contents === false) continue;
    $count = preg_match_all($pattern, $contents);
    if ($count <= 0) continue;
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $current[$relative] = $count;
}
ksort($current);

$violations = [];
foreach ($current as $path => $count) {
    $allowed = $baseline[$path] ?? 0;
    if ($count > $allowed) $violations[] = "{$path}: {$count} direct DB calls (baseline {$allowed})";
}

if ($violations !== []) {
    fwrite(STDERR, "New direct database access in controllers is prohibited. Use a repository/use case instead:\n - " . implode("\n - ", $violations) . "\n");
    exit(1);
}

printf(
    "Controller DB access gate passed: %d legacy files, %d direct calls, 0 additions.\n",
    count($current),
    array_sum($current),
);
