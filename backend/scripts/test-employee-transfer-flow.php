<?php
/**
 * Smoke test untuk modul Employee Transfer (Mutasi).
 *
 * Coverage:
 *   1. Create draft → record tersimpan
 *   2. Submit → status SUBMITTED, transfer_number ter-generate
 *   3. Approve oleh user lain → status APPROVED
 *   4. Implement → records employees ter-update, status IMPLEMENTED
 *   5. Cross-tenant block (target branch dari company lain → 422)
 *   6. No-change validation (semua field tujuan sama dengan posisi sekarang)
 *   7. Approve before consent (requires_consent=1) → 422
 */

use CodeIgniter\Boot;
use Config\Paths;
use Config\Database;
use Config\Services;

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('FCPATH', __DIR__ . '/../public/');
chdir(FCPATH);

require __DIR__ . '/../app/Config/Paths.php';
$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';
Boot::bootSpark($paths);

$db = Database::connect();
$pass = 0; $fail = 0;
function check(string $label, bool $cond, $extra = null): void {
    global $pass, $fail;
    if ($cond) { echo "  [OK]   $label\n"; $pass++; }
    else       {
        echo "  [FAIL] $label\n";
        if ($extra !== null) echo "         " . (is_string($extra) ? $extra : json_encode($extra)) . "\n";
        $fail++;
    }
}

echo "=== Employee Transfer Flow Test ===\n\n";

// ─── Setup: cari karyawan + 2 branch dalam 1 company ──────────────
$companyId = (int) $db->table('companies')->where('is_active', 1)
    ->orderBy('id', 'ASC')->limit(1)->get()->getRowArray()['id'];
echo "Company: #$companyId\n";

$emp = $db->table('employees')
    ->where('company_id', $companyId)
    ->where('is_active', 1)
    ->where('deleted_at IS NULL')
    ->limit(1)->get()->getRowArray();
if (! $emp) { echo "Skip: tidak ada karyawan aktif di company #$companyId\n"; exit(0); }

$branches = $db->table('branches')->where('company_id', $companyId)
    ->where('is_active', 1)->where('deleted_at IS NULL')
    ->orderBy('id','ASC')->limit(3)->get()->getResultArray();
if (count($branches) < 2) {
    echo "Skip: butuh minimal 2 branch di company #$companyId\n"; exit(0);
}

// Branch tujuan = branch yang BEDA dari branch sekarang
$currentBranchId = (int) ($emp['branch_id'] ?? 0);
$targetBranch = null;
foreach ($branches as $b) {
    if ((int) $b['id'] !== $currentBranchId) { $targetBranch = $b; break; }
}
if (! $targetBranch) { echo "Skip: semua branch sama dengan branch karyawan\n"; exit(0); }

// Cross-tenant target
$otherCompany = $db->table('companies')->where('id !=', $companyId)
    ->where('is_active', 1)->limit(1)->get()->getRowArray();
$otherBranch = null;
if ($otherCompany) {
    $otherBranch = $db->table('branches')->where('company_id', (int) $otherCompany['id'])
        ->limit(1)->get()->getRowArray();
}

echo "Karyawan: {$emp['full_name']} (#{$emp['id']}, branch saat ini #{$currentBranchId})\n";
echo "Target branch: {$targetBranch['name']} (#{$targetBranch['id']})\n\n";

Services::tenant()->setCompanyId($companyId);
Services::tenant()->setUserId(1);
Services::tenant()->setRoleId(2); // HR admin

$svc = Services::employeeTransfer();

// ─── Test 1: Create draft ──────────────────────────────────────
echo "1. Create draft transfer\n";
try {
    $row = $svc->create($companyId, 1, [
        'employee_id'    => (int) $emp['id'],
        'transfer_type'  => 'BRANCH',
        'reason'         => 'OPERATIONAL',
        'reason_text'    => 'Test mutasi otomatis untuk smoke test',
        'new_branch_id'  => (int) $targetBranch['id'],
        'effective_date' => date('Y-m-d', strtotime('+7 days')),
    ]);
    check('row dibuat', isset($row['id']) && $row['id'] > 0);
    check('status DRAFT', ($row['status'] ?? '') === 'DRAFT');
    check('snapshot old_branch_id', (int) ($row['old_branch_id'] ?? 0) === $currentBranchId);
    check('new_branch_id sesuai', (int) ($row['new_branch_id'] ?? 0) === (int) $targetBranch['id']);
    $transferId = (int) $row['id'];
} catch (\Throwable $e) {
    check('create sukses', false, $e->getMessage());
    exit(1);
}

