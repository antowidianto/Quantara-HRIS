<?php
/**
 * regenerate-users-employees.php
 *
 * Bersihkan data users + employees + semua tabel transaksional yang
 * referensi mereka, lalu generate ulang dari scratch dengan struktur:
 *   - Setiap perusahaan punya Presiden Direktur + Direktur per area
 *   - Setiap departemen non-Direksi punya 1 Manager + 1-2 Supervisor + N staff
 *   - NIK 16-digit unik, di-encrypt sesuai library Encryption
 *   - Email pakai domain @test.com
 *   - Password default: Password123!
 *   - Super Admin (id=1) dipertahankan
 *
 * Idempotent? Tidak — script ini destructive. Jalankan satu kali saja.
 *
 * Usage: php scripts/regenerate-users-employees.php
 */

$dbPath  = __DIR__ . '/../writable/hris.sqlite';
$envPath = __DIR__ . '/../.env';

if (! file_exists($dbPath)) {
    fwrite(STDERR, "Database not found: $dbPath\n");
    exit(1);
}

$db = new SQLite3($dbPath);
$db->enableExceptions(true);

$now    = date('Y-m-d H:i:s');
$today  = date('Y-m-d');
$year   = (int) date('Y');

// ──────────────────────────────────────────────────────────────────────────
// Encryption helpers — mirror App\Libraries\Encryption (v1, no version prefix)
// ──────────────────────────────────────────────────────────────────────────
function loadEncryptionKey(string $envPath): string
{
    $raw = '';
    if (file_exists($envPath)) {
        $contents = file_get_contents($envPath);
        if (preg_match('/^\s*encryption\.key\s*=\s*[\'"]?([^\'"\r\n#]+?)[\'"]?\s*(?:#.*)?$/m', $contents, $m)) {
            $raw = trim($m[1]);
        }
    }

    if ($raw === '') {
        return hash('sha256', 'hris_dev_no_key', true);
    }
    if (str_starts_with($raw, 'base64:')) {
        $decoded = base64_decode(substr($raw, 7), true);
        if ($decoded !== false && strlen($decoded) >= 32) {
            return $decoded;
        }
    }
    if (strlen($raw) >= 32) {
        return $raw;
    }
    return hash('sha256', $raw . '_hris_pad', true);
}

function encryptValue(?string $plain, string $key): ?string
{
    if ($plain === null || $plain === '') return null;
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        throw new RuntimeException('openssl_encrypt failed');
    }
    // v1 ciphertext: no prefix
    return base64_encode($iv . $tag . $ct);
}

function nikHash(string $plain): string
{
    return hash('sha256', $plain . '_hris_lookup');
}

$encKey = loadEncryptionKey($envPath);
echo "Encryption key loaded (" . strlen($encKey) . " bytes).\n";

// ──────────────────────────────────────────────────────────────────────────
// Stage A: Cleanup
// ──────────────────────────────────────────────────────────────────────────
echo "\n[A] Cleaning up transactional + employee/user data...\n";

$db->exec('PRAGMA foreign_keys = OFF');
$db->exec('BEGIN');

// Tables that reference users or employees — clear them all.
// Order doesn't matter karena FK off, tapi grouping per domain.
$cleanup = [
    // Auth & sessions
    'refresh_tokens', 'password_resets', 'login_attempts',

    // Notifications & email
    'notifications', 'email_queue', 'user_notification_preferences',

    // Audit
    'audit_logs',

    // Attendance & related
    'attendance_corrections', 'attendance_change_requests', 'attendances',
    'shift_schedules', 'shift_assignments', 'shift_pattern_assignments',

    // Leave / overtime / reimbursement / loan
    'leaves', 'leave_balances', 'overtimes', 'reimbursements', 'loans',

    // Payroll
    'payslip_details', 'payslips',

    // Approval / delegation
    'approval_decisions', 'approval_requests', 'approval_assignments',
    'approval_delegations',

    // Employee related
    'employee_contracts', 'employee_documents', 'employee_families',
    'employee_educations', 'employee_payroll_components',
    'employee_allowance_assignments', 'employee_tasks', 'employee_work_locations',

    // Assets
    'asset_assignments',

    // Business trip / disciplinary / severance
    'business_trip_expenses', 'business_trips',
    'disciplinary_records', 'severance_calculations',

    // Recruitment & career
    'candidate_documents', 'candidate_stages', 'candidates',
    'application_track_tokens',
    'manpower_request_approvals', 'manpower_request_postings', 'manpower_requests',
    'generated_contracts',
    'job_information_changes', 'resignation_requests',

    // Helpdesk
    'hr_ticket_comments', 'hr_tickets',

    // Training & performance
    'certificates', 'training_registrations',
    'goal_check_ins', 'goals', 'performance_reviews',
];

