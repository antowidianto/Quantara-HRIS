<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * InitialSeeder — bootstrap data inti.
 *
 * Idempotent: aman dijalankan berulang. Setiap insert dicek dulu
 * eksistensinya berdasarkan kunci natural (slug, code, email).
 *
 * Catatan: untuk SQLite, hindari insertBatch ketika row count tidak
 * sama keys-nya — gunakan loop per-row.
 */
class InitialSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->seedPermissions($now);
        $this->seedRoles($now);
        $this->seedRolePermissions($now);
        $this->seedDefaultAdmin($now);
        $this->seedPtkpRates($now);
        $this->seedPph21Brackets($now);
        $this->seedTerCategoryA($now);
        $this->seedTerCategoryB($now);
        $this->seedTerCategoryC($now);
        $this->seedDemoCompany($now);
    }

    private function seedPermissions(string $now): void
    {
        $permissions = [
            ['slug' => 'company.view', 'name' => 'View Company', 'group' => 'master'],
            ['slug' => 'company.manage', 'name' => 'Manage Company', 'group' => 'master'],
            ['slug' => 'branch.view', 'name' => 'View Branch', 'group' => 'master'],
            ['slug' => 'branch.manage', 'name' => 'Manage Branch', 'group' => 'master'],
            ['slug' => 'department.view', 'name' => 'View Department', 'group' => 'master'],
            ['slug' => 'department.manage', 'name' => 'Manage Department', 'group' => 'master'],
            ['slug' => 'position.view', 'name' => 'View Position', 'group' => 'master'],
            ['slug' => 'position.manage', 'name' => 'Manage Position', 'group' => 'master'],
            ['slug' => 'employee.view', 'name' => 'View Employees', 'group' => 'employee'],
            ['slug' => 'employee.create', 'name' => 'Create Employee', 'group' => 'employee'],
            ['slug' => 'employee.update', 'name' => 'Update Employee', 'group' => 'employee'],
            ['slug' => 'employee.delete', 'name' => 'Delete Employee', 'group' => 'employee'],
            ['slug' => 'employee.view_self', 'name' => 'View Own Profile', 'group' => 'employee'],
            ['slug' => 'attendance.view', 'name' => 'View Attendance', 'group' => 'attendance'],
            ['slug' => 'attendance.view_self', 'name' => 'View Own Attendance', 'group' => 'attendance'],
            ['slug' => 'attendance.view_all', 'name' => 'View All Attendance (own branch scope)', 'group' => 'attendance'],
            ['slug' => 'attendance.view_all_branches', 'name' => 'View Attendance Across Branches (within company)', 'group' => 'attendance'],
            ['slug' => 'attendance.clock', 'name' => 'Clock In/Out', 'group' => 'attendance'],
            ['slug' => 'attendance.correct', 'name' => 'Correct Attendance', 'group' => 'attendance'],
            ['slug' => 'attendance.approve', 'name' => 'Approve Corrections', 'group' => 'attendance'],
            ['slug' => 'attendance.request_change', 'name' => 'Request Attendance Change', 'group' => 'attendance'],
            ['slug' => 'attendance.approve_change', 'name' => 'Approve Attendance Change', 'group' => 'attendance'],
            ['slug' => 'leave.view', 'name' => 'View Leaves', 'group' => 'leave'],
            ['slug' => 'leave.create', 'name' => 'Request Leave', 'group' => 'leave'],
            ['slug' => 'leave.approve', 'name' => 'Approve Leave', 'group' => 'leave'],
            ['slug' => 'leave.manage_types', 'name' => 'Manage Leave Types', 'group' => 'leave'],
            ['slug' => 'overtime.view', 'name' => 'View Overtime', 'group' => 'overtime'],
            ['slug' => 'overtime.create', 'name' => 'Request Overtime', 'group' => 'overtime'],
            ['slug' => 'overtime.approve', 'name' => 'Approve Overtime', 'group' => 'overtime'],
            ['slug' => 'payroll.view', 'name' => 'View Payroll', 'group' => 'payroll'],
            ['slug' => 'payroll.manage', 'name' => 'Manage Payroll', 'group' => 'payroll'],
            ['slug' => 'payroll.calculate', 'name' => 'Calculate Payroll', 'group' => 'payroll'],
            ['slug' => 'payroll.finalize', 'name' => 'Finalize Payroll', 'group' => 'payroll'],
            ['slug' => 'payslip.view_self', 'name' => 'View Own Payslip', 'group' => 'payroll'],
            ['slug' => 'payslip.view_all', 'name' => 'View All Payslips', 'group' => 'payroll'],
            ['slug' => 'reimbursement.view', 'name' => 'View Reimbursement', 'group' => 'finance'],
            ['slug' => 'reimbursement.create', 'name' => 'Request Reimbursement', 'group' => 'finance'],
            ['slug' => 'reimbursement.approve', 'name' => 'Approve Reimbursement', 'group' => 'finance'],
            ['slug' => 'loan.view', 'name' => 'View Loans', 'group' => 'finance'],
            ['slug' => 'loan.create', 'name' => 'Request Loan', 'group' => 'finance'],
            ['slug' => 'loan.approve', 'name' => 'Approve Loan', 'group' => 'finance'],
            ['slug' => 'user.manage', 'name' => 'Manage Users', 'group' => 'admin'],
            ['slug' => 'role.manage', 'name' => 'Manage Roles', 'group' => 'admin'],
            ['slug' => 'audit.view', 'name' => 'View Audit Log', 'group' => 'admin'],
            ['slug' => 'settings.manage', 'name' => 'Manage Settings', 'group' => 'admin'],
            ['slug' => 'report.view', 'name' => 'View Reports', 'group' => 'report'],
        ];

        foreach ($permissions as $p) {
            $exists = $this->db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $this->db->table('permissions')->insert($p);
        }
    }

    private function seedRoles(string $now): void
    {
        $roles = [
            ['id' => 1, 'slug' => 'super_admin', 'name' => 'Super Admin'],
            ['id' => 2, 'slug' => 'hr_admin',    'name' => 'HR Admin'],
            ['id' => 3, 'slug' => 'manager',     'name' => 'Manager'],
            ['id' => 4, 'slug' => 'employee',    'name' => 'Employee'],
            ['id' => 5, 'slug' => 'finance',     'name' => 'Finance'],
        ];
        foreach ($roles as $r) {
            $exists = $this->db->table('roles')->where('slug', $r['slug'])->countAllResults();
            if ($exists > 0) continue;
            $this->db->table('roles')->insert([
                'id'         => $r['id'],
                'company_id' => null,
                'slug'       => $r['slug'],
                'name'       => $r['name'],
                'is_system'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedRolePermissions(string $now): void
    {
        $allPermissions = $this->db->table('permissions')->get()->getResultArray();
        $bySlug = [];
        foreach ($allPermissions as $p) {
            $bySlug[$p['slug']] = (int) $p['id'];
        }

        // HR Admin: scope tetap di-restrict ke cabang & perusahaannya. Untuk
        // bisa melihat lintas cabang dalam company yang sama, role harus
        // ditambahkan permission `attendance.view_all_branches` secara explisit.
        $hrAdminPerms = ['company.view','branch.view','branch.manage','department.view','department.manage',
            'position.view','position.manage','employee.view','employee.create','employee.update','employee.delete',
            'attendance.view_all','attendance.view_all_branches','attendance.correct','attendance.approve',
            'attendance.approve_change',
            'leave.view','leave.approve','leave.manage_types',
            'overtime.view','overtime.approve',
            'payroll.view','payroll.manage','payroll.calculate','payroll.finalize','payslip.view_all',
            'reimbursement.view','reimbursement.approve','loan.view','loan.approve',
            'user.manage','audit.view','settings.manage','report.view'];

        // Manager: hanya melihat absensi cabangnya sendiri (tanpa
        // `attendance.view_all_branches`). Boleh approve change request
        // yang diajukan bawahannya di cabang yang sama.
        $managerPerms = ['employee.view','attendance.view_all','leave.view','leave.approve',
            'overtime.view','overtime.approve','reimbursement.view','reimbursement.approve','report.view',
            'employee.view_self','attendance.clock','attendance.view_self','attendance.request_change','attendance.approve_change',
            'leave.create','overtime.create','reimbursement.create','loan.create','payslip.view_self'];

        $employeePerms = ['employee.view_self','attendance.clock','attendance.view_self','attendance.view','attendance.request_change',
            'leave.view','leave.create','overtime.view','overtime.create',
            'reimbursement.view','reimbursement.create','loan.view','loan.create','payslip.view_self'];

        $financePerms = ['employee.view','payroll.view','payroll.manage','payroll.calculate','payroll.finalize',
            'payslip.view_all','reimbursement.view','reimbursement.approve','loan.view','loan.approve','report.view'];

        $assignments = [
            1 => array_keys($bySlug),     // super admin: all
            2 => $hrAdminPerms,
            3 => $managerPerms,
            4 => $employeePerms,
            5 => $financePerms,
        ];

        foreach ($assignments as $roleId => $slugs) {
            foreach ($slugs as $slug) {
                if (! isset($bySlug[$slug])) continue;
                $permId = $bySlug[$slug];
                $exists = $this->db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permId)
                    ->countAllResults();
                if ($exists > 0) continue;
                $this->db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => $permId,
                    'created_at'    => $now,
                ]);
            }
        }
    }

    private function seedDefaultAdmin(string $now): void
    {
        $email = 'admin@hris.local';
        $exists = $this->db->table('users')->where('email', $email)->countAllResults();
        if ($exists > 0) {
            echo "  Default admin '{$email}' already exists. Skipped.\n";
            return;
        }

        $defaultPassword = 'Admin@12345';
        $this->db->table('users')->insert([
            'company_id'           => null,
            'role_id'              => 1,
            'employee_id'          => null,
            'email'                => $email,
            'password_hash'        => password_hash($defaultPassword, PASSWORD_ARGON2ID),
            'name'                 => 'Super Admin',
            'is_active'            => 1,
            'must_change_password' => 1,
            'password_changed_at'  => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        echo "\n──────────────────────────────────────────\n";
        echo "  Default Super Admin created:\n";
        echo "  Email:    {$email}\n";
        echo "  Password: {$defaultPassword}\n";
        echo "  ⚠ Change this password immediately!\n";
        echo "──────────────────────────────────────────\n\n";
    }

    private function seedPtkpRates(string $now): void
    {
        $year = (int) date('Y');
        $ptkp = [
            ['code' => 'TK0', 'description' => 'Tidak Kawin tanpa tanggungan', 'amount_yearly' => 54000000],
            ['code' => 'TK1', 'description' => 'Tidak Kawin 1 tanggungan',     'amount_yearly' => 58500000],
            ['code' => 'TK2', 'description' => 'Tidak Kawin 2 tanggungan',     'amount_yearly' => 63000000],
            ['code' => 'TK3', 'description' => 'Tidak Kawin 3 tanggungan',     'amount_yearly' => 67500000],
            ['code' => 'K0',  'description' => 'Kawin tanpa tanggungan',        'amount_yearly' => 58500000],
            ['code' => 'K1',  'description' => 'Kawin 1 tanggungan',            'amount_yearly' => 63000000],
            ['code' => 'K2',  'description' => 'Kawin 2 tanggungan',            'amount_yearly' => 67500000],
            ['code' => 'K3',  'description' => 'Kawin 3 tanggungan',            'amount_yearly' => 72000000],
            ['code' => 'HB0', 'description' => 'Suami Istri digabung 0',        'amount_yearly' => 112500000],
            ['code' => 'HB1', 'description' => 'Suami Istri digabung 1',        'amount_yearly' => 117000000],
            ['code' => 'HB2', 'description' => 'Suami Istri digabung 2',        'amount_yearly' => 121500000],
            ['code' => 'HB3', 'description' => 'Suami Istri digabung 3',        'amount_yearly' => 126000000],
        ];
        foreach ($ptkp as $row) {
            $exists = $this->db->table('ptkp_rates')
                ->where('code', $row['code'])
                ->where('effective_year', $year)
                ->countAllResults();
            if ($exists > 0) continue;
            $row['effective_year'] = $year;
            $row['created_at']     = $now;
            $row['updated_at']     = $now;
            $this->db->table('ptkp_rates')->insert($row);
        }
    }

    private function seedPph21Brackets(string $now): void
    {
        $year = (int) date('Y');
        $exists = $this->db->table('pph21_brackets')->where('effective_year', $year)->countAllResults();
        if ($exists > 0) return;

        $brackets = [
            ['min_income' => 0,           'max_income' => 60000000,    'rate' => 0.05],
            ['min_income' => 60000000,    'max_income' => 250000000,   'rate' => 0.15],
            ['min_income' => 250000000,   'max_income' => 500000000,   'rate' => 0.25],
            ['min_income' => 500000000,   'max_income' => 5000000000,  'rate' => 0.30],
            ['min_income' => 5000000000,  'max_income' => null,        'rate' => 0.35],
        ];
        foreach ($brackets as $row) {
            $row['effective_year'] = $year;
            $row['created_at']     = $now;
            $this->db->table('pph21_brackets')->insert($row);
        }
    }

    private function seedDemoCompany(string $now): void
    {
        $existing = $this->db->table('companies')->where('code', 'DEMO')->get()->getRowArray();
        if ($existing) {
            echo "  Demo company already exists (id={$existing['id']}). Skipping demo seed.\n";
            return;
        }

        $this->db->table('companies')->insert([
            'code'       => 'DEMO',
            'name'       => 'PT Demo HRIS Indonesia',
            'legal_name' => 'PT Demo HRIS Indonesia',
            'email'      => 'hr@demo.id',
            'address'    => 'Jl. Sudirman No. 1',
            'province'   => 'DKI Jakarta',
            'city'       => 'Jakarta Selatan',
            'is_active'  => 1,
            'timezone'   => 'Asia/Jakarta',
            'currency'   => 'IDR',
            'fiscal_year_start' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $companyId = (int) $this->db->insertID();

        // BPJS rates default
        $this->db->table('bpjs_rates')->insert([
            'company_id'        => $companyId,
            'kes_employee_rate' => 0.01,
            'kes_company_rate'  => 0.04,
            'kes_max_salary'    => 12000000,
            'jht_employee_rate' => 0.02,
            'jht_company_rate'  => 0.037,
            'jp_employee_rate'  => 0.01,
            'jp_company_rate'   => 0.02,
            'jp_max_salary'     => 10547400,
            'jkk_company_rate'  => 0.0024,
            'jkm_company_rate'  => 0.003,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        // Default leave types mengikuti UU 13/2003, UU 6/2023 (Cipta Kerja),
        // dan PP 35/2021. Kolom `description` menjelaskan dasar hukumnya
        // supaya tampil di UI saat user memilih tipe cuti.
        $leaveTypes = [
            ['code' => 'CT',  'name' => 'Cuti Tahunan',                       'category' => 'ANNUAL',     'default_days' => 12, 'min_tenure_months' => 12, 'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '12 hari per tahun setelah masa kerja 12 bulan (UU 13/2003 Pasal 79).'],
            ['code' => 'CS',  'name' => 'Cuti Sakit',                         'category' => 'SICK',       'default_days' => 0,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 1, 'gender_restriction' => 'ALL', 'description' => 'Memerlukan surat dokter. Pembayaran upah mengikuti UU 13/2003 Pasal 93.'],
            ['code' => 'CM',  'name' => 'Cuti Melahirkan (3 bulan)',          'category' => 'MATERNITY',  'default_days' => 90, 'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'F',   'description' => '1,5 bulan sebelum dan 1,5 bulan sesudah melahirkan (UU 13/2003 Pasal 82). Khusus karyawan perempuan.'],
            ['code' => 'CPM', 'name' => 'Cuti Pendamping Melahirkan',         'category' => 'PATERNITY',  'default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'M',   'description' => '2 hari mendampingi istri melahirkan / keguguran (UU 13/2003 Pasal 93). Khusus karyawan laki-laki yang menikah.'],
            ['code' => 'CB',  'name' => 'Cuti Besar (per 6 tahun)',           'category' => 'SPECIAL',    'default_days' => 30, 'min_tenure_months' => 72, 'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => 'Cuti panjang setelah masa kerja 6 tahun.'],
            ['code' => 'CH',  'name' => 'Cuti Haid',                          'category' => 'MENSTRUAL',  'default_days' => 24, 'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'F',   'description' => 'Pekerja perempuan yang merasa sakit pada hari pertama dan kedua masa haid (UU 13/2003 Pasal 81).'],
            ['code' => 'CN',  'name' => 'Cuti Menikah',                       'category' => 'MARRIAGE',   'default_days' => 3,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '3 hari saat pernikahan pekerja sendiri (UU 13/2003 Pasal 93).'],
            ['code' => 'CMA', 'name' => 'Cuti Menikahkan Anak',               'category' => 'SPECIAL',    'default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '2 hari saat menikahkan anak (UU 13/2003 Pasal 93).'],
            ['code' => 'CKA', 'name' => 'Cuti Khitanan / Baptis Anak',        'category' => 'SPECIAL',    'default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '2 hari saat mengkhitankan / membaptiskan anak (UU 13/2003 Pasal 93).'],
            ['code' => 'CKL', 'name' => 'Cuti Kelahiran Anak',                'category' => 'SPECIAL',    'default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '2 hari saat istri pekerja melahirkan / keguguran (UU 13/2003 Pasal 93).'],
            ['code' => 'CD',  'name' => 'Cuti Duka (Keluarga Inti Meninggal)','category' => 'BEREAVEMENT','default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '2 hari saat suami/istri/orang tua/mertua/anak/menantu meninggal (UU 13/2003 Pasal 93).'],
            ['code' => 'CDR', 'name' => 'Cuti Duka (Anggota Keluarga Serumah)','category' => 'BEREAVEMENT','default_days' => 1,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => '1 hari saat anggota keluarga dalam satu rumah meninggal (UU 13/2003 Pasal 93).'],
            ['code' => 'CIH', 'name' => 'Cuti Ibadah Haji',                   'category' => 'HAJJ',       'default_days' => 40, 'min_tenure_months' => 12, 'is_paid' => 1, 'requires_doc' => 1, 'gender_restriction' => 'ALL', 'description' => 'Sekali seumur masa kerja untuk menunaikan ibadah haji (UU 13/2003 Pasal 80).'],
            ['code' => 'IB',  'name' => 'Izin Khusus (Anak Sakit, dll)',      'category' => 'SPECIAL',    'default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => 'Izin tidak masuk dengan keperluan keluarga mendesak.'],
            ['code' => 'CTB', 'name' => 'Cuti Tidak Berbayar',                'category' => 'UNPAID',     'default_days' => 30, 'min_tenure_months' => 0,  'is_paid' => 0, 'requires_doc' => 0, 'gender_restriction' => 'ALL', 'description' => 'Unpaid leave dengan persetujuan khusus dari HR.'],
        ];
        foreach ($leaveTypes as $lt) {
            // Idempotent: skip kalau code sudah ada untuk perusahaan ini.
            $exists = $this->db->table('leave_types')
                ->where('company_id', $companyId)
                ->where('code', $lt['code'])
                ->countAllResults();
            if ($exists > 0) continue;

            $row = [
                'company_id'         => $companyId,
                'code'               => $lt['code'],
                'name'               => $lt['name'],
                'category'           => $lt['category'],
                'default_days'       => $lt['default_days'],
                'min_tenure_months'  => $lt['min_tenure_months'],
                'is_paid'            => $lt['is_paid'],
                'requires_doc'       => $lt['requires_doc'],
                'gender_restriction' => $lt['gender_restriction'],
                'reset_yearly'       => 1,
                'max_days'           => null,
                'description'        => $lt['description'] ?? null,
                'is_active'          => 1,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
            $this->db->table('leave_types')->insert($row);
        }

        // Default shift
        $this->db->table('shifts')->insert([
            'company_id' => $companyId,
            'code'       => 'REG',
            'name'       => 'Reguler 09:00-17:00',
            'start_time' => '09:00:00',
            'end_time'   => '17:00:00',
            'break_minutes' => 60,
            'late_tolerance_minutes' => 15,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Default payroll components
        $components = [
            ['code' => 'BASIC', 'name' => 'Gaji Pokok', 'type' => 'EARNING', 'category' => 'BASIC', 'is_taxable' => 1, 'is_fixed' => 1],
            ['code' => 'TUNJ_TRANS', 'name' => 'Tunjangan Transport', 'type' => 'EARNING', 'category' => 'ALLOWANCE', 'is_taxable' => 1, 'is_fixed' => 1],
            ['code' => 'TUNJ_MAKAN', 'name' => 'Tunjangan Makan', 'type' => 'EARNING', 'category' => 'ALLOWANCE', 'is_taxable' => 1, 'is_fixed' => 0],
            ['code' => 'TUNJ_JBT', 'name' => 'Tunjangan Jabatan', 'type' => 'EARNING', 'category' => 'ALLOWANCE', 'is_taxable' => 1, 'is_fixed' => 1],
            ['code' => 'OT', 'name' => 'Lembur', 'type' => 'EARNING', 'category' => 'OVERTIME', 'is_taxable' => 1, 'is_fixed' => 0],
            ['code' => 'THR', 'name' => 'THR Keagamaan', 'type' => 'EARNING', 'category' => 'THR', 'is_taxable' => 1, 'is_fixed' => 0],
            ['code' => 'BPJS_KES_EMP', 'name' => 'BPJS Kesehatan (Karyawan)', 'type' => 'DEDUCTION', 'category' => 'BPJS_KES', 'is_taxable' => 0, 'is_fixed' => 1],
            ['code' => 'BPJS_JHT_EMP', 'name' => 'BPJS JHT (Karyawan)', 'type' => 'DEDUCTION', 'category' => 'BPJS_TK', 'is_taxable' => 0, 'is_fixed' => 1],
            ['code' => 'BPJS_JP_EMP', 'name' => 'BPJS JP (Karyawan)', 'type' => 'DEDUCTION', 'category' => 'BPJS_TK', 'is_taxable' => 0, 'is_fixed' => 1],
            ['code' => 'PPH21', 'name' => 'PPh 21', 'type' => 'DEDUCTION', 'category' => 'PPH21', 'is_taxable' => 0, 'is_fixed' => 0],
            ['code' => 'LOAN', 'name' => 'Potongan Kasbon', 'type' => 'DEDUCTION', 'category' => 'LOAN', 'is_taxable' => 0, 'is_fixed' => 0],
        ];
        foreach ($components as $c) {
            $this->db->table('payroll_components')->insert([
                'company_id' => $companyId,
                'code'       => $c['code'],
                'name'       => $c['name'],
                'type'       => $c['type'],
                'category'   => $c['category'],
                'is_taxable' => $c['is_taxable'],
                'is_fixed'   => $c['is_fixed'],
                'amount'     => 0,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        echo "\n  Demo company '{$companyId}' (PT Demo HRIS Indonesia) created with default config.\n\n";
    }

    /**
     * TER Category A (PMK 168/2023): TK/0, TK/1, K/0
     */
    private function seedTerCategoryA(string $now): void
    {
        $year = (int) date('Y');
        $exists = $this->db->table('ter_rates')->where('category', 'A')->where('effective_year', $year)->countAllResults();
        if ($exists > 0) return;

        $rows = [
            ['min_income' => 0,           'max_income' => 5400000,    'rate' => 0.0000],
            ['min_income' => 5400000,     'max_income' => 5650000,    'rate' => 0.0025],
            ['min_income' => 5650000,     'max_income' => 5950000,    'rate' => 0.0050],
            ['min_income' => 5950000,     'max_income' => 6300000,    'rate' => 0.0075],
            ['min_income' => 6300000,     'max_income' => 6750000,    'rate' => 0.0100],
            ['min_income' => 6750000,     'max_income' => 7500000,    'rate' => 0.0125],
            ['min_income' => 7500000,     'max_income' => 8550000,    'rate' => 0.0150],
            ['min_income' => 8550000,     'max_income' => 9650000,    'rate' => 0.0175],
            ['min_income' => 9650000,     'max_income' => 10050000,   'rate' => 0.0200],
            ['min_income' => 10050000,    'max_income' => 10350000,   'rate' => 0.0225],
            ['min_income' => 10350000,    'max_income' => 10700000,   'rate' => 0.0250],
            ['min_income' => 10700000,    'max_income' => 11050000,   'rate' => 0.0300],
            ['min_income' => 11050000,    'max_income' => 11600000,   'rate' => 0.0350],
            ['min_income' => 11600000,    'max_income' => 12500000,   'rate' => 0.0400],
            ['min_income' => 12500000,    'max_income' => 13750000,   'rate' => 0.0500],
            ['min_income' => 13750000,    'max_income' => 15100000,   'rate' => 0.0600],
            ['min_income' => 15100000,    'max_income' => 16950000,   'rate' => 0.0700],
            ['min_income' => 16950000,    'max_income' => 19750000,   'rate' => 0.0800],
            ['min_income' => 19750000,    'max_income' => 24150000,   'rate' => 0.0900],
            ['min_income' => 24150000,    'max_income' => 26450000,   'rate' => 0.1000],
            ['min_income' => 26450000,    'max_income' => 28000000,   'rate' => 0.1100],
            ['min_income' => 28000000,    'max_income' => 30050000,   'rate' => 0.1200],
            ['min_income' => 30050000,    'max_income' => 32400000,   'rate' => 0.1300],
            ['min_income' => 32400000,    'max_income' => 35400000,   'rate' => 0.1400],
            ['min_income' => 35400000,    'max_income' => 39100000,   'rate' => 0.1500],
            ['min_income' => 39100000,    'max_income' => 43850000,   'rate' => 0.1600],
            ['min_income' => 43850000,    'max_income' => 47800000,   'rate' => 0.1700],
            ['min_income' => 47800000,    'max_income' => 51400000,   'rate' => 0.1800],
            ['min_income' => 51400000,    'max_income' => 56300000,   'rate' => 0.1900],
            ['min_income' => 56300000,    'max_income' => 62200000,   'rate' => 0.2000],
            ['min_income' => 62200000,    'max_income' => 68600000,   'rate' => 0.2100],
            ['min_income' => 68600000,    'max_income' => 77500000,   'rate' => 0.2200],
            ['min_income' => 77500000,    'max_income' => 89000000,   'rate' => 0.2300],
            ['min_income' => 89000000,    'max_income' => 103000000,  'rate' => 0.2400],
            ['min_income' => 103000000,   'max_income' => 125000000,  'rate' => 0.2500],
            ['min_income' => 125000000,   'max_income' => 157000000,  'rate' => 0.2600],
            ['min_income' => 157000000,   'max_income' => 206000000,  'rate' => 0.2700],
            ['min_income' => 206000000,   'max_income' => 337000000,  'rate' => 0.2800],
            ['min_income' => 337000000,   'max_income' => 454000000,  'rate' => 0.2900],
            ['min_income' => 454000000,   'max_income' => 550000000,  'rate' => 0.3000],
            ['min_income' => 550000000,   'max_income' => 695000000,  'rate' => 0.3100],
            ['min_income' => 695000000,   'max_income' => 910000000,  'rate' => 0.3200],
            ['min_income' => 910000000,   'max_income' => 1400000000, 'rate' => 0.3300],
            ['min_income' => 1400000000,  'max_income' => null,       'rate' => 0.3400],
        ];
        foreach ($rows as $r) {
            $r['category']       = 'A';
            $r['effective_year'] = $year;
            $r['created_at']     = $now;
            $this->db->table('ter_rates')->insert($r);
        }
    }

    /**
     * TER Category B: TK/2, TK/3, K/1, K/2
     */
    private function seedTerCategoryB(string $now): void
    {
        $year = (int) date('Y');
        $exists = $this->db->table('ter_rates')->where('category', 'B')->where('effective_year', $year)->countAllResults();
        if ($exists > 0) return;

        $rows = [
            ['min_income' => 0,           'max_income' => 6200000,    'rate' => 0.0000],
            ['min_income' => 6200000,     'max_income' => 6500000,    'rate' => 0.0025],
            ['min_income' => 6500000,     'max_income' => 6850000,    'rate' => 0.0050],
            ['min_income' => 6850000,     'max_income' => 7300000,    'rate' => 0.0075],
            ['min_income' => 7300000,     'max_income' => 9200000,    'rate' => 0.0100],
            ['min_income' => 9200000,     'max_income' => 10750000,   'rate' => 0.0150],
            ['min_income' => 10750000,    'max_income' => 11250000,   'rate' => 0.0200],
            ['min_income' => 11250000,    'max_income' => 11600000,   'rate' => 0.0250],
            ['min_income' => 11600000,    'max_income' => 12600000,   'rate' => 0.0300],
            ['min_income' => 12600000,    'max_income' => 13600000,   'rate' => 0.0400],
            ['min_income' => 13600000,    'max_income' => 14950000,   'rate' => 0.0500],
            ['min_income' => 14950000,    'max_income' => 16400000,   'rate' => 0.0600],
            ['min_income' => 16400000,    'max_income' => 18450000,   'rate' => 0.0700],
            ['min_income' => 18450000,    'max_income' => 21850000,   'rate' => 0.0800],
            ['min_income' => 21850000,    'max_income' => 26000000,   'rate' => 0.0900],
            ['min_income' => 26000000,    'max_income' => 27700000,   'rate' => 0.1000],
            ['min_income' => 27700000,    'max_income' => 29350000,   'rate' => 0.1100],
            ['min_income' => 29350000,    'max_income' => 31450000,   'rate' => 0.1200],
            ['min_income' => 31450000,    'max_income' => 33950000,   'rate' => 0.1300],
            ['min_income' => 33950000,    'max_income' => 37100000,   'rate' => 0.1400],
            ['min_income' => 37100000,    'max_income' => 41100000,   'rate' => 0.1500],
            ['min_income' => 41100000,    'max_income' => 45800000,   'rate' => 0.1600],
            ['min_income' => 45800000,    'max_income' => 49500000,   'rate' => 0.1700],
            ['min_income' => 49500000,    'max_income' => 53800000,   'rate' => 0.1800],
            ['min_income' => 53800000,    'max_income' => 58500000,   'rate' => 0.1900],
            ['min_income' => 58500000,    'max_income' => 64000000,   'rate' => 0.2000],
            ['min_income' => 64000000,    'max_income' => 71000000,   'rate' => 0.2100],
            ['min_income' => 71000000,    'max_income' => 80000000,   'rate' => 0.2200],
            ['min_income' => 80000000,    'max_income' => 93000000,   'rate' => 0.2300],
            ['min_income' => 93000000,    'max_income' => 109000000,  'rate' => 0.2400],
            ['min_income' => 109000000,   'max_income' => 129000000,  'rate' => 0.2500],
            ['min_income' => 129000000,   'max_income' => 163000000,  'rate' => 0.2600],
            ['min_income' => 163000000,   'max_income' => 211000000,  'rate' => 0.2700],
            ['min_income' => 211000000,   'max_income' => 374000000,  'rate' => 0.2800],
            ['min_income' => 374000000,   'max_income' => 459000000,  'rate' => 0.2900],
            ['min_income' => 459000000,   'max_income' => 555000000,  'rate' => 0.3000],
            ['min_income' => 555000000,   'max_income' => 704000000,  'rate' => 0.3100],
            ['min_income' => 704000000,   'max_income' => 957000000,  'rate' => 0.3200],
            ['min_income' => 957000000,   'max_income' => 1405000000, 'rate' => 0.3300],
            ['min_income' => 1405000000,  'max_income' => null,       'rate' => 0.3400],
        ];
        foreach ($rows as $r) {
            $r['category']       = 'B';
            $r['effective_year'] = $year;
            $r['created_at']     = $now;
            $this->db->table('ter_rates')->insert($r);
        }
    }

    /**
     * TER Category C: K/3
     */
    private function seedTerCategoryC(string $now): void
    {
        $year = (int) date('Y');
        $exists = $this->db->table('ter_rates')->where('category', 'C')->where('effective_year', $year)->countAllResults();
        if ($exists > 0) return;

        $rows = [
            ['min_income' => 0,           'max_income' => 6600000,    'rate' => 0.0000],
            ['min_income' => 6600000,     'max_income' => 6950000,    'rate' => 0.0025],
            ['min_income' => 6950000,     'max_income' => 7350000,    'rate' => 0.0050],
            ['min_income' => 7350000,     'max_income' => 7800000,    'rate' => 0.0075],
            ['min_income' => 7800000,     'max_income' => 8850000,    'rate' => 0.0100],
            ['min_income' => 8850000,     'max_income' => 9800000,    'rate' => 0.0125],
            ['min_income' => 9800000,     'max_income' => 10950000,   'rate' => 0.0150],
            ['min_income' => 10950000,    'max_income' => 11200000,   'rate' => 0.0175],
            ['min_income' => 11200000,    'max_income' => 12050000,   'rate' => 0.0200],
            ['min_income' => 12050000,    'max_income' => 12950000,   'rate' => 0.0300],
            ['min_income' => 12950000,    'max_income' => 14150000,   'rate' => 0.0400],
            ['min_income' => 14150000,    'max_income' => 15550000,   'rate' => 0.0500],
            ['min_income' => 15550000,    'max_income' => 17050000,   'rate' => 0.0600],
            ['min_income' => 17050000,    'max_income' => 19500000,   'rate' => 0.0700],
            ['min_income' => 19500000,    'max_income' => 22700000,   'rate' => 0.0800],
            ['min_income' => 22700000,    'max_income' => 26600000,   'rate' => 0.0900],
            ['min_income' => 26600000,    'max_income' => 28100000,   'rate' => 0.1000],
            ['min_income' => 28100000,    'max_income' => 30100000,   'rate' => 0.1100],
            ['min_income' => 30100000,    'max_income' => 32600000,   'rate' => 0.1200],
            ['min_income' => 32600000,    'max_income' => 35400000,   'rate' => 0.1300],
            ['min_income' => 35400000,    'max_income' => 38900000,   'rate' => 0.1400],
            ['min_income' => 38900000,    'max_income' => 43000000,   'rate' => 0.1500],
            ['min_income' => 43000000,    'max_income' => 47400000,   'rate' => 0.1600],
            ['min_income' => 47400000,    'max_income' => 51200000,   'rate' => 0.1700],
            ['min_income' => 51200000,    'max_income' => 55800000,   'rate' => 0.1800],
            ['min_income' => 55800000,    'max_income' => 60400000,   'rate' => 0.1900],
            ['min_income' => 60400000,    'max_income' => 66700000,   'rate' => 0.2000],
            ['min_income' => 66700000,    'max_income' => 74500000,   'rate' => 0.2100],
            ['min_income' => 74500000,    'max_income' => 83200000,   'rate' => 0.2200],
            ['min_income' => 83200000,    'max_income' => 95600000,   'rate' => 0.2300],
            ['min_income' => 95600000,    'max_income' => 110000000,  'rate' => 0.2400],
            ['min_income' => 110000000,   'max_income' => 134000000,  'rate' => 0.2500],
            ['min_income' => 134000000,   'max_income' => 169000000,  'rate' => 0.2600],
            ['min_income' => 169000000,   'max_income' => 221000000,  'rate' => 0.2700],
            ['min_income' => 221000000,   'max_income' => 390000000,  'rate' => 0.2800],
            ['min_income' => 390000000,   'max_income' => 463000000,  'rate' => 0.2900],
            ['min_income' => 463000000,   'max_income' => 561000000,  'rate' => 0.3000],
            ['min_income' => 561000000,   'max_income' => 709000000,  'rate' => 0.3100],
            ['min_income' => 709000000,   'max_income' => 965000000,  'rate' => 0.3200],
            ['min_income' => 965000000,   'max_income' => 1419000000, 'rate' => 0.3300],
            ['min_income' => 1419000000,  'max_income' => null,       'rate' => 0.3400],
        ];
        foreach ($rows as $r) {
            $r['category']       = 'C';
            $r['effective_year'] = $year;
            $r['created_at']     = $now;
            $this->db->table('ter_rates')->insert($r);
        }
    }
}
