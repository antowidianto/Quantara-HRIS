<?php

/**
 * Smoke test untuk Business Trip / SPPD module.
 *
 * Skenario:
 *  1. Schema check (tables + permissions)
 *  2. nextReferenceNumber — format SPPD/<CODE>/<YEAR>/<5digit>
 *  3. computeDurationDays
 *  4. Create draft trip
 *  5. Submit (DRAFT → PENDING) — tanpa approval chain (catch try/catch)
 *  6. Approve (PENDING → APPROVED) → SPPD HTML rendered
 *  7. Add expenses (3 line items) → recompute actual_amount
 *  8. Mark completed (APPROVED → COMPLETED)
 *  9. Settle case 1: actual > advance → buat reimbursement
 * 10. Cycle 2: settle case 2: actual < advance → tidak buat reimbursement
 * 11. Cancel & cleanup
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

echo "=== Business Trip / SPPD Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
check('Tabel business_trips ada', $db->tableExists('business_trips'));
check('Tabel business_trip_expenses ada', $db->tableExists('business_trip_expenses'));
$perms = $db->table('permissions')->whereIn('slug', [
    'business_trip.view','business_trip.manage','business_trip.approve','business_trip.settle'
])->countAllResults();
check('4 permission business_trip terdaftar', $perms === 4);

$svc = \Config\Services::businessTrip(false);

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
$leftoverIds = $db->table('business_trips')->where('employee_id', $emp['id'])
    ->like('purpose', 'SMOKE_TEST', 'after')->get()->getResultArray();
foreach ($leftoverIds as $r) {
    $db->table('business_trip_expenses')->where('business_trip_id', $r['id'])->delete();
    $db->table('business_trips')->where('id', $r['id'])->delete();
}
$db->table('reimbursements')->like('description', 'Selisih SPPD', 'after')->delete();

// 3. nextReferenceNumber
echo "\n3. nextReferenceNumber\n";
$ref = $svc->nextReferenceNumber((int) $company['id']);
check('Format SPPD/<CODE>/<YEAR>/<5digit>', preg_match('/^SPPD\/[A-Z0-9]+\/\d{4}\/\d{5}$/', $ref) === 1);

// 4. computeDurationDays
echo "\n4. computeDurationDays\n";
check('1 hari (start=end)',  $svc->computeDurationDays('2026-06-01', '2026-06-01') === 1);
check('3 hari',               $svc->computeDurationDays('2026-06-01', '2026-06-03') === 3);
check('7 hari',               $svc->computeDurationDays('2026-06-01', '2026-06-07') === 7);
check('end < start → 1 hari', $svc->computeDurationDays('2026-06-05', '2026-06-01') === 1);

// 5. Create draft trip
echo "\n5. Create draft trip\n";
$now = date('Y-m-d H:i:s');
$insert = [
    'company_id'       => (int) $emp['company_id'],
    'employee_id'      => (int) $emp['id'],
    'reference_number' => $svc->nextReferenceNumber((int) $emp['company_id']),
    'purpose'          => 'SMOKE_TEST kunjungan klien Surabaya',
    'destination'      => 'Surabaya',
    'destination_type' => 'DOMESTIC',
    'start_date'       => '2026-07-01',
    'end_date'         => '2026-07-03',
    'duration_days'    => 3,
    'transport_mode'   => 'PESAWAT',
    'accommodation'    => 'Hotel Bumi',
    'advance_amount'   => 5000000,
    'status'           => 'DRAFT',
    'created_at'       => $now, 'updated_at' => $now,
];
$db->table('business_trips')->insert($insert);
$tripId = (int) $db->insertID();
check('Trip created (id ' . $tripId . ')', $tripId > 0);
check('Status awal DRAFT', $db->table('business_trips')->where('id', $tripId)->get()->getRowArray()['status'] === 'DRAFT');

// 6. Submit
echo "\n6. submit() DRAFT → PENDING\n";
$r = $svc->submit($tripId);
check('Status berubah ke PENDING', $r['status'] === 'PENDING');
check('submitted_at ter-set',
    ! empty($db->table('business_trips')->where('id', $tripId)->get()->getRowArray()['submitted_at']));

// Submit kedua kali → exception
try {
    $svc->submit($tripId);
    check('Resubmit non-DRAFT harusnya throw', false);
} catch (\RuntimeException $e) {
    check('Resubmit non-DRAFT → exception', true);
}

// 7. Approve
echo "\n7. approve() PENDING → APPROVED\n";
$svc->approve($tripId);
$row = $db->table('business_trips')->where('id', $tripId)->get()->getRowArray();
check('Status APPROVED', $row['status'] === 'APPROVED');
check('approved_at ter-set', ! empty($row['approved_at']));
check('SPPD HTML snapshot tersimpan', ! empty($row['sppd_html']));
check('SPPD mengandung "SURAT PERINTAH PERJALANAN DINAS"',
    str_contains((string) $row['sppd_html'], 'SURAT PERINTAH PERJALANAN DINAS'));
check('SPPD mengandung reference_number',
    str_contains((string) $row['sppd_html'], (string) $row['reference_number']));

// 8. Add expenses
echo "\n8. Add expenses (line items)\n";
$expensesData = [
    ['category' => 'TRANSPORT',     'description' => 'Tiket pesawat PP CGK-SUB',    'amount' => 2800000, 'is_billable' => 1],
    ['category' => 'ACCOMMODATION', 'description' => 'Hotel 2 malam',                'amount' => 1800000, 'is_billable' => 1],
    ['category' => 'MEAL',          'description' => 'Uang makan harian (3 hari)',   'amount' => 600000,  'is_billable' => 1],
    ['category' => 'OTHER',         'description' => 'Beli oleh-oleh pribadi',      'amount' => 200000,  'is_billable' => 0],
];
foreach ($expensesData as $e) {
    $db->table('business_trip_expenses')->insert(array_merge($e, [
        'company_id' => (int) $emp['company_id'],
        'business_trip_id' => $tripId,
        'expense_date' => '2026-07-02',
        'created_at' => $now, 'updated_at' => $now,
    ]));
}
$totalActual = $svc->recomputeActual($tripId);
check('Total actual = sum billable (5,200,000, exclude 200,000 non-billable)',
    abs($totalActual - 5200000) < 0.01);

// 9. Mark completed
echo "\n9. markCompleted() APPROVED → COMPLETED\n";
$svc->markCompleted($tripId);
$row = $db->table('business_trips')->where('id', $tripId)->get()->getRowArray();
check('Status COMPLETED', $row['status'] === 'COMPLETED');
check('completed_at ter-set', ! empty($row['completed_at']));
check('actual_amount = 5,200,000', abs((float) $row['actual_amount'] - 5200000) < 0.01);

// 10. Settle case 1: actual > advance (5,200,000 > 5,000,000)
echo "\n10. settle() case 1: actual > advance → reimbursement\n";
$result = $svc->settle($tripId);
$row = $db->table('business_trips')->where('id', $tripId)->get()->getRowArray();
check('Status SETTLED', $row['status'] === 'SETTLED');
check('settlement_amount = +200,000', abs((float) $row['settlement_amount'] - 200000) < 0.01);
check('reimbursement_id ter-set', ! empty($row['reimbursement_id']));
$reimb = $db->table('reimbursements')->where('id', $row['reimbursement_id'])->get()->getRowArray();
check('Reimbursement otomatis dibuat dgn amount = 200,000', $reimb && abs((float) $reimb['amount'] - 200000) < 0.01);
check('Reimbursement category = BUSINESS_TRIP', $reimb && $reimb['category'] === 'BUSINESS_TRIP');

// 11. Cycle 2: settle case 2: actual < advance
echo "\n11. settle() case 2: actual < advance → no reimbursement\n";
// Create trip kedua
$insert2 = array_merge($insert, [
    'reference_number' => $svc->nextReferenceNumber((int) $emp['company_id']),
    'purpose' => 'SMOKE_TEST overpay scenario',
    'advance_amount' => 5000000,
    'status' => 'APPROVED',
    'submitted_at' => $now, 'approved_at' => $now,
]);
$db->table('business_trips')->insert($insert2);
$tripId2 = (int) $db->insertID();
// Add expense kecil saja
$db->table('business_trip_expenses')->insert([
    'company_id' => (int) $emp['company_id'],
    'business_trip_id' => $tripId2,
    'category' => 'TRANSPORT',
    'description' => 'Bensin mobil kantor',
    'amount' => 3000000,
    'is_billable' => 1,
    'expense_date' => '2026-07-02',
    'created_at' => $now, 'updated_at' => $now,
]);
$svc->markCompleted($tripId2);
$result2 = $svc->settle($tripId2);
$row2 = $db->table('business_trips')->where('id', $tripId2)->get()->getRowArray();
check('settlement_amount negatif (kelebihan advance)', (float) $row2['settlement_amount'] < 0);
check('settlement_amount = -2,000,000', abs((float) $row2['settlement_amount'] - (-2000000)) < 0.01);
check('reimbursement_id null (no reimbursement)', empty($row2['reimbursement_id']));
check('overpay_to_return = 2,000,000', abs($result2['overpay_to_return'] - 2000000) < 0.01);

// 12. Cancel
echo "\n12. cancel()\n";
$insert3 = array_merge($insert, [
    'reference_number' => $svc->nextReferenceNumber((int) $emp['company_id']),
    'purpose' => 'SMOKE_TEST cancel',
    'status' => 'DRAFT',
]);
$db->table('business_trips')->insert($insert3);
$tripId3 = (int) $db->insertID();
$svc->cancel($tripId3, 'Test cancel');
$row3 = $db->table('business_trips')->where('id', $tripId3)->get()->getRowArray();
check('Cancel: status CANCELLED', $row3['status'] === 'CANCELLED');

// Cancel SETTLED → exception
try {
    $svc->cancel($tripId);
    check('Cancel SETTLED harusnya throw', false);
} catch (\RuntimeException $e) {
    check('Cancel SETTLED → exception', true);
}

// Cleanup
foreach ([$tripId, $tripId2, $tripId3] as $id) {
    $db->table('business_trip_expenses')->where('business_trip_id', $id)->delete();
    $db->table('business_trips')->where('id', $id)->delete();
}
$db->table('reimbursements')->like('description', 'Selisih SPPD', 'after')->delete();
echo "  ✓ Cleanup test data\n";

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);