// ─── Test 2: Submit ───────────────────────────────────────────
echo "\n2. Submit draft → SUBMITTED\n";
try {
    $row = $svc->submit($transferId, 1);
    check('status SUBMITTED', $row['status'] === 'SUBMITTED');
    check('transfer_number ter-generate', ! empty($row['transfer_number']));
    check('format MUT-YYYYMM-NNNN', preg_match('/^MUT-\d{6}-\d{4}$/', $row['transfer_number']) === 1);
} catch (\Throwable $e) {
    check('submit sukses', false, $e->getMessage());
}

// ─── Test 3: Approve ──────────────────────────────────────────
echo "\n3. Approve\n";
try {
    $row = $svc->approve($transferId, 2, 'OK silakan');
    check('status APPROVED', $row['status'] === 'APPROVED');
    check('approver_user_id ter-set', (int) $row['approver_user_id'] === 2);
} catch (\Throwable $e) {
    check('approve sukses', false, $e->getMessage());
}

// ─── Test 4: Implement ────────────────────────────────────────
echo "\n4. Implement (apply ke records employees)\n";
try {
    $row = $svc->implement($transferId, 1, 'Auto-test');
    check('status IMPLEMENTED', $row['status'] === 'IMPLEMENTED');
    check('implemented_at ter-set', ! empty($row['implemented_at']));

    // Verify employees ter-update
    $updated = $db->table('employees')->where('id', $emp['id'])->get()->getRowArray();
    check('employee.branch_id berubah ke target',
        (int) $updated['branch_id'] === (int) $targetBranch['id'],
        'expected ' . $targetBranch['id'] . ', got ' . $updated['branch_id']);
} catch (\Throwable $e) {
    check('implement sukses', false, $e->getMessage());
}

// Restore: revert branch supaya idempoten
$db->table('employees')->where('id', $emp['id'])->update(['branch_id' => $currentBranchId]);

// ─── Test 5: Cross-tenant block ───────────────────────────────
if ($otherBranch) {
    echo "\n5. Cross-tenant block (target branch beda company)\n";
    try {
        $svc->create($companyId, 1, [
            'employee_id'    => (int) $emp['id'],
            'transfer_type'  => 'BRANCH',
            'reason'         => 'OPERATIONAL',
            'new_branch_id'  => (int) $otherBranch['id'],
            'effective_date' => date('Y-m-d', strtotime('+7 days')),
        ]);
        check('cross-tenant ditolak', false, 'Seharusnya error, ternyata sukses');
    } catch (\InvalidArgumentException $e) {
        check('cross-tenant ditolak', stripos($e->getMessage(), 'perusahaan yang sama') !== false,
            'msg: ' . $e->getMessage());
    }
} else {
    echo "\n5. Skip cross-tenant test (tidak ada company kedua)\n";
}

// ─── Test 6: No change validation ─────────────────────────────
echo "\n6. No-change (semua field tujuan = posisi sekarang)\n";
try {
    $svc->create($companyId, 1, [
        'employee_id'    => (int) $emp['id'],
        'transfer_type'  => 'BRANCH',
        'reason'         => 'OPERATIONAL',
        'new_branch_id'  => $currentBranchId, // sama persis
        'effective_date' => date('Y-m-d', strtotime('+7 days')),
    ]);
    check('no-change ditolak', false, 'Seharusnya error');
} catch (\InvalidArgumentException $e) {
    check('no-change ditolak', stripos($e->getMessage(), 'Tidak ada perubahan') !== false,
        'msg: ' . $e->getMessage());
}

// ─── Test 7: Approve before consent ───────────────────────────
echo "\n7. Approve gagal kalau requires_consent=1 belum AGREED\n";
try {
    $row = $svc->create($companyId, 1, [
        'employee_id'      => (int) $emp['id'],
        'transfer_type'    => 'BRANCH',
        'reason'           => 'OPERATIONAL',
        'new_branch_id'    => (int) $targetBranch['id'],
        'effective_date'   => date('Y-m-d', strtotime('+7 days')),
        'requires_consent' => 1,
        'submit'           => true,
    ]);
    $tid = (int) $row['id'];
    try {
        $svc->approve($tid, 2);
        check('approve ditolak tanpa consent', false, 'Seharusnya error');
    } catch (\InvalidArgumentException $e) {
        check('approve ditolak tanpa consent',
            stripos($e->getMessage(), 'consent') !== false,
            'msg: ' . $e->getMessage());
    }
    // Cleanup
    $svc->cancel($tid, 1, 'Test cleanup setelah verifikasi consent gate');
} catch (\Throwable $e) {
    check('test 7 setup sukses', false, $e->getMessage());
}

echo "\n=== Result: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);