<?php

/**
 * Smoke test untuk Training & Sertifikasi.
 *
 * Skenario:
 *  1. Schema check: 4 tabel + 6 permissions
 *  2. Generators TRN-YEAR-XXXXX & CERT-YEAR-XXXXX
 *  3. Create training + register karyawan
 *  4. Approve registration → status APPROVED + budget reserved bertambah
 *  5. Complete dengan score → COMPLETED + auto-issue cert + budget used bertambah
 *  6. Cert dibuat dengan expires_date = +N bulan
 *  7. Reject + cancel scenarios
 *  8. expireOverdueCertificates → set EXPIRED
 *  9. findExpiringCertificates dalam X hari
 * 10. Budget allocation + tracking
 * 11. Stats dashboard
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

$db = \Config\Database::connect();
$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $pass++; }
    else       { echo "  [FAIL] {$label}\n"; $fail++; }
}

echo "=== Training & Certification Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
check('Tabel trainings ada', $db->tableExists('trainings'));
check('Tabel training_registrations ada', $db->tableExists('training_registrations'));
check('Tabel certificates ada', $db->tableExists('certificates'));
check('Tabel training_budgets ada', $db->tableExists('training_budgets'));

$perms = ['training.view','training.manage','training.register','certificate.view','certificate.manage','training_budget.manage'];
$cnt = $db->table('permissions')->whereIn('slug', $perms)->countAllResults();
check("6 permission training/certificate terdaftar (got {$cnt})", $cnt === 6);

// 2. Setup
echo "\n2. Setup\n";
$company = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$emp1 = $db->table('employees')->where('company_id', $company['id'])->where('is_active', 1)
    ->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$emp2 = $db->table('employees')->where('company_id', $company['id'])->where('is_active', 1)
    ->where('id !=', (int) $emp1['id'])->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$user = $db->table('users')->where('company_id', $company['id'])->where('is_active', 1)
    ->orderBy('id','ASC')->limit(1)->get()->getRowArray();
if (! $emp1 || ! $emp2 || ! $user) { echo "  ⚠ Butuh 2+ employee. Skip.\n"; exit($fail > 0 ? 1 : 0); }

// Cleanup test data sebelumnya
$db->table('certificates')->where('certificate_number LIKE', 'TEST_CERT_%')->delete();
$db->table('training_registrations')->where('notes LIKE', 'TEST_TRN%')->delete();
$db->table('trainings')->where('title LIKE', 'TEST_TRN%')->delete();
$db->table('training_budgets')->where('notes', 'TEST_BUDGET')->delete();

$svc = new \App\Libraries\TrainingService();

// 3. Generators
echo "\n3. Generators\n";
$trnCode = $svc->nextTrainingCode((int) $company['id']);
check("Training code format TRN-YEAR-XXXXX (got {$trnCode})", preg_match('/^TRN-\d{4}-\d{5}$/', $trnCode) === 1);
$certNum = $svc->nextCertNumber((int) $company['id']);
check("Cert number format CERT-YEAR-XXXXX (got {$certNum})", preg_match('/^CERT-\d{4}-\d{5}$/', $certNum) === 1);

// 4. Create training
echo "\n4. Create training\n";
$now = date('Y-m-d H:i:s');
$db->table('trainings')->insert([
    'company_id' => $company['id'],
    'code' => $trnCode,
    'title' => 'TEST_TRN K3 Awareness Annual',
    'category' => 'COMPLIANCE',
    'training_type' => 'INTERNAL',
    'start_date' => date('Y-m-d', strtotime('+7 days')),
    'end_date' => date('Y-m-d', strtotime('+9 days')),
    'duration_hours' => 16,
    'max_participants' => 5,
    'cost_per_participant' => 500000,
    'issues_certificate' => 1,
    'certificate_validity_months' => 12,
    'passing_score' => 70,
    'status' => 'PUBLISHED',
    'created_at' => $now, 'updated_at' => $now,
]);
$trnId = (int) $db->insertID();
check('Training dibuat dgn issues_certificate=1', $trnId > 0);

// 5. Register karyawan
echo "\n5. Register karyawan\n";
$db->table('training_registrations')->insert([
    'company_id' => $company['id'],
    'training_id' => $trnId,
    'employee_id' => $emp1['id'],
    'status' => 'REGISTERED',
    'registered_at' => $now,
    'cost' => 500000,
    'notes' => 'TEST_TRN registration emp1',
    'created_at' => $now, 'updated_at' => $now,
]);
$regId1 = (int) $db->insertID();
check('Registration emp1 dibuat', $regId1 > 0);

// 6. Approve registration
echo "\n6. Approve registration\n";
try {
    $r = $svc->approveRegistration($regId1, (int) $user['id']);
    check('approveRegistration OK', $r['status'] === 'APPROVED');
    check('Cost = 500000', (int) $r['cost'] === 500000);
} catch (\Throwable $e) {
    check('approveRegistration ERROR: ' . $e->getMessage(), false);
}

$reg1 = $db->table('training_registrations')->where('id', $regId1)->get()->getRowArray();
check('DB status APPROVED', $reg1['status'] === 'APPROVED');
check('approved_at terisi', ! empty($reg1['approved_at']));

// Budget reserved bertambah?
$budget = $db->table('training_budgets')->where('company_id', $company['id'])
    ->where('year', (int) date('Y'))->where('department_id IS NULL')
    ->get()->getRowArray();
check('Budget row ter-create dgn reserved >= 500000', $budget && (float) $budget['reserved_amount'] >= 500000);

// 7. Test capacity cap
echo "\n7. Capacity cap test\n";
// Ambil semua employee aktif (selain emp1 yg sudah approved) untuk fill capacity
$otherEmps = $db->table('employees')->where('company_id', $company['id'])->where('is_active', 1)
    ->where('id !=', (int) $emp1['id'])
    ->orderBy('id','ASC')->limit(20)->get()->getResultArray();

// Reduce max_participants jadi 2 supaya gampang test
$db->table('trainings')->where('id', $trnId)->update(['max_participants' => 2]);

$regOverflowId = null;
foreach ($otherEmps as $idx => $tmpEmp) {
    $db->table('training_registrations')->insert([
        'company_id' => $company['id'],
        'training_id' => $trnId,
        'employee_id' => $tmpEmp['id'],
        'status' => $idx < 1 ? 'APPROVED' : 'REGISTERED',
        'registered_at' => $now,
        'cost' => 500000,
        'notes' => 'TEST_TRN reg emp' . $idx,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    if ($idx === 1) {
        $regOverflowId = (int) $db->insertID();
        break;
    }
}

if ($regOverflowId) {
    try {
        $svc->approveRegistration($regOverflowId, (int) $user['id']);
        check('Capacity overflow → harusnya throw', false);
    } catch (\Throwable $e) {
        check('Capacity overflow → throw OK', str_contains($e->getMessage(), 'penuh'));
    }
} else {
    echo "  (skip — tidak cukup employee untuk test capacity)\n";
}

// 8. Complete + auto-issue certificate
echo "\n8. Complete + auto-issue cert\n";
try {
    $r = $svc->completeRegistration($regId1, [
        'final_score' => 85,
        'attendance_percent' => 100,
    ]);
    check('completeRegistration → CERTIFIED (issues_certificate=1, lulus)', $r['status'] === 'CERTIFIED');
    check('certificate_id ter-isi', ! empty($r['certificate_id']));

    $cert = $db->table('certificates')->where('id', (int) $r['certificate_id'])->get()->getRowArray();
    check('Cert dibuat dgn category COMPLIANCE', $cert['category'] === 'COMPLIANCE');
    check('Cert score = 85', (float) $cert['score'] === 85.0);
    check('Cert expires_date = +12 bulan', $cert['expires_date'] === date('Y-m-d', strtotime('+12 months')));

    // Mark cert untuk cleanup
    $db->table('certificates')->where('id', (int) $r['certificate_id'])->update([
        'certificate_number' => 'TEST_CERT_' . $r['certificate_id'],
    ]);
} catch (\Throwable $e) {
    check('completeRegistration ERROR: ' . $e->getMessage(), false);
}

// 9. Budget update setelah complete
echo "\n9. Budget update\n";
$budget = $db->table('training_budgets')->where('id', $budget['id'])->get()->getRowArray();
check('Budget used >= 500000 (dari completed)', (float) $budget['used_amount'] >= 500000);

// 10. Test gagal score (final_score < passing → tidak issue cert)
echo "\n10. Gagal score → no certificate\n";
$db->table('training_registrations')->where('id', $regOverflowId)->update([
    'status' => 'APPROVED', // reset jadi approved supaya bisa di-complete
    'updated_at' => $now,
]);
try {
    $r = $svc->completeRegistration($regOverflowId, ['final_score' => 50]);
    check('Tidak lulus → status COMPLETED (bukan CERTIFIED)', $r['status'] === 'COMPLETED');
    check('Tidak ada cert dibuat', empty($r['certificate_id']));
} catch (\Throwable $e) {
    check('Test gagal-score ERROR: ' . $e->getMessage(), false);
}

// 11. expireOverdueCertificates
echo "\n11. expireOverdueCertificates\n";
// Manual insert cert dgn expires_date kemarin
$db->table('certificates')->insert([
    'company_id' => $company['id'],
    'employee_id' => $emp1['id'],
    'certificate_number' => 'TEST_CERT_overdue',
    'title' => 'TEST overdue',
    'category' => 'TRAINING',
    'issued_date' => date('Y-m-d', strtotime('-2 years')),
    'expires_date' => date('Y-m-d', strtotime('-1 day')),
    'status' => 'ACTIVE',
    'created_at' => $now, 'updated_at' => $now,
]);
$overdueCertId = (int) $db->insertID();

$expiredCount = $svc->expireOverdueCertificates((int) $company['id']);
check("expireOverdueCertificates return >= 1 (got {$expiredCount})", $expiredCount >= 1);
$overdueCert = $db->table('certificates')->where('id', $overdueCertId)->get()->getRowArray();
check('Status berubah ke EXPIRED', $overdueCert['status'] === 'EXPIRED');

// 12. findExpiringCertificates
echo "\n12. findExpiringCertificates\n";
// Insert cert yg expires +20 hari
$db->table('certificates')->insert([
    'company_id' => $company['id'],
    'employee_id' => $emp1['id'],
    'certificate_number' => 'TEST_CERT_soon',
    'title' => 'TEST expiring soon',
    'category' => 'TRAINING',
    'issued_date' => date('Y-m-d', strtotime('-11 months')),
    'expires_date' => date('Y-m-d', strtotime('+20 days')),
    'status' => 'ACTIVE',
    'created_at' => $now, 'updated_at' => $now,
]);

$expiring30 = $svc->findExpiringCertificates(30, (int) $company['id']);
$found = false;
foreach ($expiring30 as $c) {
    if ($c['certificate_number'] === 'TEST_CERT_soon') $found = true;
}
check('Cert +20 hari muncul di findExpiringCertificates(30)', $found);

$expiring7 = $svc->findExpiringCertificates(7, (int) $company['id']);
$foundIn7 = false;
foreach ($expiring7 as $c) {
    if ($c['certificate_number'] === 'TEST_CERT_soon') $foundIn7 = true;
}
check('Cert +20 hari TIDAK muncul di findExpiringCertificates(7)', ! $foundIn7);

// 13. Stats
echo "\n13. Stats\n";
$stats = $svc->stats((int) $company['id'], (int) date('Y'));
check('Stats: total_trainings >=1', $stats['total_trainings'] >= 1);
check('Stats: completed_registrations >=1', $stats['completed_registrations'] >= 1);
check('Stats: budget structure ada', isset($stats['budget']['allocated'], $stats['budget']['used']));

// 14. Class structure
echo "\n14. Class structure\n";
check('Class TrainingService loaded', class_exists('\\App\\Libraries\\TrainingService'));
check('Class TrainingController loaded', class_exists('\\App\\Controllers\\Api\\V1\\TrainingController'));
check('Service::training() registered', method_exists(\Config\Services::class, 'training'));

// Cleanup
$db->table('certificates')->where('certificate_number LIKE', 'TEST_CERT_%')->delete();
$db->table('training_registrations')->where('notes LIKE', 'TEST_TRN%')->delete();
$db->table('trainings')->where('id', $trnId)->delete();
echo "  ✓ Cleanup test data\n";

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);