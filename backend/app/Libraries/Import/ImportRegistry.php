<?php

namespace App\Libraries\Import;

/**
 * ImportRegistry — daftar resource yang bisa di-bulk-import dari xlsx.
 *
 * Kenapa registry, bukan controller per-resource? Pola controller per resource
 * akan bikin duplikasi besar (parse, validate, commit, audit, error report).
 * Dengan registry, semua resource share infrastruktur yang sama dan tinggal
 * deklarasikan **schema-nya**:
 *
 *   - permission_slug (siapa yang boleh import)
 *   - table          (tabel target insert)
 *   - columns        (definisi kolom: header xlsx, db field, type, required, unique, fk)
 *   - sample_row     (1 baris contoh untuk template)
 *   - notes_md       (instruksi singkat di sheet "Petunjuk")
 *
 * Validasi rules per kolom:
 *   - type: string|int|date|enum|fk|email|phone
 *   - required: bool
 *   - max_length: int
 *   - regex: /pattern/
 *   - in: ['NILAI1', 'NILAI2']           (untuk enum)
 *   - fk_table + fk_column + fk_label    (untuk lookup, mis. department_id dari departments.code)
 *   - unique_in_table: bool              (cek duplikat di DB tabel target)
 *   - unique_in_batch: bool              (cek duplikat antar baris di file ini sendiri)
 *
 * Catatan penting:
 *   - Kolom FK biasanya pakai LABEL (mis. "Department Code") yang akan di-resolve
 *     ke ID lewat lookup. User HR lebih nyaman ngetik "ENGINEERING" daripada
 *     hafal ID.
 *   - Header xlsx case-insensitive, leading/trailing space di-trim.
 */
class ImportRegistry
{
    /**
     * @return array<string, array> Resource specs keyed by slug
     */
    public static function all(): array
    {
        return [
            // Batch 1 (Migration #067) — master organisasi inti
            'departments' => self::departments(),
            'branches'    => self::branches(),
            'job_titles'  => self::jobTitles(),
            'job_grades'  => self::jobGrades(),
            'positions'   => self::positions(),
            'employees'   => self::employees(),

            // Batch 2 (Migration #068) — master HR/payroll yang sering
            // dibutuhkan saat onboarding awal / migrasi data.
            'allowance_types'         => self::allowanceTypes(),
            'payroll_components'      => self::payrollComponents(),
            'leave_types'             => self::leaveTypes(),
            'assets'                  => self::assets(),
            'cost_centers'            => self::costCenters(),
            'organization_structures' => self::organizationStructures(),
            'work_locations'          => self::workLocations(),
            'shifts'                  => self::shifts(),

            // Batch 3 (Migration #069) — transactional data: saldo cuti,
            // roster bulanan, attendance backfill.
            'leave_balances'   => self::leaveBalances(),
            'shift_schedules'  => self::shiftSchedules(),
            'attendances'      => self::attendances(),

            // Batch 4 (Migration #070) — bonus recipients bulk upload.
            'bonus_recipients' => self::bonusRecipients(),

            // Batch 5 (Migration #071) — loan, goals, asset (tombol di view).
            'loans' => self::loans(),
            'goals' => self::goals(),
        ];
    }

    public static function find(string $slug): ?array
    {
        $all = self::all();
        return $all[$slug] ?? null;
    }

    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    // ────────────────────────────────────────────────────────────────
    // Resource definitions
    // ────────────────────────────────────────────────────────────────

    private static function departments(): array
    {
        return [
            'slug'        => 'departments',
            'label'       => 'Master Department',
            'permission'  => 'departments.bulk_import',
            'table'       => 'departments',
            'columns'     => [
                ['header' => 'Code',        'field' => 'code',        'type' => 'string', 'required' => true, 'max_length' => 32, 'regex' => '/^[A-Za-z0-9_\-]+$/', 'unique_in_table' => true, 'unique_in_batch' => true, 'help' => 'Kode unik per perusahaan, alfanumerik (A-Z, 0-9, _ -). Mis. ENG, MKT, FIN.'],
                ['header' => 'Name',        'field' => 'name',        'type' => 'string', 'required' => true, 'max_length' => 200, 'help' => 'Nama lengkap department.'],
                ['header' => 'Description', 'field' => 'description', 'type' => 'string', 'max_length' => 500],
                ['header' => 'Parent Code', 'field' => 'parent_id',   'type' => 'fk',     'fk_table' => 'departments', 'fk_column' => 'code', 'help' => 'Kosongkan kalau department top-level. Isi Code parent.'],
                ['header' => 'Active',      'field' => 'is_active',   'type' => 'bool',   'default' => 1, 'help' => '1 = aktif, 0 = non-aktif. Default 1 kalau kosong.'],
            ],
            'sample_row'  => [
                ['ENG',  'Engineering',     'Tim engineering & product', '',    '1'],
                ['ENG-BE','Backend Squad',  'Tim backend developer',     'ENG', '1'],
            ],
            'notes_md'    => "Setup awal department & sub-department perusahaan. Parent Code opsional — kosongkan untuk top-level. Nilai unique by Code per company.",
        ];
    }

    private static function branches(): array
    {
        return [
            'slug'        => 'branches',
            'label'       => 'Master Cabang',
            'permission'  => 'branches.bulk_import',
            'table'       => 'branches',
            'columns'     => [
                ['header' => 'Code',     'field' => 'code',     'type' => 'string', 'required' => true, 'max_length' => 32, 'regex' => '/^[A-Za-z0-9_\-]+$/', 'unique_in_table' => true, 'unique_in_batch' => true],
                ['header' => 'Name',     'field' => 'name',     'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Address',  'field' => 'address',  'type' => 'string', 'max_length' => 500],
                ['header' => 'Province', 'field' => 'province', 'type' => 'string', 'max_length' => 100],
                ['header' => 'City',     'field' => 'city',     'type' => 'string', 'max_length' => 100],
                ['header' => 'Phone',    'field' => 'phone',    'type' => 'string', 'max_length' => 32],
                ['header' => 'Latitude',          'field' => 'lat',                'type' => 'float', 'help' => 'Koordinat untuk geofencing absensi (opsional).'],
                ['header' => 'Longitude',         'field' => 'lng',                'type' => 'float'],
                ['header' => 'Geofence Radius (m)','field' => 'geofence_radius_m', 'type' => 'int',   'default' => 100, 'help' => 'Radius geofence absensi dalam meter.'],
                ['header' => 'Active',   'field' => 'is_active','type' => 'bool',   'default' => 1],
            ],
            'sample_row'  => [
                ['HQ',     'Head Office Jakarta', 'Jl. Sudirman No. 1', 'DKI Jakarta', 'Jakarta Pusat', '021-1234567', '-6.2088', '106.8456', '100', '1'],
                ['BDG-01', 'Cabang Bandung',      'Jl. Asia Afrika 100','Jawa Barat',  'Bandung',       '022-1234567', '-6.9175', '107.6191', '150', '1'],
            ],
            'notes_md'    => "Master cabang/lokasi kerja. Lat/Lng untuk geofencing absensi GPS — kosongkan kalau tidak pakai radius check.",
        ];
    }

