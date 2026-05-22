<?php
/**
 * Smoke test alur Manpower Planning (MPP).
 *
 * Skenario:
 *  1. Schema check (3 tabel + 4 permissions)
 *  2. Buat MPP DRAFT (BUDGET, REPLACEMENT, qty 2)
 *  3. Submit → SUBMITTED + 1 step approval PENDING
 *  4. Approve step → APPROVED
 *  5. Buat job_posting dari MPP (split 1+1) → HIRING, sisa 0
 *  6. Try buat ke-3 → harus fail (headcount habis)
 *  7. Test UNBUDGET → 2-step approval
 *  8. Reject UNBUDGET di step 1 → REJECTED
 *  9. Cleanup
 *
 * Jalankan dari root backend:
 *   php scripts/test-mpp-flow.php
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
$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $pass++; }
    else       { echo "  [FAIL] {$label}\n"; $fail++; }
}

echo "=== Manpower Planning (MPP) Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
check('Tabel manpower_requests ada',           $db->tableExists('manpower_requests'));
check('Tabel manpower_request_approvals ada',  $db->tableExists('manpower_request_approvals'));
check('Tabel manpower_request_postings ada',   $db->tableExists('manpower_request_postings'));
$perms = $db->table('permissions')->whereIn('slug', [
    'mpp.view','mpp.submit','mpp.approve','mpp.fulfill'
])->countAllResults();
check('4 permission MPP terdaftar', $perms === 4);

$svc = \Config\Services::manpower(false);

// 2. Setup test
echo "\n2. Setup test\n";
$company = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
if (! $company) { echo "  FAIL: tidak ada company aktif\n"; exit(1); }
$companyId = (int) $company['id'];
echo "  Pakai company #{$companyId} ({$company['name']})\n";

$user = $db->table('users')->where('company_id', $companyId)->limit(1)->get()->getRowArray()
    ?: $db->table('users')->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$userId = (int) $user['id'];
echo "  Pakai user #{$userId} ({$user['name']})\n";

// 3. Generator reference number
echo "\n3. Generator reference number\n";
$ref = $svc->nextReferenceNumber($companyId);
check('Format MPP-YYYY-NNNNN', preg_match('/^MPP-\d{4}-\d{5}$/', $ref) === 1);
echo "  Generated: {$ref}\n";

// 4. Insert DRAFT BUDGET
echo "\n4. Create DRAFT (BUDGET, qty 2)\n";
$now = date('Y-m-d H:i:s');
$insert = [
    'company_id'           => $companyId,
    'reference_number'     => $ref,
    'requested_by_user_id' => $userId,
    'request_type'         => 'BUDGET',
    'reason'               => 'REPLACEMENT',
    'proposed_title'       => 'Smoke Test - ' . date('His'),
    'headcount'            => 2,
    'employment_type'      => 'PKWTT',
    'job_description'      => 'Smoke test',
    'requirements'         => 'Smoke req',
    'status'               => 'DRAFT',
    'created_by'           => $userId,
    'created_at'           => $now,
    'updated_at'           => $now,
];
$db->table('manpower_requests')->insert($insert);
$mppId = (int) $db->insertID();
check('MPP draft created', $mppId > 0);

// 5. Submit
echo "\n5. Submit DRAFT → SUBMITTED\n";
$svc->submit($mppId, $userId);
$mpp = $db->table('manpower_requests')->where('id', $mppId)->get()->getRowArray();
check('Status SUBMITTED', $mpp['status'] === 'SUBMITTED');
check('submitted_at terisi', ! empty($mpp['submitted_at']));
$approvals = $db->table('manpower_request_approvals')->where('manpower_request_id', $mppId)->get()->getResultArray();
check('BUDGET → 1 step approval', count($approvals) === 1);
check('Step pertama PENDING', $approvals[0]['decision'] === 'PENDING');

// 6. Approve
echo "\n6. Approve step → APPROVED\n";
$result = $svc->approveStep($mppId, $userId, 'OK approved');
check('final_approved=true', $result['final_approved'] === true);
check('remaining_steps=0', $result['remaining_steps'] === 0);
$mpp = $db->table('manpower_requests')->where('id', $mppId)->get()->getRowArray();
check('Status APPROVED', $mpp['status'] === 'APPROVED');

// 7. Create job_posting dari MPP
echo "\n7. Create job_postings dari MPP (split 1+1)\n";
$post1 = $svc->createJobPostingFromMpp($mppId, $userId, ['headcount' => 1]);
$mpp = $db->table('manpower_requests')->where('id', $mppId)->get()->getRowArray();
check('Posting 1 created', isset($post1['job_posting_id']) && $post1['job_posting_id'] > 0);
check('Status HIRING setelah posting', $mpp['status'] === 'HIRING');

$post2 = $svc->createJobPostingFromMpp($mppId, $userId, ['headcount' => 1]);
check('Posting 2 created', $post2['job_posting_id'] > 0);

$pivots = $db->table('manpower_request_postings')->where('manpower_request_id', $mppId)->countAllResults();
check('Pivot manpower_request_postings 2 row', $pivots === 2);

// 8. Try buat ke-3 (harus fail)
echo "\n8. Validasi headcount habis\n";
try {
    $svc->createJobPostingFromMpp($mppId, $userId, ['headcount' => 1]);
    check('Validasi headcount habis raise exception', false);
} catch (\RuntimeException $e) {
    check('Validasi headcount habis raise exception', true);
}

// 9. Close MPP
echo "\n9. Close MPP\n";
$svc->close($mppId, $userId, 'Smoke test cleanup');
$mpp = $db->table('manpower_requests')->where('id', $mppId)->get()->getRowArray();
check('Status CLOSED', $mpp['status'] === 'CLOSED');
check('closed_at terisi', ! empty($mpp['closed_at']));

// 10. UNBUDGET multi-step
echo "\n10. UNBUDGET workflow (2-step)\n";
$ref2 = $svc->nextReferenceNumber($companyId);
$db->table('manpower_requests')->insert([
    'company_id'           => $companyId,
    'reference_number'     => $ref2,
    'requested_by_user_id' => $userId,
    'request_type'         => 'UNBUDGET',
    'reason'               => 'EXPANSION',
    'proposed_title'       => 'Unbudget Smoke - ' . date('His'),
    'headcount'            => 1,
    'justification'        => 'Workload meningkat 2x',
    'status'               => 'DRAFT',
    'created_by'           => $userId,
    'created_at'           => $now,
    'updated_at'           => $now,
]);
$mpp2Id = (int) $db->insertID();
$svc->submit($mpp2Id, $userId);
$steps = $db->table('manpower_request_approvals')->where('manpower_request_id', $mpp2Id)->countAllResults();
check('UNBUDGET → 2 step approval', $steps === 2);

// Approve step 1
$r1 = $svc->approveStep($mpp2Id, $userId, 'HRD ok');
check('Step 1 final=false (masih ada step 2)', $r1['final_approved'] === false);
check('Step 1 remaining=1', $r1['remaining_steps'] === 1);
$mpp2 = $db->table('manpower_requests')->where('id', $mpp2Id)->get()->getRowArray();
check('Status masih SUBMITTED setelah step 1', $mpp2['status'] === 'SUBMITTED');

// Reject step 2
$svc->rejectStep($mpp2Id, $userId, 'Reject by Director di step 2');
$mpp2 = $db->table('manpower_requests')->where('id', $mpp2Id)->get()->getRowArray();
check('Status REJECTED setelah reject step 2', $mpp2['status'] === 'REJECTED');
check('rejection_reason terisi', ! empty($mpp2['rejection_reason']));

// 11. Cleanup
echo "\n11. Cleanup\n";
$db->table('manpower_request_postings')->where('manpower_request_id', $mppId)->delete();
$db->table('job_postings')->whereIn('id', [$post1['job_posting_id'], $post2['job_posting_id']])->delete();
$db->table('manpower_request_approvals')->whereIn('manpower_request_id', [$mppId, $mpp2Id])->delete();
$db->table('manpower_requests')->whereIn('id', [$mppId, $mpp2Id])->delete();
echo "  Cleaned up test data\n";

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);