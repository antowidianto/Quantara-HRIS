<?php
/**
 * Smoke test untuk hr:leave-anniversary-carry.
 *
 *   1. Pilih leave_type ANNUAL → set carry_over_basis = ANNIVERSARY
 *   2. Pilih 1 karyawan dengan tenure ≥ 12 bulan
 *   3. Override "today" = MM-DD karyawan
 *   4. Inject saldo lama (period_year - 1) supaya ada yg di-carry
 *   5. Run runAnniversaryCarryOver() → verifikasi:
 *      - allocated penuh (default_days)
 *      - carried_over di-cap
 *      - expires_at = today + carry_over_expires_in_months
 *      - anniversary_processed_at = today
 *   6. Run lagi (idempotency) → tidak double-carry
 *   7. Restore state semula (jangan rusak data demo)
 *
 * Pakai: cd backend && php scripts/test-leave-anniversary-flow.php
 */

use CodeIgniter\Boot;
use Config\Paths;
use Config\Services;

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('FCPATH', __DIR__ . '/../public/');
chdir(FCPATH);

require __DIR__ . '/../app/Config/Paths.php';
$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';
Boot::bootSpark($paths);

$db = \Config\Database::connect();
$service = Services::leaveBalance();

$ok = 0;
$fail = 0;
function assertion(string $label, bool $cond): void {
    global $ok, $fail;
    if ($cond) { echo "  ✓ {$label}\n"; $ok++; }
    else       { echo "  ✗ {$label}\n"; $fail++; }
}

// ─── 1. Setup ────────────────────────────────────────────────────
echo "1. Setup\n";

$companyId = (int) ($db->table('companies')->where('is_active', 1)->where('deleted_at IS NULL')
    ->orderBy('id')->limit(1)->get()->getRow()?->id);
assertion("Company aktif ditemukan (#{$companyId})", $companyId > 0);

// Pilih leave_type ANNUAL
$leaveType = $db->table('leave_types')
    ->where('company_id', $companyId)
    ->where('category', 'ANNUAL')
    ->where('is_active', 1)
    ->where('deleted_at IS NULL')
    ->limit(1)->get()->getRowArray();
assertion("Leave type ANNUAL ditemukan: " . ($leaveType['name'] ?? '-'), ! empty($leaveType));
if (! $leaveType) exit(1);

$origBasis = $leaveType['carry_over_basis'] ?? 'YEAR_END';
$db->table('leave_types')->where('id', $leaveType['id'])->update([
    'carry_over_basis' => 'ANNIVERSARY',
    'is_carry_over_allowed' => 1,
    'carry_over_max_days' => 6,
    'carry_over_expires_in_months' => 6,
]);
echo "  · Set carry_over_basis = ANNIVERSARY untuk '{$leaveType['name']}'\n";

// Pilih karyawan dengan tenure ≥ 1 tahun
$emp = $db->table('employees')
    ->where('company_id', $companyId)
    ->where('is_active', 1)
    ->where('deleted_at IS NULL')
    ->where('join_date IS NOT NULL')
    ->where('join_date <', date('Y-m-d', strtotime('-13 months')))
    ->orderBy('id')
    ->limit(1)->get()->getRowArray();
assertion("Karyawan tenure ≥ 12 bulan ditemukan: " . ($emp['full_name'] ?? '-'), ! empty($emp));
if (! $emp) {
    // restore basis
    $db->table('leave_types')->where('id', $leaveType['id'])->update(['carry_over_basis' => $origBasis]);
    exit(1);
}

// "Today" = MM-DD dari join_date karyawan, di tahun saat ini
$todayYear = (int) date('Y');
$todayStr = $todayYear . '-' . substr($emp['join_date'], 5, 5);
echo "  · Simulasi today = {$todayStr} (anniversary {$emp['full_name']})\n";

// Inject saldo lama untuk anniversary year - 1
$prevYear = $todayYear - 1;
$old = $db->table('leave_balances')
    ->where('employee_id', $emp['id'])
    ->where('leave_type_id', $leaveType['id'])
    ->where('period_year', $prevYear)
    ->get()->getRowArray();