    private static function jobTitles(): array
    {
        return [
            'slug'       => 'job_titles',
            'label'      => 'Master Jabatan',
            'permission' => 'job_titles.bulk_import',
            'table'      => 'job_titles',
            'columns'    => [
                ['header' => 'Code',        'field' => 'code',        'type' => 'string', 'required' => true, 'max_length' => 32, 'regex' => '/^[A-Za-z0-9_\-]+$/', 'unique_in_table' => true, 'unique_in_batch' => true],
                ['header' => 'Name',        'field' => 'name',        'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Family',      'field' => 'family',      'type' => 'string', 'max_length' => 100, 'help' => 'Job family: Engineering, Sales, Marketing, Finance, dst.'],
                ['header' => 'Level',       'field' => 'level',       'type' => 'enum',   'in' => ['JUNIOR','MID','SENIOR','LEAD','MANAGER','DIRECTOR','EXECUTIVE'], 'default' => 'MID'],
                ['header' => 'Description', 'field' => 'description', 'type' => 'string', 'max_length' => 500],
                ['header' => 'Active',      'field' => 'is_active',   'type' => 'bool',   'default' => 1],
            ],
            'sample_row' => [
                ['SE-JR',  'Software Engineer Junior',  'Engineering', 'JUNIOR',  '0-2 thn pengalaman', '1'],
                ['SE-SR',  'Senior Software Engineer',  'Engineering', 'SENIOR',  '5+ thn, technical leader', '1'],
                ['HRBP',   'HR Business Partner',       'HR',          'MANAGER', '', '1'],
            ],
            'notes_md'   => "Standar nama jabatan terstandarisasi untuk laporan compa-ratio, salary benchmark, dan WLKP. Lihat docs/JOB_TITLES.md.",
        ];
    }

    private static function jobGrades(): array
    {
        return [
            'slug'       => 'job_grades',
            'label'      => 'Master Job Grade',
            'permission' => 'job_grades.bulk_import',
            'table'      => 'job_grades',
            'columns'    => [
                ['header' => 'Code',          'field' => 'code',          'type' => 'string', 'required' => true, 'max_length' => 32, 'regex' => '/^[A-Za-z0-9_\-]+$/', 'unique_in_table' => true, 'unique_in_batch' => true],
                ['header' => 'Name',          'field' => 'name',          'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Level',         'field' => 'level',         'type' => 'int',    'required' => true, 'help' => 'Bilangan bulat. Makin tinggi level, makin senior (1=staff, 10=BOD).'],
                ['header' => 'Min Salary',    'field' => 'min_salary',    'type' => 'float',  'help' => 'Range gaji bulanan minimum (Rupiah).'],
                ['header' => 'Max Salary',    'field' => 'max_salary',    'type' => 'float',  'help' => 'Range gaji bulanan maksimum (Rupiah).'],
                ['header' => 'Active',        'field' => 'is_active',     'type' => 'bool',   'default' => 1],
            ],
            'sample_row' => [
                ['JRS', 'Junior Staff',    '2',  '4500000',  '6500000',  '1'],
                ['SRS', 'Senior Staff',    '4',  '8000000',  '12000000', '1'],
                ['MGR', 'Manager',         '7',  '22000000', '38000000', '1'],
            ],
            'notes_md'   => "Master grading kepegawaian. Min-Max salary dipakai compensation intel & compa-ratio per posisi. Level adalah bilangan bulat (makin tinggi makin senior).",
        ];
    }

    private static function positions(): array
    {
        return [
            'slug'       => 'positions',
            'label'      => 'Master Posisi',
            'permission' => 'positions.bulk_import',
            'table'      => 'positions',
            'columns'    => [
                ['header' => 'Code',            'field' => 'code',          'type' => 'string', 'required' => true, 'max_length' => 32, 'regex' => '/^[A-Za-z0-9_\-]+$/', 'unique_in_table' => true, 'unique_in_batch' => true],
                ['header' => 'Name',            'field' => 'name',          'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Department Code', 'field' => 'department_id', 'type' => 'fk',     'required' => true, 'fk_table' => 'departments', 'fk_column' => 'code', 'help' => 'Wajib. Department code yang sudah ada di master department.'],
                ['header' => 'Job Title Code',  'field' => 'job_title_id',  'type' => 'fk',     'fk_table' => 'job_titles', 'fk_column' => 'code', 'help' => 'Opsional. Master jabatan terstandarisasi.'],
                ['header' => 'Job Grade Code',  'field' => 'job_grade_id',  'type' => 'fk',     'fk_table' => 'job_grades', 'fk_column' => 'code'],
                ['header' => 'Tier',            'field' => 'tier',          'type' => 'enum',   'in' => ['MANAGER','ASST_MANAGER','SUPERVISOR','STAFF']],
                ['header' => 'Description',     'field' => 'description',   'type' => 'string', 'max_length' => 500],
                ['header' => 'Active',          'field' => 'is_active',     'type' => 'bool',   'default' => 1],
            ],
            'sample_row' => [
                ['BE-ENG-01', 'Backend Engineer - Platform', 'ENG-BE', 'SE-JR',  'G2', 'STAFF',     'Kontribusi ke API platform', '1'],
                ['HRBP-01',   'HR Business Partner',          'HR',     'HRBP',   'G5', 'MANAGER',   '',                            '1'],
            ],
            'notes_md'   => "Slot/role kerja di department tertentu. FK ke department wajib, ke job_title & job_grade opsional. Department, Job Title, dan Job Grade harus sudah ada di master sebelum import.",
        ];
    }

