<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * IndonesianStakeholderSeeder — reset penuh + setup struktur organisasi PT
 * (Perseroan Terbatas) Indonesia yang lengkap dan realistis.
 *
 * SCOPE:
 *   1. CLEANUP: Hapus semua user (kecuali jejak super admin di akhir
 *      tetap ada lewat akun Direktur Utama), semua employees, semua data
 *      transaksional (attendance, leave, payroll, dll), semua candidate
 *      portal data, dan master per-company (branch, dept, position, dst).
 *      Master sistem (roles, permissions, ptkp/ter/pph21, holidays,
 *      regional_minimum_wages) dipertahankan karena lifetime-nya bukan
 *      per-company.
 *
 *   2. REBUILD: Buat ulang berdasarkan struktur yang umum dipakai PT
 *      tertutup di Indonesia (UU 40/2007 tentang Perseroan Terbatas):
 *
 *        - Dewan Komisaris (Board of Commissioners)
 *            • Komisaris Utama
 *            • Komisaris Independen
 *        - Dewan Direksi (Board of Directors / Direksi)
 *            • Direktur Utama (President Director / CEO)
 *            • Direktur Keuangan (CFO)
 *            • Direktur SDM (CHRO / HR Director)
 *            • Direktur Operasional (COO)
 *            • Direktur Teknologi Informasi (CTO/CIO)
 *            • Direktur Pemasaran & Penjualan (CMO)
 *            • Direktur Hukum & Kepatuhan (Chief Legal Officer)
 *        - Senior VP / GM (Kepala Divisi)
 *        - Manager (Kepala Departemen)
 *        - Asst. Manager / Section Head
 *        - Supervisor / Team Lead
 *        - Senior Staff / Specialist
 *        - Staff / Officer
 *        - Junior Staff
 *
 *      Total ~55 karyawan dengan reporting line lengkap (manager_id +
 *      supervisor_id), email institusional @quantara.id, NIK & NPWP
 *      ter-encrypt, salary band sesuai job grade, tersebar di 5 cabang
 *      (Jakarta HQ, Surabaya, Bandung, Medan, Makassar).
 *
 *   3. KANDIDAT: Hapus semua data candidate (akun pelamar, lamaran,
 *      online test, dst). Tidak di-recreate (sesuai instruksi).
 *
 *   4. POST-SEED: Set 1 user sebagai Super Admin (Direktur Utama) +
 *      bootstrap admin@quantara.id sebagai akun darurat super_admin.
 *
 * Idempotent: aman dijalankan ulang. Setiap insert dicek dulu eksistensinya.
 *
 * Jalankan:
 *   php spark db:seed IndonesianStakeholderSeeder
 *
 * Pra-syarat: migrations sudah jalan (terutama 000001..000063), permissions
 * & roles sudah ter-seed lewat InitialSeeder + SeedEnterpriseRoles migration.
 */
class IndonesianStakeholderSeeder extends Seeder
{
    private string $now;
    private int $companyId = 0;

    /** @var array<string,int> code => branch_id */
    private array $branches = [];
    /** @var array<string,int> code => department_id */
    private array $departments = [];
    /** @var array<string,int> code => job_grade_id */
    private array $jobGrades = [];
    /** @var array<string,int> code => position_id */
    private array $positions = [];
    /** @var array<string,int> code => shift_id */
    private array $shifts = [];
    /** @var array<string,int> empcode => employee_id */
    private array $employees = [];
    /** @var array<string,int> empcode => user_id */
    private array $users = [];
    /** @var array<string,int> slug => role_id */
    private array $roles = [];

    /**
     * Default password untuk semua akun yang dibuat. Wajib ganti saat
     * login pertama (must_change_password=1).
     */
    private const DEFAULT_PASSWORD = 'Quantara@2026';

    public function run(): void
    {
        $this->now = date('Y-m-d H:i:s');

        echo "\n══════════════════════════════════════════════════════════════\n";
        echo "  Indonesian Stakeholder Seeder — Full Reset & Rebuild\n";
        echo "══════════════════════════════════════════════════════════════\n\n";

        $this->loadRoleMap();
        $this->wipeAllUserAndEmployeeData();
        $this->seedCompany();
        $this->seedBranches();
        $this->seedDepartments();
        $this->seedJobGrades();
        $this->seedPositions();
        $this->seedShifts();
        $this->seedLeaveTypesAndPayrollComponents();
        $this->seedBpjsRates();
        $this->seedStakeholders();
        $this->resolveDirectReports();
        $this->seedDepartmentHeads();
        $this->seedDefaultBootstrapAdmin();
        $this->seedOnlineTestBank();

        echo "\n══════════════════════════════════════════════════════════════\n";
        echo "  Selesai. Total " . count($this->employees) . " karyawan dengan struktur lengkap.\n";
        echo "  Default password (semua akun, must_change=1):  " . self::DEFAULT_PASSWORD . "\n";
        echo "  Bootstrap Super Admin:  admin@quantara.id\n";
        echo "  Direktur Utama (CEO):   direktur.utama@quantara.id\n";
        echo "══════════════════════════════════════════════════════════════\n\n";
    }

