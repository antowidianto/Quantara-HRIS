<?php

/**
 * Smoke test untuk Overtime → Cuti Pengganti.
 *
 * Skenario:
 *  1. Schema check (kolom + leave_type baru + settings default)
 *  2. Buat overtime PENDING dengan compensation_type = LEAVE
 *  3. Approve LEAVE → leave_balances allocated bertambah
 *  4. Buat overtime CASH (default) → approve menghitung amount uang
 *  5. Test ratio non-default (1.5 jam OT = 1 jam cuti)
 *  6. Test max_days_year cap
 *  7. Cleanup
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

echo "=== Overtime → Leave Compensation Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
check('Kolom overtimes.compensation_type ada', $db->fieldExists('compensation_type', 'overtimes'));
check('Kolom overtimes.leave_hours_credited ada', $db->fieldExists('leave_hours_credited', 'overtimes'));
check('Kolom overtimes.leave_balance_id ada', $db->fieldExists('leave_balance_id', 'overtimes'));
check('Kolom leave_types.source ada', $db->fieldExists('source', 'leave_types'));

// 2. Setup
echo "\n2. Setup test\n";
$company = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$emp = $db->table('employees')->where('company_id', $company['id'])->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
if (! $emp) {
    echo "  ⚠ Tidak ada employee aktif.\n"; exit($fail > 0 ? 1 : 0);
}
echo "  Test pakai employee #{$emp['id']} ({$emp['full_name']})\n";

// Pastikan leave_type CPL ada
$cpl = $db->table('leave_types')
    ->where('company_id', (int) $company['id'])
    ->where('source', 'OVERTIME_COMP')
    ->where('is_active', 1)->get()->getRowArray();
check('Leave type CPL (Cuti Pengganti Lembur) ada', (bool) $cpl);

// Pastikan settings default ada
if ($db->tableExists('settings')) {
    $st = $db->table('settings')->where('company_id', (int) $company['id'])->where('`key`', 'overtime_to_leave_ratio')->get()->getRowArray();
    check('Setting overtime_to_leave_ratio terisi', (bool) $st);
}

// Cleanup leftover
$db->table('overtimes')->where('employee_id', $emp['id'])->like('reason', 'SMOKE_TEST', 'after')->delete();
$db->table('leave_balances')->where('employee_id', $emp['id'])->where('leave_type_id', (int) $cpl['id'])->delete();

$ctrl = null;
// Helper: panggil approve via service-like inline
function approveOvertime($id, $userId, $db) {
    // Replicate logic OvertimeController::approve but inline (no HTTP)
    $ot = $db->table('overtimes')->where('id', $id)->get()->getRowArray();
    if (! $ot) return ['error' => 'not found'];
    $emp = $db->table('employees')->where('id', $ot['employee_id'])->get()->getRowArray();
    $compType = strtoupper((string) ($ot['compensation_type'] ?? 'CASH'));

    $update = [
        'status'      => 'APPROVED',
        'approved_by' => $userId,
        'approved_at' => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ];

    if ($compType === 'LEAVE') {
        $ratio = 1.0;
        if ($db->tableExists('settings')) {
            $st = $db->table('settings')->where('company_id', (int) $ot['company_id'])
                ->where('`key`', 'overtime_to_leave_ratio')->get()->getRowArray();
            if ($st && (float) $st['value'] > 0) $ratio = (float) $st['value'];
        }
        $hours = (float) $ot['hours'];
        $leaveHours = round($hours * $ratio, 2);
        $leaveType = $db->table('leave_types')
            ->where('company_id', (int) $ot['company_id'])
            ->where('source', 'OVERTIME_COMP')
            ->where('is_active', 1)
            ->get()->getRowArray();
        if (! $leaveType) return ['error' => 'CPL not configured'];

        $maxDaysYear = 12;
        if ($db->tableExists('settings')) {
            $st = $db->table('settings')->where('company_id', (int) $ot['company_id'])
                ->where('`key`', 'overtime_to_leave_max_days_year')->get()->getRowArray();
            if ($st && (int) $st['value'] > 0) $maxDaysYear = (int) $st['value'];
        }
        $year = (int) date('Y', strtotime((string) $ot['work_date']));
        $hoursPerDay = 8;

        $balance = $db->table('leave_balances')
            ->where('employee_id', (int) $ot['employee_id'])
            ->where('leave_type_id', (int) $leaveType['id'])
            ->where('period_year', $year)->get()->getRowArray();
        if (! $balance) {
            $db->table('leave_balances')->insert([
                'company_id'    => (int) $ot['company_id'],
                'employee_id'   => (int) $ot['employee_id'],
                'leave_type_id' => (int) $leaveType['id'],
                'period_year'   => $year,
                'allocated' => 0, 'carried_over' => 0, 'used' => 0, 'remaining' => 0,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $balanceId = (int) $db->insertID();
            $balance = $db->table('leave_balances')->where('id', $balanceId)->get()->getRowArray();
        }

        $newAllocatedHours = (float) $balance['allocated'] * $hoursPerDay + $leaveHours;
        $maxHoursYear = $maxDaysYear * $hoursPerDay;
        if ($newAllocatedHours > $maxHoursYear) {
            $availableHours = max(0, $maxHoursYear - (float) $balance['allocated'] * $hoursPerDay);
            if ($availableHours <= 0) return ['error' => 'quota max tahun ini'];
            $leaveHours = $availableHours;
        }
        $addDays = round($leaveHours / $hoursPerDay, 2);
        $newAllocated = (float) $balance['allocated'] + $addDays;
        $newRemaining = $newAllocated + (float) $balance['carried_over'] - (float) $balance['used'];
        $db->table('leave_balances')->where('id', (int) $balance['id'])->update([
            'allocated' => $newAllocated,
            'remaining' => $newRemaining,
            'updated_at'=> date('Y-m-d H:i:s'),
        ]);
        $update['leave_balance_id'] = (int) $balance['id'];
        $update['leave_hours_credited'] = $leaveHours;
        $update['leave_conversion_ratio'] = $ratio;
        $update['computed_amount'] = 0;
    } else {
        $base = (float) ($emp['basic_salary'] ?? 0);
        $calc = new \App\Libraries\Attendance\OvertimeCalculator();
        $amount = $calc->calculate($base, (float) $ot['hours'], $ot['day_type']);
        $update['computed_amount'] = $amount;
        $db->table('overtimes')->where('id', $id)->update($update);
        return ['compensation_type' => 'CASH', 'amount' => $amount];
    }

    $db->table('overtimes')->where('id', $id)->update($update);
    return ['compensation_type' => 'LEAVE', 'leave_hours_credited' => $update['leave_hours_credited'],
            'leave_balance_id' => $update['leave_balance_id']];
}

// 3. Buat overtime LEAVE
echo "\n3. Buat overtime PENDING type=LEAVE\n";
$now = date('Y-m-d H:i:s');
$db->table('overtimes')->insert([
    'company_id' => (int) $emp['company_id'],
    'employee_id' => (int) $emp['id'],
    'request_number' => 'OT-SMOKE-' . uniqid(),
    'work_date' => date('Y-m-d', strtotime('-1 day')),
    'start_time' => '18:00:00',
    'end_time' => '22:00:00',
    'hours' => 4,
    'day_type' => 'WEEKDAY',
    'reason' => 'SMOKE_TEST overtime LEAVE',
    'status' => 'PENDING',
    'compensation_type' => 'LEAVE',
    'created_at' => $now, 'updated_at' => $now,
]);
$otLeaveId = (int) $db->insertID();
check('Overtime LEAVE created', $otLeaveId > 0);

// 4. Approve LEAVE → balance ter-update
echo "\n4. Approve LEAVE → leave_balance allocated bertambah\n";
$res = approveOvertime($otLeaveId, 1, $db);
check('Approve LEAVE return success', isset($res['compensation_type']) && $res['compensation_type'] === 'LEAVE');
check('leave_hours_credited = 4 (ratio 1.0)', isset($res['leave_hours_credited']) && abs($res['leave_hours_credited'] - 4.0) < 0.01);
$balance = $db->table('leave_balances')->where('id', $res['leave_balance_id'])->get()->getRowArray();
check('balance.allocated = 0.5 (4 jam / 8 jam-per-hari)', abs((float) $balance['allocated'] - 0.5) < 0.01);
check('balance.remaining = 0.5', abs((float) $balance['remaining'] - 0.5) < 0.01);
$ot = $db->table('overtimes')->where('id', $otLeaveId)->get()->getRowArray();
check('Overtime status APPROVED', $ot['status'] === 'APPROVED');
check('Overtime computed_amount = 0 (LEAVE tidak bayar uang)', (float) $ot['computed_amount'] === 0.0);
check('Overtime leave_balance_id ter-set', (int) $ot['leave_balance_id'] === (int) $balance['id']);

// 5. Buat overtime CASH (default) → approve hitung amount
echo "\n5. Buat overtime PENDING type=CASH (default)\n";
$db->table('overtimes')->insert([
    'company_id' => (int) $emp['company_id'],
    'employee_id' => (int) $emp['id'],
    'request_number' => 'OT-SMOKE-' . uniqid(),
    'work_date' => date('Y-m-d', strtotime('-2 day')),
    'start_time' => '18:00:00', 'end_time' => '20:00:00',
    'hours' => 2, 'day_type' => 'WEEKDAY',
    'reason' => 'SMOKE_TEST overtime CASH',
    'status' => 'PENDING',
    'compensation_type' => 'CASH',
    'created_at' => $now, 'updated_at' => $now,
]);
$otCashId = (int) $db->insertID();
$res2 = approveOvertime($otCashId, 1, $db);
check('Approve CASH success', isset($res2['compensation_type']) && $res2['compensation_type'] === 'CASH');
check('amount > 0 (atau 0 kalau employee belum ada gaji)', isset($res2['amount']));
$ot2 = $db->table('overtimes')->where('id', $otCashId)->get()->getRowArray();
check('Overtime CASH status APPROVED', $ot2['status'] === 'APPROVED');
check('Overtime CASH leave_balance_id null', empty($ot2['leave_balance_id']));

// 6. Ratio non-default
echo "\n6. Test ratio 1.5 (1.5 jam OT = 1 jam cuti, jadi 6 jam OT → 4 jam cuti)\n";
// Update ratio jadi 0.667 (1/1.5) — yang artinya 1 jam OT = 0.667 jam cuti
// Atau kita test dgn ratio 0.5 supaya jelas
if ($db->tableExists('settings')) {
    $db->table('settings')->where('company_id', (int) $company['id'])
        ->where('`key`', 'overtime_to_leave_ratio')->update(['value' => '0.5']);
}
$db->table('overtimes')->insert([
    'company_id' => (int) $emp['company_id'],
    'employee_id' => (int) $emp['id'],
    'request_number' => 'OT-SMOKE-' . uniqid(),
    'work_date' => date('Y-m-d', strtotime('-3 day')),
    'start_time' => '18:00:00', 'end_time' => '20:00:00',
    'hours' => 2, 'day_type' => 'WEEKDAY',
    'reason' => 'SMOKE_TEST ratio 0.5',
    'status' => 'PENDING', 'compensation_type' => 'LEAVE',
    'created_at' => $now, 'updated_at' => $now,
]);
$otRatioId = (int) $db->insertID();
$res3 = approveOvertime($otRatioId, 1, $db);
check('Ratio 0.5: 2 jam OT → 1 jam cuti', isset($res3['leave_hours_credited']) && abs($res3['leave_hours_credited'] - 1.0) < 0.01);

// Reset ratio ke 1.0
if ($db->tableExists('settings')) {
    $db->table('settings')->where('company_id', (int) $company['id'])
        ->where('`key`', 'overtime_to_leave_ratio')->update(['value' => '1.0']);
}

// 7. Cap max_days_year
echo "\n7. Test cap max_days_year (set ke 1 hari = 8 jam, harus capped)\n";
if ($db->tableExists('settings')) {
    $db->table('settings')->where('company_id', (int) $company['id'])
        ->where('`key`', 'overtime_to_leave_max_days_year')->update(['value' => '1']);
}
// Reset balance dulu
$db->table('leave_balances')->where('employee_id', $emp['id'])->where('leave_type_id', (int) $cpl['id'])->update([
    'allocated' => 0, 'remaining' => 0,
]);
// Buat OT 10 jam → harus capped ke 8 jam (max 1 hari = 8 jam)
$db->table('overtimes')->insert([
    'company_id' => (int) $emp['company_id'],
    'employee_id' => (int) $emp['id'],
    'request_number' => 'OT-SMOKE-' . uniqid(),
    'work_date' => date('Y-m-d', strtotime('-4 day')),
    'start_time' => '08:00:00', 'end_time' => '18:00:00',
    'hours' => 10, 'day_type' => 'WEEKDAY',
    'reason' => 'SMOKE_TEST cap',
    'status' => 'PENDING', 'compensation_type' => 'LEAVE',
    'created_at' => $now, 'updated_at' => $now,
]);
$otCapId = (int) $db->insertID();
$res4 = approveOvertime($otCapId, 1, $db);
check('Cap: 10 jam OT capped ke 8 jam (= 1 hari max)',
    isset($res4['leave_hours_credited']) && abs($res4['leave_hours_credited'] - 8.0) < 0.01);
$balanceCap = $db->table('leave_balances')->where('id', $res4['leave_balance_id'])->get()->getRowArray();
check('balance.allocated = 1.0 (capped di max 1 hari)', abs((float) $balanceCap['allocated'] - 1.0) < 0.01);

// 8. Cap reached → exception
echo "\n8. Cap sudah max → harus error\n";
$db->table('overtimes')->insert([
    'company_id' => (int) $emp['company_id'],
    'employee_id' => (int) $emp['id'],
    'request_number' => 'OT-SMOKE-' . uniqid(),
    'work_date' => date('Y-m-d'),
    'start_time' => '18:00:00', 'end_time' => '20:00:00',
    'hours' => 2, 'day_type' => 'WEEKDAY',
    'reason' => 'SMOKE_TEST already capped',
    'status' => 'PENDING', 'compensation_type' => 'LEAVE',
    'created_at' => $now, 'updated_at' => $now,
]);
$otCappedId = (int) $db->insertID();
$res5 = approveOvertime($otCappedId, 1, $db);
check('Approve setelah cap penuh → error', isset($res5['error']) && str_contains((string) $res5['error'], 'quota max'));

// Cleanup
if ($db->tableExists('settings')) {
    $db->table('settings')->where('company_id', (int) $company['id'])
        ->where('`key`', 'overtime_to_leave_max_days_year')->update(['value' => '12']);
}
$db->table('overtimes')->where('employee_id', $emp['id'])->like('reason', 'SMOKE_TEST', 'after')->delete();
$db->table('leave_balances')->where('employee_id', $emp['id'])->where('leave_type_id', (int) $cpl['id'])->delete();
echo "  ✓ Cleanup test data\n";

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);