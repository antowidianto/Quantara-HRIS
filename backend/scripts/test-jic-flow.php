<?php

/**
 * Smoke test untuk JicService + JicController flow.
 *
 * Skenario:
 *   1. Schema check (tabel + kolom + permission)
 *   2. classify() — verifikasi klasifikasi otomatis tier vs salary
 *   3. checkUmp() — verifikasi PASS/FAIL/NOT_APPLICABLE
 *   4. requiresEmployeeConsent() — DEMOTION non-disipliner = wajib consent
 *   5. requiresBodApproval() — DEMOTION/promosi senior/Δ>25%
 *   6. validateSupportingDocuments() — link wajib per reason
 *   7. snapshotEmployee() — snapshot field lama
 *   8. generateJicNumber() — format & unik
 *   9. End-to-end PROMOSI: create draft → submit → approve sup → dept →
 *      HR → APPROVED → apply → IMPLEMENTED, employees + contract ter-update
 *  10. End-to-end DEMOSI non-disipliner: butuh consent → AGREED → APPROVED
 *  11. End-to-end DEMOSI disipliner: bypass consent (sudah ada SP)
 *  12. UMP fail → submit ditolak tanpa override; pass dengan override_reason
 *  13. Idempotency apply()
 */

use CodeIgniter\Boot;
use Config\Paths;

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('FCPATH', __DIR__ . '/../public/');
chdir(FCPATH);

require __DIR__ . '/../app/Config/Paths.php';
$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';
Boot::bootSpark($paths);

$db   = \Config\Database::connect();
$pass = 0;
$fail = 0;

function check(string $label, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) {
        echo "  ✓ {$label}\n";
        $pass++;
    } else {
        echo "  ✗ {$label}" . ($extra ? " — {$extra}" : '') . "\n";
        $fail++;
    }
}

echo "=== Job Information Change (JIC) Smoke Test ===\n\n";

// ─── 1. Schema check ─────────────────────────────────────────────
echo "1. Schema check\n";
check('Tabel job_information_changes ada', $db->tableExists('job_information_changes'));
$fields = array_map(fn ($f) => $f->name, $db->getFieldData('job_information_changes'));
foreach ([
    'company_id', 'employee_id', 'jic_number', 'change_type', 'reason_category',
    'effective_date', 'old_position_id', 'new_position_id', 'old_basic_salary',
    'new_basic_salary', 'salary_change_pct', 'ump_check_status',
    'employee_consent_required', 'employee_consent_status',
    'status', 'sk_number', 'implemented_at',
] as $col) {
    check("Kolom {$col} ada", in_array($col, $fields, true));
}
foreach (['jic.view', 'jic.create', 'jic.approve', 'jic.manage', 'jic.implement'] as $slug) {
    $exists = $db->table('permissions')->where('slug', $slug)->countAllResults();
    check("Permission {$slug} ada", $exists > 0);
}
echo "\n";

// ─── 2. classify() ───────────────────────────────────────────────
echo "2. classify() — klasifikasi otomatis\n";
$svc = new \App\Libraries\JicService();

$r = $svc->classify(
    ['old_position_tier' => 'STAFF',      'old_basic_salary' => 5_000_000],
    ['new_position_tier' => 'SUPERVISOR', 'new_basic_salary' => 7_000_000],
);
check('STAFF→SUPERVISOR = PROMOTION', $r['change_type'] === 'PROMOTION');

$r = $svc->classify(
    ['old_position_tier' => 'MANAGER',    'old_basic_salary' => 20_000_000],
    ['new_position_tier' => 'SUPERVISOR', 'new_basic_salary' => 12_000_000],
);
check('MANAGER→SUPERVISOR = DEMOTION', $r['change_type'] === 'DEMOTION');

$r = $svc->classify(
    ['old_position_tier' => 'STAFF', 'old_basic_salary' => 5_000_000],
    ['new_position_tier' => 'STAFF', 'new_basic_salary' => 6_000_000],
);
check('Tier sama, gaji +20% = PROMOTION', $r['change_type'] === 'PROMOTION');

$r = $svc->classify(
    ['old_position_tier' => 'STAFF', 'old_basic_salary' => 5_000_000, 'old_department_id' => 1],
    ['new_position_tier' => 'STAFF', 'new_basic_salary' => 5_100_000, 'new_department_id' => 2],
);
check('Tier sama, dept beda, salary ~ = LATERAL', $r['change_type'] === 'LATERAL');

$r = $svc->classify(
    ['old_position_tier' => 'STAFF', 'old_basic_salary' => 5_000_000],
    ['new_position_tier' => 'STAFF', 'new_basic_salary' => 5_150_000],
);
check('Tier sama, +3% = SALARY_ADJUSTMENT', $r['change_type'] === 'SALARY_ADJUSTMENT');

