<?php
/**
 * Smoke test untuk Organization Structures master jabatan kunci.
 *
 * Skenario:
 *  1. Schema check (table + 2 permissions)
 *  2. Default role kunci ter-seed (HRD_MANAGER, CEO, dst)
 *  3. Service: resolveApproverUser dengan vacant role → null
 *  4. Assign employee ke HRD_MANAGER → resolveApproverUser return user_id
 *  5. listKeyRoles + filter has_scope=mpp.budget
 *  6. Submit MPP → expected_approver_user_id ter-isi dari org_structures
 *  7. Cleanup
 *
 * Jalankan dari root backend:
 *   php scripts/test-org-structure-flow.php
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

echo "=== Organization Structure Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
check('Tabel organization_structures ada', $db->tableExists('organization_structures'));
$perms = $db->table('permissions')->whereIn('slug', ['org_structure.view','org_structure.manage'])->countAllResults();
check('2 permission org_structure terdaftar', $perms === 2);
$cols = $db->getFieldNames('manpower_request_approvals');
check('expected_approver_user_id kolom ada di approvals', in_array('expected_approver_user_id', $cols, true));

// 2. Setup
echo "\n2. Setup\n";
$company = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$companyId = (int) $company['id'];

// Cari employee yang punya user_id valid — gunakan user_id dari row employee
// supaya assertion resolveApproverUser konsisten dengan data sebenarnya.
$emp = $db->table('employees')->where('company_id', $companyId)
    ->where('user_id IS NOT NULL')->where('deleted_at IS NULL')->limit(1)->get()->getRowArray();
if (! $emp) {
    echo "  FAIL: tidak ada employee dengan user_id valid di company\n";
    exit(1);
}
$empId = (int) $emp['id'];
$userId = (int) $emp['user_id'];
echo "  Pakai company #{$companyId}, user #{$userId} (linked to employee), employee #{$empId} ({$emp['full_name']})\n";

// 3. Default role kunci
echo "\n3. Default key roles ter-seed\n";
$roleCount = $db->table('organization_structures')
    ->where('company_id', $companyId)->countAllResults();
check('Setidaknya 10 role default ter-seed', $roleCount >= 10);
$hrdRole = $db->table('organization_structures')
    ->where('company_id', $companyId)->where('role_key', 'HRD_MANAGER')->get()->getRowArray();
check('HRD_MANAGER ter-seed', $hrdRole !== null);
check('HRD_MANAGER level=MANAGER', $hrdRole && $hrdRole['level'] === 'MANAGER');
$scopes = $hrdRole && $hrdRole['approval_scopes'] ? json_decode($hrdRole['approval_scopes'], true) : [];
check('HRD_MANAGER punya scope mpp.budget', in_array('mpp.budget', $scopes, true));

// 4. Service lookup
echo "\n4. Service: resolveApproverUser\n";
$svc = \Config\Services::orgStructure(false);
$resolvedNull = $svc->resolveApproverUser($companyId, 'HRD_MANAGER');
check('Sebelum assign, HRD_MANAGER vacant → resolve null', $resolvedNull === null);

// 5. Assign employee ke HRD_MANAGER
echo "\n5. Assign employee ke HRD_MANAGER\n";
$db->table('organization_structures')
    ->where('company_id', $companyId)->where('role_key', 'HRD_MANAGER')
    ->update(['employee_id' => $empId, 'updated_at' => date('Y-m-d H:i:s')]);
$resolvedUid = $svc->resolveApproverUser($companyId, 'HRD_MANAGER');
check('Setelah assign, resolve user_id employee', $resolvedUid === $userId);
$resolvedEmp = $svc->resolveApproverEmployee($companyId, 'HRD_MANAGER');
check('resolveApproverEmployee return employee_id', $resolvedEmp === $empId);

// 6. listKeyRoles + filter
echo "\n6. listKeyRoles\n";
$all = $svc->listKeyRoles($companyId);
check('listKeyRoles return >= 10 row', count($all) >= 10);
$mppApprovers = $svc->listKeyRoles($companyId, ['has_scope' => 'mpp.budget']);
check('Filter has_scope=mpp.budget tidak kosong', count($mppApprovers) >= 1);
$found = array_filter($mppApprovers, fn($r) => $r['role_key'] === 'HRD_MANAGER');
check('HRD_MANAGER muncul di filter mpp.budget', count($found) === 1);

// 7. Submit MPP → expected_approver_user_id ter-isi
echo "\n7. Submit MPP — verifikasi expected_approver di-resolve\n";
$mppSvc = new \App\Libraries\Manpower\ManpowerService();
$now = date('Y-m-d H:i:s');
$ref = $mppSvc->nextReferenceNumber($companyId);
$db->table('manpower_requests')->insert([
    'company_id'           => $companyId,
    'reference_number'     => $ref,
    'requested_by_user_id' => $userId,
    'request_type'         => 'BUDGET',
    'reason'               => 'REPLACEMENT',
    'proposed_title'       => 'OrgStruct Test - ' . date('His'),
    'headcount'            => 1,
    'employment_type'      => 'PKWTT',
    'status'               => 'DRAFT',
    'created_by'           => $userId,
    'created_at'           => $now,
    'updated_at'           => $now,
]);
$mppId = (int) $db->insertID();

$mppSvc->submit($mppId, $userId);
$step1 = $db->table('manpower_request_approvals')
    ->where('manpower_request_id', $mppId)->where('step', 1)
    ->get()->getRowArray();
check('approver_role step 1 = HRD_MANAGER', $step1['approver_role'] === 'HRD_MANAGER');
check('expected_approver_user_id resolved', (int) ($step1['expected_approver_user_id'] ?? 0) === $userId);
check('expected_approver_employee_id resolved', (int) ($step1['expected_approver_employee_id'] ?? 0) === $empId);

// 8. Cleanup
echo "\n8. Cleanup\n";
$db->table('manpower_request_approvals')->where('manpower_request_id', $mppId)->delete();
$db->table('manpower_requests')->where('id', $mppId)->delete();
// Reset HRD_MANAGER ke vacant supaya test idempoten
$db->table('organization_structures')
    ->where('company_id', $companyId)->where('role_key', 'HRD_MANAGER')
    ->update(['employee_id' => null, 'updated_at' => date('Y-m-d H:i:s')]);
echo "  Cleaned up\n";

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);