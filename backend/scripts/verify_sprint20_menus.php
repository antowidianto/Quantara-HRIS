<?php

/**
 * Verify Sprint 20 menu seed via direct mysqli (pola sama dengan
 * verify_sprint20_db.php).
 *
 * Run: cd backend; php scripts/verify_sprint20_menus.php
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

$host   = $env['database.default.hostname'] ?? '127.0.0.1';
$user   = $env['database.default.username'] ?? 'root';
$pass   = $env['database.default.password'] ?? '';
$dbname = $env['database.default.database'] ?? 'hris';
$port   = (int) ($env['database.default.port'] ?? 3306);

$mysqli = @new mysqli($host, $user, $pass, $dbname, $port);
if ($mysqli->connect_error) {
    fprintf(STDERR, "DB connect failed: %s\n", $mysqli->connect_error);
    exit(2);
}
$mysqli->set_charset('utf8mb4');

echo "=== Sprint 20 Menu Seed Verification ===\n";
echo "DB: {$dbname}@{$host}:{$port}\n\n";

// ─── Phase 1: pastikan entries lama dari migration #006 sudah dihapus ───
$obsolete = [
    'enterprise',
    'enterprise.erp_journal',
    'enterprise.signing',
    'enterprise.api_keys',
    'enterprise.licenses',
    'my.timesheet',
];
$placeholders = implode(',', array_fill(0, count($obsolete), '?'));
$stmt = $mysqli->prepare("
    SELECT code FROM menus
    WHERE company_id IS NULL AND code IN ($placeholders)
");
$stmt->bind_param(str_repeat('s', count($obsolete)), ...$obsolete);
$stmt->execute();
$leftover = [];
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) {
    $leftover[] = $r['code'];
}
$stmt->close();

echo "[Cleanup check]\n";
if (empty($leftover)) {
    echo "  ✓ Tidak ada entries lama dari migration #006\n";
} else {
    echo "  ✗ Masih ada entries lama: " . implode(', ', $leftover) . "\n";
}

// ─── Phase 2: cek entries baru migration #007 ─────────────────────
$expected = [
    'admin.enterprise',
    'admin.enterprise.subscription',
    'admin.enterprise.usage',
    'admin.enterprise.erp_journal',
    'admin.enterprise.signing',
    'admin.enterprise.api_keys',
    'admin.enterprise.licenses',
    'admin.enterprise.timesheet',
];

$placeholders = implode(',', array_fill(0, count($expected), '?'));
$stmt = $mysqli->prepare("
    SELECT id, type, parent_id, code, label, route_path, permission, super_admin_only, sort_order
    FROM menus
    WHERE company_id IS NULL AND code IN ($placeholders)
    ORDER BY sort_order ASC
");
$stmt->bind_param(str_repeat('s', count($expected)), ...$expected);
$stmt->execute();
$result = $stmt->get_result();

echo "\n[Enterprise Modules under Admin section]\n";
$found = [];
while ($r = $result->fetch_assoc()) {
    $found[$r['code']] = $r;
    $sa = $r['super_admin_only'] ? 'SA' : '--';
    printf(
        "  ✓ id=%-4d %-7s [%s] parent=%-5s code=%-32s route=%-22s perm=%s\n",
        $r['id'],
        $r['type'],
        $sa,
        $r['parent_id'] ?? 'NULL',
        $r['code'],
        $r['route_path'] ?? '(none)',
        $r['permission'] ?? 'public'
    );
}
$stmt->close();

// ─── Phase 3: pastikan parent chain valid ───────────────────────
echo "\n[Parent chain]\n";
$res = $mysqli->query("
    SELECT id, code FROM menus
    WHERE company_id IS NULL AND code IN ('admin', 'admin.enterprise')
");
$parents = [];
while ($r = $res->fetch_assoc()) {
    $parents[$r['code']] = (int) $r['id'];
    printf("  · %-25s → id=%d\n", $r['code'], $r['id']);
}

if (isset($parents['admin'], $parents['admin.enterprise'])
    && isset($found['admin.enterprise'])
    && (int) $found['admin.enterprise']['parent_id'] === $parents['admin']) {
    echo "  ✓ admin.enterprise nempel ke section 'admin'\n";
}

// ─── Summary ──────────────────────────────────────────────────
echo "\n[Summary]\n";
$missing = array_values(array_diff($expected, array_keys($found)));
echo '  Expected:    ' . count($expected) . "\n";
echo '  Found:       ' . count($found) . "\n";
echo '  Missing:     ' . (count($missing) ? implode(', ', $missing) : '(none)') . "\n";
echo '  Obsolete:    ' . (count($leftover) ? implode(', ', $leftover) : '(none)') . "\n";

$saCount = 0;
foreach ($found as $r) {
    if ((int) $r['super_admin_only'] === 1) $saCount++;
}
echo "  super_admin_only=1: {$saCount}/" . count($found) . "\n";

if (! empty($missing) || ! empty($leftover) || $saCount !== count($found)) {
    echo "\nStatus: ✗ INCOMPLETE\n";
    exit(1);
}
echo "\nStatus: ✓ COMPLETE — Enterprise Modules super_admin_only di bawah Admin\n";
exit(0);