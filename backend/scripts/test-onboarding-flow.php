<?php

/**
 * Smoke test untuk Onboarding/Offboarding tasks.
 *
 * Skenario:
 *  1. Schema check (tables + permissions + seeded templates)
 *  2. resolveAssignee untuk berbagai owner_role
 *  3. applyTemplate ONBOARD → tasks dibuat sesuai template, due_date dihitung
 *  4. Idempotent: panggil kedua kali → 0 baru, semua skipped
 *  5. progress() — total, completed, mandatory, percent
 *  6. applyTemplate OFFBOARD → tasks offboard dibuat
 *  7. findOverdue() detection
 *  8. Cleanup
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

echo "=== Onboarding/Offboarding Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
check('Tabel task_templates ada', $db->tableExists('task_templates'));
check('Tabel employee_tasks ada', $db->tableExists('employee_tasks'));
$perms = $db->table('permissions')->whereIn('slug', ['onboarding.view','onboarding.manage','onboarding.complete_self'])->countAllResults();
check('3 permission onboarding terdaftar', $perms === 3);
$tplCount = $db->table('task_templates')->countAllResults();
check('Template Onboard/Offboard sudah di-seed', $tplCount > 0);

$svc = \Config\Services::onboarding(false);

// 2. Setup test
echo "\n2. Setup test\n";
$company = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$emp = $db->table('employees')->where('company_id', $company['id'])->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
if (! $emp) {
    echo "  ⚠ Tidak ada employee aktif. Skip integration test.\n";
    echo "Schema check passed ({$pass} ok, {$fail} fail).\n";
    exit($fail > 0 ? 1 : 0);
}
echo "  Test pakai employee #{$emp['id']} ({$emp['full_name']})\n";

// Cleanup leftover task lama untuk test ini — hapus semua task employee
// agar progress mulai dari kondisi bersih
$db->table('employee_tasks')->where('employee_id', $emp['id'])->delete();

// 3. resolveAssignee
echo "\n3. resolveAssignee\n";
$hrId = $svc->resolveAssignee('HR', $emp);
check('HR resolved ke user role 2', $hrId !== null && $hrId > 0);

$empUid = $svc->resolveAssignee('EMPLOYEE', $emp);
$expectedUid = $emp['user_id'] ? (int) $emp['user_id'] : null;
check('EMPLOYEE resolved ke user_id karyawan', $empUid === $expectedUid);

$unknown = $svc->resolveAssignee('UNKNOWN_ROLE', $emp);
check('Unknown role → null', $unknown === null);

// 4. applyTemplate ONBOARD
echo "\n4. applyTemplate ONBOARD (anchor = today)\n";
$today = date('Y-m-d');
$result = $svc->applyTemplate((int) $emp['id'], null, 'ONBOARD', $today);
check('Tasks dibuat (>0)', ($result['created'] ?? 0) > 0);
check('Skipped = 0 (first run)', ($result['skipped'] ?? -1) === 0);
echo "    Created: {$result['created']}, skipped: {$result['skipped']}\n";

// Verifikasi due_date dihitung
$row = $db->table('employee_tasks')->where('employee_id', $emp['id'])
    ->where('task_key', 'WELCOME_MEET')->orderBy('id','DESC')->limit(1)->get()->getRowArray();
check('Task WELCOME_MEET ada', (bool) $row);
check('due_date = today (offset 0)', $row && $row['due_date'] === $today);

$probation = $db->table('employee_tasks')->where('employee_id', $emp['id'])
    ->where('task_key', 'PROBATION_REVIEW')->orderBy('id','DESC')->limit(1)->get()->getRowArray();
$expectedDue = date('Y-m-d', strtotime($today . ' +90 days'));
check('PROBATION_REVIEW due = today+90', $probation && $probation['due_date'] === $expectedDue);

// 5. Idempotent
echo "\n5. Idempotent (re-apply)\n";
$result2 = $svc->applyTemplate((int) $emp['id'], null, 'ONBOARD', $today);
check('Re-apply: created = 0', ($result2['created'] ?? -1) === 0);
check('Re-apply: skipped > 0',  ($result2['skipped'] ?? 0) > 0);

// 6. progress
echo "\n6. progress\n";
$prog = $svc->progress((int) $emp['id'], 'ONBOARD');
check('progress.total > 0', $prog['total'] > 0);
check('progress.completed = 0 (semua pending)', $prog['completed'] === 0);
check('progress.mandatory > 0', $prog['mandatory'] > 0);
check('progress.percent = 0', $prog['percent'] === 0);

// Complete satu task → re-check
if ($row) {
    $db->table('employee_tasks')->where('id', $row['id'])->update([
        'status' => 'COMPLETED',
        'completed_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $prog2 = $svc->progress((int) $emp['id'], 'ONBOARD');
    check('Setelah 1 task completed: completed = 1', $prog2['completed'] === 1);
    check('Setelah 1 task completed: percent > 0', $prog2['percent'] > 0);
}

// 7. applyTemplate OFFBOARD
echo "\n7. applyTemplate OFFBOARD\n";
$resign = date('Y-m-d', strtotime('+30 days'));
$resultOff = $svc->applyTemplate((int) $emp['id'], null, 'OFFBOARD', $resign);
check('Offboard tasks dibuat', ($resultOff['created'] ?? 0) > 0);
$exitTask = $db->table('employee_tasks')->where('employee_id', $emp['id'])
    ->where('type', 'OFFBOARD')->where('task_key', 'EXIT_INTERVIEW')->limit(1)->get()->getRowArray();
check('EXIT_INTERVIEW task ada', (bool) $exitTask);
check('EXIT_INTERVIEW due = resign date (offset 0)', $exitTask && $exitTask['due_date'] === $resign);

// 8. findOverdue
echo "\n8. findOverdue\n";
// Create 1 task overdue manual
$overdueId = $db->table('employee_tasks')->insert([
    'company_id' => (int) $emp['company_id'],
    'employee_id' => (int) $emp['id'],
    'type' => 'ONBOARD',
    'task_name' => 'TEST_OVERDUE',
    'task_key' => 'TEST_OVERDUE',
    'due_date' => date('Y-m-d', strtotime('-7 days')),
    'status' => 'PENDING',
    'mandatory' => 1,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]) ? (int) $db->insertID() : 0;
$overdueRows = $svc->findOverdue((int) $emp['company_id']);
$found = false;
foreach ($overdueRows as $r) {
    if ((int) $r['id'] === $overdueId) { $found = true; break; }
}
check('findOverdue mengembalikan task overdue', $found);

// Verifikasi progress menghitung overdue
$progFinal = $svc->progress((int) $emp['id']);
check('progress.overdue >= 1', $progFinal['overdue'] >= 1);

// Cleanup test data
$db->table('employee_tasks')
    ->where('employee_id', $emp['id'])
    ->groupStart()
        ->where('task_name', 'TEST_OVERDUE')
        ->orLike('task_key', 'WELCOME_MEET')
        ->orLike('task_key', 'CONTRACT_SIGN')
        ->orLike('task_key', 'EMPLOYEE_HANDBOOK')
        ->orLike('task_key', 'SETUP_LAPTOP')
        ->orLike('task_key', 'SETUP_HRIS')
        ->orLike('task_key', 'BPJS_REGISTER')
        ->orLike('task_key', 'NPWP_VERIFY')
        ->orLike('task_key', 'BANK_ACCOUNT')
        ->orLike('task_key', 'SUBMIT_DOCS')
        ->orLike('task_key', 'INDUCTION')
        ->orLike('task_key', 'TRAINING_ROLE')
        ->orLike('task_key', 'PROBATION_REVIEW')
        ->orLike('task_key', 'EXIT_INTERVIEW')
        ->orLike('task_key', 'RETURN_LAPTOP')
        ->orLike('task_key', 'RETURN_ASSETS')
        ->orLike('task_key', 'REVOKE_EMAIL')
        ->orLike('task_key', 'REVOKE_HRIS')
        ->orLike('task_key', 'PESANGON_CALC')
        ->orLike('task_key', 'CLEARANCE_FIN')
        ->orLike('task_key', 'BPJS_RESIGN')
        ->orLike('task_key', 'PAKLARING')
        ->orLike('task_key', 'TAX_CLEARANCE')
        ->orLike('task_key', 'KNOWLEDGE_TFR')
        ->orLike('task_key', 'EMPLOYEE_DEACT')
    ->groupEnd()
    ->delete();
echo "  ✓ Cleanup test data\n";

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);