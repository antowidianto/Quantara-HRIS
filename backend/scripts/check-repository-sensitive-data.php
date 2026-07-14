<?php

declare(strict_types=1);

/**
 * Fail CI when a tracked file looks like a deploy secret, private key, or
 * production data export. This is intentionally deterministic and complements
 * (rather than replaces) repository-host secret scanning.
 */
$repositoryRoot = dirname(__DIR__, 2);
chdir($repositoryRoot);

exec('git ls-files', $trackedFiles, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "Unable to inventory Git-tracked files.\n");
    exit(2);
}

$forbiddenPaths = [
    '/(^|\/)\.env(?!\.example$)/i' => 'runtime environment file',
    '/\.(pem|key|p12|pfx|jks|keystore)$/i' => 'private key or signing store',
    '/(^|\/)(production|prod|customer|employee)[^\/]*\.(sql|dump|bak)(\.gz)?$/i' => 'production/customer data dump',
];

$credentialPatterns = [
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/' => 'private key material',
    '/\bAKIA[0-9A-Z]{16}\b/' => 'AWS access key',
    '/\bgh[pousr]_[A-Za-z0-9_]{30,}\b/' => 'GitHub access token',
    '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/' => 'Slack access token',
    '/\bsk_live_[A-Za-z0-9]{16,}\b/' => 'live payment-provider secret',
];

$violations = [];
foreach ($trackedFiles as $path) {
    $normalized = str_replace('\\', '/', $path);
    if ($normalized === 'mobile/android/app/debug.keystore') {
        // Standard Android development key; never accepted for a release build.
        continue;
    }
    foreach ($forbiddenPaths as $pattern => $label) {
        if (preg_match($pattern, $normalized) === 1) {
            $violations[] = "{$normalized}: {$label}";
        }
    }

    $absolute = $repositoryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (! is_file($absolute) || filesize($absolute) > 5_000_000) continue;
    $contents = file_get_contents($absolute);
    if ($contents === false || str_contains($contents, "\0")) continue;

    foreach ($credentialPatterns as $pattern => $label) {
        if (preg_match($pattern, $contents) === 1) {
            $violations[] = "{$normalized}: {$label}";
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Potential secret or production-data artifact is tracked:\n - " . implode("\n - ", array_unique($violations)) . "\n");
    exit(1);
}

printf("Sensitive repository gate passed: %d tracked files; no private keys, known live-token formats, runtime .env files, or production dump artifacts.\n", count($trackedFiles));
