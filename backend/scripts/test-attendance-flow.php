<?php

// Smoke test: cek tabel & permission baru, plus simulasi scope branch.
// Jalankan dari project root:
//   php backend/scripts/test-attendance-flow.php

$dbPath = __DIR__ . '/../writable/hris.sqlite';
if (! file_exists($dbPath)) {
    echo "DB tidak ditemukan di {$dbPath}. Jalankan migrasi/seed dulu.\n";
    exit(1);
}
$db = new SQLite3($dbPath);
$db->enableExceptions(true);

$pass = 0;
$fail = 0;

function check($label, $expected, $actual) {
    global $pass, $fail;
    if ($expected === $actual) {
        echo "  [OK]   {$label}\n";
        $pass++;
    } else {
        echo "  [FAIL] {$label}\n";
        echo "         expected: " . json_encode($expected) . "\n";
        echo "         actual:   " . json_encode($actual) . "\n";
        $fail++;
    }
}

echo str_repeat('-', 60) . "\n";
echo " Smoke test: attendance change request workflow\n";
echo str_repeat('-', 60) . "\n";

// 1) Tabel attendance_change_requests sudah ada
$row = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='attendance_change_requests'");
check('tabel attendance_change_requests ada', 'attendance_change_requests', $row ?: '');

// 2) Permission baru
foreach (['attendance.view_all_branches', 'attendance.request_change', 'attendance.approve_change'] as $slug) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM permissions WHERE slug = :slug");
    $stmt->bindValue(':slug', $slug);
    $res = $stmt->execute();
    $cnt = (int) $res->fetchArray(SQLITE3_NUM)[0];
    check("permission '{$slug}' terdaftar", 1, $cnt);
}

// 3) HR Admin (role_id=2) harus punya permission approve_change
$stmt = $db->prepare("SELECT COUNT(*) FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.role_id = 2 AND p.slug = 'attendance.approve_change'");
$res = $stmt->execute();
$cnt = (int) $res->fetchArray(SQLITE3_NUM)[0];
check('HR Admin (role_id=2) punya attendance.approve_change', 1, $cnt);

// 4) Manager (role_id=3) harus punya request_change & approve_change
$stmt = $db->prepare("SELECT COUNT(DISTINCT p.slug) FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.role_id = 3 AND p.slug IN ('attendance.request_change','attendance.approve_change')");
$res = $stmt->execute();
$cnt = (int) $res->fetchArray(SQLITE3_NUM)[0];
check('Manager (role_id=3) punya request_change & approve_change', 2, $cnt);

// 5) Employee biasa (role_id=4) harus punya request_change tapi TIDAK approve_change
$stmt = $db->prepare("SELECT COUNT(*) FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.role_id = 4 AND p.slug = 'attendance.request_change'");
$cnt = (int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
check('Employee (role_id=4) punya request_change', 1, $cnt);
$stmt = $db->prepare("SELECT COUNT(*) FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.role_id = 4 AND p.slug = 'attendance.approve_change'");
$cnt = (int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
check('Employee (role_id=4) TIDAK punya approve_change', 0, $cnt);

// 6) Cari karyawan di ≥2 cabang berbeda untuk simulasi cross-branch
$res = $db->query("SELECT id, full_name, branch_id, company_id FROM employees
    WHERE branch_id IS NOT NULL AND is_active = 1
    ORDER BY branch_id ASC LIMIT 50");
$emps = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $emps[] = $r;
$byBranch = [];
foreach ($emps as $e) $byBranch[(int) $e['branch_id']][] = $e;
$branches = array_keys($byBranch);

if (count($branches) < 2) {
    echo "  [skip] Butuh ≥2 cabang dengan karyawan untuk simulasi cross-branch.\n";
} else {
    $emp1 = $byBranch[$branches[0]][0];
    $branchOther = $branches[1];

    // Insert dummy change request
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $stmt = $db->prepare("INSERT INTO attendance_change_requests
        (company_id, branch_id, employee_id, work_date, request_number, change_type,
         requested_clock_in, requested_clock_out, reason, status, created_at, updated_at)
        VALUES (:c, :b, :e, :d, :n, 'BOTH', :ci, :co, :r, 'PENDING', :now, :now)");
    $stmt->bindValue(':c', $emp1['company_id']);
    $stmt->bindValue(':b', $emp1['branch_id']);
    $stmt->bindValue(':e', $emp1['id']);
    $stmt->bindValue(':d', $tomorrow);
    $stmt->bindValue(':n', 'TEST-' . uniqid());
    $stmt->bindValue(':ci', "{$tomorrow} 09:00:00");
    $stmt->bindValue(':co', "{$tomorrow} 17:00:00");
    $stmt->bindValue(':r', 'Smoke test branch isolation');
    $stmt->bindValue(':now', date('Y-m-d H:i:s'));
    $stmt->execute();
    $crId = (int) $db->lastInsertRowID();
    echo "  [info] dummy CR id={$crId} for emp #{$emp1['id']} branch {$emp1['branch_id']}\n";

    // HR di cabang yang sama menemukan request
    $stmt = $db->prepare("SELECT COUNT(*) FROM attendance_change_requests cr
        JOIN employees e ON e.id = cr.employee_id
        WHERE cr.company_id = :c AND e.branch_id = :b AND cr.id = :id");
    $stmt->bindValue(':c', $emp1['company_id']);
    $stmt->bindValue(':b', $emp1['branch_id']);
    $stmt->bindValue(':id', $crId);
    $cnt = (int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
    check('HR di cabang yg sama TEMUKAN request', 1, $cnt);

    // HR di cabang lain TIDAK menemukan
    $stmt->bindValue(':c', $emp1['company_id']);
    $stmt->bindValue(':b', $branchOther);
    $stmt->bindValue(':id', $crId);
    $cnt = (int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
    check('HR di cabang LAIN TIDAK temukan request', 0, $cnt);

    // Cleanup
    $db->exec("DELETE FROM attendance_change_requests WHERE id = {$crId}");
    echo "  [info] cleaned up CR id={$crId}\n";
}

echo str_repeat('-', 60) . "\n";
echo " Pass: {$pass}   Fail: {$fail}\n";
echo str_repeat('-', 60) . "\n";

exit($fail === 0 ? 0 : 1);