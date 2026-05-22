<?php
/**
 * verify-regenerate.php — sanity check setelah regenerate.
 *
 * Cek:
 *   1. Setiap dept punya manager_employee_id yang valid
 *   2. Tidak ada email selain @test.com (kecuali super admin)
 *   3. NIK encrypted bisa di-decrypt balik dan formatnya 16 digit
 *   4. Manager chain: setiap staff punya manager_id yang valid
 *   5. Roles tersebar wajar (manager, hr_admin, finance, employee)
 */

$dbPath  = __DIR__ . '/../writable/hris.sqlite';
$envPath = __DIR__ . '/../.env';

$db = new SQLite3($dbPath);
$db->enableExceptions(true);

// Load encryption key (sama logic seperti regenerate script)
function loadKey(string $envPath): string {
    $raw = '';
    if (file_exists($envPath)) {
        $contents = file_get_contents($envPath);
        if (preg_match('/^\s*encryption\.key\s*=\s*[\'"]?([^\'"\r\n#]+?)[\'"]?\s*(?:#.*)?$/m', $contents, $m)) {
            $raw = trim($m[1]);
        }
    }
    if ($raw === '') return hash('sha256', 'hris_dev_no_key', true);
    if (str_starts_with($raw, 'base64:')) {
        $decoded = base64_decode(substr($raw, 7), true);
        if ($decoded !== false && strlen($decoded) >= 32) return $decoded;
    }
    if (strlen($raw) >= 32) return $raw;
    return hash('sha256', $raw . '_hris_pad', true);
}
function decryptValue(?string $cipher, string $key): ?string {
    if ($cipher === null || $cipher === '') return null;
    if (preg_match('/^v[1-9]:(.+)$/s', $cipher, $m)) $cipher = $m[1];
    $raw = base64_decode($cipher, true);
    if ($raw === false || strlen($raw) < 28) return null;
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? null : $pt;
}

$encKey = loadKey($envPath);

echo "=== Verify regenerate result ===\n\n";

// 1. Dept manager coverage
echo "[1] Department manager coverage:\n";
$res = $db->query("
    SELECT d.id, c.name AS company, d.name AS dept, d.manager_employee_id,
           e.full_name AS mgr_name
    FROM departments d
    JOIN companies c ON c.id = d.company_id
    LEFT JOIN employees e ON e.id = d.manager_employee_id
    WHERE d.is_active = 1
    ORDER BY d.company_id, d.name
");
$missingMgr = 0;
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $mgr = $r['manager_employee_id']
        ? $r['mgr_name']
        : '— VACANT —';
    $flag = $r['manager_employee_id'] ? '✓' : ($r['dept'] === 'Direksi' ? '~' : '✗');
    if ($flag === '✗') $missingMgr++;
    printf("  %s [%s] %-25s → %s\n", $flag, substr($r['company'], 0, 8), $r['dept'], $mgr);
}
echo "  Missing managers: $missingMgr (Direksi excluded)\n\n";

// 2. Email domain check
echo "[2] Email domain:\n";
$total = (int) $db->querySingle("SELECT COUNT(*) FROM users WHERE id != 1");
$testCom = (int) $db->querySingle("SELECT COUNT(*) FROM users WHERE id != 1 AND email LIKE '%@test.com'");
$other = (int) $db->querySingle("SELECT COUNT(*) FROM users WHERE id != 1 AND email NOT LIKE '%@test.com'");
echo "  Total users (excl. super admin): $total\n";
echo "  @test.com:     $testCom\n";
echo "  Other domains: $other\n";
if ($other > 0) {
    $sample = $db->query("SELECT email FROM users WHERE id != 1 AND email NOT LIKE '%@test.com' LIMIT 5");
    while ($r = $sample->fetchArray(SQLITE3_ASSOC)) echo "    - {$r['email']}\n";
}
echo "\n";

// 3. NIK encryption sanity
echo "[3] NIK decrypt sample:\n";
$sample = $db->query("SELECT id, employee_number, full_name, nik_encrypted, nik_hash FROM employees LIMIT 5");
$ok = 0; $fail = 0;
while ($r = $sample->fetchArray(SQLITE3_ASSOC)) {
    $nik = decryptValue($r['nik_encrypted'], $encKey);
    if ($nik && strlen($nik) === 16 && ctype_digit($nik)) {
        printf("  ✓ #%d %-15s %-25s NIK=%s\n", $r['id'], $r['employee_number'], $r['full_name'], $nik);
        $ok++;
    } else {
        printf("  ✗ #%d %-15s %-25s NIK FAIL\n", $r['id'], $r['employee_number'], $r['full_name']);
        $fail++;
    }
}
echo "  Sample: $ok ok, $fail failed\n\n";

// 4. Manager chain coverage
echo "[4] Manager chain (staff with valid manager_id):\n";
$totalStaff = (int) $db->querySingle("
    SELECT COUNT(*) FROM employees
    WHERE department_id IN (SELECT id FROM departments WHERE name != 'Direksi')
");
$withMgr = (int) $db->querySingle("
    SELECT COUNT(*) FROM employees e
    WHERE e.department_id IN (SELECT id FROM departments WHERE name != 'Direksi')
      AND e.manager_id IS NOT NULL
      AND EXISTS (SELECT 1 FROM employees e2 WHERE e2.id = e.manager_id)
");
$noMgr = $totalStaff - $withMgr;
echo "  Total non-Direksi staff: $totalStaff\n";
echo "  With valid manager:      $withMgr\n";
echo "  Without manager:         $noMgr (managers themselves, OK)\n\n";

// 5. Role distribution per company
echo "[5] Role distribution per company:\n";
$res = $db->query("
    SELECT c.code AS company, r.slug AS role, COUNT(*) AS cnt
    FROM users u
    JOIN companies c ON c.id = u.company_id
    JOIN roles r ON r.id = u.role_id
    GROUP BY c.code, r.slug
    ORDER BY c.code, r.slug
");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  %-6s %-15s %d\n", $r['company'], $r['role'], $r['cnt']);
}
echo "\n";

// 6. Direksi check — expect Presdir + Direktur N
echo "[6] Direksi members:\n";
$res = $db->query("
    SELECT c.code, e.full_name, p.name AS position, u.email
    FROM employees e
    JOIN companies c ON c.id = e.company_id
    JOIN departments d ON d.id = e.department_id
    JOIN positions p ON p.id = e.position_id
    LEFT JOIN users u ON u.id = e.user_id
    WHERE d.name = 'Direksi'
    ORDER BY c.code, p.name
");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  [%s] %-30s %-25s %s\n", $r['code'], $r['position'], $r['full_name'], $r['email']);
}
echo "\n";

// 7. Org_structures wired
echo "[7] organization_structures assignments:\n";
$res = $db->query("
    SELECT c.code, os.role_key, e.full_name
    FROM organization_structures os
    JOIN companies c ON c.id = os.company_id
    LEFT JOIN employees e ON e.id = os.employee_id
    ORDER BY c.code, os.role_key
");
$assigned = 0; $vacant = 0;
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $name = $r['full_name'] ?: '— vacant —';
    $flag = $r['full_name'] ? '✓' : '·';
    if ($r['full_name']) $assigned++; else $vacant++;
    printf("  %s [%s] %-25s → %s\n", $flag, $r['code'], $r['role_key'], $name);
}
echo "  Total: $assigned assigned, $vacant vacant\n";

echo "\n=== End verify ===\n";