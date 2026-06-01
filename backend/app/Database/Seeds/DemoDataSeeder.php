<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * DemoDataSeeder — sample data realistis untuk semua modul HRIS.
 *
 * Membuat:
 *  - Branches (3 cabang)
 *  - Departments (5 departemen)
 *  - Job Grades (5 level)
 *  - Positions (10 jabatan)
 *  - Employees (15 karyawan + user account)
 *  - Shifts tambahan (Pagi/Siang/Malam)
 *  - Shift schedules untuk 2 minggu ke depan
 *  - Attendances 30 hari terakhir
 *  - Leave requests sample
 *  - Overtime requests sample
 *  - Payroll period bulan berjalan + draft
 *  - Reimbursements & loans contoh
 *  - Notifications sample
 *
 * Idempotent: cek existing pakai natural key (code/email).
 *
 * Jalankan:
 *   php spark db:seed DemoDataSeeder
 *
 * Pra-syarat: InitialSeeder & IndonesianMastersSeeder sudah dijalankan.
 */
class DemoDataSeeder extends Seeder
{
    private int $companyId = 0;
    private string $now;
    /** Cache: {code => id} */
    private array $branches = [];
    private array $departments = [];
    private array $jobGrades = [];
    private array $positions = [];
    private array $shifts = [];
    private array $employees = [];
    private array $users = [];

    public function run(): void
    {
        $this->now = date('Y-m-d H:i:s');

        // Cari demo company
        $company = $this->db->table('companies')->where('code', 'DEMO')->get()->getRowArray();
        if (! $company) {
            echo "  Demo company tidak ditemukan. Jalankan InitialSeeder dulu.\n";
            return;
        }
        $this->companyId = (int) $company['id'];
        echo "  Seeding demo data untuk company '{$company['name']}' (id={$this->companyId})\n";

        $this->seedBranches();
        $this->seedDepartments();
        $this->seedJobGrades();
        $this->seedPositions();
        $this->seedShifts();
        $this->seedEmployees();
        $this->seedShiftSchedules();
        $this->seedAttendances();
        $this->seedLeaves();
        $this->seedOvertimes();
        $this->seedReimbursements();
        $this->seedLoans();
        $this->seedPayrollPeriod();
        $this->seedNotifications();

        echo "\n  Selesai. Login sebagai admin@hris.local atau employee.demo.X@hris.local (password: Demo@12345).\n\n";
    }

    private function seedBranches(): void
    {
        $rows = [
            ['code' => 'JKT', 'name' => 'Jakarta Pusat', 'province' => 'DKI Jakarta', 'city' => 'Jakarta Pusat',
             'address' => 'Jl. MH Thamrin No. 1', 'phone' => '0211234567',
             'lat' => -6.1944, 'lng' => 106.8229, 'geofence_radius_m' => 200],
            ['code' => 'BDG', 'name' => 'Bandung', 'province' => 'Jawa Barat', 'city' => 'Bandung',
             'address' => 'Jl. Asia Afrika No. 8', 'phone' => '0221234567',
             'lat' => -6.9218, 'lng' => 107.6075, 'geofence_radius_m' => 200],
            ['code' => 'SBY', 'name' => 'Surabaya', 'province' => 'Jawa Timur', 'city' => 'Surabaya',
             'address' => 'Jl. Tunjungan No. 12', 'phone' => '0311234567',
             'lat' => -7.2575, 'lng' => 112.7521, 'geofence_radius_m' => 200],
        ];
        foreach ($rows as $r) {
            $existing = $this->db->table('branches')
                ->where('company_id', $this->companyId)
                ->where('code', $r['code'])
                ->get()->getRowArray();
            if ($existing) {
                $this->branches[$r['code']] = (int) $existing['id'];
                continue;
            }
            $this->db->table('branches')->insert(array_merge($r, [
                'company_id' => $this->companyId,
                'is_active'  => 1,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]));
            $this->branches[$r['code']] = (int) $this->db->insertID();
        }
    }

