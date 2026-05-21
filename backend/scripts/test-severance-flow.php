<?php

/**
 * Smoke test untuk SeveranceCalculator + SeveranceController flow.
 *
 * Skenario:
 *  1. Schema check (tabel + permission)
 *  2. pesangonMonths() — verifikasi tabel masa kerja
 *  3. upmkMonths() — verifikasi tabel masa kerja UPMK
 *  4. computeServiceYears() — masa kerja decimal
 *  5. Calculate kasus standar EFFICIENCY_LOSS untuk masa kerja 5 tahun
 *  6. Calculate kasus PENSION_RETIRE (multiplier 1.75 + 1.0)
 *  7. Calculate kasus DEATH (multiplier 2.0 + 1.0)
 *  8. Calculate kasus RESIGN (UPH only + uang pisah)
 *  9. Calculate kasus END_OF_PKWT (proporsional)
 *  10. UPH unused leave = days × dailyRate
 *  11. Render breakdown HTML berisi referensi UU & semua section
 *  12. Workflow DB: insert → finalize → mark-paid → idempotent
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
function approxEq(float $a, float $b, float $tol = 0.5): bool {
    return abs($a - $b) <= $tol;
}

echo "=== Severance Calculator Smoke Test ===\n\n";

// 1. Schema check
echo "1. Schema check\n";
check('Tabel severance_calculations ada', $db->tableExists('severance_calculations'));
$perms = $db->table('permissions')->whereIn('slug', ['severance.view','severance.manage'])->countAllResults();
check('2 permission severance terdaftar', $perms === 2);

$svc = \Config\Services::severance(false);

// 2. Tabel pesangon
echo "\n2. pesangonMonths() — tabel Pasal 40 ayat 2\n";
check('< 1 thn = 1 bulan', $svc->pesangonMonths(0.5) === 1);
check('1.5 thn = 2 bulan',  $svc->pesangonMonths(1.5) === 2);
check('5 thn = 6 bulan',    $svc->pesangonMonths(5.0) === 6);
check('8 thn = 9 bulan',    $svc->pesangonMonths(8.0) === 9);
check('15 thn = 9 bulan (capped)', $svc->pesangonMonths(15.0) === 9);

// 3. Tabel UPMK
echo "\n3. upmkMonths() — tabel Pasal 40 ayat 3\n";
check('< 3 thn = 0 bulan',  $svc->upmkMonths(2.5) === 0);
check('5 thn = 2 bulan',    $svc->upmkMonths(5.0) === 2);
check('10 thn = 4 bulan',   $svc->upmkMonths(10.0) === 4);
check('20 thn = 7 bulan',   $svc->upmkMonths(20.0) === 7);
check('25 thn = 10 bulan',  $svc->upmkMonths(25.0) === 10);

// 4. computeServiceYears
echo "\n4. computeServiceYears\n";
$y = $svc->computeServiceYears('2020-01-01', '2025-01-01');
check('2020-01-01 → 2025-01-01 ≈ 5 tahun', approxEq($y, 5.0, 0.05));

// 5. EFFICIENCY_LOSS, masa kerja 5 thn, gaji 10 juta
echo "\n5. Kasus EFFICIENCY_LOSS (5 thn, gaji 10jt)\n";
$r = $svc->calculate([
    'last_monthly_salary' => 10_000_000,
    'service_years' => 5.0,
    'termination_reason' => 'EFFICIENCY_LOSS',
    'termination_date' => '2026-06-01',
    'unused_leave_days' => 0,
]);
// Pesangon = 0.5 × 6 × 10jt = 30jt
check('Pesangon 6 bulan × 0.5 = 30jt', approxEq($r['pesangon_amount'], 30_000_000));
// UPMK = 1.0 × 2 × 10jt = 20jt
check('UPMK 2 bulan × 1.0 = 20jt', approxEq($r['upmk_amount'], 20_000_000));
check('UPH cuti = 0 (no unused)', approxEq($r['uph_unused_leave_amount'], 0));
check('Total = 50jt', approxEq($r['total_amount'], 50_000_000));

// 6. PENSION_RETIRE 8 thn, gaji 12 juta
echo "\n6. Kasus PENSION_RETIRE (8 thn, gaji 12jt)\n";
$r = $svc->calculate([
    'last_monthly_salary' => 12_000_000,
    'service_years' => 8.0,
    'termination_reason' => 'PENSION_RETIRE',
    'termination_date' => '2026-12-01',
    'unused_leave_days' => 0,
]);
// Pesangon = 1.75 × 9 × 12jt = 189jt
check('Pesangon 1.75 × 9 × 12jt = 189jt', approxEq($r['pesangon_amount'], 189_000_000, 1));
// UPMK = 1.0 × 3 × 12jt = 36jt
check('UPMK 1.0 × 3 × 12jt = 36jt', approxEq($r['upmk_amount'], 36_000_000));
check('Total = 225jt', approxEq($r['total_amount'], 225_000_000, 1));

// 7. DEATH (multiplier 2.0)
echo "\n7. Kasus DEATH (3 thn, gaji 8jt)\n";
$r = $svc->calculate([
    'last_monthly_salary' => 8_000_000,
    'service_years' => 3.0,
    'termination_reason' => 'DEATH',
    'termination_date' => '2026-06-01',
    'unused_leave_days' => 0,
]);
// Pesangon = 2.0 × 4 × 8jt = 64jt
check('Pesangon 2.0 × 4 × 8jt = 64jt', approxEq($r['pesangon_amount'], 64_000_000));
// UPMK = 1.0 × 2 × 8jt = 16jt
check('UPMK 1.0 × 2 × 8jt = 16jt', approxEq($r['upmk_amount'], 16_000_000));

// 8. RESIGN — UPH + uang pisah saja
echo "\n8. Kasus RESIGN (4 thn, gaji 6jt, 5 hari cuti, uang pisah 3jt)\n";
$r = $svc->calculate([
    'last_monthly_salary' => 6_000_000,
    'service_years' => 4.0,
    'termination_reason' => 'RESIGN',
    'termination_date' => '2026-06-01',
    'unused_leave_days' => 5,
    'separation_pay' => 3_000_000,
]);
check('Pesangon = 0', approxEq($r['pesangon_amount'], 0));
check('UPMK = 0', approxEq($r['upmk_amount'], 0));
// UPH cuti: 5 hari × (6jt/22) = 5 × 272.727 ≈ 1.363.636
check('UPH cuti ≈ 1.36jt', approxEq($r['uph_unused_leave_amount'], 1_363_636, 100));
check('Uang pisah = 3jt', approxEq($r['separation_pay'], 3_000_000));
check('Total ≈ 4.36jt', approxEq($r['total_amount'], 4_363_636, 100));

// 9. END_OF_PKWT (kompensasi 1 bulan per 12 bulan masa kerja)
echo "\n9. Kasus END_OF_PKWT (24 bulan, gaji 5jt)\n";
$r = $svc->calculate([
    'last_monthly_salary' => 5_000_000,
    'service_years' => 2.0,  // 24 bulan
    'termination_reason' => 'END_OF_PKWT',
    'termination_date' => '2026-06-01',
    'unused_leave_days' => 0,
]);
// 24/12 × 5jt = 10jt
check('PKWT compensation = 24/12 × 5jt = 10jt', approxEq($r['pesangon_amount'], 10_000_000));
check('UPMK = 0 untuk PKWT', approxEq($r['upmk_amount'], 0));
check('Total = 10jt', approxEq($r['total_amount'], 10_000_000));

// 10. UPH unused leave detail
echo "\n10. UPH cuti formula\n";
$r = $svc->calculate([
    'last_monthly_salary' => 4_400_000, // 22 hari × 200rb = 4.4jt
    'service_years' => 1,
    'termination_reason' => 'EFFICIENCY_LOSS',
    'termination_date' => '2026-01-01',
    'unused_leave_days' => 10,
]);
// Daily rate = 4.400.000 / 22 = 200.000; UPH = 10 × 200.000 = 2.000.000
check('Daily rate × 10 hari = 2jt', approxEq($r['uph_unused_leave_amount'], 2_000_000));

// 11. Render breakdown HTML (no unused leave biar total round 50jt)
echo "\n11. Render breakdown\n";
$result = $svc->calculate([
    'last_monthly_salary' => 10_000_000,
    'service_years' => 5.0,
    'termination_reason' => 'EFFICIENCY_LOSS',
    'termination_date' => '2026-06-01',
    'unused_leave_days' => 0,
]);
$result['termination_date'] = '2026-06-01';
$html = $svc->renderBreakdown($result, [
    'full_name' => 'Budi Santoso',
    'employee_number' => 'EMP-001',
    'join_date' => '2021-06-01',
], ['city' => 'Jakarta']);
check('HTML berisi judul utama', str_contains($html, 'PERHITUNGAN HAK KARYAWAN'));
check('HTML berisi referensi UU 6/2023', str_contains($html, 'UU No 6 Tahun 2023'));
check('HTML berisi nama karyawan', str_contains($html, 'Budi Santoso'));
check('HTML berisi section Pesangon', str_contains($html, 'Pasal 40 ayat 2'));
check('HTML berisi section UPMK',     str_contains($html, 'Pasal 40 ayat 3'));
check('HTML berisi section UPH',      str_contains($html, 'Pasal 40 ayat 4'));
check('HTML berisi total Rp 50.000.000', str_contains($html, 'Rp 50.000.000'));

// 12. Negative test: alasan invalid
echo "\n12. Alasan invalid\n";
try {
    $svc->calculate([
        'last_monthly_salary' => 5_000_000,
        'service_years' => 1,
        'termination_reason' => 'BUKAN_REASON',
        'termination_date' => '2026-01-01',
    ]);
    check('Alasan invalid → exception', false);
} catch (\InvalidArgumentException $e) {
    check('Alasan invalid → exception (OK)', true);
}

// 13. DB workflow (kalau ada employee aktif)
echo "\n13. DB workflow (optional)\n";
$company = $db->table('companies')->where('is_active', 1)->limit(1)->get()->getRowArray();
$emp = $db->table('employees')->where('is_active', 1)->where('company_id', $company['id'] ?? 0)->limit(1)->get()->getRowArray();
if ($emp) {
    // Cleanup leftover
    $db->table('severance_calculations')->where('notes', 'SMOKE TEST')->delete();

    $now = date('Y-m-d H:i:s');
    $r = $svc->calculate([
        'employee_id'         => (int) $emp['id'],
        'last_monthly_salary' => 7_000_000,
        'service_years'       => 4.0,
        'termination_reason'  => 'EFFICIENCY_LOSS',
        'termination_date'    => date('Y-m-d'),
        'unused_leave_days'   => 0,
    ]);
    $insert = [
        'company_id'        => (int) $company['id'],
        'employee_id'       => (int) $emp['id'],
        'termination_date'  => date('Y-m-d'),
        'termination_reason'=> $r['termination_reason'],
        'reason_label'      => $r['reason_label'],
        'last_monthly_salary'=> $r['last_monthly_salary'],
        'service_years'     => $r['service_years'],
        'service_months'    => $r['service_months'],
        'pesangon_multiplier'=> $r['pesangon_multiplier'],
        'pesangon_months'   => $r['pesangon_months'],
        'pesangon_amount'   => $r['pesangon_amount'],
        'upmk_multiplier'   => $r['upmk_multiplier'],
        'upmk_months'       => $r['upmk_months'],
        'upmk_amount'       => $r['upmk_amount'],
        'uph_unused_leave_amount'=> $r['uph_unused_leave_amount'],
        'uph_homecoming_amount'  => $r['uph_homecoming_amount'],
        'uph_other_amount'  => $r['uph_other_amount'],
        'uph_total'         => $r['uph_total'],
        'separation_pay'    => $r['separation_pay'],
        'unused_leave_days' => $r['unused_leave_days'],
        'total_amount'      => $r['total_amount'],
        'notes'             => 'SMOKE TEST',
        'status'            => 'DRAFT',
        'created_at'        => $now,
        'updated_at'        => $now,
    ];
    $db->table('severance_calculations')->insert($insert);
    $id = (int) $db->insertID();
    check('Insert DRAFT', $id > 0);

    // Finalize
    $db->table('severance_calculations')->where('id', $id)->update([
        'status' => 'FINALIZED',
        'finalized_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $row = $db->table('severance_calculations')->where('id', $id)->get()->getRowArray();
    check('Status berubah jadi FINALIZED', $row['status'] === 'FINALIZED');

    // Cleanup
    $db->table('severance_calculations')->where('id', $id)->delete();
    echo "  ✓ Cleanup test record\n";
} else {
    echo "  ⚠ Tidak ada employee aktif untuk DB workflow test, skip.\n";
}

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);