    private static function employees(): array
    {
        return [
            'slug'       => 'employees',
            'label'      => 'Master Karyawan',
            'permission' => 'employees.bulk_import',
            'table'      => 'employees',
            'columns'    => [
                ['header' => 'Employee Number',  'field' => 'employee_number',  'type' => 'string', 'required' => true, 'max_length' => 32, 'unique_in_table' => true, 'unique_in_batch' => true, 'help' => 'NIP/NIK perusahaan.'],
                ['header' => 'First Name',       'field' => 'first_name',       'type' => 'string', 'required' => true, 'max_length' => 100],
                ['header' => 'Last Name',        'field' => 'last_name',        'type' => 'string', 'max_length' => 100],
                ['header' => 'Email',            'field' => 'email',            'type' => 'email',  'max_length' => 200, 'help' => 'Email kerja, harus unik.'],
                ['header' => 'Phone',            'field' => 'phone',            'type' => 'phone',  'max_length' => 32],
                ['header' => 'Gender',           'field' => 'gender',           'type' => 'enum',   'in' => ['MALE','FEMALE']],
                ['header' => 'Date of Birth',    'field' => 'date_of_birth',    'type' => 'date',   'help' => 'Format: YYYY-MM-DD atau DD/MM/YYYY.'],
                ['header' => 'Marital Status',   'field' => 'marital_status',   'type' => 'enum',   'in' => ['SINGLE','MARRIED','DIVORCED','WIDOWED']],
                ['header' => 'Religion',         'field' => 'religion',         'type' => 'enum',   'in' => ['ISLAM','KRISTEN','KATOLIK','HINDU','BUDDHA','KONGHUCU','OTHER']],
                ['header' => 'Branch Code',      'field' => 'branch_id',        'type' => 'fk',     'fk_table' => 'branches',    'fk_column' => 'code'],
                ['header' => 'Department Code',  'field' => 'department_id',    'type' => 'fk',     'fk_table' => 'departments', 'fk_column' => 'code'],
                ['header' => 'Position Code',    'field' => 'position_id',      'type' => 'fk',     'fk_table' => 'positions',   'fk_column' => 'code'],
                ['header' => 'Job Grade Code',   'field' => 'job_grade_id',     'type' => 'fk',     'fk_table' => 'job_grades',  'fk_column' => 'code'],
                ['header' => 'Employment Type',  'field' => 'employment_type',  'type' => 'enum',   'in' => ['PKWT','PKWTT','MAGANG','OUTSOURCING','PARTTIME','CONTRACT'], 'default' => 'PKWTT'],
                ['header' => 'Join Date',        'field' => 'join_date',        'type' => 'date',   'required' => true, 'help' => 'Format: YYYY-MM-DD atau DD/MM/YYYY.'],
                ['header' => 'Probation End',    'field' => 'probation_end_date','type' => 'date',  'help' => 'Tanggal selesai probation. Kosongkan kalau tidak ada probation.'],
                ['header' => 'Contract End',     'field' => 'contract_end_date','type' => 'date',   'help' => 'Khusus PKWT/MAGANG. Kosongkan untuk PKWTT.'],
                ['header' => 'Basic Salary',     'field' => 'basic_salary',     'type' => 'float',  'help' => 'Gaji pokok bulanan (Rupiah).'],
                ['header' => 'Fixed Allowance',  'field' => 'fixed_allowance',  'type' => 'float',  'default' => 0],
                ['header' => 'NPWP',             'field' => 'npwp_encrypted',   'type' => 'encrypted', 'max_length' => 32, 'help' => 'Akan di-enkripsi otomatis di DB.'],
                ['header' => 'PTKP Status',      'field' => 'ptkp_status',      'type' => 'enum',   'in' => ['TK0','TK1','TK2','TK3','K0','K1','K2','K3'], 'default' => 'TK0', 'help' => 'TK = Tidak Kawin (TK0/1/2/3 berdasar tanggungan), K = Kawin (K0/1/2/3).'],
                ['header' => 'Bank Name',        'field' => 'bank_name',        'type' => 'string', 'max_length' => 100],
                ['header' => 'Bank Account',     'field' => 'bank_account_encrypted', 'type' => 'encrypted', 'max_length' => 64, 'help' => 'Akan di-enkripsi otomatis.'],
                ['header' => 'Bank Account Holder','field' => 'bank_account_holder', 'type' => 'string', 'max_length' => 200],
                ['header' => 'BPJS Kesehatan',   'field' => 'bpjs_kesehatan_no','type' => 'string', 'max_length' => 32],
                ['header' => 'BPJS TK',          'field' => 'bpjs_tk_no',       'type' => 'string', 'max_length' => 32],
                ['header' => 'Active',           'field' => 'is_active',        'type' => 'bool',   'default' => 1],
            ],
            'sample_row' => [
                ['EMP001', 'Budi', 'Santoso', 'budi@example.com', '081234567890', 'MALE', '1990-05-15', 'MARRIED', 'ISLAM', 'HQ', 'ENG-BE', 'BE-ENG-01', 'G2', 'PKWTT', '2024-01-15', '2024-04-15', '', '8000000', '1500000', '12.345.678.9-012.000', 'K1', 'BCA', '1234567890', 'BUDI SANTOSO', '0001234567890', '0009876543210', '1'],
                ['EMP002', 'Siti', 'Rahmawati','siti@example.com','081298765432', 'FEMALE','1992-08-20', 'SINGLE',  'ISLAM','BDG-01','HR',     'HRBP-01',  'G5', 'PKWTT','2023-03-01','',           '',           '15000000','3000000', '23.456.789.0-123.000','TK0','BCA','9876543210', 'SITI RAHMAWATI','0001122334455','0005544332211','1'],
            ],
            'notes_md'   => "Onboarding awal saat go-live. Pastikan Master Department, Branch, Position, Job Grade SUDAH di-import duluan. NPWP & Bank Account otomatis di-enkripsi backend. Format tanggal YYYY-MM-DD lebih disarankan.",
        ];
    }

    // ────────────────────────────────────────────────────────────────
    // Batch 2 (Migration #068) — Master HR/payroll tambahan
    // ────────────────────────────────────────────────────────────────

    private static function allowanceTypes(): array
    {
        return [
            'slug'       => 'allowance_types',
            'label'      => 'Master Tunjangan',
            'permission' => 'allowance_types.bulk_import',
            'table'      => 'allowance_types',
            'columns'    => [
                ['header' => 'Code',        'field' => 'code',        'type' => 'string', 'required' => true, 'max_length' => 32, 'regex' => '/^[A-Za-z0-9_\-]+$/', 'unique_in_table' => true, 'unique_in_batch' => true],
                ['header' => 'Name',        'field' => 'name',        'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Description', 'field' => 'description', 'type' => 'string', 'max_length' => 500],
                ['header' => 'Category',    'field' => 'category',    'type' => 'enum',   'required' => true,
                 'in' => ['BRANCH','TIER','PLAN','MILEAGE','TRAINING_OOR','TRANSPORT','MEAL','HOUSING','TELECOM','OTHER'],
                 'help' => 'Kategori tunjangan. BRANCH=tunjangan cabang, TIER=tier-based, MILEAGE=tunjangan jarak, dll.'],
                ['header' => 'Is Taxable',  'field' => 'is_taxable',  'type' => 'bool',   'default' => 1, 'help' => '1 = kena PPh21, 0 = non-taxable.'],
                ['header' => 'Is Distance Based', 'field' => 'is_distance_based', 'type' => 'bool', 'default' => 0],
                ['header' => 'Default Threshold KM', 'field' => 'default_threshold_km', 'type' => 'float', 'default' => 0],
                ['header' => 'Active',      'field' => 'is_active',   'type' => 'bool',   'default' => 1],
            ],
            'sample_row' => [
                ['MEAL',     'Tunjangan Makan',     'Per hari kerja',          'MEAL',      '1', '0', '0', '1'],
                ['TRANSPORT','Tunjangan Transport', 'Per hari kerja',          'TRANSPORT', '1', '0', '0', '1'],
                ['MILEAGE',  'Tunjangan Mileage',   'Threshold 10 km',         'MILEAGE',   '1', '1', '10', '1'],
            ],
            'notes_md'   => "Master jenis tunjangan. Tunjangan akan di-link ke karyawan via `employee_allowances` (assignment) — yang itu di-set lewat UI / API biasa, bukan import.",
        ];
    }

    private static function payrollComponents(): array
    {
        return [
            'slug'       => 'payroll_components',
            'label'      => 'Master Komponen Payroll',
            'permission' => 'payroll_components.bulk_import',
            'table'      => 'payroll_components',
            'columns'    => [
                ['header' => 'Code',     'field' => 'code',     'type' => 'string', 'required' => true, 'max_length' => 32, 'regex' => '/^[A-Za-z0-9_\-]+$/', 'unique_in_table' => true, 'unique_in_batch' => true],
                ['header' => 'Name',     'field' => 'name',     'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Type',     'field' => 'type',     'type' => 'enum',   'required' => true,
                 'in' => ['EARNING','DEDUCTION'], 'help' => 'EARNING = penambah gaji, DEDUCTION = pengurang.'],
                ['header' => 'Category', 'field' => 'category', 'type' => 'enum',   'required' => true,
                 'in' => ['BASIC','ALLOWANCE','BONUS','OVERTIME','TAX','BPJS','LOAN','OTHER'],
                 'help' => 'Group untuk reporting payroll.'],
                ['header' => 'Is Taxable', 'field' => 'is_taxable', 'type' => 'bool', 'default' => 1],
                ['header' => 'Is Fixed',   'field' => 'is_fixed',   'type' => 'bool', 'default' => 1, 'help' => '1 = nominal tetap, 0 = computed via formula.'],
                ['header' => 'Amount',     'field' => 'amount',     'type' => 'float', 'default' => 0],
                ['header' => 'Formula',    'field' => 'formula',    'type' => 'string', 'max_length' => 1000, 'help' => 'Formula expression. Diisi kalau Is Fixed = 0.'],
                ['header' => 'Active',     'field' => 'is_active',  'type' => 'bool',   'default' => 1],
            ],
            'sample_row' => [
                ['BASIC',     'Gaji Pokok',         'EARNING',   'BASIC',     '1', '1', '0',       '',  '1'],
                ['MEAL_ALLOW','Tunjangan Makan',    'EARNING',   'ALLOWANCE', '1', '1', '500000',  '',  '1'],
                ['LOAN_DED',  'Potongan Pinjaman',  'DEDUCTION', 'LOAN',      '0', '1', '0',       '',  '1'],
            ],
            'notes_md'   => "Komponen payroll: earnings (gaji pokok, tunjangan, lembur, bonus) dan deductions (PPh21, BPJS, pinjaman). Formula dipakai PayrollService saat hitung slip gaji.",
        ];
    }