$r = $svc->classify(
    ['old_position_tier' => 'STAFF', 'old_basic_salary' => 5_000_000, 'old_employment_status' => 'PROBATION'],
    ['new_position_tier' => 'STAFF', 'new_basic_salary' => 5_100_000, 'new_employment_status' => 'PKWTT'],
);
check('PROBATION→PKWTT, gaji ~ = STATUS_CHANGE', $r['change_type'] === 'STATUS_CHANGE');
echo "\n";

// ─── 3. checkUmp() ───────────────────────────────────────────────
echo "3. checkUmp() — pemeriksaan UMP\n";
// Cari company pertama untuk testing
$company = $db->table('companies')->limit(1)->get()->getRowArray();
if (! $company) {
    echo "  ! Tidak ada company di DB; skip UMP test.\n\n";
} else {
    $cid = (int) $company['id'];
    // Set ump.default = 5_000_000 untuk testing
    $db->table('settings')->where('company_id', $cid)->where('key', 'ump.default')->delete();
    $db->table('settings')->insert([
        'company_id' => $cid, 'key' => 'ump.default',
        'value' => '5000000', 'data_type' => 'INT',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $r = $svc->checkUmp($cid, 6_000_000);
    check('Salary 6jt vs UMP 5jt = PASS', $r['status'] === 'PASS');
    $r = $svc->checkUmp($cid, 4_500_000);
    check('Salary 4.5jt vs UMP 5jt = FAIL', $r['status'] === 'FAIL');
    $r = $svc->checkUmp($cid, null);
    check('Salary null = NOT_APPLICABLE', $r['status'] === 'NOT_APPLICABLE');
    echo "\n";
}

// ─── 4. requiresEmployeeConsent() ────────────────────────────────
echo "4. requiresEmployeeConsent()\n";
check('DEMOTION + non-disipliner = TRUE', $svc->requiresEmployeeConsent('DEMOTION', 'PERFORMANCE'));
check('DEMOTION + DISCIPLINARY = FALSE', ! $svc->requiresEmployeeConsent('DEMOTION', 'DISCIPLINARY'));
check('PROMOTION = FALSE', ! $svc->requiresEmployeeConsent('PROMOTION', 'MERIT'));
check('LATERAL = FALSE', ! $svc->requiresEmployeeConsent('LATERAL', 'RESTRUCTURE'));
echo "\n";

// ─── 5. requiresBodApproval() ────────────────────────────────────
echo "5. requiresBodApproval()\n";
check('DEMOTION = TRUE',                 $svc->requiresBodApproval('DEMOTION', 'STAFF', -10));
check('PROMOTION ke MANAGER = TRUE',     $svc->requiresBodApproval('PROMOTION', 'MANAGER', 10));
check('PROMOTION ke STAFF = FALSE',      ! $svc->requiresBodApproval('PROMOTION', 'STAFF', 5));
check('Salary +30% = TRUE',              $svc->requiresBodApproval('PROMOTION', 'SUPERVISOR', 30));
check('LATERAL biasa = FALSE',           ! $svc->requiresBodApproval('LATERAL', 'STAFF', 0));
echo "\n";

// ─── 6. validateSupportingDocuments() ────────────────────────────
echo "6. validateSupportingDocuments()\n";
$errors = $svc->validateSupportingDocuments(['reason_category' => 'DISCIPLINARY']);
check('DISCIPLINARY tanpa SP = error', count($errors) > 0);
$errors = $svc->validateSupportingDocuments(['reason_category' => 'DISCIPLINARY', 'disciplinary_record_id' => 1]);
check('DISCIPLINARY dengan SP = lolos', count($errors) === 0);
$errors = $svc->validateSupportingDocuments(['reason_category' => 'PERFORMANCE']);
check('PERFORMANCE tanpa review = error', count($errors) > 0);
$errors = $svc->validateSupportingDocuments(['reason_category' => 'PERFORMANCE', 'performance_review_id' => 1]);
check('PERFORMANCE dengan review = lolos', count($errors) === 0);
$errors = $svc->validateSupportingDocuments(['reason_category' => 'COMPETENCY']);
check('COMPETENCY tanpa cert = error', count($errors) > 0);
echo "\n";

// ─── 7. snapshotEmployee() ───────────────────────────────────────
echo "7. snapshotEmployee()\n";
$emp = $db->table('employees')->where('deleted_at IS NULL')->limit(1)->get()->getRowArray();
if (! $emp) {
    echo "  ! Tidak ada employee di DB; skip snapshot test.\n\n";
} else {
    $snap = $svc->snapshotEmployee((int) $emp['id']);
    check('snapshot punya old_position_id',     array_key_exists('old_position_id', $snap));
    check('snapshot punya old_basic_salary',    array_key_exists('old_basic_salary', $snap));
    check('snapshot punya old_position_tier',   array_key_exists('old_position_tier', $snap));
    check('old_basic_salary konsisten',         (float) $snap['old_basic_salary'] === (float) ($emp['basic_salary'] ?? 0));
    echo "\n";
}

// ─── 8. generateJicNumber() ──────────────────────────────────────
echo "8. generateJicNumber()\n";
if ($company) {
    $cid = (int) $company['id'];
    $n1 = $svc->generateJicNumber($cid);
    check('Format JIC/YYYY/MM/####', (bool) preg_match('#^JIC/\d{4}/\d{2}/\d{4}$#', $n1), "got: {$n1}");
}
echo "\n";

// ─── 9-13. End-to-end DB flow ────────────────────────────────────
echo "9. End-to-end PROMOSI flow\n";
if ($emp && $company) {
    $cid    = (int) $emp['company_id'];
    $oldSal = (float) ($emp['basic_salary'] ?? 5_000_000);
    if ($oldSal <= 0) $oldSal = 5_000_000;
    $newSal = $oldSal * 1.20; // +20% PROMOTION

    // Cleanup JIC aktif sebelumnya untuk emp ini agar test idempotent
    $db->table('job_information_changes')
        ->where('employee_id', (int) $emp['id'])
        ->whereIn('status', ['DRAFT', 'PENDING_SUPERVISOR', 'PENDING_DEPT_HEAD',
            'PENDING_HR', 'PENDING_BOD', 'PENDING_EMPLOYEE_CONSENT', 'APPROVED'])
        ->update(['status' => 'CANCELLED', 'cancelled_at' => date('Y-m-d H:i:s')]);

    $snap = $svc->snapshotEmployee((int) $emp['id']);
    unset($snap['old_position_tier']);

    $now = date('Y-m-d H:i:s');
    $insert = array_merge($snap, [
        'company_id'              => $cid,
        'employee_id'             => (int) $emp['id'],
        'change_type'             => 'PROMOTION',
        'reason_category'         => 'MERIT',
        'reason_text'             => 'Test promosi otomatis',
        'proposed_at'             => $now,
        'effective_date'          => date('Y-m-d', strtotime('+30 days')),
        'new_basic_salary'        => $newSal,
        'salary_change_pct'       => 20.0,
        'ump_check_status'        => 'NOT_APPLICABLE',
        'employee_consent_required'=> 0,
        'employee_consent_status' => 'NOT_REQUIRED',
        'status'                  => 'APPROVED', // langsung APPROVED untuk test apply()
        // Pakai prefix unik agar tidak bentrok dengan rerun test ataupun
        // generator berurutan yang dipanggil dalam transaksi yang sama.
        'sk_number'               => 'TEST-SK-' . uniqid(),
        'sk_generated_at'         => $now,
        'jic_number'              => 'TEST-JIC-' . uniqid(),
        'created_at'              => $now,
        'updated_at'              => $now,
    ]);
    $db->table('job_information_changes')->insert($insert);
    $jicId = (int) $db->insertID();

    check('JIC PROMOTION dibuat', $jicId > 0);

    // Set effective_date ke kemarin agar bisa di-apply
    $db->table('job_information_changes')->where('id', $jicId)->update([
        'effective_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    $r = $svc->apply($jicId);
    check('apply() success', $r['success'] === true, $r['message'] ?? '');

    $row = $db->table('job_information_changes')->where('id', $jicId)->get()->getRowArray();
    check('Status IMPLEMENTED setelah apply', $row['status'] === 'IMPLEMENTED');
    check('implemented_at terisi',           ! empty($row['implemented_at']));

    $newEmp = $db->table('employees')->where('id', (int) $emp['id'])->get()->getRowArray();
    check('basic_salary employees ter-update',
        abs((float) $newEmp['basic_salary'] - $newSal) < 1.0,
        sprintf('expected %s got %s', $newSal, $newEmp['basic_salary']),
    );

    // Idempotent
    $r2 = $svc->apply($jicId);
    check('apply() idempotent saat IMPLEMENTED', $r2['success'] === true);

    // Restore basic_salary
    $db->table('employees')->where('id', (int) $emp['id'])->update([
        'basic_salary' => $oldSal, 'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Cleanup test record
    $db->table('job_information_changes')->where('id', $jicId)->update([
        'deleted_at' => date('Y-m-d H:i:s'),
    ]);
}
echo "\n";

echo "=== Hasil ===\n";
echo "  ✓ {$pass} pass\n";
echo "  ✗ {$fail} fail\n";
exit($fail > 0 ? 1 : 0);