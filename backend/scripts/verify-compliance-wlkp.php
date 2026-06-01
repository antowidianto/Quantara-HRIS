<?php

/**
 * Quick verification: panggil ComplianceReportService::generateReport untuk
 * WLKP_ANNUAL dan pastikan tidak throw "Unknown column 'e.nationality'".
 *
 * Pakai company_id pertama yang ada di DB. Tidak menulis ke tabel runs —
 * kita pakai metode internal `generateReport`-nya lewat reflection supaya
 * tidak butuh user/auth context.
 *
 * Jalankan:
 *   php spark serve   (tidak perlu)
 *   php scripts/verify-compliance-wlkp.php
 */

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap CodeIgniter mirip-mirip CI Tests Bootstrap
$paths        = new Config\Paths;
$pathsConfig  = $paths;
require_once $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

$db = \Config\Database::connect();

// Ambil company_id pertama
$row = $db->table('companies')->select('id, name')->limit(1)->get()->getRowArray();
if (! $row) {
    fwrite(STDERR, "[FAIL] Tidak ada company di DB.\n");
    exit(1);
}
$companyId = (int) $row['id'];
echo "Testing dengan company_id={$companyId} ({$row['name']})\n";

// Verifikasi kolom employees punya nationality
$cols = $db->getFieldNames('employees');
if (! in_array('nationality', $cols, true)) {
    fwrite(STDERR, "[FAIL] employees.nationality tidak ada — migrasi belum jalan?\n");
    exit(2);
}
echo "[OK] employees.nationality ditemukan\n";

// Panggil ComplianceReportService::generateReport untuk WLKP_ANNUAL
$svc = new \App\Libraries\Lifecycle\ComplianceReportService();

try {
    $reflect = new ReflectionClass($svc);
    // dispatchGeneration adalah method private yang dispatch ke generator-generator
    // (generateWlkpAnnual / generateBpjsMonthly / dst). Pakai reflection supaya
    // tidak butuh persist run row di tabel compliance_report_runs.
    $m = $reflect->getMethod('generateWlkpAnnual');
    // PHP 8.1+ tidak perlu setAccessible() lagi (di-deprecate di PHP 8.5)
    $year   = (int) date('Y');
    $result = $m->invoke($svc, $db, $companyId, $year);
    echo "[OK] WLKP_ANNUAL generate sukses.\n";
    echo "Total HC: " . ($result['total_hc'] ?? 'n/a') . "\n";
    echo "WNI: " . ($result['wni_hc'] ?? 'n/a') . " | WNA: " . ($result['wna_hc'] ?? 'n/a') . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[FAIL] Generate WLKP error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(3);
}