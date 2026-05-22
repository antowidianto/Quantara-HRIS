<?php
/**
 * seed-kaldu-richeese.php — generate realistic running-company demo data.
 *
 * Mengubah dua company demo menjadi:
 *   - Company #1: PT Kaldu Sari Nabati Indonesia (KSNI, manufaktur snack/wafer)
 *   - Company #2: PT Richeese Factory (RFAC, restoran cheese chicken)
 *
 * Aksi:
 *   1. Rename + update profil 2 company (legal_name, alamat, email, dst).
 *   2. Tambah branch baru (HQ + outlet/factory tambahan).
 *   3. Tambah departments + positions sesuai industri masing-masing.
 *   4. Generate karyawan ~55 per company dengan struktur lengkap:
 *      - 1 Direktur Utama
 *      - 4-5 Direktur (HRD, Finance, Operations, Sales/Production)
 *      - 8-10 Manager per fungsi
 *      - 8-12 Supervisor / Asisten Manager
 *      - 30+ staff/operator/officer
 *   5. Assign penjabat ke organization_structures (HR Manager, Finance
 *      Manager, dst) — hilangkan vacant.
 *   6. Buat user login untuk semua jabatan kunci + sample staff.
 *
 * IDEMPOTENT: skip insert kalau employee_number/email sudah ada.
 * Aman dijalankan ulang.
 */

$dbPath = __DIR__ . '/../writable/hris.sqlite';
$db = new SQLite3($dbPath);
$db->enableExceptions(true);
$db->exec('PRAGMA foreign_keys = ON');

$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');

// ──────────────────────────────────────────────────────────────────────────
// Utility helpers
// ──────────────────────────────────────────────────────────────────────────