    private static function leaveTypes(): array
    {
        return [
            'slug'       => 'leave_types',
            'label'      => 'Master Jenis Cuti',
            'permission' => 'leave_types.bulk_import',
            'table'      => 'leave_types',
            'columns'    => [
                ['header' => 'Code',          'field' => 'code',          'type' => 'string', 'required' => true, 'max_length' => 32, 'regex' => '/^[A-Za-z0-9_\-]+$/', 'unique_in_table' => true, 'unique_in_batch' => true],
                ['header' => 'Name',          'field' => 'name',          'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Category',      'field' => 'category',      'type' => 'enum',   'required' => true,
                 'in' => ['ANNUAL','SICK','MATERNITY','PATERNITY','MARRIAGE','BEREAVEMENT','UNPAID','OTHER']],
                ['header' => 'Default Days',  'field' => 'default_days',  'type' => 'int',    'required' => true, 'help' => 'Jumlah hari default per tahun. Mis. ANNUAL=12, SICK=12.'],
                ['header' => 'Max Days',      'field' => 'max_days',      'type' => 'int',    'help' => 'Batas maksimum (opsional).'],
                ['header' => 'Is Paid',       'field' => 'is_paid',       'type' => 'bool',   'default' => 1],
                ['header' => 'Requires Doc',  'field' => 'requires_doc',  'type' => 'bool',   'default' => 0, 'help' => '1 = wajib upload bukti (mis. surat dokter).'],
                ['header' => 'Min Tenure (months)','field' => 'min_tenure_months', 'type' => 'int', 'default' => 0],
                ['header' => 'Reset Yearly',  'field' => 'reset_yearly',  'type' => 'bool',   'default' => 1],
                ['header' => 'Gender Restriction', 'field' => 'gender_restriction', 'type' => 'enum',
                 'in' => ['NONE','MALE','FEMALE'], 'default' => 'NONE',
                 'help' => 'MALE untuk PATERNITY, FEMALE untuk MATERNITY.'],
                ['header' => 'Is Prorated',          'field' => 'is_prorated',          'type' => 'bool', 'default' => 1],
                ['header' => 'Is Carry Over Allowed','field' => 'is_carry_over_allowed','type' => 'bool', 'default' => 0],
                ['header' => 'Carry Over Max Days',  'field' => 'carry_over_max_days',  'type' => 'float','default' => 0],
                ['header' => 'Carry Over Expires (months)','field' => 'carry_over_expires_in_months','type' => 'int', 'default' => 12],
                ['header' => 'Carry Over Basis',     'field' => 'carry_over_basis',     'type' => 'enum',
                 'in' => ['YEAR_END','ANNIVERSARY'], 'default' => 'YEAR_END'],
                ['header' => 'Description',   'field' => 'description',   'type' => 'string', 'max_length' => 500],
                ['header' => 'Active',        'field' => 'is_active',     'type' => 'bool',   'default' => 1],
            ],
            'sample_row' => [
                ['ANNUAL',   'Cuti Tahunan',           'ANNUAL',    '12', '',   '1', '0', '12', '1', 'NONE',   '1', '1', '6', '12', 'ANNIVERSARY', 'UU 13/2003 Pasal 79', '1'],
                ['SICK',     'Cuti Sakit',             'SICK',      '12', '',   '1', '1', '0',  '1', 'NONE',   '0', '0', '0', '0',  'YEAR_END',    'Wajib surat dokter > 1 hari', '1'],
                ['MATERNITY','Cuti Melahirkan',        'MATERNITY', '90', '',   '1', '1', '0',  '1', 'FEMALE', '0', '0', '0', '0',  'YEAR_END',    'UU 4/2024: 3 bulan + tambahan kondisi khusus hingga 3 bulan; jangan hard-limit 90 hari', '1'],
            ],
            'notes_md'   => "Master jenis cuti. Carry-over basis menentukan kapan saldo di-reset (akhir tahun vs. anniversary join). Lihat docs/LEAVE_BALANCE.md.",
        ];
    }

    private static function assets(): array
    {
        return [
            'slug'       => 'assets',
            'label'      => 'Master Asset',
            'permission' => 'assets.bulk_import',
            'table'      => 'assets',
            'columns'    => [
                ['header' => 'Asset Number',  'field' => 'asset_number',  'type' => 'string', 'required' => true, 'max_length' => 50, 'unique_in_table' => true, 'unique_in_batch' => true],
                ['header' => 'Category',      'field' => 'category',      'type' => 'string', 'required' => true, 'max_length' => 50, 'help' => 'LAPTOP, DESKTOP, PHONE, VEHICLE, FURNITURE, ID_CARD, ACCESS_CARD, TOOL, OTHER.'],
                ['header' => 'Name',          'field' => 'name',          'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Brand',         'field' => 'brand',         'type' => 'string', 'max_length' => 100],
                ['header' => 'Model',         'field' => 'model',         'type' => 'string', 'max_length' => 100],
                ['header' => 'Serial Number', 'field' => 'serial_number', 'type' => 'string', 'max_length' => 100],
                ['header' => 'Description',   'field' => 'description',   'type' => 'string', 'max_length' => 500],
                ['header' => 'Purchase Date', 'field' => 'purchase_date', 'type' => 'date'],
                ['header' => 'Purchase Price','field' => 'purchase_price','type' => 'float',  'default' => 0],
                ['header' => 'Purchase Invoice','field' => 'purchase_invoice','type' => 'string', 'max_length' => 100],
                ['header' => 'Condition',     'field' => 'condition',     'type' => 'enum',   'required' => true,
                 'in' => ['NEW','GOOD','FAIR','POOR','DAMAGED','LOST'], 'default' => 'GOOD'],
                ['header' => 'Status',        'field' => 'status',        'type' => 'enum',   'required' => true,
                 'in' => ['AVAILABLE','ASSIGNED','MAINTENANCE','RETIRED','LOST'], 'default' => 'AVAILABLE'],
                ['header' => 'Branch Code',   'field' => 'branch_id',     'type' => 'fk',     'fk_table' => 'branches', 'fk_column' => 'code'],
                ['header' => 'Notes',         'field' => 'notes',         'type' => 'string', 'max_length' => 500],
            ],
            'sample_row' => [
                ['IT-LAP-001', 'LAPTOP',  'ThinkPad T14',     'Lenovo',  'T14 Gen 3', 'PF3ABC123', 'i7 16GB',     '2024-01-15', '18000000', 'INV-2024-001', 'NEW',  'AVAILABLE', 'HQ',     ''],
                ['IT-MON-002', 'DESKTOP', 'Monitor 27 inch',  'Dell',    'U2722DE',   'CN-D2722', 'Untuk WFO',    '2024-02-10', '5500000',  'INV-2024-008', 'GOOD', 'AVAILABLE', 'HQ',     ''],
                ['VEH-CAR-001','VEHICLE', 'Toyota Avanza',    'Toyota',  'Avanza G',  'B-1234-XX', 'Mobil ops',   '2023-06-01', '220000000','INV-2023-099', 'GOOD', 'AVAILABLE', 'BDG-01', ''],
            ],
            'notes_md'   => "Master inventory asset perusahaan. Asset di-assign ke karyawan via `asset_assignments` (proses biasa via UI), bukan import.",
        ];
    }