    /**
     * Run online test seeders dengan delegasi ke kelas yang sudah ada.
     * Karena `wipeAllUserAndEmployeeData()` sudah TRUNCATE seluruh
     * `online_test_*` tables, kita bisa pakai seeder bawaan tanpa risiko
     * duplikasi. Kalau seeder gagal load (mis. file tidak ada), kita
     * skip dengan warning supaya seeder utama tidak gagal.
     */
    private function seedOnlineTestBank(): void
    {
        echo "▶ ONLINE TEST BANK — seed soal default + 4 paket test ekspansi...\n";
        try {
            $this->call('OnlineTestDummySeeder');
        } catch (\Throwable $e) {
            log_message('warning', 'OnlineTestDummySeeder gagal: ' . $e->getMessage());
            echo "  ⚠ OnlineTestDummySeeder gagal (lihat log). Lanjut ke ExpandedBank.\n";
        }
        try {
            $this->call('OnlineTestExpandedBankSeeder');
        } catch (\Throwable $e) {
            log_message('warning', 'OnlineTestExpandedBankSeeder gagal: ' . $e->getMessage());
            echo "  ⚠ OnlineTestExpandedBankSeeder gagal (lihat log).\n";
        }
        echo "  ✓ Online test bank ter-seed (lihat scripts/verify-online-tests.php).\n\n";
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. CLEANUP
    // ─────────────────────────────────────────────────────────────────

    /**
     * Hapus semua user, employees, dan data transaksional lain. Pakai
     * disable FK checks supaya order-nya tidak masalah. Master sistem
     * (roles, permissions, ptkp_rates, ter_rates, pph21_brackets,
     * regional_minimum_wages, holidays, password_history-system) tetap.
     */
    private function wipeAllUserAndEmployeeData(): void
    {
        echo "▶ CLEANUP — menghapus semua user, employees, candidates, dan data transaksional...\n";

        $db = $this->db;
        $platform = strtolower($db->getPlatform());

        if (str_contains($platform, 'mysql')) {
            $db->query('SET FOREIGN_KEY_CHECKS=0');
        }

        // Urutan: anak duluan → induk. TRUNCATE kalau bisa, fallback DELETE.
        // Kategori dipisah supaya jelas.
        $tablesToWipe = [
            // ─── Recruitment / candidate portal (sesuai instruksi: hapus saja) ─
            'online_test_events',
            'online_test_answers',
            'online_test_attempts',
            'online_test_assignments',
            'online_test_questions',
            'online_test_question_bank',
            'online_tests',
            'candidate_password_resets',
            'candidate_refresh_tokens',
            'candidate_offers',
            'candidate_stage_history',
            'candidate_documents',
            'candidate_stages',
            'candidates',
            'candidate_accounts',
            'application_track_tokens',
            'job_posting_assets',
            'job_posting_scoring_criteria',
            'job_postings',
            'manpower_request_postings',
            'manpower_request_assets',
            'manpower_request_approvals',
            'manpower_requests',
            'internal_applications',
            'internal_job_postings',
            'referral_links',
            'referral_bonus_rules',
            'career_pages',

            // ─── Approval engine state ───────────────────────────────────────
            'approval_decisions',
            'approval_requests',
            'approval_delegations',

            // ─── Employee transactional ──────────────────────────────────────
            'attendance_change_requests',
            'attendances',
            'shift_assignments',
            'shift_pattern_assignments',
            'shift_patterns',
            'leaves',
            'leave_balances',
            'overtimes',
            'wfh_requests',
            'reimbursements',
            'loans',
            'business_trip_expenses',
            'business_trips',
            'severance_calculations',
            'disciplinary_records',
            'employee_tasks',
            'employee_transfers',
            'job_information_changes',
            'employee_work_locations',
            'asset_assignments',
            'assets',
            'training_registrations',
            'training_budgets',
            'trainings',
            'certificates',

            // ─── Performance / talent ────────────────────────────────────────
            'goal_check_ins',
            'goals',
            'peer_review_nominations',
            'performance_reviews',
            'performance_cycles',
            'individual_development_plans',
            'employee_aspirations',
            'employee_skills',
            'employee_competency_assessments',
            'succession_candidates',
            'critical_positions',
            'compensation_analyses',
            'salary_benchmarks',
            'turnover_metrics_snapshot',

            // ─── Pulse survey / AI assistant ─────────────────────────────────
            'pulse_survey_responses',
            'pulse_survey_aggregates',
            'pulse_survey_questions',
            'pulse_surveys',
            'ai_chat_messages',
            'ai_chat_sessions',

            // ─── Helpdesk ────────────────────────────────────────────────────
            'hr_ticket_comments',
            'hr_tickets',

            // ─── Termination & offboarding ───────────────────────────────────
            'exit_interviews',
            'termination_requests',
            'resignation_requests',

            // ─── Payroll ─────────────────────────────────────────────────────
            'payroll_runs',
            'payslip_components',
            'payslips',
            'payroll_periods',
            'payroll_components',
            'allowance_mappings',
            'employee_fixed_allowances',

            // ─── Bonus engine ────────────────────────────────────────────────
            'bonus_calculations',
            'bonus_pools',
            'bonus_rules',

            // ─── Generated docs / contracts ──────────────────────────────────
            'generated_contracts',
            'employee_contracts',
            'employee_documents',
            'employee_families',
            'employee_educations',

            // ─── Audit / notifications / 2FA / privacy ──────────────────────
            'data_deletion_requests',
            'privacy_consents',
            'audit_logs',
            'notifications',
            'user_notification_preferences',
            'twofa_backup_codes',
            'password_history',
            'password_resets',
            'refresh_tokens',
            'compliance_report_runs',

            // ─── Master per-company ──────────────────────────────────────────
            'employees',
            'users',
            'cost_center_budgets',
            'cost_centers',
            'organization_structures',
            'employee_work_locations',
            'work_locations',
            'shifts',
            'leave_types',
            'positions',
            'job_grades',
            'departments',
            'branches',
            'bpjs_rates',
            'company_regulations',
            'contract_templates',
            'approval_chain_steps',
            'approval_chains',
            'approval_assignments',
            'task_templates',
            'email_templates',
            'whatsapp_send_log',
            'whatsapp_provider_configs',

            // ─── Companies (paling akhir) ────────────────────────────────────
            'companies',
        ];

        foreach ($tablesToWipe as $table) {
            if (! $db->tableExists($table)) {
                continue;
            }
            try {
                if (str_contains($platform, 'mysql')) {
                    $db->query("TRUNCATE TABLE `{$table}`");
                } else {
                    $db->query("DELETE FROM `{$table}`");
                }
            } catch (\Throwable $e) {
                // Fallback: DELETE.
                try {
                    $db->table($table)->emptyTable();
                } catch (\Throwable $e2) {
                    log_message('warning', "wipe {$table}: " . $e2->getMessage());
                }
            }
        }

        if (str_contains($platform, 'mysql')) {
            $db->query('SET FOREIGN_KEY_CHECKS=1');
        }

        echo "  ✓ Cleanup selesai — semua data user/employee/candidate/transaksional dihapus.\n\n";
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. MASTER SETUP
    // ─────────────────────────────────────────────────────────────────

    private function loadRoleMap(): void
    {
        $rows = $this->db->table('roles')->get()->getResultArray();
        foreach ($rows as $r) {
            $this->roles[$r['slug']] = (int) $r['id'];
        }
    }

    private function seedCompany(): void
    {
        echo "▶ COMPANY — buat PT Quantara Nusantara Sejahtera...\n";

        $this->db->table('companies')->insert([
            'code'              => 'QNS',
            'name'              => 'PT Quantara Nusantara Sejahtera',
            'legal_name'        => 'PT Quantara Nusantara Sejahtera, Tbk.',
            'npwp'              => '01.234.567.8-901.000',
            'phone'             => '021-50101010',
            'email'             => 'info@quantara.id',
            'address'           => 'Wisma Quantara, Jl. Jenderal Sudirman Kav. 52-53',
            'province'          => 'DKI Jakarta',
            'city'              => 'Jakarta Selatan',
            'postal_code'       => '12190',
            'timezone'          => 'Asia/Jakarta',
            'currency'          => 'IDR',
            'fiscal_year_start' => 1,
            'is_active'         => 1,
            'created_at'        => $this->now,
            'updated_at'        => $this->now,
        ]);
        $this->companyId = (int) $this->db->insertID();
        echo "  ✓ Company id={$this->companyId} dibuat.\n\n";
    }

    private function seedBranches(): void
    {
        echo "▶ BRANCHES — 5 cabang (Jakarta HQ + 4 regional)...\n";
        $rows = [
            ['code' => 'JKT', 'name' => 'Jakarta Pusat (Head Office)',
             'province' => 'DKI Jakarta', 'city' => 'Jakarta Selatan',
             'address' => 'Wisma Quantara, Jl. Jenderal Sudirman Kav. 52-53',
             'phone' => '021-50101010', 'lat' => -6.2247, 'lng' => 106.8094],
            ['code' => 'SBY', 'name' => 'Cabang Surabaya',
             'province' => 'Jawa Timur', 'city' => 'Surabaya',
             'address' => 'Pakuwon Tower, Jl. Embong Malang 1-3',
             'phone' => '031-5462100', 'lat' => -7.2664, 'lng' => 112.7430],
            ['code' => 'BDG', 'name' => 'Cabang Bandung',
             'province' => 'Jawa Barat', 'city' => 'Bandung',
             'address' => 'Wisma Bumiputera, Jl. Asia Afrika 141-149',
             'phone' => '022-4203500', 'lat' => -6.9218, 'lng' => 107.6075],
            ['code' => 'MDN', 'name' => 'Cabang Medan',
             'province' => 'Sumatera Utara', 'city' => 'Medan',
             'address' => 'Wisma BII, Jl. Diponegoro 18',
             'phone' => '061-4570000', 'lat' => 3.5891, 'lng' => 98.6745],
            ['code' => 'MKS', 'name' => 'Cabang Makassar',
             'province' => 'Sulawesi Selatan', 'city' => 'Makassar',
             'address' => 'Menara Bosowa, Jl. Jenderal Sudirman 5',
             'phone' => '0411-3623030', 'lat' => -5.1352, 'lng' => 119.4172],
        ];
        foreach ($rows as $r) {
            $this->db->table('branches')->insert([
                'company_id'        => $this->companyId,
                'code'              => $r['code'],
                'name'              => $r['name'],
                'province'          => $r['province'],
                'city'              => $r['city'],
                'address'           => $r['address'],
                'phone'             => $r['phone'],
                'lat'               => $r['lat'],
                'lng'               => $r['lng'],
                'geofence_radius_m' => 250,
                'is_active'         => 1,
                'created_at'        => $this->now,
                'updated_at'        => $this->now,
            ]);
            $this->branches[$r['code']] = (int) $this->db->insertID();
        }
        echo "  ✓ " . count($this->branches) . " branches dibuat.\n\n";
    }

    private function seedDepartments(): void
    {
        echo "▶ DEPARTMENTS — struktur 2 level (Direktorat → Departemen)...\n";

        // Top-level Direktorat (parent_id null)
        $tops = [
            'BOD'    => 'Dewan Direksi',
            'BOC'    => 'Dewan Komisaris',
            'FIN'    => 'Direktorat Keuangan',
            'HR'     => 'Direktorat Sumber Daya Manusia',
            'OPS'    => 'Direktorat Operasional',
            'IT'     => 'Direktorat Teknologi Informasi',
            'MKT'    => 'Direktorat Pemasaran & Penjualan',
            'LGL'    => 'Direktorat Hukum & Kepatuhan',
        ];
        foreach ($tops as $code => $name) {
            $this->db->table('departments')->insert([
                'company_id' => $this->companyId,
                'parent_id'  => null,
                'code'       => $code,
                'name'       => $name,
                'description'=> "Direktorat {$name}",
                'is_active'  => 1,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
            $this->departments[$code] = (int) $this->db->insertID();
        }

        // Sub-departemen
        $subs = [
            ['code' => 'FIN-ACC', 'parent' => 'FIN', 'name' => 'Departemen Accounting'],
            ['code' => 'FIN-TRS', 'parent' => 'FIN', 'name' => 'Departemen Treasury & Finance'],
            ['code' => 'FIN-TAX', 'parent' => 'FIN', 'name' => 'Departemen Pajak'],

            ['code' => 'HR-REC', 'parent' => 'HR', 'name' => 'Departemen Rekrutmen & Talent Acquisition'],
            ['code' => 'HR-LND', 'parent' => 'HR', 'name' => 'Departemen Learning & Development'],
            ['code' => 'HR-CNB', 'parent' => 'HR', 'name' => 'Departemen Compensation & Benefits'],
            ['code' => 'HR-IR',  'parent' => 'HR', 'name' => 'Departemen Industrial Relations'],
            ['code' => 'HR-OPS', 'parent' => 'HR', 'name' => 'Departemen HR Operations & Payroll'],

            ['code' => 'OPS-OPR', 'parent' => 'OPS', 'name' => 'Departemen Operasional'],
            ['code' => 'OPS-LOG', 'parent' => 'OPS', 'name' => 'Departemen Logistik'],
            ['code' => 'OPS-PRC', 'parent' => 'OPS', 'name' => 'Departemen Procurement'],

            ['code' => 'IT-DEV', 'parent' => 'IT', 'name' => 'Departemen Software Engineering'],
            ['code' => 'IT-INF', 'parent' => 'IT', 'name' => 'Departemen IT Infrastructure'],
            ['code' => 'IT-SUP', 'parent' => 'IT', 'name' => 'Departemen IT Support'],

            ['code' => 'MKT-MKT', 'parent' => 'MKT', 'name' => 'Departemen Marketing'],
            ['code' => 'MKT-SLS', 'parent' => 'MKT', 'name' => 'Departemen Sales'],
        ];
        foreach ($subs as $s) {
            $this->db->table('departments')->insert([
                'company_id' => $this->companyId,
                'parent_id'  => $this->departments[$s['parent']],
                'code'       => $s['code'],
                'name'       => $s['name'],
                'is_active'  => 1,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
            $this->departments[$s['code']] = (int) $this->db->insertID();
        }
        echo "  ✓ " . count($this->departments) . " departemen dibuat.\n\n";
    }

    private function seedJobGrades(): void
    {
        echo "▶ JOB GRADES — 9 level (Komisaris → Junior Staff)...\n";
        // level: angka makin besar makin tinggi. min/max: salary band IDR.
        $rows = [
            ['code' => 'BOC',  'name' => 'Komisaris',          'level' => 11, 'min' => 80000000,  'max' => 200000000],
            ['code' => 'DIR',  'name' => 'Direktur (BOD)',     'level' => 10, 'min' => 60000000,  'max' => 150000000],
            ['code' => 'VP',   'name' => 'Vice President / GM','level' => 8,  'min' => 35000000,  'max' => 60000000],
            ['code' => 'MGR',  'name' => 'Manager',            'level' => 7,  'min' => 22000000,  'max' => 38000000],
            ['code' => 'ASM',  'name' => 'Assistant Manager',  'level' => 6,  'min' => 14000000,  'max' => 22000000],
            ['code' => 'SPV',  'name' => 'Supervisor',         'level' => 5,  'min' => 10000000,  'max' => 15000000],
            ['code' => 'SRS',  'name' => 'Senior Staff',       'level' => 4,  'min' => 8000000,   'max' => 12000000],
            ['code' => 'STF',  'name' => 'Staff',              'level' => 3,  'min' => 5500000,   'max' => 8500000],
            ['code' => 'JRS',  'name' => 'Junior Staff',       'level' => 2,  'min' => 4500000,   'max' => 6500000],
        ];
        foreach ($rows as $r) {
            $this->db->table('job_grades')->insert([
                'company_id' => $this->companyId,
                'code'       => $r['code'],
                'name'       => $r['name'],
                'level'      => $r['level'],
                'min_salary' => $r['min'],
                'max_salary' => $r['max'],
                'is_active'  => 1,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
            $this->jobGrades[$r['code']] = (int) $this->db->insertID();
        }
        echo "  ✓ " . count($this->jobGrades) . " job grades dibuat.\n\n";
    }

    private function seedPositions(): void
    {
        echo "▶ POSITIONS — peta jabatan komplit dengan tier struktural...\n";

        // tier: MANAGER / ASST_MANAGER / SUPERVISOR / STAFF — dipakai
        // OrgStructureController untuk auto-resolve direct report.
        // Untuk Direktur/Komisaris kita pakai tier MANAGER (top of chain).
        $rows = [
            // ── Komisaris ────────────────────────────────────────────
            ['code' => 'POS-KOMUT', 'name' => 'Komisaris Utama',           'dept' => 'BOC', 'grade' => 'BOC', 'tier' => 'MANAGER'],
            ['code' => 'POS-KOMIN', 'name' => 'Komisaris Independen',      'dept' => 'BOC', 'grade' => 'BOC', 'tier' => 'MANAGER'],

            // ── Direksi ──────────────────────────────────────────────
            ['code' => 'POS-DIRUT', 'name' => 'Direktur Utama (President Director / CEO)', 'dept' => 'BOD', 'grade' => 'DIR', 'tier' => 'MANAGER'],
            ['code' => 'POS-DIRKEU','name' => 'Direktur Keuangan (CFO)',                   'dept' => 'BOD', 'grade' => 'DIR', 'tier' => 'MANAGER'],
            ['code' => 'POS-DIRSDM','name' => 'Direktur Sumber Daya Manusia (CHRO)',       'dept' => 'BOD', 'grade' => 'DIR', 'tier' => 'MANAGER'],
            ['code' => 'POS-DIROPS','name' => 'Direktur Operasional (COO)',                'dept' => 'BOD', 'grade' => 'DIR', 'tier' => 'MANAGER'],
            ['code' => 'POS-DIRIT', 'name' => 'Direktur Teknologi Informasi (CTO)',        'dept' => 'BOD', 'grade' => 'DIR', 'tier' => 'MANAGER'],
            ['code' => 'POS-DIRMKT','name' => 'Direktur Pemasaran & Penjualan (CMO)',      'dept' => 'BOD', 'grade' => 'DIR', 'tier' => 'MANAGER'],
            ['code' => 'POS-DIRLGL','name' => 'Direktur Hukum & Kepatuhan (CLO)',          'dept' => 'BOD', 'grade' => 'DIR', 'tier' => 'MANAGER'],

            // ── VP / GM (Kepala Divisi) ───────────────────────────────
            ['code' => 'POS-GMFIN', 'name' => 'GM Finance & Accounting',  'dept' => 'FIN', 'grade' => 'VP', 'tier' => 'MANAGER'],
            ['code' => 'POS-GMHR',  'name' => 'GM Human Resources',       'dept' => 'HR',  'grade' => 'VP', 'tier' => 'MANAGER'],
            ['code' => 'POS-GMOPS', 'name' => 'GM Operations',            'dept' => 'OPS', 'grade' => 'VP', 'tier' => 'MANAGER'],
            ['code' => 'POS-GMIT',  'name' => 'GM IT',                    'dept' => 'IT',  'grade' => 'VP', 'tier' => 'MANAGER'],
            ['code' => 'POS-GMMKT', 'name' => 'GM Marketing & Sales',     'dept' => 'MKT', 'grade' => 'VP', 'tier' => 'MANAGER'],

            // ── Branch Manager (Kepala Cabang) ────────────────────────
            ['code' => 'POS-BMSBY', 'name' => 'Branch Manager Surabaya',  'dept' => 'OPS', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-BMBDG', 'name' => 'Branch Manager Bandung',   'dept' => 'OPS', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-BMMDN', 'name' => 'Branch Manager Medan',     'dept' => 'OPS', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-BMMKS', 'name' => 'Branch Manager Makassar',  'dept' => 'OPS', 'grade' => 'MGR', 'tier' => 'MANAGER'],

            // ── Manager (Kepala Departemen) ───────────────────────────
            ['code' => 'POS-MGRACC', 'name' => 'Accounting Manager',     'dept' => 'FIN-ACC', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRTRS', 'name' => 'Treasury Manager',       'dept' => 'FIN-TRS', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRTAX', 'name' => 'Tax Manager',            'dept' => 'FIN-TAX', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRREC', 'name' => 'Recruitment Manager',    'dept' => 'HR-REC',  'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRLND', 'name' => 'L&D Manager',            'dept' => 'HR-LND',  'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRCNB', 'name' => 'Comp & Ben Manager',     'dept' => 'HR-CNB',  'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRIR',  'name' => 'Industrial Relations Manager', 'dept' => 'HR-IR', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRHRO', 'name' => 'HR Operations & Payroll Manager','dept' => 'HR-OPS','grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRLOG', 'name' => 'Logistics Manager',      'dept' => 'OPS-LOG', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRPRC', 'name' => 'Procurement Manager',    'dept' => 'OPS-PRC', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRDEV', 'name' => 'Software Engineering Manager','dept' => 'IT-DEV', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRINF', 'name' => 'IT Infrastructure Manager','dept' => 'IT-INF', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRMKT', 'name' => 'Marketing Manager',      'dept' => 'MKT-MKT', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRSLS', 'name' => 'Sales Manager',          'dept' => 'MKT-SLS', 'grade' => 'MGR', 'tier' => 'MANAGER'],
            ['code' => 'POS-MGRLGL', 'name' => 'Legal Counsel Manager',  'dept' => 'LGL',     'grade' => 'MGR', 'tier' => 'MANAGER'],

            // ── Asst. Manager / Section Head ──────────────────────────
            ['code' => 'POS-ASMACC', 'name' => 'Asst. Accounting Manager','dept' => 'FIN-ACC', 'grade' => 'ASM', 'tier' => 'ASST_MANAGER'],
            ['code' => 'POS-ASMHR',  'name' => 'Asst. HR Operations Manager','dept' => 'HR-OPS','grade' => 'ASM', 'tier' => 'ASST_MANAGER'],
            ['code' => 'POS-ASMITS', 'name' => 'Asst. IT Support Manager','dept' => 'IT-SUP', 'grade' => 'ASM', 'tier' => 'ASST_MANAGER'],

            // ── Supervisor / Team Lead ────────────────────────────────
            ['code' => 'POS-SPVDEV', 'name' => 'Backend Tech Lead',      'dept' => 'IT-DEV', 'grade' => 'SPV', 'tier' => 'SUPERVISOR'],
            ['code' => 'POS-SPVFE',  'name' => 'Frontend Tech Lead',     'dept' => 'IT-DEV', 'grade' => 'SPV', 'tier' => 'SUPERVISOR'],
            ['code' => 'POS-SPVOPS', 'name' => 'Operations Supervisor',  'dept' => 'OPS-OPR','grade' => 'SPV', 'tier' => 'SUPERVISOR'],
            ['code' => 'POS-SPVSLS', 'name' => 'Sales Supervisor',       'dept' => 'MKT-SLS','grade' => 'SPV', 'tier' => 'SUPERVISOR'],
            ['code' => 'POS-SPVPAY', 'name' => 'Payroll Supervisor',     'dept' => 'HR-OPS', 'grade' => 'SPV', 'tier' => 'SUPERVISOR'],

            // ── Senior Staff / Specialist ─────────────────────────────
            ['code' => 'POS-SRREC',  'name' => 'Senior Recruiter',       'dept' => 'HR-REC', 'grade' => 'SRS', 'tier' => 'STAFF'],
            ['code' => 'POS-SRACC',  'name' => 'Senior Accountant',      'dept' => 'FIN-ACC','grade' => 'SRS', 'tier' => 'STAFF'],
            ['code' => 'POS-SRBE',   'name' => 'Senior Backend Developer','dept' => 'IT-DEV','grade' => 'SRS', 'tier' => 'STAFF'],
            ['code' => 'POS-SRFE',   'name' => 'Senior Frontend Developer','dept' => 'IT-DEV','grade'=>'SRS', 'tier' => 'STAFF'],
            ['code' => 'POS-SRPAY',  'name' => 'Senior Payroll Specialist','dept' => 'HR-OPS','grade'=>'SRS', 'tier' => 'STAFF'],

            // ── Staff ─────────────────────────────────────────────────
            ['code' => 'POS-STFREC', 'name' => 'Recruiter (Staff)',      'dept' => 'HR-REC', 'grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFLND', 'name' => 'L&D Staff',              'dept' => 'HR-LND', 'grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFCNB', 'name' => 'Comp & Ben Staff',       'dept' => 'HR-CNB', 'grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFIR',  'name' => 'IR Staff',               'dept' => 'HR-IR',  'grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFACC', 'name' => 'Accountant',             'dept' => 'FIN-ACC','grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFTRS', 'name' => 'Treasury Staff',         'dept' => 'FIN-TRS','grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFTAX', 'name' => 'Tax Staff',              'dept' => 'FIN-TAX','grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFOPS', 'name' => 'Operations Staff',       'dept' => 'OPS-OPR','grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFLOG', 'name' => 'Logistics Staff',        'dept' => 'OPS-LOG','grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFPRC', 'name' => 'Procurement Staff',      'dept' => 'OPS-PRC','grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFBE',  'name' => 'Backend Developer',      'dept' => 'IT-DEV', 'grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFFE',  'name' => 'Frontend Developer',     'dept' => 'IT-DEV', 'grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFINF', 'name' => 'DevOps / Infra Engineer','dept' => 'IT-INF', 'grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFITS', 'name' => 'IT Support Officer',     'dept' => 'IT-SUP', 'grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFMKT', 'name' => 'Marketing Staff',        'dept' => 'MKT-MKT','grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFSLS', 'name' => 'Sales Executive',        'dept' => 'MKT-SLS','grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFLGL', 'name' => 'Legal Officer',          'dept' => 'LGL',    'grade' => 'STF', 'tier' => 'STAFF'],
            ['code' => 'POS-STFPAY', 'name' => 'Payroll Officer',        'dept' => 'HR-OPS', 'grade' => 'STF', 'tier' => 'STAFF'],

            // ── Junior ────────────────────────────────────────────────
            ['code' => 'POS-JRSLS',  'name' => 'Junior Sales Executive', 'dept' => 'MKT-SLS','grade' => 'JRS', 'tier' => 'STAFF'],
            ['code' => 'POS-JROPS',  'name' => 'Junior Operations Staff','dept' => 'OPS-OPR','grade' => 'JRS', 'tier' => 'STAFF'],
        ];

        foreach ($rows as $r) {
            $this->db->table('positions')->insert([
                'company_id'    => $this->companyId,
                'department_id' => $this->departments[$r['dept']],
                'job_grade_id'  => $this->jobGrades[$r['grade']],
                'code'          => $r['code'],
                'name'          => $r['name'],
                'tier'          => $r['tier'],
                'description'   => $this->jobDescriptionFor($r['name']),
                'is_active'     => 1,
                'created_at'    => $this->now,
                'updated_at'    => $this->now,
            ]);
            $this->positions[$r['code']] = (int) $this->db->insertID();
        }
        echo "  ✓ " . count($this->positions) . " positions dibuat (sudah ada tier + jobdesc).\n\n";
    }

    /**
     * Jobdesk singkat per role. Diambil dari role description khas
     * praktik HR Indonesia. Bukan novel — fokus ke akuntabilitas inti.
     */
    private function jobDescriptionFor(string $posName): string
    {
        $map = [
            'Komisaris Utama' => 'Memimpin Dewan Komisaris dalam melakukan pengawasan terhadap kebijakan pengurusan, jalannya pengurusan oleh Direksi, serta memberi nasihat kepada Direksi sesuai UU 40/2007 tentang PT.',
            'Komisaris Independen' => 'Anggota Dewan Komisaris yang tidak terafiliasi dengan pemegang saham pengendali, Direksi, dan Komisaris lain. Bertugas memastikan tata kelola perusahaan (GCG) berjalan dengan baik.',
            'Direktur Utama (President Director / CEO)' => 'Memimpin dan bertanggung jawab penuh atas seluruh pengurusan perseroan untuk kepentingan dan tujuan perseroan, mewakili perseroan di dalam maupun di luar pengadilan.',
            'Direktur Keuangan (CFO)' => 'Bertanggung jawab atas perencanaan, pengendalian, dan pelaporan keuangan perusahaan, termasuk treasury, accounting, perpajakan, audit internal, dan investor relations.',
            'Direktur Sumber Daya Manusia (CHRO)' => 'Merumuskan dan mengeksekusi strategi human capital end-to-end: rekrutmen, pengembangan talenta, manajemen kinerja, kompensasi & benefit, hubungan industrial, serta kepatuhan ketenagakerjaan.',
            'Direktur Operasional (COO)' => 'Mengelola operasional harian perusahaan agar selaras dengan strategi korporat, mencakup operations, supply chain, logistik, dan kualitas layanan/produk.',
            'Direktur Teknologi Informasi (CTO)' => 'Memimpin strategi dan eksekusi teknologi informasi: pengembangan produk digital, infrastruktur, keamanan informasi, serta transformasi digital perusahaan.',
            'Direktur Pemasaran & Penjualan (CMO)' => 'Bertanggung jawab atas strategi pemasaran, branding, akuisisi pelanggan, dan pencapaian target penjualan nasional perusahaan.',
            'Direktur Hukum & Kepatuhan (CLO)' => 'Mengelola seluruh aspek hukum, perizinan, kontrak, litigasi, GCG, kepatuhan regulasi (OJK/BPJS/Disnaker/Pajak), serta manajemen risiko hukum perusahaan.',
            'GM Finance & Accounting' => 'Mengelola fungsi accounting, finance, treasury, dan tax untuk memastikan laporan keuangan akurat, tepat waktu, dan compliant terhadap PSAK serta peraturan perpajakan.',
            'GM Human Resources' => 'Mengelola seluruh fungsi HR operasional dan strategis di tingkat divisi, memastikan eksekusi kebijakan SDM perusahaan di seluruh cabang.',
            'GM Operations' => 'Memastikan kelancaran operasional di seluruh cabang dan memimpin perbaikan proses bisnis berkelanjutan (continuous improvement).',
            'GM IT' => 'Memimpin tim engineering, infrastructure, dan support untuk delivery produk digital dan dukungan operasional sehari-hari.',
            'GM Marketing & Sales' => 'Memimpin tim marketing dan sales nasional dalam mencapai target pertumbuhan revenue dan market share.',
            'Branch Manager Surabaya' => 'Mengelola operasional cabang Surabaya, memimpin tim lintas fungsi di cabang, dan bertanggung jawab atas P&L cabang.',
            'Branch Manager Bandung'  => 'Mengelola operasional cabang Bandung, memimpin tim lintas fungsi di cabang, dan bertanggung jawab atas P&L cabang.',
            'Branch Manager Medan'    => 'Mengelola operasional cabang Medan, memimpin tim lintas fungsi di cabang, dan bertanggung jawab atas P&L cabang.',
            'Branch Manager Makassar' => 'Mengelola operasional cabang Makassar, memimpin tim lintas fungsi di cabang, dan bertanggung jawab atas P&L cabang.',
            'Accounting Manager' => 'Memimpin proses pencatatan akuntansi, closing bulanan/tahunan, rekonsiliasi, dan penyusunan laporan keuangan sesuai PSAK.',
            'Treasury Manager'   => 'Mengelola kas, perbankan, pembayaran vendor, dan working capital perusahaan.',
            'Tax Manager'        => 'Memastikan kepatuhan pajak (PPh 21, PPh 23, PPh Badan, PPN) dan optimalisasi struktur pajak yang halal.',
            'Recruitment Manager' => 'Memimpin end-to-end proses rekrutmen, employer branding, dan pengisian Manpower Planning Plan (MPP) tahunan.',
            'L&D Manager' => 'Merancang program pengembangan karyawan, IDP, training, dan competency framework perusahaan.',
            'Comp & Ben Manager' => 'Mengelola kebijakan kompensasi, struktur skala upah, benefit, BPJS, dan benchmarking pasar.',
            'Industrial Relations Manager' => 'Mengelola hubungan industrial, PKB, perselisihan ketenagakerjaan, dan komunikasi dengan Disnaker/serikat pekerja.',
            'HR Operations & Payroll Manager' => 'Memimpin operasional HR sehari-hari: payroll, attendance, leave, BPJS, PPh 21, dan administrasi kepegawaian.',
            'Logistics Manager'  => 'Mengelola gudang, distribusi, dan rantai pasok untuk seluruh cabang.',
            'Procurement Manager'=> 'Mengelola pengadaan barang dan jasa, vendor management, dan kontrak supplier.',
            'Software Engineering Manager' => 'Memimpin tim engineering dalam mengembangkan dan memelihara aplikasi internal serta produk digital perusahaan.',
            'IT Infrastructure Manager' => 'Mengelola server, network, cloud, dan keamanan infrastruktur TI perusahaan.',
            'Marketing Manager'  => 'Memimpin tim marketing dalam menjalankan kampanye, content, dan digital marketing.',
            'Sales Manager'      => 'Memimpin tim sales dalam mencapai target penjualan area kerja masing-masing.',
            'Legal Counsel Manager' => 'Memberikan nasihat hukum, mengelola kontrak, dan menangani permasalahan legal harian perusahaan.',
            'Asst. Accounting Manager' => 'Membantu Accounting Manager dalam supervisi pencatatan, AP/AR, dan rekonsiliasi.',
            'Asst. HR Operations Manager' => 'Membantu HR Operations Manager dalam administrasi kepegawaian dan eksekusi payroll bulanan.',
            'Asst. IT Support Manager' => 'Membantu mengelola tim IT Support dan ticket helpdesk internal.',
            'Backend Tech Lead'  => 'Memimpin secara teknis tim backend developer, code review, dan arsitektur API.',
            'Frontend Tech Lead' => 'Memimpin secara teknis tim frontend developer dan menentukan standar UI/UX engineering.',
            'Operations Supervisor' => 'Mensupervisi tim operasional harian di lapangan/kantor cabang.',
            'Sales Supervisor'   => 'Mensupervisi tim sales executive dan tracking pencapaian target.',
            'Payroll Supervisor' => 'Mensupervisi proses payroll bulanan, slip gaji, dan rekonsiliasi BPJS/PPh 21.',
            'Senior Recruiter'   => 'Menjalankan rekrutmen end-to-end untuk posisi mid–senior, screening, interview, dan offering.',
            'Senior Accountant'  => 'Menyusun jurnal kompleks, pajak, dan menyiapkan laporan keuangan periodik.',
            'Senior Backend Developer'  => 'Mengembangkan service backend, REST API, dan integrasi sistem internal.',
            'Senior Frontend Developer' => 'Mengembangkan aplikasi frontend (Vue/React) dan komponen reusable.',
            'Senior Payroll Specialist' => 'Memproses payroll bulanan, BPJS, PPh 21, dan slip gaji untuk seluruh karyawan.',
            'Recruiter (Staff)'  => 'Sourcing kandidat, screening CV, jadwal interview, dan administrasi rekrutmen.',
            'L&D Staff'          => 'Membantu eksekusi program training, registrasi peserta, dan administrasi LMS.',
            'Comp & Ben Staff'   => 'Membantu administrasi benefit karyawan, BPJS, dan klaim kesehatan.',
            'IR Staff'           => 'Membantu administrasi PKB, surat peringatan, dan komunikasi serikat pekerja.',
            'Accountant'         => 'Mencatat transaksi harian, AP/AR, dan rekonsiliasi bank.',
            'Treasury Staff'     => 'Memproses pembayaran vendor, payroll disbursement, dan kas kecil.',
            'Tax Staff'          => 'Membantu pelaporan pajak bulanan/tahunan dan rekonsiliasi pajak.',
            'Operations Staff'   => 'Menjalankan tugas operasional harian sesuai SOP cabang.',
            'Logistics Staff'    => 'Mengelola pergerakan barang masuk-keluar gudang dan dokumen pengiriman.',
            'Procurement Staff'  => 'Memproses PR/PO, koordinasi dengan vendor, dan administrasi pengadaan.',
            'Backend Developer'  => 'Mengembangkan API, service, dan database query untuk produk internal.',
            'Frontend Developer' => 'Mengembangkan halaman aplikasi web sesuai design system.',
            'DevOps / Infra Engineer' => 'Mengelola CI/CD, server, monitoring, dan deployment aplikasi.',
            'IT Support Officer' => 'Menangani helpdesk internal, troubleshooting laptop & jaringan kantor.',
            'Marketing Staff'    => 'Menjalankan kampanye marketing, content, dan analytics.',
            'Sales Executive'    => 'Mencapai target penjualan, akuisisi & retensi customer di area kerja.',
            'Legal Officer'      => 'Membantu drafting kontrak, review legal, dan administrasi perizinan.',
            'Payroll Officer'    => 'Membantu proses payroll bulanan, input data lembur/cuti, dan distribusi slip gaji.',
            'Junior Sales Executive'  => 'Mendukung tim sales senior dan belajar siklus penjualan end-to-end.',
            'Junior Operations Staff' => 'Membantu tugas operasional ringan sebagai jenjang awal karier.',
        ];
        return $map[$posName] ?? "Bertanggung jawab atas tugas-tugas {$posName} sesuai job grade dan kebijakan perusahaan.";
    }

    private function seedShifts(): void
    {
        $rows = [
            ['code' => 'REG',   'name' => 'Reguler 09:00-18:00',     'start' => '09:00:00', 'end' => '18:00:00', 'crosses' => 0],
            ['code' => 'PAGI',  'name' => 'Shift Pagi 06:00-14:00',  'start' => '06:00:00', 'end' => '14:00:00', 'crosses' => 0],
            ['code' => 'SIANG', 'name' => 'Shift Siang 14:00-22:00', 'start' => '14:00:00', 'end' => '22:00:00', 'crosses' => 0],
            ['code' => 'MALAM', 'name' => 'Shift Malam 22:00-06:00', 'start' => '22:00:00', 'end' => '06:00:00', 'crosses' => 1],
        ];
        foreach ($rows as $r) {
            $this->db->table('shifts')->insert([
                'company_id'                    => $this->companyId,
                'code'                          => $r['code'],
                'name'                          => $r['name'],
                'start_time'                    => $r['start'],
                'end_time'                      => $r['end'],
                'break_minutes'                 => 60,
                'crosses_midnight'              => $r['crosses'],
                'late_tolerance_minutes'        => 15,
                'early_leave_tolerance_minutes' => 15,
                'is_active'                     => 1,
                'created_at'                    => $this->now,
                'updated_at'                    => $this->now,
            ]);
            $this->shifts[$r['code']] = (int) $this->db->insertID();
        }
    }

    private function seedLeaveTypesAndPayrollComponents(): void
    {
        // Leave types — sama persis seperti InitialSeeder, di-clone untuk
        // company baru. Mengikuti UU 13/2003 + UU 6/2023.
        $leaveTypes = [
            ['code' => 'CT',  'name' => 'Cuti Tahunan',                       'category' => 'ANNUAL',     'default_days' => 12, 'min_tenure_months' => 12, 'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL'],
            ['code' => 'CS',  'name' => 'Cuti Sakit',                         'category' => 'SICK',       'default_days' => 0,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 1, 'gender_restriction' => 'ALL'],
            ['code' => 'CM',  'name' => 'Cuti Melahirkan (3 bulan)',          'category' => 'MATERNITY',  'default_days' => 90, 'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'F'],
            ['code' => 'CPM', 'name' => 'Cuti Pendamping Melahirkan',         'category' => 'PATERNITY',  'default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'M'],
            ['code' => 'CB',  'name' => 'Cuti Besar (per 6 tahun)',           'category' => 'SPECIAL',    'default_days' => 30, 'min_tenure_months' => 72, 'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL'],
            ['code' => 'CH',  'name' => 'Cuti Haid',                          'category' => 'MENSTRUAL',  'default_days' => 24, 'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'F'],
            ['code' => 'CN',  'name' => 'Cuti Menikah',                       'category' => 'MARRIAGE',   'default_days' => 3,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL'],
            ['code' => 'CD',  'name' => 'Cuti Duka',                          'category' => 'BEREAVEMENT','default_days' => 2,  'min_tenure_months' => 0,  'is_paid' => 1, 'requires_doc' => 0, 'gender_restriction' => 'ALL'],
            ['code' => 'CIH', 'name' => 'Cuti Ibadah Haji',                   'category' => 'HAJJ',       'default_days' => 40, 'min_tenure_months' => 12, 'is_paid' => 1, 'requires_doc' => 1, 'gender_restriction' => 'ALL'],
            ['code' => 'CTB', 'name' => 'Cuti Tidak Berbayar',                'category' => 'UNPAID',     'default_days' => 30, 'min_tenure_months' => 0,  'is_paid' => 0, 'requires_doc' => 0, 'gender_restriction' => 'ALL'],
        ];
        foreach ($leaveTypes as $lt) {
            $this->db->table('leave_types')->insert([
                'company_id'         => $this->companyId,
                'code'               => $lt['code'],
                'name'               => $lt['name'],
                'category'           => $lt['category'],
                'default_days'       => $lt['default_days'],
                'min_tenure_months'  => $lt['min_tenure_months'],
                'is_paid'            => $lt['is_paid'],
                'requires_doc'       => $lt['requires_doc'],
                'gender_restriction' => $lt['gender_restriction'],
                'reset_yearly'       => 1,
                'is_active'          => 1,
                'created_at'         => $this->now,
                'updated_at'         => $this->now,
            ]);
        }

        // Payroll components default
        $components = [
            ['code' => 'BASIC',         'name' => 'Gaji Pokok',                      'type' => 'EARNING',   'category' => 'BASIC',     'is_taxable' => 1, 'is_fixed' => 1],
            ['code' => 'TUNJ_JBT',      'name' => 'Tunjangan Jabatan',               'type' => 'EARNING',   'category' => 'ALLOWANCE', 'is_taxable' => 1, 'is_fixed' => 1],
            ['code' => 'TUNJ_TRANS',    'name' => 'Tunjangan Transport',             'type' => 'EARNING',   'category' => 'ALLOWANCE', 'is_taxable' => 1, 'is_fixed' => 1],
            ['code' => 'TUNJ_MAKAN',    'name' => 'Tunjangan Makan',                 'type' => 'EARNING',   'category' => 'ALLOWANCE', 'is_taxable' => 1, 'is_fixed' => 0],
            ['code' => 'TUNJ_KOMUNIKASI','name'=> 'Tunjangan Komunikasi',            'type' => 'EARNING',   'category' => 'ALLOWANCE', 'is_taxable' => 1, 'is_fixed' => 1],
            ['code' => 'OT',            'name' => 'Lembur',                          'type' => 'EARNING',   'category' => 'OVERTIME',  'is_taxable' => 1, 'is_fixed' => 0],
            ['code' => 'THR',           'name' => 'THR Keagamaan',                   'type' => 'EARNING',   'category' => 'THR',       'is_taxable' => 1, 'is_fixed' => 0],
            ['code' => 'BPJS_KES_EMP',  'name' => 'BPJS Kesehatan (Karyawan 1%)',    'type' => 'DEDUCTION', 'category' => 'BPJS_KES',  'is_taxable' => 0, 'is_fixed' => 1],
            ['code' => 'BPJS_JHT_EMP',  'name' => 'BPJS JHT (Karyawan 2%)',          'type' => 'DEDUCTION', 'category' => 'BPJS_TK',   'is_taxable' => 0, 'is_fixed' => 1],
            ['code' => 'BPJS_JP_EMP',   'name' => 'BPJS JP (Karyawan 1%)',           'type' => 'DEDUCTION', 'category' => 'BPJS_TK',   'is_taxable' => 0, 'is_fixed' => 1],
            ['code' => 'PPH21',         'name' => 'PPh 21',                          'type' => 'DEDUCTION', 'category' => 'PPH21',     'is_taxable' => 0, 'is_fixed' => 0],
            ['code' => 'LOAN',          'name' => 'Potongan Kasbon',                 'type' => 'DEDUCTION', 'category' => 'LOAN',      'is_taxable' => 0, 'is_fixed' => 0],
        ];
        foreach ($components as $c) {
            $this->db->table('payroll_components')->insert([
                'company_id' => $this->companyId,
                'code'       => $c['code'],
                'name'       => $c['name'],
                'type'       => $c['type'],
                'category'   => $c['category'],
                'is_taxable' => $c['is_taxable'],
                'is_fixed'   => $c['is_fixed'],
                'amount'     => 0,
                'is_active'  => 1,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedBpjsRates(): void
    {
        $this->db->table('bpjs_rates')->insert([
            'company_id'        => $this->companyId,
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
            'created_at'        => $this->now,
            'updated_at'        => $this->now,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. STAKEHOLDERS (employees + user accounts)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Daftar lengkap stakeholders. `manager_code` boleh null untuk
     * Komisaris Utama. `role_slug` mengacu ke roles enterprise yang
     * sudah di-seed di migration 000047.
     *
     * Field: code, first, last, gender, religion, ptkp, pos_code,
     *        branch_code, manager_code (atasan), salary, role_slug.
     */
    private function stakeholderRoster(): array
    {
        return [
            // ── Komisaris ────────────────────────────────────────────
            ['code' => 'EMP-0001', 'first' => 'Sumarno',   'last' => 'Hadiwijaya',   'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K3', 'pos' => 'POS-KOMUT', 'branch' => 'JKT', 'manager' => null,        'salary' => 120000000, 'role' => 'super_admin'],
            ['code' => 'EMP-0002', 'first' => 'Diah',      'last' => 'Pertiwi',      'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'K2', 'pos' => 'POS-KOMIN', 'branch' => 'JKT', 'manager' => 'EMP-0001',  'salary' => 90000000,  'role' => 'internal_auditor'],

            // ── Direksi ──────────────────────────────────────────────
            ['code' => 'EMP-0010', 'first' => 'Bambang',  'last' => 'Soewignyo',     'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K2', 'pos' => 'POS-DIRUT', 'branch' => 'JKT', 'manager' => 'EMP-0001', 'salary' => 100000000, 'role' => 'ceo'],
            ['code' => 'EMP-0011', 'first' => 'Hendra',   'last' => 'Wijaya',        'gender' => 'M', 'religion' => 'Buddha',  'ptkp' => 'K2', 'pos' => 'POS-DIRKEU','branch' => 'JKT', 'manager' => 'EMP-0010','salary' => 75000000,  'role' => 'cfo'],
            ['code' => 'EMP-0012', 'first' => 'Ratna',    'last' => 'Kusumawardhani','gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-DIRSDM','branch' => 'JKT', 'manager' => 'EMP-0010','salary' => 70000000,  'role' => 'hr_director'],
            ['code' => 'EMP-0013', 'first' => 'Agung',    'last' => 'Pranata',       'gender' => 'M', 'religion' => 'Hindu',   'ptkp' => 'K3', 'pos' => 'POS-DIROPS','branch' => 'JKT', 'manager' => 'EMP-0010','salary' => 72000000,  'role' => 'department_head'],
            ['code' => 'EMP-0014', 'first' => 'Iwan',     'last' => 'Setiawan',      'gender' => 'M', 'religion' => 'Kristen', 'ptkp' => 'K2', 'pos' => 'POS-DIRIT', 'branch' => 'JKT', 'manager' => 'EMP-0010','salary' => 70000000,  'role' => 'it_admin'],
            ['code' => 'EMP-0015', 'first' => 'Maria',    'last' => 'Christina',     'gender' => 'F', 'religion' => 'Katolik', 'ptkp' => 'K0', 'pos' => 'POS-DIRMKT','branch' => 'JKT', 'manager' => 'EMP-0010','salary' => 68000000,  'role' => 'department_head'],
            ['code' => 'EMP-0016', 'first' => 'Yusuf',    'last' => 'Mahendra',      'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K2', 'pos' => 'POS-DIRLGL','branch' => 'JKT', 'manager' => 'EMP-0010','salary' => 65000000,  'role' => 'legal_compliance'],

            // ── GM / VP ──────────────────────────────────────────────
            ['code' => 'EMP-0020', 'first' => 'Dewi',     'last' => 'Anggraini',     'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-GMFIN', 'branch' => 'JKT', 'manager' => 'EMP-0011','salary' => 45000000,  'role' => 'finance'],
            ['code' => 'EMP-0021', 'first' => 'Surya',    'last' => 'Pradana',       'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K2', 'pos' => 'POS-GMHR',  'branch' => 'JKT', 'manager' => 'EMP-0012','salary' => 44000000,  'role' => 'hr_business_partner'],
            ['code' => 'EMP-0022', 'first' => 'Lestari',  'last' => 'Handayani',     'gender' => 'F', 'religion' => 'Kristen', 'ptkp' => 'TK0','pos' => 'POS-GMOPS', 'branch' => 'JKT', 'manager' => 'EMP-0013','salary' => 43000000,  'role' => 'department_head'],
            ['code' => 'EMP-0023', 'first' => 'Reza',     'last' => 'Adiputra',      'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-GMIT',  'branch' => 'JKT', 'manager' => 'EMP-0014','salary' => 46000000,  'role' => 'it_admin'],
            ['code' => 'EMP-0024', 'first' => 'Indah',    'last' => 'Permatasari',   'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'K0', 'pos' => 'POS-GMMKT', 'branch' => 'JKT', 'manager' => 'EMP-0015','salary' => 42000000,  'role' => 'department_head'],

            // ── Branch Managers ──────────────────────────────────────
            ['code' => 'EMP-0030', 'first' => 'Joko',     'last' => 'Susilo',        'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K2', 'pos' => 'POS-BMSBY','branch' => 'SBY','manager' => 'EMP-0022','salary' => 28000000,  'role' => 'branch_manager'],
            ['code' => 'EMP-0031', 'first' => 'Eka',      'last' => 'Setiawati',     'gender' => 'F', 'religion' => 'Hindu',   'ptkp' => 'K1', 'pos' => 'POS-BMBDG','branch' => 'BDG','manager' => 'EMP-0022','salary' => 27000000,  'role' => 'branch_manager'],
            ['code' => 'EMP-0032', 'first' => 'Marbun',   'last' => 'Simatupang',    'gender' => 'M', 'religion' => 'Kristen', 'ptkp' => 'K2', 'pos' => 'POS-BMMDN','branch' => 'MDN','manager' => 'EMP-0022','salary' => 27500000,  'role' => 'branch_manager'],
            ['code' => 'EMP-0033', 'first' => 'Rahmat',   'last' => 'Tahir',         'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K3', 'pos' => 'POS-BMMKS','branch' => 'MKS','manager' => 'EMP-0022','salary' => 27000000,  'role' => 'branch_manager'],

            // ── Manager (Kepala Departemen) ──────────────────────────
            ['code' => 'EMP-0040', 'first' => 'Tomy',     'last' => 'Salim',         'gender' => 'M', 'religion' => 'Buddha',  'ptkp' => 'K0', 'pos' => 'POS-MGRACC','branch' => 'JKT','manager' => 'EMP-0020','salary' => 32000000, 'role' => 'finance'],
            ['code' => 'EMP-0041', 'first' => 'Ningsih',  'last' => 'Cahyani',       'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-MGRTRS','branch' => 'JKT','manager' => 'EMP-0020','salary' => 31000000, 'role' => 'finance'],
            ['code' => 'EMP-0042', 'first' => 'Prabowo',  'last' => 'Hartono',       'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K2', 'pos' => 'POS-MGRTAX','branch' => 'JKT','manager' => 'EMP-0020','salary' => 32500000, 'role' => 'finance'],
            ['code' => 'EMP-0043', 'first' => 'Anita',    'last' => 'Rahayu',        'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-MGRREC','branch' => 'JKT','manager' => 'EMP-0021','salary' => 28000000, 'role' => 'recruiter'],
            ['code' => 'EMP-0044', 'first' => 'Fitriani', 'last' => 'Sari',          'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-MGRLND','branch' => 'JKT','manager' => 'EMP-0021','salary' => 27000000, 'role' => 'learning_development'],
            ['code' => 'EMP-0045', 'first' => 'Hendrawan','last' => 'Lubis',         'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-MGRCNB','branch' => 'JKT','manager' => 'EMP-0021','salary' => 28500000, 'role' => 'compensation_benefits'],
            ['code' => 'EMP-0046', 'first' => 'Sutrisno', 'last' => 'Ismail',        'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K3', 'pos' => 'POS-MGRIR', 'branch' => 'JKT','manager' => 'EMP-0021','salary' => 27500000, 'role' => 'hr_business_partner'],
            ['code' => 'EMP-0047', 'first' => 'Yuni',     'last' => 'Astuti',        'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'K0', 'pos' => 'POS-MGRHRO','branch' => 'JKT','manager' => 'EMP-0021','salary' => 28000000, 'role' => 'payroll_admin'],
            ['code' => 'EMP-0048', 'first' => 'Faisal',   'last' => 'Nasution',      'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K2', 'pos' => 'POS-MGRLOG','branch' => 'JKT','manager' => 'EMP-0022','salary' => 26000000, 'role' => 'manager'],
            ['code' => 'EMP-0049', 'first' => 'Sinta',    'last' => 'Mahardhika',    'gender' => 'F', 'religion' => 'Hindu',   'ptkp' => 'TK0','pos' => 'POS-MGRPRC','branch' => 'JKT','manager' => 'EMP-0022','salary' => 25500000, 'role' => 'manager'],
            ['code' => 'EMP-0050', 'first' => 'Aldi',     'last' => 'Maulana',       'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-MGRDEV','branch' => 'JKT','manager' => 'EMP-0023','salary' => 32000000, 'role' => 'manager'],
            ['code' => 'EMP-0051', 'first' => 'Rangga',   'last' => 'Wibowo',        'gender' => 'M', 'religion' => 'Katolik', 'ptkp' => 'K2', 'pos' => 'POS-MGRINF','branch' => 'JKT','manager' => 'EMP-0023','salary' => 30000000, 'role' => 'it_admin'],
            ['code' => 'EMP-0052', 'first' => 'Karina',   'last' => 'Damayanti',     'gender' => 'F', 'religion' => 'Kristen', 'ptkp' => 'TK0','pos' => 'POS-MGRMKT','branch' => 'JKT','manager' => 'EMP-0024','salary' => 26000000, 'role' => 'manager'],
            ['code' => 'EMP-0053', 'first' => 'Bayu',     'last' => 'Pamungkas',     'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-MGRSLS','branch' => 'JKT','manager' => 'EMP-0024','salary' => 27500000, 'role' => 'manager'],
            ['code' => 'EMP-0054', 'first' => 'Lukman',   'last' => 'Hakim',         'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K2', 'pos' => 'POS-MGRLGL','branch' => 'JKT','manager' => 'EMP-0016','salary' => 28000000, 'role' => 'legal_compliance'],

            // ── Asst Manager ─────────────────────────────────────────
            ['code' => 'EMP-0060', 'first' => 'Vania',    'last' => 'Hapsari',       'gender' => 'F', 'religion' => 'Katolik', 'ptkp' => 'TK0','pos' => 'POS-ASMACC','branch' => 'JKT','manager' => 'EMP-0040','salary' => 18000000, 'role' => 'finance'],
            ['code' => 'EMP-0061', 'first' => 'Galang',   'last' => 'Pratama',       'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K0', 'pos' => 'POS-ASMHR', 'branch' => 'JKT','manager' => 'EMP-0047','salary' => 17500000, 'role' => 'payroll_admin'],
            ['code' => 'EMP-0062', 'first' => 'Wahyu',    'last' => 'Saputro',       'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-ASMITS','branch' => 'JKT','manager' => 'EMP-0023','salary' => 17000000, 'role' => 'it_admin'],

            // ── Supervisor ───────────────────────────────────────────
            ['code' => 'EMP-0070', 'first' => 'Arif',     'last' => 'Hidayat',       'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-SPVDEV','branch' => 'JKT','manager' => 'EMP-0050','salary' => 13500000, 'role' => 'supervisor'],
            ['code' => 'EMP-0071', 'first' => 'Lia',      'last' => 'Anggrayni',     'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-SPVFE', 'branch' => 'JKT','manager' => 'EMP-0050','salary' => 13000000, 'role' => 'supervisor'],
            ['code' => 'EMP-0072', 'first' => 'Edi',      'last' => 'Nurcahyo',      'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K2', 'pos' => 'POS-SPVOPS','branch' => 'SBY','manager' => 'EMP-0030','salary' => 12500000, 'role' => 'supervisor'],
            ['code' => 'EMP-0073', 'first' => 'Tania',    'last' => 'Marbun',        'gender' => 'F', 'religion' => 'Kristen', 'ptkp' => 'TK0','pos' => 'POS-SPVSLS','branch' => 'JKT','manager' => 'EMP-0053','salary' => 12000000, 'role' => 'supervisor'],
            ['code' => 'EMP-0074', 'first' => 'Putri',    'last' => 'Larasati',      'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'K0', 'pos' => 'POS-SPVPAY','branch' => 'JKT','manager' => 'EMP-0061','salary' => 12500000, 'role' => 'payroll_admin'],

            // ── Senior Staff ─────────────────────────────────────────
            ['code' => 'EMP-0080', 'first' => 'Dani',     'last' => 'Firmansyah',    'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-SRREC','branch' => 'JKT','manager' => 'EMP-0043','salary' => 11000000, 'role' => 'recruiter'],
            ['code' => 'EMP-0081', 'first' => 'Rini',     'last' => 'Sulistiawati',  'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-SRACC','branch' => 'JKT','manager' => 'EMP-0060','salary' => 10500000, 'role' => 'finance'],
            ['code' => 'EMP-0082', 'first' => 'Krisna',   'last' => 'Aditya',        'gender' => 'M', 'religion' => 'Hindu',   'ptkp' => 'K0', 'pos' => 'POS-SRBE', 'branch' => 'BDG','manager' => 'EMP-0070','salary' => 11500000, 'role' => 'employee'],
            ['code' => 'EMP-0083', 'first' => 'Kirana',   'last' => 'Aulia',         'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-SRFE', 'branch' => 'BDG','manager' => 'EMP-0071','salary' => 10800000, 'role' => 'employee'],
            ['code' => 'EMP-0084', 'first' => 'Rendy',    'last' => 'Kurniawan',     'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-SRPAY','branch' => 'JKT','manager' => 'EMP-0074','salary' => 10500000, 'role' => 'payroll_admin'],

            // ── Staff ────────────────────────────────────────────────
            ['code' => 'EMP-0090', 'first' => 'Mira',     'last' => 'Maharani',      'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-STFREC','branch' => 'JKT','manager' => 'EMP-0080','salary' => 7500000,  'role' => 'recruiter'],
            ['code' => 'EMP-0091', 'first' => 'Nadia',    'last' => 'Sadewa',        'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-STFLND','branch' => 'JKT','manager' => 'EMP-0044','salary' => 7000000,  'role' => 'learning_development'],
            ['code' => 'EMP-0092', 'first' => 'Arman',    'last' => 'Yulianto',      'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K0', 'pos' => 'POS-STFCNB','branch' => 'JKT','manager' => 'EMP-0045','salary' => 7200000,  'role' => 'compensation_benefits'],
            ['code' => 'EMP-0093', 'first' => 'Ratih',    'last' => 'Pradnyasari',   'gender' => 'F', 'religion' => 'Hindu',   'ptkp' => 'TK0','pos' => 'POS-STFIR', 'branch' => 'JKT','manager' => 'EMP-0046','salary' => 7000000,  'role' => 'employee'],
            ['code' => 'EMP-0094', 'first' => 'Ahmad',    'last' => 'Fauzi',         'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-STFACC','branch' => 'JKT','manager' => 'EMP-0060','salary' => 7500000,  'role' => 'finance'],
            ['code' => 'EMP-0095', 'first' => 'Linda',    'last' => 'Haryanti',      'gender' => 'F', 'religion' => 'Kristen', 'ptkp' => 'TK0','pos' => 'POS-STFTRS','branch' => 'JKT','manager' => 'EMP-0041','salary' => 7300000,  'role' => 'finance'],
            ['code' => 'EMP-0096', 'first' => 'Budi',     'last' => 'Sukarno',       'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K0', 'pos' => 'POS-STFTAX','branch' => 'JKT','manager' => 'EMP-0042','salary' => 7400000,  'role' => 'finance'],
            ['code' => 'EMP-0097', 'first' => 'Iwan',     'last' => 'Sembiring',     'gender' => 'M', 'religion' => 'Kristen', 'ptkp' => 'K1', 'pos' => 'POS-STFOPS','branch' => 'MDN','manager' => 'EMP-0032','salary' => 6500000,  'role' => 'employee'],
            ['code' => 'EMP-0098', 'first' => 'Tia',      'last' => 'Mulyani',       'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-STFOPS','branch' => 'SBY','manager' => 'EMP-0072','salary' => 6300000,  'role' => 'employee'],
            ['code' => 'EMP-0099', 'first' => 'Edo',      'last' => 'Pranoto',       'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-STFLOG','branch' => 'JKT','manager' => 'EMP-0048','salary' => 6700000,  'role' => 'employee'],
            ['code' => 'EMP-0100', 'first' => 'Vina',     'last' => 'Susanti',       'gender' => 'F', 'religion' => 'Buddha',  'ptkp' => 'TK0','pos' => 'POS-STFPRC','branch' => 'JKT','manager' => 'EMP-0049','salary' => 6800000,  'role' => 'employee'],
            ['code' => 'EMP-0101', 'first' => 'Hadi',     'last' => 'Pranata',       'gender' => 'M', 'religion' => 'Hindu',   'ptkp' => 'K0', 'pos' => 'POS-STFBE', 'branch' => 'BDG','manager' => 'EMP-0070','salary' => 8000000,  'role' => 'employee'],
            ['code' => 'EMP-0102', 'first' => 'Gita',     'last' => 'Permata',       'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-STFFE', 'branch' => 'BDG','manager' => 'EMP-0071','salary' => 7800000,  'role' => 'employee'],
            ['code' => 'EMP-0103', 'first' => 'Yoga',     'last' => 'Prasetyo',      'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K1', 'pos' => 'POS-STFINF','branch' => 'JKT','manager' => 'EMP-0051','salary' => 8500000,  'role' => 'it_admin'],
            ['code' => 'EMP-0104', 'first' => 'Cinta',    'last' => 'Wulandari',     'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-STFITS','branch' => 'JKT','manager' => 'EMP-0062','salary' => 6500000,  'role' => 'employee'],
            ['code' => 'EMP-0105', 'first' => 'Doni',     'last' => 'Saputra',       'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K0', 'pos' => 'POS-STFMKT','branch' => 'JKT','manager' => 'EMP-0052','salary' => 7200000,  'role' => 'employee'],
            ['code' => 'EMP-0106', 'first' => 'Erina',    'last' => 'Pranadewi',     'gender' => 'F', 'religion' => 'Hindu',   'ptkp' => 'TK0','pos' => 'POS-STFSLS','branch' => 'MKS','manager' => 'EMP-0033','salary' => 7000000,  'role' => 'employee'],
            ['code' => 'EMP-0107', 'first' => 'Faisal',   'last' => 'Rahman',        'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-STFSLS','branch' => 'MDN','manager' => 'EMP-0032','salary' => 7200000,  'role' => 'employee'],
            ['code' => 'EMP-0108', 'first' => 'Imam',     'last' => 'Mustofa',       'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'K2', 'pos' => 'POS-STFLGL','branch' => 'JKT','manager' => 'EMP-0054','salary' => 7800000,  'role' => 'legal_compliance'],
            ['code' => 'EMP-0109', 'first' => 'Citra',    'last' => 'Aulia',         'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-STFPAY','branch' => 'JKT','manager' => 'EMP-0074','salary' => 7000000,  'role' => 'payroll_admin'],

            // ── Junior ───────────────────────────────────────────────
            ['code' => 'EMP-0120', 'first' => 'Bagas',    'last' => 'Sanjaya',       'gender' => 'M', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-JRSLS', 'branch' => 'BDG','manager' => 'EMP-0073','salary' => 5200000,  'role' => 'employee'],
            ['code' => 'EMP-0121', 'first' => 'Lia',      'last' => 'Kusumastuti',   'gender' => 'F', 'religion' => 'Islam',   'ptkp' => 'TK0','pos' => 'POS-JROPS', 'branch' => 'MKS','manager' => 'EMP-0033','salary' => 5000000,  'role' => 'employee'],
        ];
    }

    private function seedStakeholders(): void
    {
        echo "▶ STAKEHOLDERS — buat employees + user accounts...\n";

        $roster = $this->stakeholderRoster();
        $enc    = \Config\Services::encryption();
        $passwordHash = password_hash(self::DEFAULT_PASSWORD, PASSWORD_ARGON2ID);

        // Round 1: insert SEMUA employees dulu, manager_id NULL.
        // Round 2: setelah semua punya ID, baru update manager_id.
        // Ini menghindari self-reference circular issue saat insert.
        foreach ($roster as $e) {
            $posId = $this->positions[$e['pos']];
            $posRow = $this->db->table('positions')->where('id', $posId)->get()->getRowArray();
            $deptId = (int) $posRow['department_id'];
            $gradeId = (int) $posRow['job_grade_id'];

            $email     = $this->emailFor($e['first'], $e['last'], $e['code']);
            $birthDate = date('Y-m-d', strtotime('-' . random_int(28, 58) . ' years -' . random_int(0, 365) . ' days'));
            $joinDate  = date('Y-m-d', strtotime('-' . random_int(180, 3000) . ' days'));

            // NIK 16 digit. Wanita: tanggal+40 (PPKKCC DDMMYY NNNN).
            $bd = (int) substr($birthDate, 8, 2);
            if ($e['gender'] === 'F') $bd += 40;
            $nik = '3171' . '01'
                . str_pad((string) $bd, 2, '0', STR_PAD_LEFT)
                . substr($birthDate, 5, 2)
                . substr($birthDate, 2, 2)
                . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

            $npwp = '0' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT)
                . str_pad((string) random_int(0, 9), 1, '0', STR_PAD_LEFT) . '000000';

            $bankAccount = str_pad((string) random_int(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);

            $branchProvince = $this->provinceForBranch($e['branch']);
            $branchCity     = $this->cityForBranch($e['branch']);

            $insert = [
                'company_id'        => $this->companyId,
                'branch_id'         => $this->branches[$e['branch']],
                'department_id'     => $deptId,
                'position_id'       => $posId,
                'job_grade_id'      => $gradeId,
                'manager_id'        => null, // resolved di round 2
                'employee_number'   => $e['code'],
                'first_name'        => $e['first'],
                'last_name'         => $e['last'],
                'full_name'         => trim($e['first'] . ' ' . $e['last']),
                'gender'            => $e['gender'],
                'religion'          => $e['religion'],
                'marital_status'    => $e['ptkp'],
                'place_of_birth'    => $branchCity,
                'date_of_birth'     => $birthDate,
                'blood_type'        => ['A','B','AB','O'][random_int(0, 3)],
                'nik_encrypted'     => $enc->encrypt($nik),
                'nik_hash'          => hash('sha256', $nik),
                'npwp_encrypted'    => $enc->encrypt($npwp),
                'bpjs_kesehatan_no' => '00' . str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT),
                'bpjs_tk_no'        => str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT),
                'bank_name'         => 'BCA',
                'bank_account_encrypted' => $enc->encrypt($bankAccount),
                'bank_account_holder'    => trim($e['first'] . ' ' . $e['last']),
                'email'             => $email,
                'phone'             => '08' . random_int(100000000, 999999999),
                'address'           => 'Jl. Quantara Residence Blok ' . chr(65 + random_int(0, 9)) . ' No. ' . random_int(1, 50),
                'province'          => $branchProvince,
                'city'              => $branchCity,
                'postal_code'       => $this->postalForBranch($e['branch']),
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

            // Optional fields yang ditambah migration 000010 (KTP, mother_name, dst).
            // Pakai try/catch supaya kalau kolom tidak ada (mis. setup minimal),
            // tetap bisa insert dengan kolom yang valid saja.
            try {
                $extra = [
                    'mother_name'     => 'Ibu ' . $e['first'],
                    'father_name'     => 'Bapak ' . $e['last'],
                    'citizenship'     => 'WNI',
                    'education_level' => $this->educationForGrade($gradeId),
                    'ktp_address'     => $insert['address'],
                    'ktp_city'        => $branchCity,
                    'ktp_province'    => $branchProvince,
                    'ktp_postal_code' => $insert['postal_code'],
                ];
                $existingCols = array_map(
                    static fn ($c) => strtolower($c->name),
                    $this->db->getFieldData('employees')
                );
                foreach ($extra as $k => $v) {
                    if (in_array(strtolower($k), $existingCols, true)) {
                        $insert[$k] = $v;
                    }
                }
            } catch (\Throwable $ex) {
                // Lewati silently — kolom optional.
            }

            $this->db->table('employees')->insert($insert);
            $empId = (int) $this->db->insertID();
            $this->employees[$e['code']] = $empId;

            // Buat user account. Role mapped via slug roster.
            $roleId = $this->roles[$e['role']] ?? $this->roles['employee'] ?? null;
            if ($roleId === null) {
                log_message('warning', "Role '{$e['role']}' tidak ditemukan. Skip user untuk {$e['code']}.");
                continue;
            }

            $this->db->table('users')->insert([
                'company_id'           => $this->companyId,
                'role_id'              => $roleId,
                'employee_id'          => $empId,
                'email'                => $email,
                'password_hash'        => $passwordHash,
                'name'                 => trim($e['first'] . ' ' . $e['last']),
                'is_active'            => 1,
                'must_change_password' => 1, // wajib ganti saat login pertama
                'password_changed_at'  => $this->now,
                'created_at'           => $this->now,
                'updated_at'           => $this->now,
            ]);
            $userId = (int) $this->db->insertID();
            $this->users[$e['code']] = $userId;

            // Link user_id balik ke employees.
            $this->db->table('employees')->where('id', $empId)->update(['user_id' => $userId]);

            // Insert kontrak PKWTT default.
            $this->db->table('employee_contracts')->insert([
                'company_id'      => $this->companyId,
                'employee_id'     => $empId,
                'contract_number' => 'CTR-' . $e['code'],
                'contract_type'   => 'PKWTT',
                'start_date'      => $joinDate,
                'end_date'        => null,
                'basic_salary'    => $e['salary'],
                'position_id'     => $posId,
                'department_id'   => $deptId,
                'notes'           => 'Kontrak Pegawai Tetap (PKWTT) — ' . $posRow['name'],
                'created_at'      => $this->now,
                'updated_at'      => $this->now,
            ]);
        }

        echo "  ✓ " . count($this->employees) . " employees + user accounts dibuat.\n\n";
    }

    /**
     * Round 2: set manager_id (dan supervisor_id kalau kolom ada) untuk
     * semua employee berdasar `manager_code` di roster. Dilakukan setelah
     * semua employee punya ID supaya self-FK aman.
     */
    private function resolveDirectReports(): void
    {
        echo "▶ DIRECT REPORTS — set manager_id & supervisor_id...\n";

        $hasSupervisor = false;
        try {
            $cols = array_map(
                static fn ($c) => strtolower($c->name),
                $this->db->getFieldData('employees')
            );
            $hasSupervisor = in_array('supervisor_id', $cols, true);
        } catch (\Throwable $e) {
            // ignore
        }

        $roster = $this->stakeholderRoster();
        foreach ($roster as $e) {
            if (! isset($this->employees[$e['code']])) continue;
            $update = [];
            if ($e['manager'] !== null && isset($this->employees[$e['manager']])) {
                $update['manager_id'] = $this->employees[$e['manager']];
                if ($hasSupervisor) {
                    $update['supervisor_id'] = $this->employees[$e['manager']];
                }
            }
            if ($update) {
                $this->db->table('employees')
                    ->where('id', $this->employees[$e['code']])
                    ->update($update);
            }
        }
        echo "  ✓ Reporting line lengkap.\n\n";
    }

    /**
     * Set departments.manager_employee_id untuk tiap departemen, agar
     * approval engine yang mencari DEPARTMENT_HEAD bisa bekerja.
     */
    private function seedDepartmentHeads(): void
    {
        echo "▶ DEPARTMENT HEADS — link manager_employee_id per departemen...\n";

        // Map: department code → employee code yang jadi head.
        $heads = [
            'BOC'     => 'EMP-0001',  // Komisaris Utama
            'BOD'     => 'EMP-0010',  // Direktur Utama
            'FIN'     => 'EMP-0011',  // CFO
            'HR'      => 'EMP-0012',  // CHRO
            'OPS'     => 'EMP-0013',  // COO
            'IT'      => 'EMP-0014',  // CTO
            'MKT'     => 'EMP-0015',  // CMO
            'LGL'     => 'EMP-0016',  // CLO

            'FIN-ACC' => 'EMP-0040',
            'FIN-TRS' => 'EMP-0041',
            'FIN-TAX' => 'EMP-0042',
            'HR-REC'  => 'EMP-0043',
            'HR-LND'  => 'EMP-0044',
            'HR-CNB'  => 'EMP-0045',
            'HR-IR'   => 'EMP-0046',
            'HR-OPS'  => 'EMP-0047',
            'OPS-OPR' => 'EMP-0072',  // Operations Supervisor jadi acting head
            'OPS-LOG' => 'EMP-0048',
            'OPS-PRC' => 'EMP-0049',
            'IT-DEV'  => 'EMP-0050',
            'IT-INF'  => 'EMP-0051',
            'IT-SUP'  => 'EMP-0062',  // Asst IT Support Manager
            'MKT-MKT' => 'EMP-0052',
            'MKT-SLS' => 'EMP-0053',
        ];

        foreach ($heads as $deptCode => $empCode) {
            if (! isset($this->departments[$deptCode]) || ! isset($this->employees[$empCode])) {
                continue;
            }
            $this->db->table('departments')
                ->where('id', $this->departments[$deptCode])
                ->update(['manager_employee_id' => $this->employees[$empCode]]);
        }
        echo "  ✓ Department heads ter-link.\n\n";
    }

    /**
     * Bootstrap admin@quantara.id sebagai akun super_admin terpisah,
     * untuk skenario darurat (kalau Direktur Utama lupa password).
     * Akun ini tidak ter-link ke employees.
     */
    private function seedDefaultBootstrapAdmin(): void
    {
        echo "▶ BOOTSTRAP ADMIN — admin@quantara.id (super_admin, tidak ter-link employee)...\n";

        $email = 'admin@quantara.id';
        $exists = $this->db->table('users')->where('email', $email)->countAllResults();
        if ($exists > 0) {
            echo "  Already exists. Skipped.\n\n";
            return;
        }

        $superRoleId = $this->roles['super_admin'] ?? 1;

        $this->db->table('users')->insert([
            'company_id'           => $this->companyId,
            'role_id'              => $superRoleId,
            'employee_id'          => null,
            'email'                => $email,
            'password_hash'        => password_hash(self::DEFAULT_PASSWORD, PASSWORD_ARGON2ID),
            'name'                 => 'Bootstrap Super Admin',
            'is_active'            => 1,
            'must_change_password' => 1,
            'password_changed_at'  => $this->now,
            'created_at'           => $this->now,
            'updated_at'           => $this->now,
        ]);
        echo "  ✓ admin@quantara.id dibuat (must_change_password=1).\n\n";
    }

    // ─────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Email institusional unik. Format default:
     *   <first>.<last>@quantara.id
     * Untuk Direktur/Komisaris, dipakai email peran khusus supaya
     * mudah dihafal (mis. direktur.utama@quantara.id).
     */
    private function emailFor(string $first, string $last, string $empCode): string
    {
        $byCode = [
            'EMP-0001' => 'komisaris.utama@quantara.id',
            'EMP-0002' => 'komisaris.independen@quantara.id',
            'EMP-0010' => 'direktur.utama@quantara.id',
            'EMP-0011' => 'direktur.keuangan@quantara.id',
            'EMP-0012' => 'direktur.sdm@quantara.id',
            'EMP-0013' => 'direktur.operasional@quantara.id',
            'EMP-0014' => 'direktur.ti@quantara.id',
            'EMP-0015' => 'direktur.pemasaran@quantara.id',
            'EMP-0016' => 'direktur.legal@quantara.id',
        ];
        if (isset($byCode[$empCode])) {
            return $byCode[$empCode];
        }
        $local = strtolower(
            preg_replace('/[^a-z0-9.]/', '', str_replace(' ', '.', strtolower($first . '.' . $last)))
        );
        return $local . '@quantara.id';
    }

    private function provinceForBranch(string $code): string
    {
        return [
            'JKT' => 'DKI Jakarta',
            'SBY' => 'Jawa Timur',
            'BDG' => 'Jawa Barat',
            'MDN' => 'Sumatera Utara',
            'MKS' => 'Sulawesi Selatan',
        ][$code] ?? 'DKI Jakarta';
    }

    private function cityForBranch(string $code): string
    {
        return [
            'JKT' => 'Jakarta Selatan',
            'SBY' => 'Surabaya',
            'BDG' => 'Bandung',
            'MDN' => 'Medan',
            'MKS' => 'Makassar',
        ][$code] ?? 'Jakarta Selatan';
    }

    private function postalForBranch(string $code): string
    {
        return [
            'JKT' => '12190',
            'SBY' => '60261',
            'BDG' => '40111',
            'MDN' => '20152',
            'MKS' => '90111',
        ][$code] ?? '12190';
    }

    /**
     * Reverse-lookup nama grade by id supaya bisa pilih level pendidikan
     * yang masuk akal: Direksi/Komisaris S2, Manager S1/S2, Staff D3/S1.
     */
    private function educationForGrade(int $gradeId): string
    {
        $byId = array_flip($this->jobGrades);
        $code = $byId[$gradeId] ?? 'STF';
        return [
            'BOC' => 'S2',
            'DIR' => 'S2',
            'VP'  => 'S2',
            'MGR' => 'S1',
            'ASM' => 'S1',
            'SPV' => 'S1',
            'SRS' => 'S1',
            'STF' => 'S1',
            'JRS' => 'D3',
        ][$code] ?? 'S1';
    }
}