/** Insert kalau belum ada (cek by where), return id-nya. */
function insertOrFind(SQLite3 $db, string $table, array $where, array $row): int {
    $whereSql = implode(' AND ', array_map(fn($k) => "$k = :w_$k", array_keys($where)));
    $stmt = $db->prepare("SELECT id FROM $table WHERE $whereSql LIMIT 1");
    foreach ($where as $k => $v) {
        $stmt->bindValue(":w_$k", $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($existing) return (int) $existing['id'];

    $cols = implode(', ', array_keys($row));
    $vals = implode(', ', array_map(fn($k) => ":i_$k", array_keys($row)));
    $stmt = $db->prepare("INSERT INTO $table ($cols) VALUES ($vals)");
    foreach ($row as $k => $v) {
        if ($v === null)         $stmt->bindValue(":i_$k", null, SQLITE3_NULL);
        elseif (is_int($v))      $stmt->bindValue(":i_$k", $v, SQLITE3_INTEGER);
        elseif (is_float($v))    $stmt->bindValue(":i_$k", $v, SQLITE3_FLOAT);
        else                     $stmt->bindValue(":i_$k", (string) $v, SQLITE3_TEXT);
    }
    $stmt->execute();
    return (int) $db->lastInsertRowID();
}

function updateRow(SQLite3 $db, string $table, int $id, array $set): void {
    $sets = implode(', ', array_map(fn($k) => "$k = :s_$k", array_keys($set)));
    $stmt = $db->prepare("UPDATE $table SET $sets WHERE id = :id");
    foreach ($set as $k => $v) {
        if ($v === null)         $stmt->bindValue(":s_$k", null, SQLITE3_NULL);
        elseif (is_int($v))      $stmt->bindValue(":s_$k", $v, SQLITE3_INTEGER);
        elseif (is_float($v))    $stmt->bindValue(":s_$k", $v, SQLITE3_FLOAT);
        else                     $stmt->bindValue(":s_$k", (string) $v, SQLITE3_TEXT);
    }
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

/** Random int inclusive. */
function ri(int $a, int $b): int { return mt_rand($a, $b); }
/** Random pick. */
function rp(array $arr) { return $arr[array_rand($arr)]; }

/** Build slug-friendly email from name. */
function emailFromName(string $name, string $domain): string {
    $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9 ]/', '', $name)));
    $base = preg_replace('/\s+/', '.', $base);
    return $base . '@' . $domain;
}

/** Generate fake-but-valid Indonesian phone. */
function fakePhone(): string {
    $prefixes = ['0811','0812','0813','0821','0822','0852','0853','0856','0857','0878'];
    return rp($prefixes) . str_pad((string) ri(1000000, 9999999), 7, '0', STR_PAD_LEFT);
}

/** Generate a valid-format BPJS Kesehatan number (13 digits). */
function fakeBpjsKes(): string {
    return '00' . str_pad((string) ri(1, 99999999999), 11, '0', STR_PAD_LEFT);
}
function fakeBpjsTk(): string {
    return str_pad((string) ri(1, 99999999999), 11, '0', STR_PAD_LEFT);
}

// Indonesian first names
$maleNames = [
    'Ahmad','Budi','Cahyo','Dedi','Eko','Fajar','Galih','Hadi','Iwan','Joko',
    'Kurnia','Lukman','Mahmud','Nanang','Oki','Pandu','Qomar','Rian','Sigit',
    'Taufik','Umar','Vino','Wahyu','Yusuf','Zainal','Agus','Bayu','Dimas','Ervan',
    'Faisal','Gilang','Heri','Irfan','Khoirul','Maulana','Nugroho','Prasetyo','Reza',
    'Surya','Teguh','Andre','Bambang','Dani','Erlangga','Fadli','Hendra','Indra',
    'Maulana','Nugraha','Ridwan','Septian','Tio','Wisnu','Yoga',
];
$femaleNames = [
    'Aisyah','Bella','Citra','Dewi','Erna','Fitri','Gita','Hana','Indah','Juwita',
    'Kartika','Lia','Maya','Nia','Oktavia','Putri','Qonita','Rina','Siti','Tari',
    'Umi','Vera','Wati','Yuni','Zahra','Amelia','Cinta','Dini','Eka','Fauziah',
    'Gladys','Hesti','Intan','Karina','Lutfia','Melati','Nurul','Olivia','Permata',
    'Ratna','Selvi','Tiara','Vania','Yanti','Anisa','Cici','Devi','Erika','Farah',
    'Hilda','Irma','Jihan','Kiki',
];
$lastNames = [
    'Pratama','Wijaya','Saputra','Hidayat','Setiawan','Susanto','Hartono','Kusuma',
    'Nugroho','Permana','Lestari','Putri','Anggraini','Rahmawati','Sari','Wulandari',
    'Maulida','Hakim','Nurjanah','Marpaung','Hutagalung','Simanjuntak','Sihotang',
    'Sitorus','Manullang','Tampubolon','Pasaribu','Lubis','Siregar','Harahap',
    'Gultom','Sianturi','Damanik','Naibaho','Sembiring','Tarigan','Ginting','Bangun',
    'Surbakti','Pinem','Sembiring','Iskandar','Mulyana','Suryana','Wibowo','Sugiarto',
    'Santoso','Cahyono','Prasetya','Pamungkas',
];

$religions = ['Islam','Kristen','Katolik','Hindu','Budha'];
$bloodTypes = ['A','B','AB','O'];
$banks = ['BCA','Mandiri','BNI','BRI','CIMB Niaga','Permata','Danamon'];
$cities = ['Bandung','Cimahi','Jakarta','Bekasi','Tangerang','Surabaya','Bogor','Depok'];
$ptkpStatuses = ['TK0','TK1','TK2','K0','K1','K2','K3'];
$educationLevels = ['SMK','SMA','D3','S1','S2'];

$created = ['users' => 0, 'employees' => 0, 'orgs_assigned' => 0];

// ──────────────────────────────────────────────────────────────────────────
// 1. Update existing companies
// ──────────────────────────────────────────────────────────────────────────
echo "\n[1] Update company profiles...\n";

// Company #1 — PT Demo HRIS Indonesia → PT Kaldu Sari Nabati Indonesia
$ksniId = 1;
updateRow($db, 'companies', $ksniId, [
    'code'       => 'KSNI',
    'name'       => 'PT Kaldu Sari Nabati Indonesia',
    'legal_name' => 'PT Kaldu Sari Nabati Indonesia',
    'email'      => 'corporate@nabatisnack.co.id',
    'phone'      => '022-6862888',
    'address'    => 'Jl. Raya Cicalengka-Majalaya KM 5, Kabupaten Bandung',
    'province'   => 'Jawa Barat',
    'city'       => 'Bandung',
    'updated_at' => $now,
]);
echo "  ✓ Company #1 → PT Kaldu Sari Nabati Indonesia (KSNI)\n";

// Company #2 — Richeese Factory → PT Richeese Factory
$rfacId = 2;
updateRow($db, 'companies', $rfacId, [
    'code'       => 'RFAC',
    'name'       => 'PT Richeese Factory',
    'legal_name' => 'PT Richeese Kuliner Indonesia',
    'email'      => 'corporate@richeesefactory.com',
    'phone'      => '022-87780000',
    'address'    => 'Jl. Soekarno-Hatta No. 456, Bandung',
    'province'   => 'Jawa Barat',
    'city'       => 'Bandung',
    'is_active'  => 1,
    'timezone'   => 'Asia/Jakarta',
    'currency'   => 'IDR',
    'fiscal_year_start' => 1,
    'updated_at' => $now,
]);
echo "  ✓ Company #2 → PT Richeese Factory (RFAC)\n";

// Bootstrap baseline tables for Richeese Factory (kalau company #2 baru kosong)
// Cek bpjs_rates, leave_types, payroll_components — replicate pattern KSNI
$rfacBpjs = $db->querySingle("SELECT id FROM bpjs_rates WHERE company_id = 2");
if (! $rfacBpjs) {
    insertOrFind($db, 'bpjs_rates', ['company_id' => $rfacId], [
        'company_id'        => $rfacId,
        'kes_employee_rate' => 0.01,  'kes_company_rate'  => 0.04,
        'kes_max_salary'    => 12000000,
        'jht_employee_rate' => 0.02,  'jht_company_rate'  => 0.037,
        'jp_employee_rate'  => 0.01,  'jp_company_rate'   => 0.02,
        'jp_max_salary'     => 10547400,
        'jkk_company_rate'  => 0.0024,'jkm_company_rate'  => 0.003,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    echo "  ✓ Richeese Factory bpjs_rates seeded\n";
}

// Replicate leave_types + payroll_components dari KSNI ke RFAC kalau kosong
$rfacLeaveCount = (int) $db->querySingle("SELECT COUNT(*) FROM leave_types WHERE company_id = 2");
if ($rfacLeaveCount === 0) {
    $ksniLeaves = $db->query("SELECT * FROM leave_types WHERE company_id = 1");
    while ($lt = $ksniLeaves->fetchArray(SQLITE3_ASSOC)) {
        unset($lt['id']);
        $lt['company_id'] = $rfacId;
        $lt['created_at'] = $now;
        $lt['updated_at'] = $now;
        insertOrFind($db, 'leave_types',
            ['company_id' => $rfacId, 'code' => $lt['code']], $lt);
    }
    echo "  ✓ Richeese Factory leave_types replicated\n";
}
$rfacPcCount = (int) $db->querySingle("SELECT COUNT(*) FROM payroll_components WHERE company_id = 2");
if ($rfacPcCount === 0) {
    $ksniPcs = $db->query("SELECT * FROM payroll_components WHERE company_id = 1");
    while ($pc = $ksniPcs->fetchArray(SQLITE3_ASSOC)) {
        unset($pc['id']);
        $pc['company_id'] = $rfacId;
        $pc['created_at'] = $now;
        $pc['updated_at'] = $now;
        insertOrFind($db, 'payroll_components',
            ['company_id' => $rfacId, 'code' => $pc['code']], $pc);
    }
    echo "  ✓ Richeese Factory payroll_components replicated\n";
}

// Default shift untuk Richeese
insertOrFind($db, 'shifts', ['company_id' => $rfacId, 'code' => 'REG'], [
    'company_id' => $rfacId, 'code' => 'REG',
    'name' => 'Reguler 09:00-17:00',
    'start_time' => '09:00:00', 'end_time' => '17:00:00',
    'break_minutes' => 60, 'late_tolerance_minutes' => 15,
    'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
]);

// ──────────────────────────────────────────────────────────────────────────
// 2. Branches
// ──────────────────────────────────────────────────────────────────────────
echo "\n[2] Branches...\n";

// KSNI branches — pabrik di Bandung Raya + sales offices
$ksniBranches = [
    ['code' => 'BDG', 'name' => 'HQ Cicalengka',          'city' => 'Bandung',     'address' => 'Jl. Raya Cicalengka-Majalaya KM 5'],
    ['code' => 'CMH', 'name' => 'Pabrik Cimahi',          'city' => 'Cimahi',      'address' => 'Jl. Industri Cimahi No. 45'],
    ['code' => 'JKT', 'name' => 'Sales Office Jakarta',   'city' => 'Jakarta',     'address' => 'Jl. Gatot Subroto Kav. 27'],
    ['code' => 'SBY', 'name' => 'Sales Office Surabaya',  'city' => 'Surabaya',    'address' => 'Jl. Mayjend Sungkono No. 88'],
];
$ksniBranchIds = [];
foreach ($ksniBranches as $b) {
    $id = insertOrFind($db, 'branches', ['company_id' => $ksniId, 'code' => $b['code']], [
        'company_id' => $ksniId, 'code' => $b['code'], 'name' => $b['name'],
        'address' => $b['address'], 'city' => $b['city'],
        'province' => $b['city'] === 'Jakarta' ? 'DKI Jakarta'
                    : ($b['city'] === 'Surabaya' ? 'Jawa Timur' : 'Jawa Barat'),
        'is_active' => 1,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $ksniBranchIds[$b['code']] = $id;
}

// Update branch existing yang renamed
updateRow($db, 'branches', $ksniBranchIds['BDG'], [
    'address' => 'Jl. Raya Cicalengka-Majalaya KM 5',
    'name' => 'HQ Cicalengka',
]);
echo "  ✓ KSNI: " . count($ksniBranchIds) . " branches\n";

// RFAC branches — outlet
$rfacBranches = [
    ['code' => 'BDG', 'name' => 'HQ Bandung',                  'city' => 'Bandung',     'address' => 'Jl. Soekarno-Hatta No. 456'],
    ['code' => 'JKT', 'name' => 'Outlet Jakarta Pusat',        'city' => 'Jakarta',     'address' => 'Mall Grand Indonesia, Lt. 3A'],
    ['code' => 'BKS', 'name' => 'Outlet Bekasi',               'city' => 'Bekasi',      'address' => 'Summarecon Mal Bekasi, Lt. GF'],
    ['code' => 'SBY', 'name' => 'Outlet Surabaya',             'city' => 'Surabaya',    'address' => 'Tunjungan Plaza, Lt. 4'],
];
$rfacBranchIds = [];
foreach ($rfacBranches as $b) {
    $id = insertOrFind($db, 'branches', ['company_id' => $rfacId, 'code' => $b['code']], [
        'company_id' => $rfacId, 'code' => $b['code'], 'name' => $b['name'],
        'address' => $b['address'], 'city' => $b['city'],
        'province' => $b['city'] === 'Jakarta' ? 'DKI Jakarta'
                    : ($b['city'] === 'Surabaya' ? 'Jawa Timur' : 'Jawa Barat'),
        'is_active' => 1,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $rfacBranchIds[$b['code']] = $id;
}
echo "  ✓ RFAC: " . count($rfacBranchIds) . " branches\n";

// ──────────────────────────────────────────────────────────────────────────
// 3. Departments
// ──────────────────────────────────────────────────────────────────────────
echo "\n[3] Departments...\n";

// KSNI — departemen manufaktur snack food
$ksniDepts = [
    'Direksi'              => 'Board of Directors',
    'Human Resources'      => 'Human Resources & GA',
    'Finance & Accounting' => 'Finance & Accounting',
    'Production'           => 'Production (Wafer & Beverage)',
    'R&D'                  => 'Research & Development',
    'Quality Assurance'    => 'Quality Assurance & QC',
    'Sales'                => 'Sales (Modern Trade & GT)',
    'Marketing'            => 'Marketing & Brand',
    'Logistics'            => 'Logistics & Warehouse',
    'Procurement'          => 'Procurement & Purchasing',
    'Engineering'          => 'Engineering & Maintenance',
    'Information Technology' => 'Information Technology',
];
/**
 * Generate unique dept code from name. Snake_case + uppercase the
 * full normalized name so collisions are virtually impossible (e.g.
 * "Information Technology" → "INFORMATION_TECHNOLOGY"). Truncate to
 * 32 chars supaya cocok dengan kolom VARCHAR umum.
 */
function deptCode(string $name): string {
    $norm = preg_replace('/[^A-Za-z0-9]+/', '_', trim($name));
    $norm = trim(strtoupper($norm), '_');
    return substr($norm, 0, 32);
}

/** Cari code unik untuk dept dalam company tertentu. */
function uniqueDeptCode(SQLite3 $db, int $companyId, string $base): string {
    $code = $base;
    $i = 2;
    while ($db->querySingle("SELECT id FROM departments
        WHERE company_id = $companyId AND code = '" . SQLite3::escapeString($code) . "'")) {
        $code = $base . '_' . $i;
        $i++;
        if ($i > 50) break;
    }
    return $code;
}

$ksniDeptIds = [];
foreach ($ksniDepts as $name => $desc) {
    $code = uniqueDeptCode($db, $ksniId, deptCode($name));
    $id = insertOrFind($db, 'departments', ['company_id' => $ksniId, 'name' => $name], [
        'company_id' => $ksniId, 'code' => $code, 'name' => $name, 'description' => $desc,
        'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $ksniDeptIds[$name] = $id;
}
echo "  ✓ KSNI: " . count($ksniDeptIds) . " departments\n";

// RFAC — departemen restoran
$rfacDepts = [
    'Direksi'              => 'Board of Directors',
    'Operations'           => 'Operations (Outlet Management)',
    'Kitchen'              => 'Kitchen & Food Production',
    'Service'              => 'Service & Cashier',
    'Marketing'            => 'Marketing & Promotion',
    'Finance & Accounting' => 'Finance & Accounting',
    'Human Resources'      => 'Human Resources & Training',
    'Information Technology' => 'IT & Digital',
    'Procurement'          => 'Procurement & Supply Chain',
];
$rfacDeptIds = [];
foreach ($rfacDepts as $name => $desc) {
    $code = uniqueDeptCode($db, $rfacId, deptCode($name));
    $id = insertOrFind($db, 'departments', ['company_id' => $rfacId, 'name' => $name], [
        'company_id' => $rfacId, 'code' => $code, 'name' => $name, 'description' => $desc,
        'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $rfacDeptIds[$name] = $id;
}
echo "  ✓ RFAC: " . count($rfacDeptIds) . " departments\n";

// ──────────────────────────────────────────────────────────────────────────
// 4. Job grades
// ──────────────────────────────────────────────────────────────────────────
echo "\n[4] Job grades...\n";

$grades = [
    ['code' => 'JG1', 'name' => 'Operator/Staff',      'min_salary' => 4500000,  'max_salary' => 6500000],
    ['code' => 'JG2', 'name' => 'Senior Staff',        'min_salary' => 6000000,  'max_salary' => 9500000],
    ['code' => 'JG3', 'name' => 'Supervisor',          'min_salary' => 9000000,  'max_salary' => 14000000],
    ['code' => 'JG4', 'name' => 'Asst. Manager',       'min_salary' => 13000000, 'max_salary' => 19000000],
    ['code' => 'JG5', 'name' => 'Manager',             'min_salary' => 18000000, 'max_salary' => 30000000],
    ['code' => 'JG6', 'name' => 'Senior Manager',      'min_salary' => 28000000, 'max_salary' => 45000000],
    ['code' => 'JG7', 'name' => 'General Manager',     'min_salary' => 42000000, 'max_salary' => 65000000],
    ['code' => 'JG8', 'name' => 'Director',            'min_salary' => 60000000, 'max_salary' => 100000000],
    ['code' => 'JG9', 'name' => 'Direktur Utama',      'min_salary' => 90000000, 'max_salary' => 150000000],
];
$ksniGradeIds = [];
$rfacGradeIds = [];
foreach ($grades as $g) {
    $ksniGradeIds[$g['code']] = insertOrFind($db, 'job_grades',
        ['company_id' => $ksniId, 'code' => $g['code']],
        ['company_id' => $ksniId] + $g + ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now]
    );
    $rfacGradeIds[$g['code']] = insertOrFind($db, 'job_grades',
        ['company_id' => $rfacId, 'code' => $g['code']],
        ['company_id' => $rfacId] + $g + ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now]
    );
}
echo "  ✓ Job grades JG1-JG9 untuk kedua company\n";

// ──────────────────────────────────────────────────────────────────────────
// 5. Positions
// ──────────────────────────────────────────────────────────────────────────
echo "\n[5] Positions...\n";

// KSNI positions per department: nama → [department, grade, count]
$ksniPositionsSpec = [
    // Direksi
    ['Presiden Direktur',             'Direksi',              'JG9', 1],
    ['Direktur HR & GA',              'Direksi',              'JG8', 1],
    ['Direktur Finance',              'Direksi',              'JG8', 1],
    ['Direktur Operations',           'Direksi',              'JG8', 1],
    ['Direktur Sales & Marketing',    'Direksi',              'JG8', 1],
    // HR
    ['HR Manager',                    'Human Resources',      'JG5', 1],
    ['Recruitment Manager',           'Human Resources',      'JG5', 1],
    ['Training Manager',              'Human Resources',      'JG5', 1],
    ['Payroll Manager',               'Human Resources',      'JG5', 1],
    ['HR Supervisor',                 'Human Resources',      'JG3', 2],
    ['HR Officer',                    'Human Resources',      'JG2', 2],
    ['HR Staff',                      'Human Resources',      'JG1', 3],
    // Finance
    ['Finance Manager',               'Finance & Accounting', 'JG5', 1],
    ['Accounting Manager',            'Finance & Accounting', 'JG5', 1],
    ['Finance Supervisor',            'Finance & Accounting', 'JG3', 2],
    ['Accounting Staff',              'Finance & Accounting', 'JG2', 3],
    ['Cost Accountant',               'Finance & Accounting', 'JG2', 2],
    // Production
    ['Production Manager',            'Production',           'JG5', 1],
    ['Production Supervisor Wafer',   'Production',           'JG3', 2],
    ['Production Supervisor Beverage','Production',           'JG3', 1],
    ['Operator Mesin Wafer',          'Production',           'JG1', 8],
    ['Operator Beverage',             'Production',           'JG1', 4],
    // R&D
    ['R&D Manager',                   'R&D',                  'JG5', 1],
    ['Food Technologist',             'R&D',                  'JG2', 3],
    // QA
    ['QA Manager',                    'Quality Assurance',    'JG5', 1],
    ['QA Supervisor',                 'Quality Assurance',    'JG3', 2],
    ['QC Inspector',                  'Quality Assurance',    'JG1', 4],
    // Sales
    ['Sales Manager Modern Trade',    'Sales',                'JG5', 1],
    ['Sales Manager General Trade',   'Sales',                'JG5', 1],
    ['Sales Supervisor',              'Sales',                'JG3', 3],
    ['Sales Representative',          'Sales',                'JG2', 5],
    // Marketing
    ['Marketing Manager',             'Marketing',            'JG5', 1],
    ['Brand Officer',                 'Marketing',            'JG2', 2],
    // Logistics
    ['Logistics Manager',             'Logistics',            'JG5', 1],
    ['Warehouse Supervisor',          'Logistics',            'JG3', 2],
    ['Warehouse Staff',               'Logistics',            'JG1', 4],
    // Procurement
    ['Procurement Manager',           'Procurement',          'JG5', 1],
    ['Procurement Officer',           'Procurement',          'JG2', 2],
    // Engineering
    ['Maintenance Manager',           'Engineering',          'JG5', 1],
    ['Maintenance Technician',        'Engineering',          'JG2', 3],
    // IT
    ['IT Manager',                    'Information Technology','JG5', 1],
    ['System Administrator',          'Information Technology','JG2', 1],
    ['IT Support',                    'Information Technology','JG1', 1],
];

/** Position code from name (same logic as deptCode). */
function posCode(string $name): string {
    $norm = preg_replace('/[^A-Za-z0-9]+/', '_', trim($name));
    return substr(trim(strtoupper($norm), '_'), 0, 32);
}

function uniquePosCode(SQLite3 $db, int $companyId, string $base): string {
    $code = $base;
    $i = 2;
    while ($db->querySingle("SELECT id FROM positions
        WHERE company_id = $companyId AND code = '" . SQLite3::escapeString($code) . "'")) {
        $code = $base . '_' . $i;
        $i++;
        if ($i > 50) break;
    }
    return $code;
}

$ksniPositionsByName = [];
foreach ($ksniPositionsSpec as $p) {
    [$name, $deptName, $gradeCode] = $p;
    $code = uniquePosCode($db, $ksniId, posCode($name));
    $id = insertOrFind($db, 'positions', ['company_id' => $ksniId, 'name' => $name], [
        'company_id' => $ksniId, 'code' => $code, 'name' => $name,
        'department_id' => $ksniDeptIds[$deptName] ?? null,
        'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $ksniPositionsByName[$name] = ['id' => $id, 'dept' => $deptName, 'grade' => $gradeCode, 'count' => $p[3]];
}
echo "  ✓ KSNI: " . count($ksniPositionsByName) . " positions\n";

// RFAC positions
$rfacPositionsSpec = [
    ['Presiden Direktur',         'Direksi',                'JG9', 1],
    ['Direktur Operasional',      'Direksi',                'JG8', 1],
    ['Direktur HR',               'Direksi',                'JG8', 1],
    ['Direktur Finance',          'Direksi',                'JG8', 1],
    ['Operations Manager',        'Operations',             'JG5', 1],
    ['Area Manager',              'Operations',             'JG5', 2],
    ['Restaurant Manager',        'Operations',             'JG4', 4],
    ['Asst. Restaurant Manager',  'Operations',             'JG3', 4],
    ['Shift Leader',              'Operations',             'JG2', 8],
    ['Crew Member',               'Operations',             'JG1', 16],
    // Kitchen
    ['Head Chef',                 'Kitchen',                'JG4', 1],
    ['Sous Chef',                 'Kitchen',                'JG3', 2],
    ['Kitchen Crew',              'Kitchen',                'JG1', 8],
    // Service
    ['Cashier',                   'Service',                'JG1', 6],
    ['Service Crew',              'Service',                'JG1', 6],
    // HR
    ['HR Manager',                'Human Resources',        'JG5', 1],
    ['Training Officer',          'Human Resources',        'JG2', 2],
    ['HR Staff',                  'Human Resources',        'JG1', 2],
    // Finance
    ['Finance Manager',           'Finance & Accounting',   'JG5', 1],
    ['Accounting Staff',          'Finance & Accounting',   'JG2', 3],
    // Marketing
    ['Marketing Manager',         'Marketing',              'JG5', 1],
    ['Marketing Officer',         'Marketing',              'JG2', 2],
    // IT
    ['IT Manager',                'Information Technology', 'JG5', 1],
    ['IT Support',                'Information Technology', 'JG1', 1],
    // Procurement
    ['Procurement Manager',       'Procurement',            'JG5', 1],
    ['Purchasing Staff',          'Procurement',            'JG1', 1],
];
$rfacPositionsByName = [];
foreach ($rfacPositionsSpec as $p) {
    [$name, $deptName, $gradeCode] = $p;
    $code = uniquePosCode($db, $rfacId, posCode($name));
    $id = insertOrFind($db, 'positions', ['company_id' => $rfacId, 'name' => $name], [
        'company_id' => $rfacId, 'code' => $code, 'name' => $name,
        'department_id' => $rfacDeptIds[$deptName] ?? null,
        'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $rfacPositionsByName[$name] = ['id' => $id, 'dept' => $deptName, 'grade' => $gradeCode, 'count' => $p[3]];
}
echo "  ✓ RFAC: " . count($rfacPositionsByName) . " positions\n";

// ──────────────────────────────────────────────────────────────────────────
// 6. Generate employees per company
// ──────────────────────────────────────────────────────────────────────────
echo "\n[6] Employees...\n";

$nextEmpNumber = function (SQLite3 $db, int $companyId, string $prefix): callable {
    static $counters = [];
    $key = "$companyId-$prefix";
    if (! isset($counters[$key])) {
        $row = $db->querySingle(
            "SELECT employee_number FROM employees
             WHERE company_id = $companyId AND employee_number LIKE '$prefix-%'
             ORDER BY employee_number DESC LIMIT 1", true);
        $counters[$key] = $row ? (int) substr($row['employee_number'], strlen($prefix) + 1) : 0;
    }
    return function () use (&$counters, $key, $prefix) {
        $counters[$key]++;
        return $prefix . '-' . str_pad((string) $counters[$key], 4, '0', STR_PAD_LEFT);
    };
};

$lastNamePool = $lastNames;

/**
 * Insert employee. Return ['id' => ..., 'name' => ..., 'email' => ...].
 */
function makeEmployee(
    SQLite3 $db, int $companyId, int $branchId, int $deptId, int $positionId,
    int $gradeId, string $empNumber, string $emailDomain,
    array $maleNames, array $femaleNames, array $lastNames,
    array $religions, array $bloodTypes, array $banks, array $ptkpStatuses,
    array $educationLevels, int $minSalary, int $maxSalary,
    string $today, string $now, ?int $managerId = null
): array {
    $isFemale = ri(0, 1) === 1;
    $first = $isFemale ? rp($femaleNames) : rp($maleNames);
    $last = rp($lastNames);
    $full = "$first $last";

    // Cek email collision — tambah angka kalau bentrok
    $email = strtolower(str_replace(' ', '.', $first . '.' . $last)) . '@' . $emailDomain;
    $tries = 0;
    while ($db->querySingle("SELECT id FROM employees WHERE email = '" . SQLite3::escapeString($email) . "'")) {
        $tries++;
        $email = strtolower(str_replace(' ', '.', $first . '.' . $last)) . $tries . '@' . $emailDomain;
        if ($tries > 20) break;
    }

    // Random join tanggal antara 6 bulan - 8 tahun yang lalu
    $joinDate = date('Y-m-d', strtotime("-" . ri(180, 2920) . " days"));
    $dob = date('Y-m-d', strtotime("-" . ri(20*365, 50*365) . " days"));

    $insert = [
        'company_id'      => $companyId,
        'branch_id'       => $branchId,
        'department_id'   => $deptId,
        'position_id'     => $positionId,
        'job_grade_id'    => $gradeId,
        'manager_id'      => $managerId,
        'employee_number' => $empNumber,
        'first_name'      => $first,
        'last_name'       => $last,
        'full_name'       => $full,
        'gender'          => $isFemale ? 'F' : 'M',
        'religion'        => rp($religions),
        'marital_status'  => rp($ptkpStatuses),
        'place_of_birth'  => rp(['Bandung','Jakarta','Surabaya','Bekasi','Bogor','Cimahi','Cirebon','Yogyakarta','Semarang','Medan']),
        'date_of_birth'   => $dob,
        'blood_type'      => rp($bloodTypes),
        'bpjs_kesehatan_no' => fakeBpjsKes(),
        'bpjs_tk_no'      => fakeBpjsTk(),
        'bank_name'       => rp($banks),
        'bank_account_holder' => $full,
        'email'           => $email,
        'phone'           => fakePhone(),
        'address'         => 'Jl. ' . rp(['Merdeka','Sudirman','Pajajaran','Cikutra','Buah Batu','Setiabudhi','Soekarno Hatta']) . ' No. ' . ri(1, 200),
        'province'        => 'Jawa Barat',
        'city'            => rp(['Bandung','Cimahi','Bekasi']),
        'postal_code'     => (string) ri(40000, 41999),
        'emergency_contact_name'  => 'Keluarga ' . $first,
        'emergency_contact_phone' => fakePhone(),
        'emergency_contact_rel'   => $isFemale ? rp(['Suami','Orang Tua','Saudara']) : rp(['Istri','Orang Tua','Saudara']),
        'employment_status' => rp(['PKWTT','PKWTT','PKWTT','PKWT']),
        'employment_type' => 'FULL_TIME',
        'join_date'       => $joinDate,
        'is_active'       => 1,
        'basic_salary'    => ri($minSalary, $maxSalary),
        'tax_method'      => 'GROSS',
        'ptkp_status'     => rp($ptkpStatuses),
        'pph21_method'    => 'TER',
        'citizenship'     => 'WNI',
        'education_level' => rp($educationLevels),
        'created_at'      => $now,
        'updated_at'      => $now,
    ];

    $id = insertOrFind($db, 'employees', ['employee_number' => $empNumber], $insert);
    return ['id' => $id, 'name' => $full, 'email' => $email, 'first_name' => $first];
}

/**
 * Insert user account, link ke employee. Return user_id.
 */
function makeUser(SQLite3 $db, int $companyId, int $employeeId, int $roleId,
    string $name, string $email, string $now): int
{
    $existing = $db->querySingle(
        "SELECT id FROM users WHERE email = '" . SQLite3::escapeString($email) . "'", true);
    if ($existing) {
        // Update link kalau employee_id belum ter-set
        $db->exec("UPDATE users SET employee_id = $employeeId, company_id = $companyId,
            role_id = $roleId WHERE id = " . (int)$existing['id']);
        return (int) $existing['id'];
    }
    $hash = password_hash('Password123!', PASSWORD_ARGON2ID);
    $stmt = $db->prepare("INSERT INTO users
        (company_id, role_id, employee_id, email, password_hash, name, is_active,
         must_change_password, password_changed_at, created_at, updated_at)
        VALUES (:c, :r, :e, :em, :h, :n, 1, 0, :now, :now, :now)");
    $stmt->bindValue(':c', $companyId, SQLITE3_INTEGER);
    $stmt->bindValue(':r', $roleId, SQLITE3_INTEGER);
    $stmt->bindValue(':e', $employeeId, SQLITE3_INTEGER);
    $stmt->bindValue(':em', $email, SQLITE3_TEXT);
    $stmt->bindValue(':h', $hash, SQLITE3_TEXT);
    $stmt->bindValue(':n', $name, SQLITE3_TEXT);
    $stmt->bindValue(':now', $now, SQLITE3_TEXT);
    $stmt->execute();
    $userId = (int) $db->lastInsertRowID();

    // Link balik ke employee
    $db->exec("UPDATE employees SET user_id = $userId WHERE id = $employeeId");
    return $userId;
}

// Role IDs
$roleIds = [
    'super_admin' => 1, 'hr_admin' => 2, 'manager' => 3, 'employee' => 4, 'finance' => 5,
];

// === Generate untuk KSNI ===
echo "\n  Generating KSNI employees...\n";
$ksniEmpNumberFn = $nextEmpNumber($db, $ksniId, 'KSNI');

// Daftar manager yang ditrack supaya bisa di-link sebagai manager_id ke staff
$ksniDirectors = [];   // role_key → emp data
$ksniManagers = [];

// Buat semua karyawan menurut spec position (count). Branch logic:
// - Direksi/HR/Finance/IT/Marketing/R&D → Bandung HQ (BDG)
// - Sales → spread antara JKT/SBY/BDG
// - Production/QA/Engineering → CMH (pabrik)
// - Logistics → CMH atau BDG
function pickKsniBranch(string $deptName, array $branchIds): int {
    if (in_array($deptName, ['Production','Quality Assurance','Engineering'], true)) return $branchIds['CMH'];
    if ($deptName === 'Sales') return rp([$branchIds['JKT'], $branchIds['SBY'], $branchIds['BDG'], $branchIds['BDG']]);
    if ($deptName === 'Logistics') return rp([$branchIds['CMH'], $branchIds['BDG']]);
    return $branchIds['BDG'];
}

// Map role_key untuk org_structures KSNI
$ksniRoleKeyMap = [
    'Presiden Direktur'           => 'DIRECTOR',
    'Direktur HR & GA'            => 'HRD_DIRECTOR',
    'Direktur Finance'            => 'FINANCE_DIRECTOR',
    'Direktur Operations'         => 'OPERATIONS_DIRECTOR',
    'HR Manager'                  => 'HRD_MANAGER',
    'Recruitment Manager'         => 'HRD_RECRUITMENT',
    'Training Manager'            => 'HRD_TRAINING',
    'Payroll Manager'             => 'HRD_PAYROLL',
    'Finance Manager'             => 'FINANCE_MANAGER',
    'IT Manager'                  => 'IT_MANAGER',
];

$ksniRoleAssignments = [];

foreach ($ksniPositionsByName as $posName => $pos) {
    $deptName = $pos['dept'];
    $deptId = $ksniDeptIds[$deptName];
    $gradeCode = $pos['grade'];
    $gradeId = $ksniGradeIds[$gradeCode];
    $gradeRow = array_values(array_filter($grades, fn($g) => $g['code'] === $gradeCode))[0];
    $minSal = (int) $gradeRow['min_salary'];
    $maxSal = (int) $gradeRow['max_salary'];

    for ($i = 0; $i < $pos['count']; $i++) {
        $empNum = $ksniEmpNumberFn();
        $branchId = pickKsniBranch($deptName, $ksniBranchIds);

        $emp = makeEmployee(
            $db, $ksniId, $branchId, $deptId, $pos['id'], $gradeId,
            $empNum, 'nabatisnack.co.id',
            $maleNames, $femaleNames, $lastNames, $religions, $bloodTypes, $banks,
            $ptkpStatuses, $educationLevels, $minSal, $maxSal, $today, $now
        );
        $created['employees']++;

        // Buat user account untuk Direksi & Manager (semua jabatan kunci)
        $isDir = strpos($posName, 'Direktur') !== false || $posName === 'Presiden Direktur';
        $isMgr = strpos($posName, 'Manager') !== false && ! $isDir;
        if ($isDir || $isMgr) {
            $roleId = $isDir
                ? ($posName === 'Direktur HR & GA' ? $roleIds['hr_admin'] : $roleIds['manager'])
                : (in_array($posName, ['HR Manager','Recruitment Manager','Training Manager','Payroll Manager'], true)
                    ? $roleIds['hr_admin']
                    : ($posName === 'Finance Manager' ? $roleIds['finance'] : $roleIds['manager']));
            $userId = makeUser($db, $ksniId, $emp['id'], $roleId, $emp['name'], $emp['email'], $now);
            $created['users']++;

            if ($isDir) $ksniDirectors[$posName] = $emp + ['user_id' => $userId];
            else        $ksniManagers[$posName]  = $emp + ['user_id' => $userId];

            // Catat untuk org_structures assignment
            if (isset($ksniRoleKeyMap[$posName])) {
                $ksniRoleAssignments[$ksniRoleKeyMap[$posName]] = $emp['id'];
            }
        }

        // Random staff/officer juga buatkan login (40% dari mereka)
        if (! $isDir && ! $isMgr && ri(1, 100) <= 40) {
            $userId = makeUser($db, $ksniId, $emp['id'], $roleIds['employee'], $emp['name'], $emp['email'], $now);
            $created['users']++;
        }
    }
}
echo "  ✓ KSNI selesai: " . count($ksniDirectors) . " direksi + "
    . count($ksniManagers) . " manager + staff lainnya\n";

// === Generate untuk RFAC ===
echo "\n  Generating RFAC employees...\n";
$rfacEmpNumberFn = $nextEmpNumber($db, $rfacId, 'RFAC');

$rfacRoleKeyMap = [
    'Presiden Direktur'       => 'DIRECTOR',
    'Direktur HR'             => 'HRD_DIRECTOR',
    'Direktur Finance'        => 'FINANCE_DIRECTOR',
    'Direktur Operasional'    => 'OPERATIONS_DIRECTOR',
    'HR Manager'              => 'HRD_MANAGER',
    'Finance Manager'         => 'FINANCE_MANAGER',
    'IT Manager'              => 'IT_MANAGER',
    'Marketing Manager'       => 'HRD_TRAINING', // re-purpose; tidak ada "MARKETING_MGR" di seed
];

function pickRfacBranch(string $deptName, array $branchIds): int {
    if ($deptName === 'Operations' || $deptName === 'Kitchen' || $deptName === 'Service') {
        return rp([$branchIds['BDG'], $branchIds['JKT'], $branchIds['BKS'], $branchIds['SBY']]);
    }
    return $branchIds['BDG'];
}

$rfacDirectors = [];
$rfacManagers = [];
$rfacRoleAssignments = [];

foreach ($rfacPositionsByName as $posName => $pos) {
    $deptName = $pos['dept'];
    $deptId = $rfacDeptIds[$deptName];
    $gradeCode = $pos['grade'];
    $gradeId = $rfacGradeIds[$gradeCode];
    $gradeRow = array_values(array_filter($grades, fn($g) => $g['code'] === $gradeCode))[0];
    $minSal = (int) $gradeRow['min_salary'];
    $maxSal = (int) $gradeRow['max_salary'];

    for ($i = 0; $i < $pos['count']; $i++) {
        $empNum = $rfacEmpNumberFn();
        $branchId = pickRfacBranch($deptName, $rfacBranchIds);

        $emp = makeEmployee(
            $db, $rfacId, $branchId, $deptId, $pos['id'], $gradeId,
            $empNum, 'richeesefactory.com',
            $maleNames, $femaleNames, $lastNames, $religions, $bloodTypes, $banks,
            $ptkpStatuses, $educationLevels, $minSal, $maxSal, $today, $now
        );
        $created['employees']++;

        $isDir = strpos($posName, 'Direktur') !== false || $posName === 'Presiden Direktur';
        $isMgr = strpos($posName, 'Manager') !== false && ! $isDir;
        if ($isDir || $isMgr) {
            $roleId = $isDir
                ? ($posName === 'Direktur HR' ? $roleIds['hr_admin'] : $roleIds['manager'])
                : ($posName === 'HR Manager' ? $roleIds['hr_admin']
                    : ($posName === 'Finance Manager' ? $roleIds['finance'] : $roleIds['manager']));
            $userId = makeUser($db, $rfacId, $emp['id'], $roleId, $emp['name'], $emp['email'], $now);
            $created['users']++;

            if ($isDir) $rfacDirectors[$posName] = $emp + ['user_id' => $userId];
            else        $rfacManagers[$posName]  = $emp + ['user_id' => $userId];

            if (isset($rfacRoleKeyMap[$posName])) {
                $rfacRoleAssignments[$rfacRoleKeyMap[$posName]] = $emp['id'];
            }
        }

        // Cashier/Crew/Staff: buat login untuk 30%
        if (! $isDir && ! $isMgr && ri(1, 100) <= 30) {
            $userId = makeUser($db, $rfacId, $emp['id'], $roleIds['employee'], $emp['name'], $emp['email'], $now);
            $created['users']++;
        }
    }
}
echo "  ✓ RFAC selesai: " . count($rfacDirectors) . " direksi + "
    . count($rfacManagers) . " manager + staff lainnya\n";

// ──────────────────────────────────────────────────────────────────────────
// 7. Wire manager_id chain (atasan langsung) untuk staff biasa
// ──────────────────────────────────────────────────────────────────────────
echo "\n[7] Setting manager_id chain...\n";

// Semua karyawan KSNI yang non-direksi & non-manager: link ke manager dept-nya
$updateMgr = function(int $companyId, array $managers, array $deptIds) use ($db) {
    foreach ($managers as $posName => $mgr) {
        // Cari dept dari position name → mgr punya dept
        $mgrEmp = $db->querySingle(
            "SELECT department_id FROM employees WHERE id = {$mgr['id']}", true);
        if (! $mgrEmp || ! $mgrEmp['department_id']) continue;
        $deptId = (int) $mgrEmp['department_id'];

        // Update semua staff di dept yang sama yang manager_id-nya null
        $db->exec("UPDATE employees
            SET manager_id = {$mgr['id']}
            WHERE company_id = $companyId
              AND department_id = $deptId
              AND id != {$mgr['id']}
              AND manager_id IS NULL");
    }
};
$updateMgr($ksniId, $ksniManagers, $ksniDeptIds);
$updateMgr($rfacId, $rfacManagers, $rfacDeptIds);
echo "  ✓ Manager chain di-set per department\n";

// ──────────────────────────────────────────────────────────────────────────
// 8. Assign penjabat ke organization_structures
// ──────────────────────────────────────────────────────────────────────────
echo "\n[8] Assigning penjabat ke organization_structures...\n";

foreach ($ksniRoleAssignments as $roleKey => $empId) {
    $existing = $db->querySingle(
        "SELECT id FROM organization_structures
         WHERE company_id = $ksniId AND role_key = '$roleKey'", true);
    if ($existing) {
        $db->exec("UPDATE organization_structures
            SET employee_id = $empId, updated_at = '$now'
            WHERE id = " . (int)$existing['id']);
        $created['orgs_assigned']++;
    }
}
foreach ($rfacRoleAssignments as $roleKey => $empId) {
    $existing = $db->querySingle(
        "SELECT id FROM organization_structures
         WHERE company_id = $rfacId AND role_key = '$roleKey'", true);
    if ($existing) {
        $db->exec("UPDATE organization_structures
            SET employee_id = $empId, updated_at = '$now'
            WHERE id = " . (int)$existing['id']);
        $created['orgs_assigned']++;
    }
}
echo "  ✓ {$created['orgs_assigned']} role di-assign ke penjabat aktual\n";

// ──────────────────────────────────────────────────────────────────────────
// Summary
// ──────────────────────────────────────────────────────────────────────────
echo "\n──────────────────────────────────────────────\n";
echo "Seed selesai.\n";
echo "  Companies:  PT Kaldu Sari Nabati Indonesia, PT Richeese Factory\n";
echo "  Users baru: {$created['users']}\n";
echo "  Employee baru: {$created['employees']}\n";
echo "  Org roles ter-assign: {$created['orgs_assigned']}\n";

// Final count per company
echo "\nFinal count per company:\n";
$res = $db->query("
    SELECT c.id, c.code, c.name, COUNT(e.id) AS total
    FROM companies c
    LEFT JOIN employees e ON e.company_id = c.id AND e.deleted_at IS NULL
    GROUP BY c.id");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  #%d  [%-5s] %-40s %d karyawan\n",
        $r['id'], $r['code'], $r['name'], $r['total']);
}
echo "──────────────────────────────────────────────\n";
echo "Login default semua user:\n";
echo "  Password: Password123!\n";
echo "  Contoh email: <firstname>.<lastname>@nabatisnack.co.id (KSNI)\n";
echo "                 <firstname>.<lastname>@richeesefactory.com (RFAC)\n";
echo "──────────────────────────────────────────────\n";