    private static function costCenters(): array
    {
        return [
            'slug'       => 'cost_centers',
            'label'      => 'Master Cost Center',
            'permission' => 'cost_centers.bulk_import',
            'table'      => 'cost_centers',
            'columns'    => [
                ['header' => 'Code',          'field' => 'code',          'type' => 'string', 'required' => true, 'max_length' => 32, 'regex' => '/^[A-Za-z0-9_\-\.]+$/', 'unique_in_table' => true, 'unique_in_batch' => true],
                ['header' => 'Name',          'field' => 'name',          'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Description',   'field' => 'description',   'type' => 'string', 'max_length' => 500],
                ['header' => 'Parent Code',   'field' => 'parent_id',     'type' => 'fk',     'fk_table' => 'cost_centers', 'fk_column' => 'code', 'help' => 'Kosongkan untuk top-level. Cost center bersifat hierarki.'],
                ['header' => 'Default Branch Code',     'field' => 'default_branch_id',     'type' => 'fk', 'fk_table' => 'branches',    'fk_column' => 'code'],
                ['header' => 'Default Department Code', 'field' => 'default_department_id', 'type' => 'fk', 'fk_table' => 'departments', 'fk_column' => 'code'],
                ['header' => 'Budget Yearly', 'field' => 'budget_yearly', 'type' => 'float',  'default' => 0, 'help' => 'Budget tahunan (Rp). Detail per period di-set lewat UI Cost Center.'],
                ['header' => 'Sort Order',    'field' => 'sort_order',    'type' => 'int',    'default' => 0],
                ['header' => 'Active',        'field' => 'is_active',     'type' => 'bool',   'default' => 1],
            ],
            'sample_row' => [
                ['CC-HQ',     'HQ Operations',     'Biaya operasional HQ',     '',       'HQ',     '',       '500000000', '10', '1'],
                ['CC-HQ.ENG', 'Engineering',       'Biaya tim engineering',    'CC-HQ',  'HQ',     'ENG',    '350000000', '20', '1'],
                ['CC-BDG',    'Branch Bandung',    'Biaya cabang Bandung',     '',       'BDG-01', '',       '200000000', '30', '1'],
            ],
            'notes_md'   => "Cost center adalah dimensi accounting untuk track biaya per bisnis unit / branch / departemen. Hierarki via Parent Code. Lihat docs Sprint 6.",
        ];
    }

    private static function organizationStructures(): array
    {
        return [
            'slug'       => 'organization_structures',
            'label'      => 'Master Jabatan Kunci (Org Roles)',
            'permission' => 'organization_structures.bulk_import',
            'table'      => 'organization_structures',
            'columns'    => [
                ['header' => 'Role Key',   'field' => 'role_key',   'type' => 'string', 'required' => true, 'max_length' => 64, 'regex' => '/^[A-Z0-9_]+$/', 'unique_in_table' => true, 'unique_in_batch' => true,
                 'help' => 'UPPERCASE_SNAKE_CASE. Mis. CEO, HR_DIRECTOR, FINANCE_MANAGER. Dipakai approval lookup.'],
                ['header' => 'Title',      'field' => 'title',      'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Level',      'field' => 'level',      'type' => 'enum',   'required' => true,
                 'in' => ['BOD','DIRECTOR','GM','MANAGER','SUPERVISOR','STAFF','OTHER']],
                ['header' => 'Description','field' => 'description','type' => 'string', 'max_length' => 500],
                ['header' => 'Department Code','field' => 'department_id','type' => 'fk', 'fk_table' => 'departments', 'fk_column' => 'code'],
                ['header' => 'Branch Code','field' => 'branch_id',  'type' => 'fk',     'fk_table' => 'branches',    'fk_column' => 'code'],
                ['header' => 'Parent Role Key','field' => 'parent_role_id','type' => 'fk','fk_table' => 'organization_structures', 'fk_column' => 'role_key',
                 'help' => 'Role atasan (untuk organogram). Kosongkan kalau top-level.'],
                ['header' => 'Is Key Role','field' => 'is_key_role','type' => 'bool',   'default' => 1, 'help' => '1 = role ini wajib ada penjabat (impact ke approval lookup).'],
                ['header' => 'Sort Order', 'field' => 'sort_order', 'type' => 'int',    'default' => 0],
                ['header' => 'Active',     'field' => 'is_active',  'type' => 'bool',   'default' => 1],
            ],
            'sample_row' => [
                ['CEO',          'Chief Executive Officer',    'BOD',      'Top management', '',     '',     '',          '1', '10',  '1'],
                ['HR_DIRECTOR',  'HR Director',                'DIRECTOR', '',               '',     '',     'CEO',       '1', '20',  '1'],
                ['HRD_MANAGER',  'HRD Manager',                'MANAGER',  'Daily HR ops',   'HR',   '',     'HR_DIRECTOR','1', '30',  '1'],
            ],
            'notes_md'   => "Master Jabatan Kunci untuk lookup approver MPP / JIC / approval chain. Penjabat (employee_id) di-assign terpisah lewat UI Org Roles, bukan via import.",
        ];
    }

    private static function workLocations(): array
    {
        return [
            'slug'       => 'work_locations',
            'label'      => 'Master Lokasi Kerja',
            'permission' => 'work_locations.bulk_import',
            'table'      => 'work_locations',
            'columns'    => [
                ['header' => 'Code',        'field' => 'code',        'type' => 'string', 'required' => true, 'max_length' => 32, 'regex' => '/^[A-Za-z0-9_\-\.]+$/', 'unique_in_table' => true, 'unique_in_batch' => true],
                ['header' => 'Name',        'field' => 'name',        'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Branch Code', 'field' => 'branch_id',   'type' => 'fk',     'fk_table' => 'branches', 'fk_column' => 'code', 'help' => 'Cabang yang menaungi lokasi ini.'],
                ['header' => 'Type',        'field' => 'type',        'type' => 'enum',   'required' => true,
                 'in' => ['OFFICE','WAREHOUSE','FACTORY','SITE','REMOTE','CLIENT','OTHER']],
                ['header' => 'Address',     'field' => 'address',     'type' => 'string', 'max_length' => 500],
                ['header' => 'Province',    'field' => 'province',    'type' => 'string', 'max_length' => 100],
                ['header' => 'City',        'field' => 'city',        'type' => 'string', 'max_length' => 100],
                ['header' => 'Postal Code', 'field' => 'postal_code', 'type' => 'string', 'max_length' => 10],
                ['header' => 'Phone',       'field' => 'phone',       'type' => 'string', 'max_length' => 32],
                ['header' => 'Latitude',    'field' => 'lat',         'type' => 'float'],
                ['header' => 'Longitude',   'field' => 'lng',         'type' => 'float'],
                ['header' => 'Geofence Radius (m)','field' => 'geofence_radius_m', 'type' => 'int',  'default' => 100],
                ['header' => 'Allow Clock Here',   'field' => 'allow_clock_here',  'type' => 'bool', 'default' => 1, 'help' => '1 = boleh clock-in/out di sini.'],
                ['header' => 'Notes',       'field' => 'notes',       'type' => 'string', 'max_length' => 500],
                ['header' => 'Active',      'field' => 'is_active',   'type' => 'bool',   'default' => 1],
            ],
            'sample_row' => [
                ['HQ.LT3',  'HQ Lantai 3 - Engineering', 'HQ',     'OFFICE',  'Jl. Sudirman No. 1 Lt. 3', 'DKI Jakarta', 'Jakarta Pusat', '10220', '021-1234567', '-6.2088', '106.8456', '50',  '1', '', '1'],
                ['BDG.WH1', 'Gudang Bandung Utama',      'BDG-01', 'WAREHOUSE','Jl. Soekarno Hatta 99',  'Jawa Barat',  'Bandung',       '40258', '022-7654321', '-6.9220', '107.6500', '100', '1', '', '1'],
            ],
            'notes_md'   => "Lokasi kerja granular di dalam cabang. Geofencing absensi berlaku per work location kalau di-set, fallback ke branch.",
        ];
    }

