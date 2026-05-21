<?php

/**
 * Smoke test untuk modul Roster.
 *
 * Cara jalankan dari root project:
 *   cd backend
 *   php scripts/test-roster-flow.php
 *
 * Test ini:
 *  1. Memuat resolver via Services
 *  2. Mengambil 1 karyawan dari company demo
 *  3. Memanggil ShiftResolver untuk 14 hari ke depan
 *  4. Memverifikasi ada shift yang ter-resolve dari sumber yang benar
 *  5. Test bulk update + clear roster
 *
 * Tidak men-tear-down apa pun, jadi aman dijalankan ulang.
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

echo "=== Roster Smoke Test ===\n\n";

// 1. Pastikan ada data
$company = $db->table('companies')->where('is_active', 1)->orderBy('id')->limit(1)->get()->getRowArray();
if (! $company) {
    fwrite(STDERR, "Tidak ada company aktif. Jalankan db:seed dulu.\n");
    exit(1);
}
echo "Company: {$company['name']} (#{$company['id']})\n";

$emp = $db->table('employees')
    ->where('company_id', $company['id'])
    ->where('is_active', 1)
    ->orderBy('id')->limit(1)->get()->getRowArray();
if (! $emp) {
    fwrite(STDERR, "Tidak ada karyawan aktif.\n");
    exit(1);
}
echo "Employee: {$emp['employee_number']} - {$emp['full_name']} (branch_id={$emp['branch_id']})\n\n";

// 2. Resolve shift untuk 14 hari ke depan
$resolver = \Config\Services::shiftResolver(false);
$today = new DateTime();
$counts = ['schedule' => 0, 'employee' => 0, 'pattern' => 0, 'assignment' => 0,
           'shift_default' => 0, 'fallback' => 0, 'none' => 0];

echo "Resolve 14 hari ke depan:\n";
echo str_repeat('-', 80) . "\n";
printf("%-12s %-7s %-15s %-25s %s\n", 'Tanggal', 'Hari', 'Shift', 'Sumber', 'Reason');
echo str_repeat('-', 80) . "\n";

for ($i = 0; $i < 14; $i++) {
    $d = $today->format('Y-m-d');
    $r = $resolver->resolve((int) $emp['id'], $d);
    $shift = $r['shift'] ? $r['shift']['code'] : '-';
    $marker = '';
    if ($r['is_off']) $marker .= ' [OFF]';
    if ($r['is_holiday']) $marker .= ' [LIBUR]';

    $dayNames = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'];
    $dayName = $dayNames[$today->format('w')];

    printf("%-12s %-7s %-15s %-25s %s\n",
        $d, $dayName, $shift . $marker, $r['source'], substr($r['reason'] ?? '', 0, 40));

    $counts[$r['source']] = ($counts[$r['source']] ?? 0) + 1;
    $today->modify('+1 day');
}

echo "\nDistribusi sumber: ";
foreach ($counts as $k => $v) {
    if ($v > 0) echo "{$k}={$v} ";
}
echo "\n\n";

// 3. Sanity check: minimal shift atau OFF harus ada
$totalSolved = 14 - ($counts['none'] ?? 0);
if ($totalSolved < 14) {
    echo "⚠️  Ada {$counts['none']} hari tanpa shift apa pun.\n";
}

// 4. List shift yang dimiliki company
$shifts = $db->table('shifts')
    ->where('company_id', $company['id'])
    ->where('deleted_at IS NULL')
    ->orderBy('name')
    ->get()->getResultArray();
echo "Shifts terdaftar di company:\n";
foreach ($shifts as $s) {
    $tag = '';
    if ((int) $s['is_default'] === 1) $tag .= ' [DEFAULT]';
    if (! empty($s['working_days'])) $tag .= " [hari: {$s['working_days']}]";
    echo "  - {$s['code']} ({$s['name']}) {$s['start_time']}–{$s['end_time']}{$tag}\n";
}
echo "\n";

// 5. List patterns
$patterns = $db->table('shift_patterns')
    ->where('company_id', $company['id'])
    ->where('deleted_at IS NULL')
    ->orderBy('name')
    ->get()->getResultArray();
echo "Patterns terdaftar:\n";
foreach ($patterns as $p) {
    echo "  - {$p['code']} ({$p['name']}) — siklus {$p['cycle_days']} hari\n";
}
echo "\n";

// 6. Test bulk update + clear (idempotent)
echo "Test bulk_update + clear flow…\n";
$testDate = (new DateTime())->modify('+30 days')->format('Y-m-d');
$shiftToUse = $shifts[0] ?? null;
if ($shiftToUse) {
    // Insert test schedule
    $exists = $db->table('shift_schedules')
        ->where('employee_id', $emp['id'])
        ->where('work_date', $testDate)
        ->countAllResults();
    if ($exists === 0) {
        $db->table('shift_schedules')->insert([
            'company_id'  => $company['id'],
            'employee_id' => $emp['id'],
            'shift_id'    => $shiftToUse['id'],
            'work_date'   => $testDate,
            'is_off'      => 0,
            'is_holiday'  => 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        echo "  ✓ Insert test schedule {$testDate} → {$shiftToUse['code']}\n";
    }

    // Resolve harus dapat source=schedule
    $r = $resolver->resolve((int) $emp['id'], $testDate);
    if ($r['source'] === 'schedule' && $r['shift'] && (int) $r['shift']['id'] === (int) $shiftToUse['id']) {
        echo "  ✓ Resolver mengembalikan source=schedule untuk override eksplisit\n";
    } else {
        echo "  ✗ Resolver salah: source={$r['source']}, shift=" . ($r['shift']['code'] ?? '-') . "\n";
    }

    // Clean up
    $db->table('shift_schedules')
        ->where('employee_id', $emp['id'])
        ->where('work_date', $testDate)
        ->delete();
    echo "  ✓ Cleanup test schedule\n";
}

echo "\n=== Selesai ===\n";