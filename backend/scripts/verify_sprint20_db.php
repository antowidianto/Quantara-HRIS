<?php

/**
 * Verify Sprint 20 DB tables + permissions via direct mysqli.
 *
 * Run: cd backend; php scripts/verify_sprint20_db.php
 */

// Parse .env file
$envPath = __DIR__ . '/../.env';
$env = [];
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (! str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, '"\'');
        $env[$k] = $v;
    }
}

$host = $env['database.default.hostname'] ?? '127.0.0.1';
$user = $env['database.default.username'] ?? 'root';
$pass = $env['database.default.password'] ?? '';
$dbname = $env['database.default.database'] ?? 'hris';
$port = (int) ($env['database.default.port'] ?? 3306);

$mysqli = @new mysqli($host, $user, $pass, $dbname, $port);
if ($mysqli->connect_error) {
    fprintf(STDERR, "DB connect failed: %s\n", $mysqli->connect_error);
    exit(2);
}
$mysqli->set_charset('utf8mb4');

echo "=== Sprint 20 DB Verification ===\n";
echo "Database: {$dbname}@{$host}:{$port}\n\n";

// ─── Tables ────────────────────────────────────────────────────────
$tables = [
    'erp_connections', 'erp_journal_mappings', 'erp_journal_exports',
    'psre_providers', 'signing_envelopes', 'signing_envelope_signers', 'signing_envelope_events',
    'api_keys', 'api_key_scopes', 'api_scopes', 'api_request_logs',
    'projects', 'project_assignments', 'timesheet_entries',
    'license_types', 'employee_licenses',
];

echo "[Tables]\n";
$missingTbl = 0;
foreach ($tables as $tbl) {
    $r = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($tbl) . "'");
    if ($r && $r->num_rows > 0) {
        $cnt = $mysqli->query("SELECT COUNT(*) AS n FROM `{$tbl}`")->fetch_assoc();
        echo "  ✓ {$tbl} ({$cnt['n']} rows)\n";
    } else {
        echo "  ✗ {$tbl} — NOT FOUND\n";
        $missingTbl++;
    }
}

// ─── Permissions ────────────────────────────────────────────────────
echo "\n[Permissions]\n";
$permSlugs = [
    'erp.mapping.manage', 'erp.journal.post', 'erp.journal.view',
    'signing.envelope.create', 'signing.envelope.view', 'signing.provider.manage',
    'api_key.manage', 'api_key.view',
    'timesheet.submit', 'timesheet.approve', 'timesheet.report', 'project.manage',
    'license_type.manage', 'employee_license.manage', 'employee_license.view',
];
$slugList = "'" . implode("','", array_map([$mysqli, 'real_escape_string'], $permSlugs)) . "'";
// Detect actual group column name (could be `group`, `group_name`, or none)
$colsRes = $mysqli->query("SHOW COLUMNS FROM permissions");
$cols = [];
while ($c = $colsRes->fetch_assoc()) $cols[] = $c['Field'];
$groupCol = in_array('group_name', $cols, true) ? 'group_name'
          : (in_array('group', $cols, true) ? '`group`' : null);
$selectCols = $groupCol ? "slug, name, {$groupCol} AS grp" : "slug, name";
$orderCols  = $groupCol ? "{$groupCol}, slug" : "slug";
$res = $mysqli->query("SELECT {$selectCols} FROM permissions WHERE slug IN ({$slugList}) ORDER BY {$orderCols}");
$found = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $found[] = $row['slug'];
        $g = $row['grp'] ?? '?';
        echo "  ✓ [{$g}] {$row['slug']} — {$row['name']}\n";
    }
}
$missingPerms = array_diff($permSlugs, $found);
foreach ($missingPerms as $slug) {
    echo "  ✗ {$slug} — NOT SEEDED\n";
}

// ─── api_scopes catalog ─────────────────────────────────────────────
echo "\n[API Scopes Catalog]\n";
$res = $mysqli->query("SELECT scope, group_label, is_dangerous FROM api_scopes ORDER BY group_label, scope");
$scopeCount = 0;
if ($res) {
    while ($s = $res->fetch_assoc()) {
        $danger = $s['is_dangerous'] ? ' ⚠' : '';
        echo "  · [{$s['group_label']}] {$s['scope']}{$danger}\n";
        $scopeCount++;
    }
    echo "  Total: {$scopeCount} scopes\n";
}

// ─── Summary ───────────────────────────────────────────────────────
echo "\n=== Summary ===\n";
echo "Tables found:        " . (count($tables) - $missingTbl) . "/" . count($tables) . "\n";
echo "Permissions seeded:  " . count($found) . "/" . count($permSlugs) . "\n";
echo "API Scopes:          {$scopeCount}\n";

$mysqli->close();

if ($missingTbl > 0 || count($missingPerms) > 0) {
    echo "Status: ✗ INCOMPLETE\n";
    exit(1);
}
echo "Status: ✓ COMPLETE\n";
exit(0);