    private static function shifts(): array
    {
        return [
            'slug'       => 'shifts',
            'label'      => 'Master Shift Kerja',
            'permission' => 'shifts.bulk_import',
            'table'      => 'shifts',
            'columns'    => [
                ['header' => 'Code',        'field' => 'code',        'type' => 'string', 'required' => true, 'max_length' => 32, 'regex' => '/^[A-Za-z0-9_\-]+$/', 'unique_in_table' => true, 'unique_in_batch' => true],
                ['header' => 'Name',        'field' => 'name',        'type' => 'string', 'required' => true, 'max_length' => 200],
                ['header' => 'Start Time',  'field' => 'start_time',  'type' => 'string', 'required' => true, 'max_length' => 8, 'regex' => '/^\d{2}:\d{2}(:\d{2})?$/', 'help' => 'Format HH:MM atau HH:MM:SS.'],
                ['header' => 'End Time',    'field' => 'end_time',    'type' => 'string', 'required' => true, 'max_length' => 8, 'regex' => '/^\d{2}:\d{2}(:\d{2})?$/'],
                ['header' => 'Break Minutes','field' => 'break_minutes','type' => 'int',  'default' => 60],
                ['header' => 'Crosses Midnight', 'field' => 'crosses_midnight', 'type' => 'bool', 'default' => 0, 'help' => '1 untuk shift yang melewati tengah malam (mis. 22:00-06:00).'],
                ['header' => 'Late Tolerance (min)',     'field' => 'late_tolerance_minutes',       'type' => 'int', 'default' => 15],
                ['header' => 'Early Leave Tolerance (min)','field' => 'early_leave_tolerance_minutes','type' => 'int','default' => 15],
                ['header' => 'Branch Code',     'field' => 'branch_id',     'type' => 'fk', 'fk_table' => 'branches',    'fk_column' => 'code'],
                ['header' => 'Department Code', 'field' => 'department_id', 'type' => 'fk', 'fk_table' => 'departments', 'fk_column' => 'code'],
                ['header' => 'Working Days', 'field' => 'working_days', 'type' => 'string', 'max_length' => 32, 'help' => 'Comma-separated 1-7 (1=Senin,7=Minggu). Kosong = semua hari.'],
                ['header' => 'Color',       'field' => 'color',       'type' => 'string', 'max_length' => 20, 'help' => 'Hex color untuk visual roster (mis. #4F46E5).'],
                ['header' => 'Description', 'field' => 'description', 'type' => 'string', 'max_length' => 500],
                ['header' => 'Active',      'field' => 'is_active',   'type' => 'bool',   'default' => 1],
            ],
            'sample_row' => [
                ['DAY',     'Shift Pagi',   '08:00', '17:00', '60', '0', '15', '15', '', '', '1,2,3,4,5', '#4F46E5', 'Shift kantor standar', '1'],
                ['EVE',     'Shift Sore',   '14:00', '22:00', '60', '0', '15', '15', '', '', '1,2,3,4,5', '#F59E0B', '', '1'],
                ['NIGHT',   'Shift Malam',  '22:00', '06:00', '60', '1', '15', '15', '', '', '1,2,3,4,5,6,7', '#1F2937', 'Cross midnight', '1'],
            ],
            'notes_md'   => "Master shift kerja. `crosses_midnight=1` untuk shift overnight. Tolerance dipakai AnomalyDetection untuk mark late/early.",
        ];
    }

    // ────────────────────────────────────────────────────────────────
    // Batch 3 (Migration #069) — Transactional data import
    // ────────────────────────────────────────────────────────────────

    private static function leaveBalances(): array
    {
        return [
            'slug'       => 'leave_balances',
            'label'      => 'Saldo Cuti Awal (Opening Balance)',
            'permission' => 'leave_balances.bulk_import',
            'table'      => 'leave_balances',
            'columns'    => [
                ['header' => 'Employee Number', 'field' => 'employee_id',   'type' => 'fk', 'required' => true,
                 'fk_table' => 'employees', 'fk_column' => 'employee_number',
                 'help' => 'NIP/NIK karyawan yang sudah terdaftar di master karyawan.'],
                ['header' => 'Leave Type Code', 'field' => 'leave_type_id', 'type' => 'fk', 'required' => true,
                 'fk_table' => 'leave_types', 'fk_column' => 'code',
                 'help' => 'Kode jenis cuti (mis. ANNUAL, SICK). Harus sudah ada di master jenis cuti.'],
                ['header' => 'Period Year',     'field' => 'period_year',   'type' => 'int', 'required' => true,
                 'help' => 'Tahun periode saldo (4 digit, mis. 2026).'],
                ['header' => 'Allocated',       'field' => 'allocated',     'type' => 'float', 'required' => true,
                 'help' => 'Jumlah hari yang dialokasikan untuk periode ini.'],
                ['header' => 'Carried Over',    'field' => 'carried_over',  'type' => 'float', 'default' => 0,
                 'help' => 'Sisa cuti yang di-carry dari tahun sebelumnya.'],
                ['header' => 'Used',            'field' => 'used',          'type' => 'float', 'default' => 0,
                 'help' => 'Jumlah hari yang sudah terpakai.'],
                ['header' => 'Remaining',       'field' => 'remaining',     'type' => 'float', 'required' => true,
                 'help' => 'Sisa saldo saat ini. Biasanya = allocated + carried_over - used.'],
                ['header' => 'Expires At',      'field' => 'expires_at',    'type' => 'date',
                 'help' => 'Tanggal kedaluwarsa saldo (opsional). Format YYYY-MM-DD.'],
            ],
            'composite_unique' => ['employee_id', 'leave_type_id', 'period_year'],
            'sample_row' => [
                ['EMP001', 'ANNUAL', '2026', '12', '3', '5', '10', ''],
                ['EMP001', 'SICK',   '2026', '12', '0', '2', '10', ''],
                ['EMP002', 'ANNUAL', '2026', '12', '0', '0', '12', '2026-12-31'],
            ],
            'notes_md'   => "Upload saldo cuti awal saat go-live / migrasi dari sistem lama.\n\n"
                . "PENTING:\n"
                . "- Employee Number dan Leave Type Code harus sudah ada di master.\n"
                . "- Kalau sudah ada baris dengan kombinasi (Employee + Leave Type + Year) yang sama, baris akan di-SKIP (tidak overwrite).\n"
                . "- Remaining = Allocated + Carried Over - Used (pastikan konsisten).\n"
                . "- Expires At opsional, diisi kalau carry-over punya batas waktu.",
        ];
    }

    private static function shiftSchedules(): array
    {
        return [
            'slug'       => 'shift_schedules',
            'label'      => 'Roster / Jadwal Shift Bulanan',
            'permission' => 'shift_schedules.bulk_import',
            'table'      => 'shift_schedules',
            'columns'    => [
                ['header' => 'Employee Number', 'field' => 'employee_id', 'type' => 'fk', 'required' => true,
                 'fk_table' => 'employees', 'fk_column' => 'employee_number',
                 'help' => 'NIP/NIK karyawan.'],
                ['header' => 'Work Date',       'field' => 'work_date',   'type' => 'date', 'required' => true,
                 'help' => 'Tanggal kerja. Format YYYY-MM-DD atau DD/MM/YYYY.'],
                ['header' => 'Shift Code',      'field' => 'shift_id',    'type' => 'fk',
                 'fk_table' => 'shifts', 'fk_column' => 'code',
                 'help' => 'Kode shift (mis. DAY, EVE, NIGHT). Kosongkan kalau hari libur/off.'],
                ['header' => 'Is Off',          'field' => 'is_off',      'type' => 'bool', 'default' => 0,
                 'help' => '1 = hari libur mingguan / off day.'],
                ['header' => 'Is Holiday',      'field' => 'is_holiday',  'type' => 'bool', 'default' => 0,
                 'help' => '1 = hari libur nasional / company holiday.'],
            ],
            'composite_unique' => ['employee_id', 'work_date'],
            'upsert_on_conflict' => true,
            'sample_row' => [
                ['EMP001', '2026-06-01', 'DAY',   '0', '0'],
                ['EMP001', '2026-06-02', 'DAY',   '0', '0'],
                ['EMP001', '2026-06-03', 'EVE',   '0', '0'],
                ['EMP001', '2026-06-07', '',       '1', '0'],
                ['EMP002', '2026-06-01', 'NIGHT', '0', '0'],
            ],
            'notes_md'   => "Upload jadwal shift bulanan untuk karyawan operasional/pabrik.\n\n"
                . "TIPS:\n"
                . "- Satu baris = satu karyawan × satu tanggal.\n"
                . "- Shift Code harus sudah ada di master shift.\n"
                . "- Kalau hari off, kosongkan Shift Code dan set Is Off = 1.\n"
                . "- Kalau sudah ada jadwal di tanggal yang sama, data akan di-UPDATE (overwrite).\n"
                . "- Untuk 1 bulan × 200 karyawan = ~6000 baris. Batas max 5000 row per batch, jadi split per 2 minggu kalau perlu.",
        ];
    }