foreach ($cleanup as $table) {
    // Skip kalau tabel tidak ada (SQLite tolerates this if we check first)
    $exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name = '$table'");
    if (! $exists) continue;
    $db->exec("DELETE FROM \"$table\"");
}

// Reset references in master tables
$db->exec("UPDATE departments SET manager_employee_id = NULL");
$db->exec("UPDATE organization_structures SET employee_id = NULL, updated_at = '$now'");

// Hapus users kecuali super admin (id=1)
$db->exec("DELETE FROM users WHERE id != 1");

// Hapus semua employees
$db->exec("DELETE FROM employees");

// Reset autoincrement counter supaya id mulai dari 1 lagi
$db->exec("DELETE FROM sqlite_sequence WHERE name IN ('employees','users')");

$db->exec('COMMIT');
$db->exec('PRAGMA foreign_keys = ON');

echo "  Cleanup complete.\n";
echo "  - users remaining: " . $db->querySingle("SELECT COUNT(*) FROM users") . " (super admin only)\n";
echo "  - employees remaining: " . $db->querySingle("SELECT COUNT(*) FROM employees") . "\n";

// ──────────────────────────────────────────────────────────────────────────
// Stage B: Data sources
// ──────────────────────────────────────────────────────────────────────────
$maleNames = [
    'Ahmad','Budi','Cahyo','Dedi','Eko','Fajar','Galih','Hadi','Iwan','Joko',
    'Kurnia','Lukman','Mahmud','Nanang','Oki','Pandu','Rian','Sigit',
    'Taufik','Umar','Wahyu','Yusuf','Zainal','Agus','Bayu','Dimas','Ervan',
    'Faisal','Gilang','Heri','Irfan','Khoirul','Maulana','Nugroho','Prasetyo','Reza',
    'Surya','Teguh','Andre','Bambang','Dani','Hendra','Indra','Ridwan','Septian',
    'Tio','Wisnu','Yoga','Adit','Bagas','Candra','Doni','Edo','Ferdi','Gunawan',
];
$femaleNames = [
    'Aisyah','Bella','Citra','Dewi','Erna','Fitri','Gita','Hana','Indah','Juwita',
    'Kartika','Lia','Maya','Nia','Oktavia','Putri','Rina','Siti','Tari',
    'Umi','Vera','Wati','Yuni','Zahra','Amelia','Cinta','Dini','Eka','Fauziah',
    'Hesti','Intan','Karina','Lutfia','Melati','Nurul','Olivia','Permata',
    'Ratna','Selvi','Tiara','Vania','Yanti','Anisa','Devi','Erika','Farah',
    'Hilda','Irma','Jihan','Kiki','Laras','Mira','Novi','Ovi','Pia','Qori',
];
$lastNames = [
    'Pratama','Wijaya','Saputra','Hidayat','Setiawan','Susanto','Hartono','Kusuma',
    'Nugroho','Permana','Lestari','Wulandari','Maulida','Hakim','Iskandar',
    'Mulyana','Suryana','Wibowo','Sugiarto','Santoso','Cahyono','Prasetya',
    'Pamungkas','Anggraini','Rahmawati','Sari',
];

$religions  = ['Islam','Kristen','Katolik','Hindu','Budha'];
$bloodTypes = ['A','B','AB','O'];
$banks      = ['BCA','Mandiri','BNI','BRI','CIMB Niaga','Permata','Danamon'];
$ptkpStatuses = ['TK0','TK1','TK2','K0','K1','K2','K3'];
$educationLevels = ['SMK','SMA','D3','S1','S2'];
$cities     = ['Bandung','Cimahi','Jakarta','Bekasi','Tangerang','Surabaya','Bogor','Depok'];

function rp(array $arr) { return $arr[array_rand($arr)]; }
function ri(int $a, int $b): int { return mt_rand($a, $b); }

function fakePhone(): string {
    $prefixes = ['0811','0812','0813','0821','0822','0852','0853','0856','0857','0878'];
    return rp($prefixes) . str_pad((string) ri(1000000, 9999999), 7, '0', STR_PAD_LEFT);
}
function fakeBpjsKes(): string { return '00' . str_pad((string) ri(1, 99999999999), 11, '0', STR_PAD_LEFT); }
function fakeBpjsTk(): string  { return str_pad((string) ri(1, 99999999999), 11, '0', STR_PAD_LEFT); }

