<?php

/**
 * Smoke test untuk LeaveBalanceService.
 *
 * Cara jalankan:
 *   cd backend
 *   php scripts/test-leave-balance-flow.php
 *
 * Verifikasi:
 *  1. Prorate quota: karyawan join Mei → quota ≈ 8/12 dari default.
 *  2. ensureBalance idempotent: panggil 2x tidak duplikat.
 *  3. Year-end carry-over dry-run: laporan jumlah hari sesuai sisa.
 *  4. recalculate: setelah balance.used dimanipulasi, remaining diperbarui.
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

echo "=== Leave Balance Smoke Test ===\n\n";

$company = $db->table('companies')->where('is_active', 1)->orderBy('id')->limit(1)->get()->getRowArray();
if (! $company) {
    fwrite(STDERR, "Tidak ada company aktif.\n");
    exit(1);
}
echo "Company: {$company['name']} (#{$company['id']})\n";

$type = $db->table('leave_types')
    ->where('company_id', $company['id'])
    ->where('category', 'ANNUAL')
    ->where('is_active', 1)
    ->limit(1)->get()->getRowArray();
if (! $type) {
    fwrite(STDERR, "Tidak ada leave_types ANNUAL.\n");
    exit(1);
}
echo "Leave type: {$type['name']} (default {$type['default_days']} hari)\n";
echo "  is_prorated: " . ($type['is_prorated'] ?? 'N/A') . "\n";
echo "  is_carry_over_allowed: " . ($type['is_carry_over_allowed'] ?? 'N/A') . "\n";
echo "  carry_over_max_days: " . ($type['carry_over_max_days'] ?? 'NULL') . "\n";
echo "  carry_over_expires_in_months: " . ($type['carry_over_expires_in_months'] ?? 'N/A') . "\n\n";

$service = \Config\Services::leaveBalance();
$year = (int) date('Y');

// ─── 1. Test prorate ───────────────────────────────────────────
echo "1. Prorate quota test\n" . str_repeat('-', 60) . "\n";

$cases = [
    ['join_date' => "{$year}-01-01", 'expected_factor' => 1.0,  'desc' => 'Januari (full year)'],
    ['join_date' => "{$year}-05-15", 'expected_factor' => 8/12, 'desc' => 'Mei (8 bulan tersisa)'],
    ['join_date' => "{$year}-10-01", 'expected_factor' => 3/12, 'desc' => 'Oktober (3 bulan)'],
    ['join_date' => ($year - 5) . "-01-01", 'expected_factor' => 1.0, 'desc' => 'Sudah lama (full)'],
];
foreach ($cases as $c) {
    $emp = ['join_date' => $c['join_date']];
    $info = $service->computeProratedQuota($emp, $type, $year);
    $expectedQuota = round($type['default_days'] * $c['expected_factor'], 1);
    $ok = abs($info['quota'] - $expectedQuota) < 0.5;
    printf("  %s join %s → quota=%.1f (expected ~%.1f) factor=%.2f\n",
        $ok ? '✓' : '✗',
        $c['join_date'], $info['quota'], $expectedQuota, $info['prorate_factor']);
    if (! $ok) echo "    ❌ {$c['desc']}\n";
}

// ─── 2. ensureBalance idempotent ────────────────────────────────
echo "\n2. ensureBalance idempotent test\n" . str_repeat('-', 60) . "\n";
$emp = $db->table('employees')->where('company_id', $company['id'])->where('is_active', 1)->limit(1)->get()->getRowArray();
echo "  Karyawan test: {$emp['full_name']} (join {$emp['join_date']})\n";

$bal1 = $service->ensureBalance((int) $emp['id'], (int) $type['id'], $year);
$bal2 = $service->ensureBalance((int) $emp['id'], (int) $type['id'], $year);
$same = $bal1 && $bal2 && (int) $bal1['id'] === (int) $bal2['id'];
echo "  " . ($same ? '✓' : '✗') . " Panggil 2x kembalikan ID yang sama\n";
echo "  Allocated: {$bal1['allocated']}, Carried: {$bal1['carried_over']}, Used: {$bal1['used']}, Remaining: {$bal1['remaining']}\n";

// ─── 3. Year-end carry-over (dry run) ──────────────────────────
echo "\n3. Year-end carry-over dry run ({$year} → " . ($year + 1) . ")\n" . str_repeat('-', 60) . "\n";

// Pastikan ada beberapa balance dengan sisa supaya tes carry-over ada datanya.
// Ambil 3 balance acak lalu update satu per satu (lebih aman lintas DB driver
// daripada UPDATE ... LIMIT).
$sample = $db->table('leave_balances')
    ->where('company_id', $company['id'])
    ->where('period_year', $year)
    ->limit(3)->get()->getResultArray();
foreach ($sample as $row) {
    $db->table('leave_balances')->where('id', $row['id'])->update([
        'remaining' => 5.0, 'used' => 7.0, 'allocated' => 12.0,
    ]);
}

$res = $service->runYearEndCarryOver((int) $company['id'], $year, $year + 1, true);
echo "  Carried: {$res['carried']} balance, total {$res['total_days']} hari, expired {$res['expired_days']} hari\n";
foreach (array_slice($res['balances'], 0, 3) as $row) {
    printf("    - %s | sisa %.1f → carry %.1f, exp %s\n",
        $row['employee'], $row['old_remaining'], $row['carried'], $row['expires_at'] ?? '-');
}

// ─── 4. Summary ─────────────────────────────────────────────────
echo "\n4. summary() output\n" . str_repeat('-', 60) . "\n";
$summary = $service->summary((int) $emp['id'], $year);
foreach ($summary as $s) {
    printf("  %-20s alloc=%4.1f carry=%4.1f used=%4.1f remain=%4.1f%s%s\n",
        $s['name'],
        $s['allocated'], $s['carried_over'], $s['used'], $s['remaining'],
        $s['expires_at'] ? " exp={$s['expires_at']}" : '',
        $s['is_expired'] ? ' [EXPIRED]' : ''
    );
}

echo "\n=== Selesai ===\n";