    private static function attendances(): array
    {
        return [
            'slug'       => 'attendances',
            'label'      => 'Attendance Backfill (Migrasi Absensi)',
            'permission' => 'attendances.bulk_import',
            'table'      => 'attendances',
            'columns'    => [
                ['header' => 'Employee Number', 'field' => 'employee_id', 'type' => 'fk', 'required' => true,
                 'fk_table' => 'employees', 'fk_column' => 'employee_number',
                 'help' => 'NIP/NIK karyawan.'],
                ['header' => 'Work Date',       'field' => 'work_date',   'type' => 'date', 'required' => true,
                 'help' => 'Tanggal kerja. Format YYYY-MM-DD.'],
                ['header' => 'Shift Code',      'field' => 'shift_id',    'type' => 'fk',
                 'fk_table' => 'shifts', 'fk_column' => 'code',
                 'help' => 'Kode shift (opsional). Kosongkan kalau tidak pakai shift.'],
                ['header' => 'Clock In',        'field' => 'clock_in_at', 'type' => 'datetime',
                 'help' => 'Waktu masuk. Format YYYY-MM-DD HH:MM:SS atau HH:MM (akan digabung dengan Work Date).'],
                ['header' => 'Clock Out',       'field' => 'clock_out_at','type' => 'datetime',
                 'help' => 'Waktu pulang. Format YYYY-MM-DD HH:MM:SS atau HH:MM.'],
                ['header' => 'Status',          'field' => 'status',      'type' => 'enum', 'required' => true,
                 'in' => ['PRESENT','LATE','ABSENT','LEAVE','HOLIDAY','SICK','HALF_DAY'],
                 'default' => 'PRESENT',
                 'help' => 'Status kehadiran hari itu.'],
                ['header' => 'Work Minutes',    'field' => 'work_minutes',       'type' => 'int', 'default' => 0,
                 'help' => 'Total menit kerja efektif. Kalau kosong, dihitung dari clock in/out.'],
                ['header' => 'Late Minutes',    'field' => 'late_minutes',       'type' => 'int', 'default' => 0,
                 'help' => 'Menit keterlambatan.'],
                ['header' => 'Overtime Minutes', 'field' => 'overtime_minutes',  'type' => 'int', 'default' => 0,
                 'help' => 'Menit lembur.'],
                ['header' => 'Notes',           'field' => 'clock_in_notes',     'type' => 'string', 'max_length' => 500,
                 'help' => 'Catatan/keterangan (opsional).'],
            ],
            'composite_unique' => ['employee_id', 'work_date'],
            'sample_row' => [
                ['EMP001', '2026-05-01', 'DAY',   '2026-05-01 08:05:00', '2026-05-01 17:10:00', 'PRESENT', '480', '5',  '10', ''],
                ['EMP001', '2026-05-02', 'DAY',   '2026-05-02 08:30:00', '2026-05-02 17:00:00', 'LATE',    '450', '30', '0',  'Macet tol'],
                ['EMP002', '2026-05-01', 'NIGHT', '2026-05-01 22:00:00', '2026-05-02 06:00:00', 'PRESENT', '420', '0',  '0',  ''],
                ['EMP001', '2026-05-03', '',       '',                    '',                    'ABSENT',  '0',   '0',  '0',  'Tanpa keterangan'],
            ],
            'notes_md'   => "Backfill data absensi dari mesin fingerprint / sistem lama.\n\n"
                . "PENTING:\n"
                . "- Satu baris = satu karyawan × satu tanggal.\n"
                . "- Clock In/Out boleh kosong (mis. untuk ABSENT, LEAVE, HOLIDAY).\n"
                . "- Kalau Clock In hanya berisi HH:MM, akan otomatis digabung dengan Work Date.\n"
                . "- Kalau sudah ada data di tanggal yang sama, baris akan di-SKIP.\n"
                . "- Field `is_corrected` otomatis di-set 1 dan `correction_reason` = 'Bulk import backfill'.\n"
                . "- Untuk migrasi besar, split per bulan (max 5000 row per batch).",
        ];
    }

    private static function bonusRecipients(): array
    {
        return [
            'slug'       => 'bonus_recipients',
            'label'      => 'Bonus Bulanan / Insentif (Recipient)',
            'permission' => 'bonus_recipients.bulk_import',
            'table'      => 'bonus_recipients',
            'columns'    => [
                ['header' => 'Bonus Run ID',    'field' => 'bonus_run_id',    'type' => 'int', 'required' => true,
                 'help' => 'ID bonus run yang sudah dibuat (status DRAFT/CALCULATED). Lihat di halaman Bonus Runs.'],
                ['header' => 'Employee Number', 'field' => 'employee_id',     'type' => 'fk', 'required' => true,
                 'fk_table' => 'employees', 'fk_column' => 'employee_number',
                 'help' => 'NIP/NIK karyawan penerima bonus.'],
                ['header' => 'Amount',          'field' => 'amount',          'type' => 'float', 'required' => true,
                 'help' => 'Nominal bonus (Rupiah). Akan di-set sebagai override manual.'],
                ['header' => 'Override Reason', 'field' => 'override_reason', 'type' => 'string', 'max_length' => 500,
                 'default' => 'Bulk import dari Excel',
                 'help' => 'Alasan override (opsional). Default: "Bulk import dari Excel".'],
                ['header' => 'Is Excluded',     'field' => 'is_excluded',     'type' => 'bool', 'default' => 0,
                 'help' => '1 = karyawan di-exclude dari run ini. Default 0.'],
                ['header' => 'Exclude Reason',  'field' => 'exclude_reason',  'type' => 'string', 'max_length' => 500],
            ],
            'composite_unique' => ['bonus_run_id', 'employee_id'],
            'upsert_on_conflict' => true,
            'auto_inject' => [
                'amount_overridden' => 1,
            ],
            'sample_row' => [
                ['1', 'EMP001', '5000000', 'Performance Q1 excellent', '0', ''],
                ['1', 'EMP002', '3500000', 'Target tercapai 110%',     '0', ''],
                ['1', 'EMP003', '0',       '',                         '1', 'Resign per 15 Mei'],
            ],
            'notes_md'   => "Upload daftar penerima bonus untuk satu Bonus Run.\n\n"
                . "PENTING:\n"
                . "- Bonus Run ID harus sudah ada dan berstatus DRAFT atau CALCULATED.\n"
                . "- Employee Number harus sudah terdaftar di master karyawan.\n"
                . "- Amount yang di-upload akan otomatis di-mark sebagai 'overridden' (manual HR).\n"
                . "- Kalau sudah ada recipient dengan Employee yang sama di run tersebut, data akan di-UPDATE.\n"
                . "- Kolom snapshot (employee_name, department_name, monthly_salary, tenure_months) akan otomatis diisi backend saat commit.\n"
                . "- Setelah import, klik 'Approve' di halaman Bonus Run untuk publish ke karyawan.",
        ];
    }

