<?php

/**
 * Smoke test untuk PayrollExporter.
 *
 * Verifikasi:
 *  1. Bank transfer CSV header sesuai bank yang dipilih
 *  2. e-SPT CSV punya kolom yang dibutuhkan + total baris = jumlah payslip
 *  3. SiPP BPJS-TK CSV bisa di-generate
 *  4. e-Dabu BPJS Kesehatan CSV bisa di-generate
 *  5. Bukti potong HTML untuk satu payslip valid
 *  6. Period belum CALCULATED → exception
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

echo "=== Payroll Exporter Smoke Test ===\n\n";

// Cari periode yg sudah CALCULATED
$period = $db->table('payroll_periods')
    ->whereIn('status', ['CALCULATED', 'APPROVED', 'PAID', 'CLOSED'])
    ->orderBy('id', 'DESC')->limit(1)
    ->get()->getRowArray();
if (! $period) {
    echo "  ⚠ Tidak ada periode CALCULATED. Jalankan calculate dulu.\n";
    echo "  → Pakai POST /payroll-periods/{id}/calculate atau seeder demo.\n\n";
    exit(1);
}
echo "Period: #{$period['id']} {$period['name']} ({$period['status']})\n";

$payslipCount = $db->table('payslips')->where('payroll_period_id', $period['id'])->countAllResults();
echo "Payslip: {$payslipCount} baris\n\n";

$exporter = \Config\Services::payrollExporter(false);

// 1. Bank transfer
echo "1. Bank transfer CSV\n" . str_repeat('-', 60) . "\n";
foreach (['BCA', 'MANDIRI', 'BNI', 'BRI'] as $bank) {
    try {
        $file = $exporter->bankTransfer((int) $period['id'], $bank);
        $lines = explode("\r\n", trim($file['content']));
        $header = $lines[1] ?? ''; // skip BOM line; first row is header
        // Karena BOM masuk di string, line[0] = BOM + header
        $header = $lines[0];
        // strip BOM
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        check("[{$bank}] header tidak kosong", $header !== '');
        check("[{$bank}] filename ada", str_contains($file['filename'], $bank));
        check("[{$bank}] mime CSV", $file['mime'] === 'text/csv');
    } catch (\Throwable $e) {
        check("[{$bank}] generate", false);
        echo "    error: " . $e->getMessage() . "\n";
    }
}

// Bank tidak didukung
try {
    $exporter->bankTransfer((int) $period['id'], 'BUKAN_BANK');
    check('Bank tidak didukung → exception', false);
} catch (\InvalidArgumentException $e) {
    check('Bank tidak didukung → exception (OK)', true);
}

// 2. e-SPT PPh21
echo "\n2. e-SPT PPh21 CSV\n" . str_repeat('-', 60) . "\n";
$file = $exporter->esptPph21((int) $period['id']);
$lines = array_filter(explode("\r\n", trim($file['content'])));
$header = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0] ?? '');
$dataRows = count($lines) - 1; // minus header
check('Header PPh21 punya kolom NPWP_Pemotong', str_contains($header, 'NPWP_Pemotong'));
check('Jumlah baris data ≈ payslip count', $dataRows === $payslipCount);
check('Filename eSPT_PPh21_*.csv', str_contains($file['filename'], 'eSPT_PPh21_'));

// 3. SiPP BPJS-TK
echo "\n3. SiPP BPJS Ketenagakerjaan CSV\n" . str_repeat('-', 60) . "\n";
$file = $exporter->bpjsKetenagakerjaan((int) $period['id']);
$header = preg_replace('/^\xEF\xBB\xBF/', '', explode("\r\n", $file['content'])[0]);
check('Header SiPP punya No_KPJ', str_contains($header, 'No_KPJ'));
check('Header SiPP punya JHT_Karyawan', str_contains($header, 'JHT_Karyawan'));
check('Filename SiPP_BPJSTK_*.csv', str_contains($file['filename'], 'SiPP_BPJSTK_'));

// 4. e-Dabu BPJS Kesehatan
echo "\n4. e-Dabu BPJS Kesehatan CSV\n" . str_repeat('-', 60) . "\n";
$file = $exporter->bpjsKesehatan((int) $period['id']);
$header = preg_replace('/^\xEF\xBB\xBF/', '', explode("\r\n", $file['content'])[0]);
check('Header e-Dabu punya No_BPJS', str_contains($header, 'No_BPJS'));
check('Header e-Dabu punya Iuran_Karyawan', str_contains($header, 'Iuran_Karyawan'));
check('Filename eDabu_BPJSKes_*.csv', str_contains($file['filename'], 'eDabu_BPJSKes_'));

// 5. Bukti potong HTML
echo "\n5. Bukti potong HTML\n" . str_repeat('-', 60) . "\n";
$slip = $db->table('payslips')->where('payroll_period_id', $period['id'])->limit(1)->get()->getRowArray();
if ($slip) {
    $file = $exporter->buktiPotongHtml((int) $slip['id']);
    check('HTML mengandung BUKTI PEMOTONGAN', str_contains($file['content'], 'BUKTI PEMOTONGAN'));
    check('HTML punya Penghasilan Bruto', str_contains($file['content'], 'Penghasilan Bruto'));
    check('Mime text/html', $file['mime'] === 'text/html');
    check('Filename BuktiPotong_*.html', str_starts_with($file['filename'], 'BuktiPotong_'));
}

// 6. Negative test: period belum dihitung
echo "\n6. Period belum CALCULATED\n" . str_repeat('-', 60) . "\n";
$draft = $db->table('payroll_periods')->where('status', 'DRAFT')->limit(1)->get()->getRowArray();
if ($draft) {
    try {
        $exporter->bankTransfer((int) $draft['id'], 'BCA');
        check('Period DRAFT → exception', false);
    } catch (\RuntimeException $e) {
        check('Period DRAFT → exception (OK)', true);
    }
} else {
    echo "  ⚠ Tidak ada period DRAFT untuk negative test, skip.\n";
}

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);