/**
 * Generate NIK Indonesia 16 digit format: PPKKKK + DDMMYY + NNNN.
 *   - PPKKKK = kode wilayah (kabupaten/kota) — pakai 320101 (Bandung) untuk semua
 *     supaya seragam dan konsisten dengan format demo.
 *   - DDMMYY = tanggal lahir (untuk perempuan: dd + 40)
 *   - NNNN   = nomor urut sequential (kombinasi seq + companyId)
 */
function generateNik(string $dob, string $gender, int $sequence): string {
    $ts = strtotime($dob);
    $dd = (int) date('d', $ts);
    $mm = date('m', $ts);
    $yy = date('y', $ts);
    if ($gender === 'F') $dd += 40; // Konvensi NIK perempuan
    $ddStr = str_pad((string) $dd, 2, '0', STR_PAD_LEFT);
    $seq   = str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    return "320101{$ddStr}{$mm}{$yy}{$seq}";
}

// ──────────────────────────────────────────────────────────────────────────
// Stage C: Spec generation per company
// ──────────────────────────────────────────────────────────────────────────

/**
 * Cari position id untuk dept tertentu yang namanya match pattern.
 * Return null kalau tidak ada match.
 */
function findPosition(SQLite3 $db, int $companyId, int $deptId, array $namePatterns): ?int {
    foreach ($namePatterns as $pattern) {
        $row = $db->querySingle(
            "SELECT id FROM positions
             WHERE company_id = $companyId AND department_id = $deptId
               AND name LIKE '%" . SQLite3::escapeString($pattern) . "%'
             ORDER BY id LIMIT 1", true);
        if ($row && isset($row['id'])) return (int) $row['id'];
    }
    return null;
}

/** Ambil semua position di dept (untuk staff random pick). */
function deptPositions(SQLite3 $db, int $companyId, int $deptId, array $excludeKeywords = []): array {
    $rows = $db->query(
        "SELECT id, name FROM positions
         WHERE company_id = $companyId AND department_id = $deptId
         ORDER BY id");
    $out = [];
    while ($r = $rows->fetchArray(SQLITE3_ASSOC)) {
        $skip = false;
        foreach ($excludeKeywords as $kw) {
            if (stripos($r['name'], $kw) !== false) { $skip = true; break; }
        }
        if (! $skip) $out[] = $r;
    }
    return $out;
}

/** Cari job_grade berdasarkan code. */
function gradeId(SQLite3 $db, int $companyId, string $code): ?int {
    $row = $db->querySingle(
        "SELECT id FROM job_grades
         WHERE company_id = $companyId AND code = '" . SQLite3::escapeString($code) . "' LIMIT 1", true);
    return $row ? (int) $row['id'] : null;
}

/** Cari department by name, return id. */
function deptId(SQLite3 $db, int $companyId, string $name): ?int {
    $row = $db->querySingle(
        "SELECT id FROM departments
         WHERE company_id = $companyId AND name = '" . SQLite3::escapeString($name) . "' LIMIT 1", true);
    return $row ? (int) $row['id'] : null;
}

/** Pilih branch HQ default + branches operasional. */
function listBranches(SQLite3 $db, int $companyId): array {
    $rows = $db->query("SELECT id, code, name FROM branches WHERE company_id = $companyId ORDER BY id");
    $arr = [];
    while ($r = $rows->fetchArray(SQLITE3_ASSOC)) $arr[] = $r;
    return $arr;
}

/**
 * Spec per perusahaan: list department + jumlah staff per dept.
 * Direksi handled secara terpisah (1 Presdir + N Direktur).
 */
$companySpecs = [
    1 => [ // KSNI
        'code'   => 'KSNI',
        'name'   => 'PT Kaldu Sari Nabati Indonesia',
        'directors' => [
            ['title' => 'Presiden Direktur',           'role' => 'manager',  'pos_keywords' => ['Presiden Direktur']],
            ['title' => 'Direktur HR & GA',            'role' => 'hr_admin', 'pos_keywords' => ['Direktur HR']],
            ['title' => 'Direktur Finance',            'role' => 'finance',  'pos_keywords' => ['Direktur Finance']],
            ['title' => 'Direktur Operations',         'role' => 'manager',  'pos_keywords' => ['Direktur Operations','Direktur Operasional']],
            ['title' => 'Direktur Sales & Marketing',  'role' => 'manager',  'pos_keywords' => ['Direktur Sales']],
        ],
        'departments' => [
            // dept_name             => [supervisor_count, staff_count]
            'Human Resources'        => [2, 6],
            'Finance & Accounting'   => [2, 6],
            'Information Technology' => [1, 4],
            'Marketing'              => [1, 4],
            'Production'             => [2, 10],
            'R&D'                    => [1, 4],
            'Quality Assurance'      => [2, 6],
            'Sales'                  => [2, 8],
            'Logistics'              => [1, 5],
            'Procurement'            => [1, 3],
            'Engineering'            => [1, 4],
            'Operations'             => [1, 4],
        ],
    ],
    2 => [ // RFAC
        'code'   => 'RFAC',
        'name'   => 'PT Richeese Factory',
        'directors' => [
            ['title' => 'Presiden Direktur',     'role' => 'manager',  'pos_keywords' => ['Presiden Direktur']],
            ['title' => 'Direktur Operasional',  'role' => 'manager',  'pos_keywords' => ['Direktur Operasional','Direktur Operations']],
            ['title' => 'Direktur HR',           'role' => 'hr_admin', 'pos_keywords' => ['Direktur HR']],
            ['title' => 'Direktur Finance',      'role' => 'finance',  'pos_keywords' => ['Direktur Finance']],
        ],
        'departments' => [
            'Operations'             => [2, 8],
            'Kitchen'                => [2, 6],
            'Service'                => [1, 6],
            'Marketing'              => [1, 3],
            'Finance & Accounting'   => [1, 4],
            'Human Resources'        => [1, 4],
            'Information Technology' => [0, 2],
            'Procurement'            => [0, 2],
        ],
    ],
];

// Role IDs (assumed exist; lihat InitialSeeder)
$roleIds = [
    'super_admin' => 1, 'hr_admin' => 2, 'manager' => 3, 'employee' => 4, 'finance' => 5,
];

// ──────────────────────────────────────────────────────────────────────────
// Stage D: Generate
// ──────────────────────────────────────────────────────────────────────────
echo "\n[B] Generating fresh users + employees...\n";

$globalSeq = 0;
$emailSeq  = []; // track collisions per base
$usedNiks  = []; // unique NIK guard
$created   = ['employees' => 0, 'users' => 0];
$passwordPlain = 'Password123!';
$passwordHash  = password_hash($passwordPlain, PASSWORD_ARGON2ID);

/**
 * Generate unique email pakai domain test.com.
 */
function buildEmail(string $first, string $last, array &$emailSeq): string {
    $base = strtolower($first . '.' . $last);
    $base = preg_replace('/[^a-z0-9]+/', '.', $base);
    $base = trim($base, '.');
    if (! isset($emailSeq[$base])) {
        $emailSeq[$base] = 0;
        return "$base@test.com";
    }
    $emailSeq[$base]++;
    return "$base{$emailSeq[$base]}@test.com";
}

/**
 * Insert employee row + opsional user row. Return [employee_id, user_id|null, name, email].
 */
function createPerson(
    SQLite3 $db, string $encKey, string $now, string $today, int $companyId,
    int $branchId, int $deptId, int $positionId, int $gradeId,
    string $employeeNumber, string $roleKey, array $roleIds,
    array $maleNames, array $femaleNames, array $lastNames,
    array $religions, array $bloodTypes, array $banks, array $ptkpStatuses,
    array $educationLevels, array $cities,
    int $minSalary, int $maxSalary,
    array &$emailSeq, array &$usedNiks, int &$globalSeq, string $passwordHash,
    bool $createUser = true, ?int $managerId = null
): array {
    $isFemale = ri(0, 1) === 1;
    $first    = $isFemale ? rp($femaleNames) : rp($maleNames);
    $last     = rp($lastNames);
    $full     = "$first $last";
    $gender   = $isFemale ? 'F' : 'M';

    // Random join 1-8 tahun lalu
    $joinDate = date('Y-m-d', strtotime('-' . ri(180, 2920) . ' days'));
    $dob      = date('Y-m-d', strtotime('-' . ri(22*365, 50*365) . ' days'));

    // NIK unik
    $globalSeq++;
    do {
        $nikSeq = ri(1, 9999);
        $nik    = generateNik($dob, $gender, $nikSeq + $globalSeq);
    } while (isset($usedNiks[$nik]));
    $usedNiks[$nik] = true;

    $nikEnc  = encryptValue($nik, $encKey);
    $nikHash = nikHash($nik);

    $email = buildEmail($first, $last, $emailSeq);

    $ptkp  = rp($ptkpStatuses);
    $bank  = rp($banks);
    $bankAcc = (string) ri(1000000000, 9999999999);
    $bankAccEnc = encryptValue($bankAcc, $encKey);

    // Insert employee
    $stmt = $db->prepare("
        INSERT INTO employees
            (company_id, branch_id, department_id, position_id, job_grade_id, manager_id,
             employee_number, first_name, last_name, full_name, gender, religion,
             marital_status, place_of_birth, date_of_birth, blood_type,
             nik_encrypted, nik_hash, bpjs_kesehatan_no, bpjs_tk_no,
             bank_name, bank_account_encrypted, bank_account_holder,
             email, phone, address, province, city, postal_code,
             emergency_contact_name, emergency_contact_phone, emergency_contact_rel,
             employment_status, employment_type, join_date, is_active,
             basic_salary, tax_method, ptkp_status, pph21_method,
             citizenship, education_level, key_version,
             created_at, updated_at)
        VALUES
            (:company_id, :branch_id, :department_id, :position_id, :job_grade_id, :manager_id,
             :employee_number, :first_name, :last_name, :full_name, :gender, :religion,
             :marital_status, :place_of_birth, :date_of_birth, :blood_type,
             :nik_encrypted, :nik_hash, :bpjs_kesehatan_no, :bpjs_tk_no,
             :bank_name, :bank_account_encrypted, :bank_account_holder,
             :email, :phone, :address, :province, :city, :postal_code,
             :emergency_contact_name, :emergency_contact_phone, :emergency_contact_rel,
             :employment_status, :employment_type, :join_date, 1,
             :basic_salary, 'GROSS', :ptkp_status, 'TER',
             'WNI', :education_level, 1,
             :now, :now)
    ");
    $stmt->bindValue(':company_id',      $companyId, SQLITE3_INTEGER);
    $stmt->bindValue(':branch_id',       $branchId, SQLITE3_INTEGER);
    $stmt->bindValue(':department_id',   $deptId, SQLITE3_INTEGER);
    $stmt->bindValue(':position_id',     $positionId, SQLITE3_INTEGER);
    $stmt->bindValue(':job_grade_id',    $gradeId, SQLITE3_INTEGER);
    if ($managerId !== null) {
        $stmt->bindValue(':manager_id', $managerId, SQLITE3_INTEGER);
    } else {
        $stmt->bindValue(':manager_id', null, SQLITE3_NULL);
    }
    $stmt->bindValue(':employee_number', $employeeNumber, SQLITE3_TEXT);
    $stmt->bindValue(':first_name',      $first, SQLITE3_TEXT);
    $stmt->bindValue(':last_name',       $last, SQLITE3_TEXT);
    $stmt->bindValue(':full_name',       $full, SQLITE3_TEXT);
    $stmt->bindValue(':gender',          $gender, SQLITE3_TEXT);
    $stmt->bindValue(':religion',        rp($religions), SQLITE3_TEXT);
    $stmt->bindValue(':marital_status',  $ptkp, SQLITE3_TEXT);
    $stmt->bindValue(':place_of_birth',  rp($cities), SQLITE3_TEXT);
    $stmt->bindValue(':date_of_birth',   $dob, SQLITE3_TEXT);
    $stmt->bindValue(':blood_type',      rp($bloodTypes), SQLITE3_TEXT);
    $stmt->bindValue(':nik_encrypted',   $nikEnc, SQLITE3_TEXT);
    $stmt->bindValue(':nik_hash',        $nikHash, SQLITE3_TEXT);
    $stmt->bindValue(':bpjs_kesehatan_no', fakeBpjsKes(), SQLITE3_TEXT);
    $stmt->bindValue(':bpjs_tk_no',      fakeBpjsTk(), SQLITE3_TEXT);
    $stmt->bindValue(':bank_name',       $bank, SQLITE3_TEXT);
    $stmt->bindValue(':bank_account_encrypted', $bankAccEnc, SQLITE3_TEXT);
    $stmt->bindValue(':bank_account_holder',    $full, SQLITE3_TEXT);
    $stmt->bindValue(':email',           $email, SQLITE3_TEXT);
    $stmt->bindValue(':phone',           fakePhone(), SQLITE3_TEXT);
    $stmt->bindValue(':address',         'Jl. ' . rp(['Merdeka','Sudirman','Pajajaran','Cikutra','Buah Batu','Setiabudhi','Soekarno Hatta']) . ' No. ' . ri(1, 200), SQLITE3_TEXT);
    $stmt->bindValue(':province',        'Jawa Barat', SQLITE3_TEXT);
    $stmt->bindValue(':city',            rp($cities), SQLITE3_TEXT);
    $stmt->bindValue(':postal_code',     (string) ri(40000, 41999), SQLITE3_TEXT);
    $stmt->bindValue(':emergency_contact_name',  'Keluarga ' . $first, SQLITE3_TEXT);
    $stmt->bindValue(':emergency_contact_phone', fakePhone(), SQLITE3_TEXT);
    $stmt->bindValue(':emergency_contact_rel',   rp(['Suami','Istri','Orang Tua','Saudara']), SQLITE3_TEXT);
    $stmt->bindValue(':employment_status', 'PKWTT', SQLITE3_TEXT);
    $stmt->bindValue(':employment_type',   'FULL_TIME', SQLITE3_TEXT);
    $stmt->bindValue(':join_date',         $joinDate, SQLITE3_TEXT);
    $stmt->bindValue(':basic_salary',      ri($minSalary, $maxSalary), SQLITE3_INTEGER);
    $stmt->bindValue(':ptkp_status',       $ptkp, SQLITE3_TEXT);
    $stmt->bindValue(':education_level',   rp($educationLevels), SQLITE3_TEXT);
    $stmt->bindValue(':now',               $now, SQLITE3_TEXT);
    $stmt->execute();
    $employeeId = (int) $db->lastInsertRowID();

    $userId = null;
    if ($createUser) {
        $stmt = $db->prepare("
            INSERT INTO users
                (company_id, role_id, employee_id, email, password_hash, name,
                 is_active, must_change_password, password_changed_at, created_at, updated_at)
            VALUES
                (:c, :r, :e, :em, :h, :n, 1, 0, :now, :now, :now)
        ");
        $stmt->bindValue(':c',   $companyId, SQLITE3_INTEGER);
        $stmt->bindValue(':r',   $roleIds[$roleKey] ?? $roleIds['employee'], SQLITE3_INTEGER);
        $stmt->bindValue(':e',   $employeeId, SQLITE3_INTEGER);
        $stmt->bindValue(':em',  $email, SQLITE3_TEXT);
        $stmt->bindValue(':h',   $GLOBALS['passwordHash'], SQLITE3_TEXT);
        $stmt->bindValue(':n',   $full, SQLITE3_TEXT);
        $stmt->bindValue(':now', $now, SQLITE3_TEXT);
        $stmt->execute();
        $userId = (int) $db->lastInsertRowID();

        $db->exec("UPDATE employees SET user_id = $userId WHERE id = $employeeId");
    }

    return [
        'employee_id' => $employeeId,
        'user_id'     => $userId,
        'name'        => $full,
        'email'       => $email,
        'first_name'  => $first,
    ];
}

// Org structure role_key map per company. Keys harus match yang
// di-seed via OrganizationStructureService kalau sudah ada.
$orgRoleMap = [
    1 => [
        'Presiden Direktur'           => 'DIRECTOR',
        'Direktur HR & GA'            => 'HRD_DIRECTOR',
        'Direktur Finance'            => 'FINANCE_DIRECTOR',
        'Direktur Operations'         => 'OPERATIONS_DIRECTOR',
        // HR / Finance / IT manager assignments di bawah di loop dept
    ],
    2 => [
        'Presiden Direktur'           => 'DIRECTOR',
        'Direktur HR'                 => 'HRD_DIRECTOR',
        'Direktur Finance'            => 'FINANCE_DIRECTOR',
        'Direktur Operasional'        => 'OPERATIONS_DIRECTOR',
    ],
];

$deptOrgRoleMap = [
    'Human Resources'        => 'HRD_MANAGER',
    'Finance & Accounting'   => 'FINANCE_MANAGER',
    'Information Technology' => 'IT_MANAGER',
];

$orgAssignments = []; // [companyId][role_key] => employee_id

foreach ($companySpecs as $companyId => $spec) {
    echo "\n  >>> Company #{$companyId}: {$spec['code']} - {$spec['name']}\n";

    $branches = listBranches($db, $companyId);
    if (empty($branches)) {
        echo "  WARN: company $companyId tidak punya branch, skip.\n";
        continue;
    }
    $hqBranchId = $branches[0]['id'];

    $direksiDeptId = deptId($db, $companyId, 'Direksi');

    // Job grade IDs
    $gradeOperator   = gradeId($db, $companyId, 'JG1') ?? gradeId($db, $companyId, 'JG2');
    $gradeStaff      = gradeId($db, $companyId, 'JG2') ?? $gradeOperator;
    $gradeSupervisor = gradeId($db, $companyId, 'JG3') ?? $gradeStaff;
    $gradeManager    = gradeId($db, $companyId, 'JG5') ?? gradeId($db, $companyId, 'JG4');
    $gradeDirector   = gradeId($db, $companyId, 'JG8') ?? gradeId($db, $companyId, 'JG7');
    $gradePresdir    = gradeId($db, $companyId, 'JG9') ?? $gradeDirector;

    $empSeq = 0;

    // ─── Direksi ──────────────────────────────────────────────────
    if ($direksiDeptId !== null) {
        echo "    [Direksi]\n";
        foreach ($spec['directors'] as $dir) {
            $empSeq++;
            $empNumber = sprintf('%s-%04d', $spec['code'], $empSeq);

            $posId = findPosition($db, $companyId, $direksiDeptId, $dir['pos_keywords']);
            if (! $posId) {
                echo "      WARN: position untuk '{$dir['title']}' tidak ditemukan\n";
                continue;
            }

            $isPresdir = stripos($dir['title'], 'Presiden') !== false;
            $gradeId = $isPresdir ? $gradePresdir : $gradeDirector;
            $minSal  = $isPresdir ? 90000000 : 60000000;
            $maxSal  = $isPresdir ? 150000000 : 100000000;

            $person = createPerson(
                $db, $encKey, $now, $today, $companyId, $hqBranchId,
                $direksiDeptId, $posId, $gradeId,
                $empNumber, $dir['role'], $roleIds,
                $maleNames, $femaleNames, $lastNames,
                $religions, $bloodTypes, $banks, $ptkpStatuses,
                $educationLevels, $cities,
                $minSal, $maxSal,
                $emailSeq, $usedNiks, $globalSeq, $passwordHash,
                true, null
            );
            $created['employees']++;
            if ($person['user_id']) $created['users']++;
            echo "      ✓ {$dir['title']}: {$person['name']} <{$person['email']}>\n";

            // Map ke org_structures
            if (isset($orgRoleMap[$companyId][$dir['title']])) {
                $orgAssignments[$companyId][$orgRoleMap[$companyId][$dir['title']]] = $person['employee_id'];
            }
        }
    }

    // ─── Per dept: 1 manager + supervisors + staff ────────────────
    foreach ($spec['departments'] as $deptName => [$supCount, $staffCount]) {
        $depId = deptId($db, $companyId, $deptName);
        if (! $depId) {
            echo "    WARN: dept '$deptName' tidak ada, skip.\n";
            continue;
        }
        echo "    [$deptName]\n";

        // Pilih branch — non-HQ untuk dept operasional
        $branchId = $hqBranchId;
        if (in_array($deptName, ['Production','Quality Assurance','Engineering','Logistics'], true) && count($branches) > 1) {
            // Bandung pabrik (CMH) untuk KSNI
            foreach ($branches as $b) {
                if (stripos($b['code'], 'CMH') !== false || stripos($b['name'], 'Pabrik') !== false) {
                    $branchId = $b['id']; break;
                }
            }
        }
        if ($deptName === 'Operations' && $companyId === 2) {
            // RFAC outlet — distribusi
            $branchId = $branches[array_rand($branches)]['id'];
        }

        // ── Manager ──
        $mgrPosId = findPosition($db, $companyId, $depId, ['Manager']);
        if (! $mgrPosId) {
            // Fallback: ambil position pertama di dept
            $positions = deptPositions($db, $companyId, $depId);
            if (empty($positions)) {
                echo "      WARN: dept '$deptName' tidak punya position, skip.\n";
                continue;
            }
            $mgrPosId = $positions[0]['id'];
        }

        // Tentukan role manager
        $mgrRole = 'manager';
        if (stripos($deptName, 'Human Resources') !== false) $mgrRole = 'hr_admin';
        if (stripos($deptName, 'Finance') !== false)         $mgrRole = 'finance';

        $empSeq++;
        $empNumber = sprintf('%s-%04d', $spec['code'], $empSeq);
        $manager = createPerson(
            $db, $encKey, $now, $today, $companyId, $branchId,
            $depId, $mgrPosId, $gradeManager,
            $empNumber, $mgrRole, $roleIds,
            $maleNames, $femaleNames, $lastNames,
            $religions, $bloodTypes, $banks, $ptkpStatuses,
            $educationLevels, $cities,
            18000000, 30000000,
            $emailSeq, $usedNiks, $globalSeq, $passwordHash,
            true, null
        );
        $created['employees']++;
        $created['users']++;
        echo "      ✓ Manager: {$manager['name']} <{$manager['email']}>\n";

        // Set department.manager_employee_id
        $db->exec("UPDATE departments SET manager_employee_id = {$manager['employee_id']} WHERE id = $depId");

        // Map ke org_structures kalau sesuai
        if (isset($deptOrgRoleMap[$deptName])) {
            $orgAssignments[$companyId][$deptOrgRoleMap[$deptName]] = $manager['employee_id'];
        }

        // ── Supervisor ──
        $supPosId = findPosition($db, $companyId, $depId, ['Supervisor','Asst','Asisten','Shift Leader','Sous','Senior']);
        if (! $supPosId) {
            // Fallback: pakai posisi staff biasa untuk supervisor juga
            $positions = deptPositions($db, $companyId, $depId, ['Manager','Director','Direktur','Presiden']);
            $supPosId = $positions[0]['id'] ?? $mgrPosId;
        }
        for ($i = 0; $i < $supCount; $i++) {
            $empSeq++;
            $empNumber = sprintf('%s-%04d', $spec['code'], $empSeq);
            $sup = createPerson(
                $db, $encKey, $now, $today, $companyId, $branchId,
                $depId, $supPosId, $gradeSupervisor,
                $empNumber, 'employee', $roleIds,
                $maleNames, $femaleNames, $lastNames,
                $religions, $bloodTypes, $banks, $ptkpStatuses,
                $educationLevels, $cities,
                9000000, 14000000,
                $emailSeq, $usedNiks, $globalSeq, $passwordHash,
                true, $manager['employee_id']
            );
            $created['employees']++;
            $created['users']++;
            echo "        ✓ Supervisor: {$sup['name']} <{$sup['email']}>\n";
        }

        // ── Staff ──
        $staffPositions = deptPositions($db, $companyId, $depId, ['Manager','Director','Direktur','Presiden','Supervisor','Asst','Asisten','Sous','Head Chef','Senior']);
        if (empty($staffPositions)) {
            // Fallback: pakai posisi non-manager apa pun
            $staffPositions = deptPositions($db, $companyId, $depId, ['Manager','Director','Direktur','Presiden']);
            if (empty($staffPositions)) $staffPositions = [['id' => $mgrPosId, 'name' => 'Staff']];
        }

        for ($i = 0; $i < $staffCount; $i++) {
            $pos = $staffPositions[array_rand($staffPositions)];
            $empSeq++;
            $empNumber = sprintf('%s-%04d', $spec['code'], $empSeq);
            $staff = createPerson(
                $db, $encKey, $now, $today, $companyId, $branchId,
                $depId, $pos['id'], $gradeStaff,
                $empNumber, 'employee', $roleIds,
                $maleNames, $femaleNames, $lastNames,
                $religions, $bloodTypes, $banks, $ptkpStatuses,
                $educationLevels, $cities,
                4500000, 8000000,
                $emailSeq, $usedNiks, $globalSeq, $passwordHash,
                true, $manager['employee_id']
            );
            $created['employees']++;
            $created['users']++;
        }
        echo "        ✓ {$staffCount} staff added\n";
    }
}

// ──────────────────────────────────────────────────────────────────────────
// Stage E: Wire org_structures
// ──────────────────────────────────────────────────────────────────────────
echo "\n[C] Wiring organization_structures...\n";

$orgsAssigned = 0;
foreach ($orgAssignments as $companyId => $roles) {
    foreach ($roles as $roleKey => $empId) {
        $row = $db->querySingle(
            "SELECT id FROM organization_structures
             WHERE company_id = $companyId AND role_key = '" . SQLite3::escapeString($roleKey) . "' LIMIT 1",
            true);
        if ($row) {
            $db->exec("UPDATE organization_structures
                SET employee_id = $empId, updated_at = '$now'
                WHERE id = " . (int) $row['id']);
            $orgsAssigned++;
        }
    }
}
echo "  ✓ $orgsAssigned org_structures roles assigned\n";

// ──────────────────────────────────────────────────────────────────────────
// Summary
// ──────────────────────────────────────────────────────────────────────────
echo "\n──────────────────────────────────────────────\n";
echo "Regenerate selesai.\n";
echo "  Employees baru: {$created['employees']}\n";
echo "  Users baru:     {$created['users']}\n";
echo "  Org roles assigned: $orgsAssigned\n";
echo "──────────────────────────────────────────────\n";

// Stats per company
$res = $db->query("
    SELECT c.id, c.code, c.name,
        (SELECT COUNT(*) FROM employees e WHERE e.company_id = c.id) AS emp_count,
        (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS user_count
    FROM companies c ORDER BY c.id");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  #%d  [%-5s] %-40s emp=%d, user=%d\n",
        $r['id'], $r['code'], $r['name'], $r['emp_count'], $r['user_count']);
}

echo "\nLogin defaults:\n";
echo "  Email format:  firstname.lastname@test.com\n";
echo "  Password:      $passwordPlain\n";
echo "  Super Admin:   admin@hris.local / Admin@12345 (unchanged)\n";
echo "──────────────────────────────────────────────\n";