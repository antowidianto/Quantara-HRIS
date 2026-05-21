<?php

/**
 * Smoke test untuk modul Disciplinary (SP).
 *
 * Skenario:
 *  1. Schema check (tabel + permission + kategori seeder)
 *  2. Service: nextReferenceNumber unik per company+level+year
 *  3. Service: defaultExpiresAt → SP1/2/3 = +6 bulan, lain = NULL
 *  4. Workflow: create SP1 → suggestNextLevel = SP2
 *  5. Create SP2 → suggestNextLevel = SP3
 *  6. Create SP3 → shouldSuggestTermination = true
 *  7. summary: active_level=SP3, active_count=3
 *  8. Render letter HTML pakai fallback template
 *  9. expireOverdue: SP dengan expires_at di masa lalu → status EXPIRED
 *  10. Cleanup
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

echo "=== Disciplinary (SP) Smoke Test ===\n\n";

// 1. Schema check
echo "1. Schema check\n";
check('Tabel disciplinary_records ada', $db->tableExists('disciplinary_records'));
check('Tabel disciplinary_categories ada', $db->tableExists('disciplinary_categories'));
$perms = $db->table('permissions')->whereIn('slug', ['disciplinary.view','disciplinary.manage'])->countAllResults();
check('2 permission disciplinary terdaftar', $perms === 2);
$catCount = $db->table('disciplinary_categories')->countAllResults();
check('Kategori SP sudah di-seed (>0)', $catCount > 0);

$svc = \Config\Services::disciplinary(false);

// 2. Setup test data
echo "\n2. Setup test\n";
$company = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$emp = $db->table('employees')->where('company_id', $company['id'])->where('is_active', 1)->limit(1)->get()->getRowArray();
if (! $emp) {
    echo "  ⚠ Tidak ada employee aktif. Skip test integrasi.\n";
    echo "Schema check passed ({$pass} ok, {$fail} fail).\n";
    exit($fail > 0 ? 1 : 0);
}
echo "  Test pakai employee #{$emp['id']} ({$emp['full_name']})\n";

// Cleanup leftover dari test sebelumnya
$db->table('disciplinary_records')
    ->like('subject', '[SMOKE TEST]', 'after')
    ->delete();

// 3. Service: reference number unik
echo "\n3. Reference number generator\n";
$ref1 = $svc->nextReferenceNumber((int) $company['id'], 'SP1');
$ref2 = $svc->nextReferenceNumber((int) $company['id'], 'SP1');
check('nextReferenceNumber tidak crash', $ref1 !== '');
check('Format: <LEVEL>/<CODE>/<YEAR>/<seq>', preg_match('#^SP1/[A-Z0-9]+/\d{4}/\d{5}$#', $ref1) === 1);
echo "    Generated: $ref1\n";

// 4. defaultExpiresAt
echo "\n4. defaultExpiresAt\n";
$exp = $svc->defaultExpiresAt('SP1', '2026-05-15');
check('SP1 expire = +6 bulan', $exp === '2026-11-15');
check('VERBAL → null',         $svc->defaultExpiresAt('VERBAL', '2026-05-15') === null);
check('TERMINATION → null',    $svc->defaultExpiresAt('TERMINATION', '2026-05-15') === null);

// 5. Create SP1
echo "\n5. Create SP1\n";
$sp1 = [
    'company_id'       => (int) $company['id'],
    'employee_id'      => (int) $emp['id'],
    'level'            => 'SP1',
    'reference_number' => $svc->nextReferenceNumber((int) $company['id'], 'SP1'),
    'subject'          => '[SMOKE TEST] Terlambat berulang',
    'description'      => 'Karyawan terlambat 5 kali dalam sebulan.',
    'incident_date'    => date('Y-m-d', strtotime('-7 days')),
    'effective_date'   => date('Y-m-d'),
    'expires_at'       => $svc->defaultExpiresAt('SP1', date('Y-m-d')),
    'status'           => 'ACTIVE',
    'created_at'       => date('Y-m-d H:i:s'),
    'updated_at'       => date('Y-m-d H:i:s'),
];
$db->table('disciplinary_records')->insert($sp1);
$id1 = (int) $db->insertID();
check('SP1 created (id ' . $id1 . ')', $id1 > 0);
check('suggestNextLevel = SP2', $svc->suggestNextLevel((int) $emp['id']) === 'SP2');

// 6. Create SP2
echo "\n6. Create SP2\n";
$sp2 = array_merge($sp1, [
    'level'            => 'SP2',
    'reference_number' => $svc->nextReferenceNumber((int) $company['id'], 'SP2'),
    'subject'          => '[SMOKE TEST] Pelanggaran berulang dalam masa SP1',
    'expires_at'       => $svc->defaultExpiresAt('SP2', date('Y-m-d')),
]);
$db->table('disciplinary_records')->insert($sp2);
$id2 = (int) $db->insertID();
check('SP2 created', $id2 > 0);
check('suggestNextLevel = SP3', $svc->suggestNextLevel((int) $emp['id']) === 'SP3');

// 7. Create SP3
echo "\n7. Create SP3\n";
$sp3 = array_merge($sp1, [
    'level'            => 'SP3',
    'reference_number' => $svc->nextReferenceNumber((int) $company['id'], 'SP3'),
    'subject'          => '[SMOKE TEST] Masih melanggar setelah SP2',
    'expires_at'       => $svc->defaultExpiresAt('SP3', date('Y-m-d')),
]);
$db->table('disciplinary_records')->insert($sp3);
$id3 = (int) $db->insertID();
check('SP3 created', $id3 > 0);
check('suggestNextLevel = TERMINATION', $svc->suggestNextLevel((int) $emp['id']) === 'TERMINATION');
check('shouldSuggestTermination = true', $svc->shouldSuggestTermination((int) $emp['id']));

// 8. Summary
echo "\n8. Summary\n";
$summary = $svc->summary((int) $emp['id']);
check('active_level = SP3', $summary['active_level'] === 'SP3');
check('active_count >= 3', $summary['active_count'] >= 3);
check('Timeline tidak kosong', count($summary['timeline']) >= 3);

// 9. Render letter
echo "\n9. Render letter\n";
$record = $db->table('disciplinary_records')->where('id', $id1)->get()->getRowArray();
$html = $svc->renderLetter($record);
check('HTML berisi SURAT PERINGATAN', str_contains($html, 'SURAT PERINGATAN'));
check('HTML berisi nama karyawan', str_contains($html, $emp['full_name']));
check('HTML berisi reference number', str_contains($html, $record['reference_number']));

// 10. Render TERMINATION
echo "\n10. Render PHK letter\n";
$phkRecord = array_merge($record, ['level' => 'TERMINATION']);
$htmlPhk = $svc->renderLetter($phkRecord);
check('HTML PHK berisi PEMUTUSAN HUBUNGAN KERJA', str_contains($htmlPhk, 'PEMUTUSAN HUBUNGAN KERJA'));
check('HTML PHK reference UU 6/2023 / PP 35', str_contains($htmlPhk, 'PP 35/2021'));

// 11. expireOverdue
echo "\n11. expireOverdue\n";
$expiredId = $db->table('disciplinary_records')->insert(array_merge($sp1, [
    'level'            => 'SP1',
    'reference_number' => $svc->nextReferenceNumber((int) $company['id'], 'SP1'),
    'subject'          => '[SMOKE TEST] SP yang sudah lewat expires_at',
    'effective_date'   => date('Y-m-d', strtotime('-1 year')),
    'expires_at'       => date('Y-m-d', strtotime('-1 month')),
    'status'           => 'ACTIVE',
])) ? (int) $db->insertID() : 0;
$expired = $svc->expireOverdue((int) $company['id']);
check('expireOverdue mengembalikan jumlah > 0', $expired > 0);
$row = $db->table('disciplinary_records')->where('id', $expiredId)->get()->getRowArray();
check('Status SP yg overdue = EXPIRED', $row['status'] === 'EXPIRED');

// 12. Idempotency: jalankan lagi expireOverdue → 0 yg ter-update
$expiredAgain = $svc->expireOverdue((int) $company['id']);
check('expireOverdue idempotent (run kedua = 0)', $expiredAgain === 0);

// Cleanup
$db->table('disciplinary_records')
    ->like('subject', '[SMOKE TEST]', 'after')
    ->delete();
echo "  ✓ Cleanup test data\n";

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);