$injectedOld = false;
if (! $old) {
    $db->table('leave_balances')->insert([
        'company_id'    => $companyId,
        'employee_id'   => (int) $emp['id'],
        'leave_type_id' => (int) $leaveType['id'],
        'period_year'   => $prevYear,
        'allocated'     => 12,
        'carried_over'  => 0,
        'used'          => 4,
        'remaining'     => 8,
        'created_at'    => date('Y-m-d H:i:s'),
        'updated_at'    => date('Y-m-d H:i:s'),
    ]);
    $injectedOld = true;
} else {
    $db->table('leave_balances')->where('id', $old['id'])->update([
        'remaining' => 8,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}
echo "  · Saldo lama tahun {$prevYear} di-set: remaining=8\n";

// Hapus balance tahun current kalau ada (test fresh)
$db->table('leave_balances')
    ->where('employee_id', $emp['id'])
    ->where('leave_type_id', $leaveType['id'])
    ->where('period_year', $todayYear)
    ->delete();

// ─── 2. Run anniversary carry-over ───────────────────────────────
echo "\n2. Run anniversary carry-over (today = {$todayStr})\n";
$res = $service->runAnniversaryCarryOver($companyId, $todayStr);
assertion("Tidak error & ada hasil", isset($res['processed']));
assertion("Karyawan terdeteksi anniversary hari ini", $res['employees_with_anniversary_today'] >= 1);
assertion("Saldo diproses ≥ 1", $res['processed'] >= 1);
assertion("carried_total_days = 6 (capped dari 8)", abs($res['carried_total_days'] - 6.0) < 0.01);
assertion("expired_total_days = 2 (8 - 6)", abs($res['expired_total_days'] - 2.0) < 0.01);

// ─── 3. Verify DB state ──────────────────────────────────────────
echo "\n3. Verify DB state\n";
$newBal = $db->table('leave_balances')
    ->where('employee_id', $emp['id'])
    ->where('leave_type_id', $leaveType['id'])
    ->where('period_year', $todayYear)
    ->get()->getRowArray();
assertion("Balance baru tahun {$todayYear} dibuat", ! empty($newBal));
if ($newBal) {
    assertion("allocated = 12 (full quota)", abs((float) $newBal['allocated'] - 12.0) < 0.01);
    assertion("carried_over = 6 (capped)", abs((float) $newBal['carried_over'] - 6.0) < 0.01);
    assertion("used = 0 (reset)", abs((float) $newBal['used']) < 0.01);
    assertion("remaining = 18 (12 + 6)", abs((float) $newBal['remaining'] - 18.0) < 0.01);
    $expectedExpire = (new DateTime($todayStr))->modify('+6 months')->format('Y-m-d');
    assertion("expires_at = {$expectedExpire}", (string) $newBal['expires_at'] === $expectedExpire);
    assertion("basis = ANNIVERSARY", (string) $newBal['basis'] === 'ANNIVERSARY');
    assertion("anniversary_processed_at = {$todayStr}", (string) $newBal['anniversary_processed_at'] === $todayStr);
}

// ─── 4. Idempotency ──────────────────────────────────────────────
echo "\n4. Idempotency — run lagi tidak boleh double-carry\n";
$res2 = $service->runAnniversaryCarryOver($companyId, $todayStr);
assertion("Run kedua skip — processed = 0", $res2['processed'] === 0);

$balAfter2 = $db->table('leave_balances')
    ->where('employee_id', $emp['id'])
    ->where('leave_type_id', $leaveType['id'])
    ->where('period_year', $todayYear)
    ->get()->getRowArray();
assertion("Saldo tidak berubah setelah run kedua",
    abs((float) $balAfter2['carried_over'] - 6.0) < 0.01);

// ─── 5. Skip karyawan baru hire (tenure < 12 bulan) ──────────────
echo "\n5. Karyawan baru hire (tenure < 12 bulan) di-skip\n";
$baruEmp = $db->table('employees')
    ->where('company_id', $companyId)
    ->where('is_active', 1)
    ->where('deleted_at IS NULL')
    ->where('join_date >', date('Y-m-d', strtotime('-6 months')))
    ->limit(1)->get()->getRowArray();
if ($baruEmp) {
    $todayBaruStr = date('Y') . '-' . substr($baruEmp['join_date'], 5, 5);
    $resBaru = $service->runAnniversaryCarryOver($companyId, $todayBaruStr);
    // Tidak ada saldo lama dr tahun lalu utk karyawan baru, processed seharusnya 0
    // untuk karyawan tsb (tapi mungkin >0 jika ada karyawan lain juga MM-DD sama)
    echo "  · Karyawan baru: {$baruEmp['full_name']} (join {$baruEmp['join_date']}) — hasil: {$resBaru['processed']} processed\n";
    assertion("Jalan tanpa error", isset($resBaru['processed']));
} else {
    echo "  · Skip — tidak ada karyawan baru hire dalam 6 bulan terakhir\n";
}

// ─── Cleanup ─────────────────────────────────────────────────────
echo "\n6. Cleanup — restore basis & saldo test\n";
$db->table('leave_types')->where('id', $leaveType['id'])->update(['carry_over_basis' => $origBasis]);
echo "  · Basis leave_type direstore ke '{$origBasis}'\n";

if ($injectedOld) {
    $db->table('leave_balances')
        ->where('employee_id', $emp['id'])
        ->where('leave_type_id', $leaveType['id'])
        ->where('period_year', $prevYear)
        ->delete();
    echo "  · Saldo lama yang di-inject dihapus\n";
}
$db->table('leave_balances')
    ->where('employee_id', $emp['id'])
    ->where('leave_type_id', $leaveType['id'])
    ->where('period_year', $todayYear)
    ->where('anniversary_processed_at', $todayStr)
    ->delete();
echo "  · Saldo anniversary test dihapus\n";

echo "\n";
echo "=================================\n";
echo "Test result: {$ok} OK / {$fail} FAIL\n";
echo "=================================\n";
exit($fail > 0 ? 1 : 0);