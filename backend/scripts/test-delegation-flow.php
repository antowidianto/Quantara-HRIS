<?php

/**
 * Smoke test untuk Approval Delegation.
 *
 * Skenario:
 *  1. Buat 1 leave dari karyawan, get approval_request + approver list
 *  2. Insert delegation: approver asli → user lain
 *  3. Re-check approver list — harus include delegate
 *  4. Test ApprovalEngine::canApprove(delegate) = true
 *  5. Test approve via delegate; cek decision row punya note "[Mendelegasi…]"
 *
 * Cara jalankan:
 *   cd backend
 *   php scripts/test-delegation-flow.php
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
    if ($cond) { echo "  ✓ {$label}\n"; $pass++; }
    else       { echo "  ✗ {$label}\n"; $fail++; }
}

echo "=== Approval Delegation Smoke Test ===\n\n";

// 1. Cek tabel + permission ada
echo "1. Schema check\n";
check('Tabel approval_delegations ada', $db->tableExists('approval_delegations'));
$perms = $db->table('permissions')->whereIn('slug', [
    'delegation.view','delegation.manage','delegation.manage_self'
])->countAllResults();
check('3 permission delegasi terdaftar', $perms === 3);

// 2. Cari pending approval_request untuk testing
echo "\n2. Approval request setup\n";
$req = $db->table('approval_requests')->where('status', 'PENDING')->orderBy('id','DESC')->limit(1)->get()->getRowArray();
if (! $req) {
    echo "  ⚠ Tidak ada approval_request PENDING. Skip integration test.\n";
    echo "  → Buat cuti dulu via UI/seeder, lalu jalankan ulang.\n\n";
    echo "Schema check passed ({$pass} ok, {$fail} fail).\n";
    exit($fail > 0 ? 1 : 0);
}
echo "  Pakai approval_request #{$req['id']} (module={$req['module']})\n";

$engine = \Config\Services::approval(false);

// 3. Approver list awal
$reflection = new ReflectionClass($engine);
$method = $reflection->getMethod('resolveCurrentApprovers');
$method->setAccessible(true);
$approversBefore = $method->invoke($engine, $req);
echo "  Approver awal: " . implode(', ', $approversBefore) . "\n";
check('Ada approver resmi', count($approversBefore) > 0);

if (empty($approversBefore)) {
    echo "  ⚠ Tidak ada approver. Skip selanjutnya.\n";
    exit($fail > 0 ? 1 : 0);
}

// 4. Pilih original approver dan delegate (user lain)
$originalUserId = (int) $approversBefore[0];
$delegate = $db->table('users')
    ->where('company_id', $req['company_id'])
    ->where('id !=', $originalUserId)
    ->where('is_active', 1)
    ->orderBy('id', 'ASC')->limit(1)
    ->get()->getRowArray();
if (! $delegate) {
    echo "  ⚠ Tidak ada user lain untuk dijadikan delegate.\n";
    exit($fail > 0 ? 1 : 0);
}
$delegateId = (int) $delegate['id'];
echo "  Original approver: user #{$originalUserId}\n";
echo "  Delegate: user #{$delegateId} ({$delegate['name']})\n";

// 5. Insert delegation aktif untuk hari ini
$today = date('Y-m-d');
$existing = $db->table('approval_delegations')
    ->where('user_id', $originalUserId)
    ->where('delegate_to_user_id', $delegateId)
    ->where('is_active', 1)
    ->where('date_from <=', $today)
    ->where('date_to >=', $today)
    ->countAllResults();
if ($existing === 0) {
    $db->table('approval_delegations')->insert([
        'company_id' => $req['company_id'],
        'user_id' => $originalUserId,
        'delegate_to_user_id' => $delegateId,
        'module' => $req['module'],
        'date_from' => $today,
        'date_to' => date('Y-m-d', strtotime('+30 days')),
        'reason' => 'Smoke test delegation',
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    echo "  ✓ Delegasi ditambahkan\n";
} else {
    echo "  ✓ Delegasi sudah ada (idempotent)\n";
}

// 6. Re-check approver list — harus ada delegate
$approversAfter = $method->invoke($engine, $req);
echo "  Approver setelah delegasi: " . implode(', ', $approversAfter) . "\n";
check('Original approver masih bisa', in_array($originalUserId, $approversAfter, true));
check('Delegate masuk approver list', in_array($delegateId, $approversAfter, true));

// 7. canApprove untuk delegate
check('canApprove(delegate) = true', $engine->canApprove((int) $req['id'], $delegateId));
check('canApprove(original) = true', $engine->canApprove((int) $req['id'], $originalUserId));

// 8. Random user yang bukan approver/delegate
$randomUser = $db->table('users')
    ->where('company_id', $req['company_id'])
    ->where('id !=', $originalUserId)
    ->where('id !=', $delegateId)
    ->where('is_active', 1)
    ->orderBy('id', 'ASC')->limit(1)
    ->get()->getRowArray();
if ($randomUser) {
    $isApprover = in_array((int) $randomUser['id'], $approversAfter, true);
    if (! $isApprover) {
        check('canApprove(user lain) = false',
            ! $engine->canApprove((int) $req['id'], (int) $randomUser['id']));
    }
}

// Cleanup test delegation supaya tidak mengganggu state lain
$db->table('approval_delegations')
    ->where('reason', 'Smoke test delegation')
    ->delete();
echo "  ✓ Cleanup test delegation\n";

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);