    private function seedDepartments(): void
    {
        $rows = [
            ['code' => 'HR',  'name' => 'Human Resources'],
            ['code' => 'FIN', 'name' => 'Finance & Accounting'],
            ['code' => 'IT',  'name' => 'Information Technology'],
            ['code' => 'MKT', 'name' => 'Marketing'],
            ['code' => 'OPS', 'name' => 'Operations'],
        ];
        foreach ($rows as $r) {
            $existing = $this->db->table('departments')
                ->where('company_id', $this->companyId)
                ->where('code', $r['code'])
                ->get()->getRowArray();
            if ($existing) {
                $this->departments[$r['code']] = (int) $existing['id'];
                continue;
            }
            $this->db->table('departments')->insert([
                'company_id' => $this->companyId,
                'code'       => $r['code'],
                'name'       => $r['name'],
                'is_active'  => 1,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
            $this->departments[$r['code']] = (int) $this->db->insertID();
        }
    }

    private function seedJobGrades(): void
    {
        $rows = [
            ['code' => 'JG1', 'name' => 'Staff',         'level' => 1, 'min_salary' => 5000000,  'max_salary' => 8000000],
            ['code' => 'JG2', 'name' => 'Senior Staff',  'level' => 2, 'min_salary' => 8000000,  'max_salary' => 12000000],
            ['code' => 'JG3', 'name' => 'Supervisor',    'level' => 3, 'min_salary' => 12000000, 'max_salary' => 18000000],
            ['code' => 'JG4', 'name' => 'Manager',       'level' => 4, 'min_salary' => 18000000, 'max_salary' => 30000000],
            ['code' => 'JG5', 'name' => 'Senior Manager','level' => 5, 'min_salary' => 30000000, 'max_salary' => 50000000],
        ];
        foreach ($rows as $r) {
            $existing = $this->db->table('job_grades')
                ->where('company_id', $this->companyId)
                ->where('code', $r['code'])
                ->get()->getRowArray();
            if ($existing) {
                $this->jobGrades[$r['code']] = (int) $existing['id'];
                continue;
            }
            $this->db->table('job_grades')->insert(array_merge($r, [
                'company_id' => $this->companyId,
                'is_active'  => 1,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]));
            $this->jobGrades[$r['code']] = (int) $this->db->insertID();
        }
    }

    private function seedPositions(): void
    {
        $rows = [
            ['code' => 'HRMGR', 'name' => 'HR Manager',          'dept' => 'HR',  'grade' => 'JG4'],
            ['code' => 'HRSPV', 'name' => 'HR Supervisor',       'dept' => 'HR',  'grade' => 'JG3'],
            ['code' => 'HRSTF', 'name' => 'HR Staff',            'dept' => 'HR',  'grade' => 'JG1'],
            ['code' => 'FINMG', 'name' => 'Finance Manager',     'dept' => 'FIN', 'grade' => 'JG4'],
            ['code' => 'ACCNT', 'name' => 'Accountant',          'dept' => 'FIN', 'grade' => 'JG2'],
            ['code' => 'ITDIR', 'name' => 'IT Director',         'dept' => 'IT',  'grade' => 'JG5'],
            ['code' => 'BEDEV', 'name' => 'Backend Developer',   'dept' => 'IT',  'grade' => 'JG2'],
            ['code' => 'FEDEV', 'name' => 'Frontend Developer',  'dept' => 'IT',  'grade' => 'JG2'],
            ['code' => 'MKTMG', 'name' => 'Marketing Manager',   'dept' => 'MKT', 'grade' => 'JG4'],
            ['code' => 'OPSST', 'name' => 'Operations Staff',    'dept' => 'OPS', 'grade' => 'JG1'],
        ];
        foreach ($rows as $r) {
            $existing = $this->db->table('positions')
                ->where('company_id', $this->companyId)
                ->where('code', $r['code'])
                ->get()->getRowArray();
            if ($existing) {
                $this->positions[$r['code']] = (int) $existing['id'];
                continue;
            }
            $this->db->table('positions')->insert([
                'company_id'    => $this->companyId,
                'code'          => $r['code'],
                'name'          => $r['name'],
                'department_id' => $this->departments[$r['dept']] ?? null,
                'job_grade_id'  => $this->jobGrades[$r['grade']] ?? null,
                'is_active'     => 1,
                'created_at'    => $this->now,
                'updated_at'    => $this->now,
            ]);
            $this->positions[$r['code']] = (int) $this->db->insertID();
        }
    }

    private function seedShifts(): void
    {
        $rows = [
            ['code' => 'PAGI',  'name' => 'Shift Pagi 06:00-14:00',  'start' => '06:00:00', 'end' => '14:00:00'],
            ['code' => 'SIANG', 'name' => 'Shift Siang 14:00-22:00', 'start' => '14:00:00', 'end' => '22:00:00'],
            ['code' => 'MALAM', 'name' => 'Shift Malam 22:00-06:00', 'start' => '22:00:00', 'end' => '06:00:00', 'crosses' => 1],
        ];
        // ambil REG yang sudah ada di InitialSeeder
        $reg = $this->db->table('shifts')
            ->where('company_id', $this->companyId)
            ->where('code', 'REG')->get()->getRowArray();
        if ($reg) $this->shifts['REG'] = (int) $reg['id'];

        foreach ($rows as $r) {
            $existing = $this->db->table('shifts')
                ->where('company_id', $this->companyId)
                ->where('code', $r['code'])
                ->get()->getRowArray();
            if ($existing) {
                $this->shifts[$r['code']] = (int) $existing['id'];
                continue;
            }
            $this->db->table('shifts')->insert([
                'company_id'             => $this->companyId,
                'code'                   => $r['code'],
                'name'                   => $r['name'],
                'start_time'             => $r['start'],
                'end_time'               => $r['end'],
                'break_minutes'          => 60,
                'crosses_midnight'       => $r['crosses'] ?? 0,
                'late_tolerance_minutes' => 15,
                'early_leave_tolerance_minutes' => 15,
                'is_active'              => 1,
                'created_at'             => $this->now,
                'updated_at'             => $this->now,
            ]);
            $this->shifts[$r['code']] = (int) $this->db->insertID();
        }
    }

    private function seedEmployees(): void
    {
        // Daftar karyawan dummy realistis
        $employees = [
            ['num' => 'EMP-001', 'first' => 'Budi',     'last' => 'Santoso',   'gender' => 'M', 'pos' => 'HRMGR', 'role_slug' => 'hr_admin',  'salary' => 22000000, 'branch' => 'JKT', 'ptkp' => 'K2', 'religion' => 'Islam'],
            ['num' => 'EMP-002', 'first' => 'Siti',     'last' => 'Aminah',    'gender' => 'F', 'pos' => 'HRSTF', 'role_slug' => 'employee',  'salary' => 6500000,  'branch' => 'JKT', 'ptkp' => 'TK0', 'religion' => 'Islam'],
            ['num' => 'EMP-003', 'first' => 'Rina',     'last' => 'Wati',      'gender' => 'F', 'pos' => 'HRSPV', 'role_slug' => 'manager',   'salary' => 14000000, 'branch' => 'JKT', 'ptkp' => 'K1', 'religion' => 'Islam'],
            ['num' => 'EMP-004', 'first' => 'Andi',     'last' => 'Pratama',   'gender' => 'M', 'pos' => 'FINMG', 'role_slug' => 'finance',   'salary' => 21000000, 'branch' => 'JKT', 'ptkp' => 'K3', 'religion' => 'Islam'],
            ['num' => 'EMP-005', 'first' => 'Dewi',     'last' => 'Lestari',   'gender' => 'F', 'pos' => 'ACCNT', 'role_slug' => 'employee',  'salary' => 9500000,  'branch' => 'JKT', 'ptkp' => 'TK0', 'religion' => 'Kristen'],
            ['num' => 'EMP-006', 'first' => 'Joko',     'last' => 'Widodo',    'gender' => 'M', 'pos' => 'ITDIR', 'role_slug' => 'manager',   'salary' => 35000000, 'branch' => 'JKT', 'ptkp' => 'K2', 'religion' => 'Islam'],
            ['num' => 'EMP-007', 'first' => 'Agus',     'last' => 'Saputra',   'gender' => 'M', 'pos' => 'BEDEV', 'role_slug' => 'employee',  'salary' => 11000000, 'branch' => 'BDG', 'ptkp' => 'TK0', 'religion' => 'Katolik'],
            ['num' => 'EMP-008', 'first' => 'Maya',     'last' => 'Putri',     'gender' => 'F', 'pos' => 'FEDEV', 'role_slug' => 'employee',  'salary' => 10500000, 'branch' => 'BDG', 'ptkp' => 'TK1', 'religion' => 'Hindu'],
            ['num' => 'EMP-009', 'first' => 'Rudi',     'last' => 'Hartono',   'gender' => 'M', 'pos' => 'BEDEV', 'role_slug' => 'employee',  'salary' => 12000000, 'branch' => 'JKT', 'ptkp' => 'K1', 'religion' => 'Islam'],
            ['num' => 'EMP-010', 'first' => 'Linda',    'last' => 'Marpaung',  'gender' => 'F', 'pos' => 'MKTMG', 'role_slug' => 'manager',   'salary' => 19000000, 'branch' => 'SBY', 'ptkp' => 'K2', 'religion' => 'Kristen'],
            ['num' => 'EMP-011', 'first' => 'Bayu',     'last' => 'Permana',   'gender' => 'M', 'pos' => 'OPSST', 'role_slug' => 'employee',  'salary' => 5500000,  'branch' => 'SBY', 'ptkp' => 'TK0', 'religion' => 'Islam'],
            ['num' => 'EMP-012', 'first' => 'Indah',    'last' => 'Permatasari','gender'=> 'F', 'pos' => 'OPSST', 'role_slug' => 'employee',  'salary' => 5300000,  'branch' => 'BDG', 'ptkp' => 'TK0', 'religion' => 'Islam'],
            ['num' => 'EMP-013', 'first' => 'Hadi',     'last' => 'Setiawan',  'gender' => 'M', 'pos' => 'BEDEV', 'role_slug' => 'employee',  'salary' => 13000000, 'branch' => 'JKT', 'ptkp' => 'K0', 'religion' => 'Islam'],
            ['num' => 'EMP-014', 'first' => 'Putri',    'last' => 'Cahyani',   'gender' => 'F', 'pos' => 'ACCNT', 'role_slug' => 'employee',  'salary' => 8800000,  'branch' => 'JKT', 'ptkp' => 'TK0', 'religion' => 'Buddha'],
            ['num' => 'EMP-015', 'first' => 'Eko',      'last' => 'Nugroho',   'gender' => 'M', 'pos' => 'OPSST', 'role_slug' => 'employee',  'salary' => 5800000,  'branch' => 'SBY', 'ptkp' => 'K1', 'religion' => 'Islam'],
        ];

        $roleIds = [];
        foreach (['super_admin','hr_admin','manager','employee','finance'] as $slug) {
            $r = $this->db->table('roles')->where('slug', $slug)->get()->getRowArray();
            if ($r) $roleIds[$slug] = (int) $r['id'];
        }

        $enc = \Config\Services::encryption();
        $tempPassword = 'Demo@12345';
        $passwordHash = password_hash($tempPassword, PASSWORD_ARGON2ID);

        foreach ($employees as $e) {
            $existing = $this->db->table('employees')
                ->where('company_id', $this->companyId)
                ->where('employee_number', $e['num'])
                ->get()->getRowArray();
            if ($existing) {
                $this->employees[$e['num']] = (int) $existing['id'];
                if ($existing['user_id']) $this->users[$e['num']] = (int) $existing['user_id'];
                continue;
            }

            $email     = strtolower(str_replace(' ', '.', $e['first'] . '.' . $e['last'])) . '@hris.local';
            $position  = $this->positions[$e['pos']] ?? null;
            $deptCode  = null;
            foreach ($this->positions as $code => $id) {
                if ($id === $position) { $deptCode = null; break; }
            }
            // Cari department dari position
            $posRow = $this->db->table('positions')->where('id', $position)->get()->getRowArray();
            $deptId = $posRow ? (int) $posRow['department_id'] : null;
            $gradeId = $posRow ? (int) $posRow['job_grade_id'] : null;

            $joinDate  = date('Y-m-d', strtotime('-' . random_int(180, 1500) . ' days'));
            $birthDate = date('Y-m-d', strtotime('-' . random_int(22, 50) . ' years -' . random_int(0, 365) . ' days'));

            // NIK fake: 16 digit, format PPKKCC DDMMYY NNNN
            $birthSeg = ($e['gender'] === 'F' ? str_pad((int)substr($birthDate, 8, 2) + 40, 2, '0', STR_PAD_LEFT) : substr($birthDate, 8, 2))
                      . substr($birthDate, 5, 2)
                      . substr($birthDate, 2, 2);
            $nik = '3171' . '01' . $birthSeg . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

            $insert = [
                'company_id'        => $this->companyId,
                'branch_id'         => $this->branches[$e['branch']] ?? null,
                'department_id'     => $deptId,
                'position_id'       => $position,
                'job_grade_id'      => $gradeId,
                'employee_number'   => $e['num'],
                'first_name'        => $e['first'],
                'last_name'         => $e['last'],
                'full_name'         => $e['first'] . ' ' . $e['last'],
                'gender'            => $e['gender'],
                'religion'          => $e['religion'],
                'marital_status'    => $e['ptkp'],
                'place_of_birth'    => $e['branch'] === 'JKT' ? 'Jakarta' : ($e['branch'] === 'BDG' ? 'Bandung' : 'Surabaya'),
                'date_of_birth'     => $birthDate,
                'blood_type'        => ['A','B','AB','O'][random_int(0, 3)],
                'nik_encrypted'     => $enc->encrypt($nik),
                'nik_hash'          => $enc->hash($nik),
                'npwp_encrypted'    => $enc->encrypt('09' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT) . '7' . random_int(100, 999) . '000'),
                'bpjs_kesehatan_no' => '00' . str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT),
                'bpjs_tk_no'        => str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT),
                'bank_name'         => 'BCA',
                'bank_account_encrypted' => $enc->encrypt(str_pad((string) random_int(1000000000, 9999999999), 10, '0', STR_PAD_LEFT)),
                'bank_account_holder' => $e['first'] . ' ' . $e['last'],
                'email'             => $email,
                'phone'             => '08' . random_int(100000000, 999999999),
                'address'           => 'Jl. Contoh No. ' . random_int(1, 100),
                'province'          => $e['branch'] === 'JKT' ? 'DKI Jakarta' : ($e['branch'] === 'BDG' ? 'Jawa Barat' : 'Jawa Timur'),
                'city'              => $e['branch'] === 'JKT' ? 'Jakarta Pusat' : ($e['branch'] === 'BDG' ? 'Bandung' : 'Surabaya'),
                'postal_code'       => $e['branch'] === 'JKT' ? '10110' : ($e['branch'] === 'BDG' ? '40111' : '60111'),
                'emergency_contact_name'  => 'Keluarga ' . $e['first'],
                'emergency_contact_phone' => '08' . random_int(100000000, 999999999),
                'emergency_contact_rel'   => $e['gender'] === 'M' ? 'Istri' : 'Suami',
                'employment_status' => 'PKWTT',
                'employment_type'   => 'FULL_TIME',
                'join_date'         => $joinDate,
                'basic_salary'      => $e['salary'],
                'tax_method'        => 'GROSS',
                'ptkp_status'       => $e['ptkp'],
                'pph21_method'      => 'TER',
                'is_active'         => 1,
                'created_at'        => $this->now,
                'updated_at'        => $this->now,
            ];
            $this->db->table('employees')->insert($insert);
            $empId = (int) $this->db->insertID();
            $this->employees[$e['num']] = $empId;

            // Buat user account
            $roleId = $roleIds[$e['role_slug']] ?? $roleIds['employee'];
            $this->db->table('users')->insert([
                'company_id'           => $this->companyId,
                'role_id'              => $roleId,
                'employee_id'          => $empId,
                'email'                => $email,
                'password_hash'        => $passwordHash,
                'name'                 => $e['first'] . ' ' . $e['last'],
                'is_active'            => 1,
                'must_change_password' => 0,    // demo: tidak perlu paksa ganti
                'password_changed_at'  => $this->now,
                'created_at'           => $this->now,
                'updated_at'           => $this->now,
            ]);
            $userId = (int) $this->db->insertID();
            $this->users[$e['num']] = $userId;

            // Update employee dengan user_id
            $this->db->table('employees')->where('id', $empId)->update(['user_id' => $userId]);

            // Tambah kontrak
            $this->db->table('employee_contracts')->insert([
                'company_id'      => $this->companyId,
                'employee_id'     => $empId,
                'contract_number' => 'CTR-' . $e['num'],
                'contract_type'   => 'PKWTT',
                'start_date'      => $joinDate,
                'end_date'        => null,
                'basic_salary'    => $e['salary'],
                'position_id'     => $position,
                'department_id'   => $deptId,
                'notes'           => 'Kontrak tetap',
                'created_at'      => $this->now,
                'updated_at'      => $this->now,
            ]);
        }
        echo "  " . count($this->employees) . " karyawan demo siap.\n";
    }

    private function seedShiftSchedules(): void
    {
        $shiftId = $this->shifts['REG'] ?? null;
        if (! $shiftId) return;

        // Schedule 14 hari ke depan untuk semua karyawan
        $start = strtotime('today');
        foreach ($this->employees as $empId) {
            for ($i = 0; $i < 14; $i++) {
                $date = date('Y-m-d', $start + $i * 86400);
                $dow = (int) date('N', $start + $i * 86400);
                $isOff = $dow >= 6 ? 1 : 0;

                $exists = $this->db->table('shift_schedules')
                    ->where('employee_id', $empId)
                    ->where('work_date', $date)
                    ->countAllResults();
                if ($exists > 0) continue;

                $this->db->table('shift_schedules')->insert([
                    'company_id'  => $this->companyId,
                    'employee_id' => $empId,
                    'shift_id'    => $isOff ? null : $shiftId,
                    'work_date'   => $date,
                    'is_off'      => $isOff,
                    'is_holiday'  => 0,
                    'created_at'  => $this->now,
                    'updated_at'  => $this->now,
                ]);
            }
        }
    }

    private function seedAttendances(): void
    {
        $shiftId = $this->shifts['REG'] ?? null;
        if (! $shiftId) return;

        $today = strtotime('today');
        foreach ($this->employees as $empNum => $empId) {
            for ($i = 30; $i >= 1; $i--) {
                $ts = $today - $i * 86400;
                $date = date('Y-m-d', $ts);
                $dow = (int) date('N', $ts);
                if ($dow >= 6) continue; // skip weekend

                $exists = $this->db->table('attendances')
                    ->where('employee_id', $empId)
                    ->where('work_date', $date)
                    ->countAllResults();
                if ($exists > 0) continue;

                // 5% absent, 15% late, sisanya hadir
                $r = random_int(1, 100);
                if ($r <= 5) {
                    $row = [
                        'company_id'  => $this->companyId,
                        'employee_id' => $empId,
                        'work_date'   => $date,
                        'status'      => 'ABSENT',
                        'created_at'  => $this->now,
                        'updated_at'  => $this->now,
                    ];
                } else {
                    $isLate = $r <= 20;
                    $clockInHour = $isLate ? '09:' . random_int(15, 45) . ':00' : '08:' . str_pad((string) random_int(30, 59), 2, '0', STR_PAD_LEFT) . ':00';
                    $clockOutHour = '17:' . str_pad((string) random_int(0, 30), 2, '0', STR_PAD_LEFT) . ':00';
                    $clockIn  = $date . ' ' . $clockInHour;
                    $clockOut = $date . ' ' . $clockOutHour;
                    $workMin  = (int) round((strtotime($clockOut) - strtotime($clockIn)) / 60);
                    $lateMin  = $isLate ? max(0, (int) round((strtotime($clockIn) - strtotime($date . ' 09:00:00')) / 60)) : 0;
                    $row = [
                        'company_id'  => $this->companyId,
                        'employee_id' => $empId,
                        'shift_id'    => $shiftId,
                        'work_date'   => $date,
                        'clock_in_at' => $clockIn,
                        'clock_out_at'=> $clockOut,
                        'work_minutes'=> $workMin,
                        'late_minutes'=> $lateMin,
                        'status'      => $isLate ? 'LATE' : 'PRESENT',
                        'created_at'  => $this->now,
                        'updated_at'  => $this->now,
                    ];
                }
                $this->db->table('attendances')->insert($row);
            }
        }
        echo "  Attendance 30 hari terakhir digenerate.\n";
    }

    private function seedLeaves(): void
    {
        $leaveTypes = $this->db->table('leave_types')
            ->where('company_id', $this->companyId)
            ->get()->getResultArray();
        $byCode = [];
        foreach ($leaveTypes as $lt) $byCode[$lt['code']] = $lt;

        $samples = [
            ['emp' => 'EMP-002', 'code' => 'CT', 'days_offset' => -10, 'duration' => 2, 'reason' => 'Liburan keluarga ke Yogyakarta', 'status' => 'APPROVED'],
            ['emp' => 'EMP-005', 'code' => 'CS', 'days_offset' => -5,  'duration' => 1, 'reason' => 'Demam tinggi, ada surat dokter', 'status' => 'APPROVED'],
            ['emp' => 'EMP-008', 'code' => 'CT', 'days_offset' => 7,   'duration' => 3, 'reason' => 'Acara keluarga di Bali',         'status' => 'PENDING'],
            ['emp' => 'EMP-007', 'code' => 'CN', 'days_offset' => 15,  'duration' => 3, 'reason' => 'Pernikahan saya',                'status' => 'PENDING'],
            ['emp' => 'EMP-011', 'code' => 'CD', 'days_offset' => -2,  'duration' => 2, 'reason' => 'Orang tua meninggal',            'status' => 'APPROVED'],
            ['emp' => 'EMP-012', 'code' => 'CH', 'days_offset' => -3,  'duration' => 1, 'reason' => 'Cuti haid',                      'status' => 'APPROVED'],
        ];

        foreach ($samples as $s) {
            if (! isset($this->employees[$s['emp']]) || ! isset($byCode[$s['code']])) continue;

            $reqNumber = 'LV-' . date('Ymd', strtotime("today {$s['days_offset']} days")) . '-' . strtoupper(substr(md5($s['emp'] . $s['code']), 0, 6));
            $exists = $this->db->table('leaves')->where('request_number', $reqNumber)->countAllResults();
            if ($exists > 0) continue;

            $startDate = date('Y-m-d', strtotime("today {$s['days_offset']} days"));
            $endDate   = date('Y-m-d', strtotime($startDate . ' +' . ($s['duration'] - 1) . ' days'));

            $this->db->table('leaves')->insert([
                'company_id'     => $this->companyId,
                'employee_id'    => $this->employees[$s['emp']],
                'leave_type_id'  => (int) $byCode[$s['code']]['id'],
                'request_number' => $reqNumber,
                'start_date'     => $startDate,
                'end_date'       => $endDate,
                'days'           => $s['duration'],
                'reason'         => $s['reason'],
                'status'         => $s['status'],
                'approved_by'    => $s['status'] === 'APPROVED' ? ($this->users['EMP-001'] ?? null) : null,
                'approved_at'    => $s['status'] === 'APPROVED' ? $this->now : null,
                'created_at'     => date('Y-m-d H:i:s', strtotime($startDate . ' -1 day')),
                'updated_at'     => $this->now,
            ]);
        }
    }

    private function seedOvertimes(): void
    {
        $samples = [
            ['emp' => 'EMP-007', 'days_offset' => -3,  'hours' => 3.0, 'reason' => 'Deploy aplikasi versi baru', 'status' => 'APPROVED', 'day_type' => 'WEEKDAY'],
            ['emp' => 'EMP-009', 'days_offset' => -7,  'hours' => 4.0, 'reason' => 'Bug fixing critical',       'status' => 'APPROVED', 'day_type' => 'WEEKEND'],
            ['emp' => 'EMP-008', 'days_offset' => -1,  'hours' => 2.5, 'reason' => 'Selesaikan UI redesign',    'status' => 'PENDING',  'day_type' => 'WEEKDAY'],
            ['emp' => 'EMP-013', 'days_offset' => -2,  'hours' => 3.5, 'reason' => 'Code review urgent',         'status' => 'PENDING',  'day_type' => 'WEEKDAY'],
        ];
        foreach ($samples as $s) {
            if (! isset($this->employees[$s['emp']])) continue;

            $reqNumber = 'OT-' . date('Ymd', strtotime("today {$s['days_offset']} days")) . '-' . strtoupper(substr(md5($s['emp']), 0, 6));
            $exists = $this->db->table('overtimes')->where('request_number', $reqNumber)->countAllResults();
            if ($exists > 0) continue;

            $workDate = date('Y-m-d', strtotime("today {$s['days_offset']} days"));
            $start = $workDate . ' 18:00:00';
            $end   = date('Y-m-d H:i:s', strtotime($start . ' +' . ((int) ($s['hours'] * 60)) . ' minutes'));

            $emp = $this->db->table('employees')->where('id', $this->employees[$s['emp']])->get()->getRowArray();
            $base = (float) $emp['basic_salary'];
            $calc = new \App\Libraries\Attendance\OvertimeCalculator();
            $amount = $s['status'] === 'APPROVED' ? $calc->calculate($base, $s['hours'], $s['day_type']) : 0;

            $this->db->table('overtimes')->insert([
                'company_id'    => $this->companyId,
                'employee_id'   => $this->employees[$s['emp']],
                'request_number'=> $reqNumber,
                'work_date'     => $workDate,
                'start_time'    => $start,
                'end_time'      => $end,
                'hours'         => $s['hours'],
                'day_type'      => $s['day_type'],
                'reason'        => $s['reason'],
                'computed_amount' => $amount,
                'status'        => $s['status'],
                'approved_by'   => $s['status'] === 'APPROVED' ? ($this->users['EMP-001'] ?? null) : null,
                'approved_at'   => $s['status'] === 'APPROVED' ? $this->now : null,
                'created_at'    => $this->now,
                'updated_at'    => $this->now,
            ]);
        }
    }

    private function seedReimbursements(): void
    {
        $samples = [
            ['emp' => 'EMP-007', 'cat' => 'TRANSPORT', 'amount' => 250000, 'desc' => 'Taksi ke kantor klien Bandung', 'status' => 'APPROVED'],
            ['emp' => 'EMP-005', 'cat' => 'MEDICAL',   'amount' => 850000, 'desc' => 'Periksa gigi anak',             'status' => 'PENDING'],
            ['emp' => 'EMP-010', 'cat' => 'TRANSPORT', 'amount' => 1200000,'desc' => 'Tiket pesawat Surabaya-Jakarta','status' => 'APPROVED'],
            ['emp' => 'EMP-008', 'cat' => 'OTHER',     'amount' => 500000, 'desc' => 'Beli buku referensi UI/UX',     'status' => 'PENDING'],
        ];
        foreach ($samples as $s) {
            if (! isset($this->employees[$s['emp']])) continue;

            $reqNumber = 'RB-' . date('Ymd') . '-' . strtoupper(substr(md5($s['emp'] . $s['desc']), 0, 6));
            $exists = $this->db->table('reimbursements')->where('request_number', $reqNumber)->countAllResults();
            if ($exists > 0) continue;

            $this->db->table('reimbursements')->insert([
                'company_id'     => $this->companyId,
                'employee_id'    => $this->employees[$s['emp']],
                'request_number' => $reqNumber,
                'category'       => $s['cat'],
                'expense_date'   => date('Y-m-d', strtotime('-' . random_int(1, 14) . ' days')),
                'amount'         => $s['amount'],
                'description'    => $s['desc'],
                'status'         => $s['status'],
                'approved_by'    => $s['status'] === 'APPROVED' ? ($this->users['EMP-004'] ?? null) : null,
                'approved_at'    => $s['status'] === 'APPROVED' ? $this->now : null,
                'created_at'     => $this->now,
                'updated_at'     => $this->now,
            ]);
        }
    }

    private function seedLoans(): void
    {
        $samples = [
            ['emp' => 'EMP-011', 'principal' => 5000000,  'installments' => 5,  'reason' => 'Kebutuhan keluarga',          'status' => 'RUNNING',  'paid' => 1],
            ['emp' => 'EMP-012', 'principal' => 3000000,  'installments' => 3,  'reason' => 'Biaya sekolah anak',          'status' => 'PENDING',  'paid' => 0],
            ['emp' => 'EMP-015', 'principal' => 10000000, 'installments' => 10, 'reason' => 'Renovasi rumah',              'status' => 'APPROVED', 'paid' => 0],
        ];
        foreach ($samples as $s) {
            if (! isset($this->employees[$s['emp']])) continue;

            $loanNumber = 'LN-' . date('Ymd') . '-' . strtoupper(substr(md5($s['emp']), 0, 6));
            $exists = $this->db->table('loans')->where('loan_number', $loanNumber)->countAllResults();
            if ($exists > 0) continue;

            $installmentAmount = (float) $s['principal'] / $s['installments'];
            $remaining = $s['principal'] - ($s['paid'] * $installmentAmount);

            $this->db->table('loans')->insert([
                'company_id'         => $this->companyId,
                'employee_id'        => $this->employees[$s['emp']],
                'loan_number'        => $loanNumber,
                'principal'          => $s['principal'],
                'installment_amount' => $installmentAmount,
                'installments'       => $s['installments'],
                'paid_installments'  => $s['paid'],
                'remaining'          => $remaining,
                'reason'             => $s['reason'],
                'status'             => $s['status'],
                'approved_by'        => $s['status'] !== 'PENDING' ? ($this->users['EMP-004'] ?? null) : null,
                'approved_at'        => $s['status'] !== 'PENDING' ? $this->now : null,
                'created_at'         => $this->now,
                'updated_at'         => $this->now,
            ]);
        }
    }

    private function seedPayrollPeriod(): void
    {
        $year  = (int) date('Y');
        $month = (int) date('m');
        $code  = sprintf('P-%04d-%02d', $year, $month);
        $exists = $this->db->table('payroll_periods')
            ->where('company_id', $this->companyId)
            ->where('code', $code)
            ->countAllResults();
        if ($exists > 0) return;

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', strtotime($startDate));
        $payDate   = date('Y-m-d', strtotime('+5 days', strtotime($endDate)));

        $monthName = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][$month - 1];

        $this->db->table('payroll_periods')->insert([
            'company_id'   => $this->companyId,
            'code'         => $code,
            'name'         => "Payroll {$monthName} {$year}",
            'type'         => 'MONTHLY',
            'period_year'  => $year,
            'period_month' => $month,
            'start_date'   => $startDate,
            'end_date'     => $endDate,
            'pay_date'     => $payDate,
            'cutoff_attendance_date' => $endDate,
            'status'       => 'DRAFT',
            'created_by'   => $this->users['EMP-001'] ?? null,
            'created_at'   => $this->now,
            'updated_at'   => $this->now,
        ]);
    }

    private function seedNotifications(): void
    {
        // Beberapa notifikasi contoh untuk admin & user
        $notifs = [
            ['user' => 'EMP-001', 'type' => 'leave.requested',     'title' => 'Pengajuan cuti baru',    'msg' => 'Maya Putri mengajukan Cuti Tahunan 3 hari'],
            ['user' => 'EMP-001', 'type' => 'overtime.requested',  'title' => 'Pengajuan lembur baru',  'msg' => 'Hadi Setiawan mengajukan lembur 3.5 jam'],
            ['user' => 'EMP-003', 'type' => 'reimbursement.requested', 'title' => 'Permintaan reimbursement', 'msg' => 'Dewi Lestari mengajukan reimbursement medical Rp 850.000'],
            ['user' => 'EMP-008', 'type' => 'leave.requested',     'title' => 'Pengajuan cuti Anda diterima', 'msg' => 'Cuti Tahunan tanggal yang akan datang sedang diproses'],
        ];
        foreach ($notifs as $n) {
            if (! isset($this->users[$n['user']])) continue;
            $this->db->table('notifications')->insert([
                'company_id' => $this->companyId,
                'user_id'    => $this->users[$n['user']],
                'type'       => $n['type'],
                'title'      => $n['title'],
                'message'    => $n['msg'],
                'is_read'    => 0,
                'created_at' => $this->now,
            ]);
        }
    }
}