    private static function loans(): array
    {
        return [
            'slug'       => 'loans',
            'label'      => 'Pinjaman / Kasbon Karyawan',
            'permission' => 'loans.bulk_import',
            'table'      => 'loans',
            'columns'    => [
                ['header' => 'Employee Number', 'field' => 'employee_id', 'type' => 'fk', 'required' => true,
                 'fk_table' => 'employees', 'fk_column' => 'employee_number',
                 'help' => 'NIP/NIK karyawan peminjam.'],
                ['header' => 'Loan Number',     'field' => 'loan_number', 'type' => 'string', 'required' => true,
                 'max_length' => 32, 'unique_in_table' => true, 'unique_in_batch' => true,
                 'help' => 'Nomor pinjaman unik (mis. LN-2026-001).'],
                ['header' => 'Principal',       'field' => 'principal',   'type' => 'float', 'required' => true,
                 'help' => 'Jumlah pokok pinjaman (Rupiah).'],
                ['header' => 'Installments',    'field' => 'installments','type' => 'int', 'required' => true,
                 'help' => 'Jumlah cicilan (bulan).'],
                ['header' => 'Installment Amount','field' => 'installment_amount','type' => 'float', 'required' => true,
                 'help' => 'Nominal cicilan per bulan (Rupiah). Biasanya = Principal / Installments.'],
                ['header' => 'Paid Installments','field' => 'paid_installments','type' => 'int', 'default' => 0,
                 'help' => 'Jumlah cicilan yang sudah dibayar (untuk migrasi data lama).'],
                ['header' => 'Remaining',       'field' => 'remaining',   'type' => 'float', 'required' => true,
                 'help' => 'Sisa pinjaman saat ini (Rupiah).'],
                ['header' => 'Reason',          'field' => 'reason',      'type' => 'string', 'max_length' => 500,
                 'default' => 'Migrasi data pinjaman',
                 'help' => 'Alasan pinjaman.'],
                ['header' => 'Status',          'field' => 'status',      'type' => 'enum', 'required' => true,
                 'in' => ['PENDING','APPROVED','RUNNING','COMPLETED','CANCELLED'],
                 'default' => 'RUNNING',
                 'help' => 'Status pinjaman. Untuk migrasi data aktif, pakai RUNNING.'],
            ],
            'sample_row' => [
                ['EMP001', 'LN-2026-001', '6000000', '6', '1000000', '2', '4000000', 'Kasbon darurat',  'RUNNING'],
                ['EMP002', 'LN-2026-002', '12000000','12','1000000', '0', '12000000','KPR koperasi',    'RUNNING'],
                ['EMP003', 'LN-2025-099', '3000000', '3', '1000000', '3', '0',       'Sudah lunas',     'COMPLETED'],
            ],
            'notes_md'   => "Migrasi data pinjaman/kasbon aktif dari sistem lama.\n\n"
                . "PENTING:\n"
                . "- Loan Number harus unik.\n"
                . "- Untuk pinjaman yang masih berjalan, set Status = RUNNING.\n"
                . "- Remaining = Principal - (Paid Installments × Installment Amount).\n"
                . "- Pinjaman dengan status RUNNING akan otomatis dipotong dari gaji saat payroll run.",
        ];
    }

    private static function goals(): array
    {
        return [
            'slug'       => 'goals',
            'label'      => 'Performance Goals / KPI',
            'permission' => 'goals.bulk_import',
            'table'      => 'goals',
            'columns'    => [
                ['header' => 'Cycle Code',      'field' => 'cycle_id',    'type' => 'fk', 'required' => true,
                 'fk_table' => 'performance_cycles', 'fk_column' => 'code',
                 'help' => 'Kode cycle (mis. Q1-2026, ANNUAL-2026). Harus sudah ada di master.'],
                ['header' => 'Employee Number', 'field' => 'employee_id', 'type' => 'fk', 'required' => true,
                 'fk_table' => 'employees', 'fk_column' => 'employee_number',
                 'help' => 'NIP/NIK karyawan pemilik goal.'],
                ['header' => 'Goal Type',       'field' => 'goal_type',   'type' => 'enum',
                 'in' => ['KPI','OKR_OBJECTIVE','OKR_KEY_RESULT','DEVELOPMENT'],
                 'default' => 'KPI'],
                ['header' => 'Category',        'field' => 'category',    'type' => 'string', 'max_length' => 32,
                 'help' => 'Perspektif BSC: FINANCIAL, CUSTOMER, INTERNAL, LEARNING, OPERATIONAL, dll.'],
                ['header' => 'Title',           'field' => 'title',       'type' => 'string', 'required' => true,
                 'max_length' => 255, 'help' => 'Judul goal/KPI.'],
                ['header' => 'Description',     'field' => 'description', 'type' => 'string', 'max_length' => 1000],
                ['header' => 'Measurement Type','field' => 'measurement_type','type' => 'enum',
                 'in' => ['PERCENT','NUMBER','CURRENCY','BINARY','MILESTONE'],
                 'default' => 'PERCENT'],
                ['header' => 'Target Value',    'field' => 'target_value','type' => 'float',
                 'help' => 'Nilai target (mis. 100 untuk 100%, 500000000 untuk revenue).'],
                ['header' => 'Unit',            'field' => 'unit',        'type' => 'string', 'max_length' => 20,
                 'help' => 'Satuan: %, IDR, pcs, hari, dll.'],
                ['header' => 'Weight',          'field' => 'weight',      'type' => 'float', 'required' => true,
                 'help' => 'Bobot 0-100. Total bobot per karyawan per cycle harus = 100.'],
                ['header' => 'Due Date',        'field' => 'due_date',    'type' => 'date',
                 'help' => 'Deadline pencapaian (opsional). Format YYYY-MM-DD.'],
                ['header' => 'Status',          'field' => 'status',      'type' => 'enum',
                 'in' => ['DRAFT','APPROVED','IN_PROGRESS','ACHIEVED','PARTIAL','MISSED','CANCELLED'],
                 'default' => 'DRAFT'],
            ],
            'sample_row' => [
                ['ANNUAL-2026', 'EMP001', 'KPI', 'FINANCIAL', 'Revenue Growth 20%',     'Capai revenue Rp 5M', 'PERCENT', '100', '%',   '30', '2026-12-31', 'APPROVED'],
                ['ANNUAL-2026', 'EMP001', 'KPI', 'CUSTOMER',  'Customer Satisfaction',  'NPS score ≥ 80',      'NUMBER',  '80',  'NPS', '25', '2026-12-31', 'APPROVED'],
                ['ANNUAL-2026', 'EMP001', 'KPI', 'INTERNAL',  'Reduce Bug Rate',        'Bug per sprint < 5',  'NUMBER',  '5',   'bug', '25', '2026-12-31', 'APPROVED'],
                ['ANNUAL-2026', 'EMP001', 'KPI', 'LEARNING',  'Sertifikasi AWS',        'Lulus AWS SAA',       'BINARY',  '1',   '',    '20', '2026-06-30', 'DRAFT'],
            ],
            'notes_md'   => "Cascade goal tahunan / KPI per karyawan.\n\n"
                . "PENTING:\n"
                . "- Cycle Code harus sudah ada di master Performance Cycles.\n"
                . "- Total Weight per karyawan per cycle idealnya = 100.\n"
                . "- Untuk goal yang sudah disetujui atasan, set Status = APPROVED.\n"
                . "- Parent goal (alignment) tidak bisa di-set via import — gunakan UI.\n"
                . "- Measurement Type menentukan cara hitung progress (PERCENT, NUMBER, CURRENCY, BINARY, MILESTONE).",
        ];
    }
}
