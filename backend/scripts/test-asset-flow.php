<?php

/**
 * Smoke test untuk Asset / Inventory module.
 *
 * Skenario:
 *  1. Schema check (tables + permissions)
 *  2. AssetService::nextAssetNumber — unique, format AST-YEAR-XXXXX
 *  3. Create asset → status AVAILABLE
 *  4. assignToEmployee → asset status ASSIGNED, assignment created, BAST snapshot
 *  5. Re-assign asset yang sudah ASSIGNED → exception
 *  6. returnAsset (kondisi GOOD) → asset back to AVAILABLE, BAST return ada
 *  7. returnAsset assignment yang sudah closed → exception
 *  8. Assign lagi & return DAMAGED → asset status REPAIR
 *  9. Cleanup
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

echo "=== Asset / Inventory Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
check('Tabel assets ada', $db->tableExists('assets'));
check('Tabel asset_assignments ada', $db->tableExists('asset_assignments'));
$perms = $db->table('permissions')->whereIn('slug', ['asset.view','asset.manage'])->countAllResults();
check('2 permission asset terdaftar', $perms === 2);

$svc = \Config\Services::asset(false);

// 2. Setup
echo "\n2. Setup test\n";
$company = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$emp = $db->table('employees')->where('company_id', $company['id'])->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
if (! $emp) {
    echo "  ⚠ Tidak ada employee aktif. Skip.\n";
    exit($fail > 0 ? 1 : 0);
}
echo "  Test pakai employee #{$emp['id']} ({$emp['full_name']})\n";

// Cleanup leftover
$db->table('asset_assignments')->where('purpose', 'SMOKE_TEST_PURPOSE')->delete();
$db->table('assets')->like('name', '[SMOKE TEST]', 'after')->delete();

// 3. nextAssetNumber
echo "\n3. nextAssetNumber\n";
$num1 = $svc->nextAssetNumber((int) $company['id']);
check('Format AST-YEAR-XXXXX', preg_match('/^AST-\d{4}-\d{5}$/', $num1) === 1);
$num2 = $svc->nextAssetNumber((int) $company['id']);
check('Generator deterministik (di tabel kosong)', $num1 === $num2);

// 4. Create asset
echo "\n4. Create asset\n";
$now = date('Y-m-d H:i:s');
$insert = [
    'company_id' => (int) $company['id'],
    'asset_number' => $svc->nextAssetNumber((int) $company['id']),
    'category' => 'LAPTOP',
    'name' => '[SMOKE TEST] Lenovo ThinkPad',
    'brand' => 'Lenovo', 'model' => 'T14',
    'serial_number' => 'SN-TEST-001',
    'condition' => 'NEW',
    'status' => 'AVAILABLE',
    'created_at' => $now, 'updated_at' => $now,
];
$db->table('assets')->insert($insert);
$assetId = (int) $db->insertID();
check('Asset created (id ' . $assetId . ')', $assetId > 0);
$asset = $db->table('assets')->where('id', $assetId)->get()->getRowArray();
check('Status awal AVAILABLE', $asset['status'] === 'AVAILABLE');

// 5. Assign
echo "\n5. assignToEmployee\n";
$assignmentId = $svc->assignToEmployee($assetId, (int) $emp['id'], [
    'condition_when_assigned' => 'NEW',
    'purpose' => 'SMOKE_TEST_PURPOSE',
    'expected_return' => date('Y-m-d', strtotime('+90 days')),
]);
check('Assignment created', $assignmentId > 0);
$assetAfter = $db->table('assets')->where('id', $assetId)->get()->getRowArray();
check('Asset status = ASSIGNED', $assetAfter['status'] === 'ASSIGNED');
check('current_assignment_id ter-set', (int) $assetAfter['current_assignment_id'] === $assignmentId);
check('current_employee_id ter-set', (int) $assetAfter['current_employee_id'] === (int) $emp['id']);
$assignment = $db->table('asset_assignments')->where('id', $assignmentId)->get()->getRowArray();
check('BAST HTML snapshot tersimpan', ! empty($assignment['bast_html']));
check('BAST mengandung "BERITA ACARA SERAH TERIMA"', str_contains((string) $assignment['bast_html'], 'BERITA ACARA SERAH TERIMA'));
check('BAST mengandung asset_number', str_contains((string) $assignment['bast_html'], (string) $asset['asset_number']));

// 6. Re-assign yang sudah ASSIGNED → exception
echo "\n6. Re-assign asset yang sudah ASSIGNED\n";
try {
    $svc->assignToEmployee($assetId, (int) $emp['id']);
    check('Re-assign harusnya throw exception', false);
} catch (\RuntimeException $e) {
    check('Re-assign → exception (OK)', true);
}

// 7. Return GOOD
echo "\n7. returnAsset (kondisi GOOD)\n";
$svc->returnAsset($assignmentId, [
    'condition_when_returned' => 'GOOD',
]);
$assetAfterReturn = $db->table('assets')->where('id', $assetId)->get()->getRowArray();
check('Asset status = AVAILABLE', $assetAfterReturn['status'] === 'AVAILABLE');
check('current_assignment_id null', empty($assetAfterReturn['current_assignment_id']));
check('current_employee_id null',   empty($assetAfterReturn['current_employee_id']));
$assignment = $db->table('asset_assignments')->where('id', $assignmentId)->get()->getRowArray();
check('returned_at ter-set', ! empty($assignment['returned_at']));
check('BAST return HTML snapshot tersimpan', ! empty($assignment['bast_return_html']));
check('BAST return mengandung "BERITA ACARA PENGEMBALIAN"', str_contains((string) $assignment['bast_return_html'], 'BERITA ACARA PENGEMBALIAN'));

// 8. Return assignment yang sudah closed → exception
echo "\n8. Return assignment yang sudah closed\n";
try {
    $svc->returnAsset($assignmentId);
    check('Return assignment closed → exception', false);
} catch (\RuntimeException $e) {
    check('Return closed → exception (OK)', true);
}

// 9. Assign lagi & return DAMAGED
echo "\n9. Cycle 2: assign + return DAMAGED\n";
$assignmentId2 = $svc->assignToEmployee($assetId, (int) $emp['id'], [
    'purpose' => 'SMOKE_TEST_PURPOSE',
    'condition_when_assigned' => 'GOOD',
]);
check('Assignment 2 created', $assignmentId2 > 0);
$svc->returnAsset($assignmentId2, [
    'condition_when_returned' => 'DAMAGED',
    'damage_note' => 'Layar cracked, perlu service center',
]);
$assetAfter2 = $db->table('assets')->where('id', $assetId)->get()->getRowArray();
check('Asset status = REPAIR (kondisi DAMAGED)', $assetAfter2['status'] === 'REPAIR');

// 10. listForEmployee
echo "\n10. listForEmployee\n";
// Assign lagi supaya ada aktif
$db->table('assets')->where('id', $assetId)->update(['status' => 'AVAILABLE', 'condition' => 'GOOD']);
$assignmentId3 = $svc->assignToEmployee($assetId, (int) $emp['id'], [
    'purpose' => 'SMOKE_TEST_PURPOSE',
]);
$active = $svc->listForEmployee((int) $emp['id'], false);
check('listForEmployee active count >= 1', count($active) >= 1);
$all = $svc->listForEmployee((int) $emp['id'], true);
check('listForEmployee include returned > active count', count($all) > count($active));

// Cleanup
$db->table('asset_assignments')->where('asset_id', $assetId)->delete();
$db->table('assets')->where('id', $assetId)->delete();
echo "  ✓ Cleanup test data\n";

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);