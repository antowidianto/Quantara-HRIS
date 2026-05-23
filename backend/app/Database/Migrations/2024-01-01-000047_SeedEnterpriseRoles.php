<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 22+ — Seed enterprise roles untuk perusahaan besar Indonesia.
 *
 * Migration ini menambahkan 13 role baru yang merepresentasikan struktur
 * organisasi perusahaan menengah-besar Indonesia (1000+ karyawan,
 * multi-cabang, multi-departemen, regulated industries).
 *
 * Role existing dari InitialSeeder (super_admin, hr_admin, manager,
 * employee, finance) tetap dipertahankan untuk backward compatibility.
 *
 * Daftar role baru (id 10–22 supaya gap dari existing & user-defined):
 *   10  ceo                        — Chief Executive Officer (BoD)
 *   11  cfo                        — Chief Financial Officer
 *   12  hr_director                — Chief HR / HR Director
 *   13  hr_business_partner        — HR Business Partner (HRBP)
 *   14  recruiter                  — Talent Acquisition / Recruiter
 *   15  learning_development       — Learning & Development Specialist
 *   16  compensation_benefits      — Comp & Benefits / Payroll Specialist
 *   17  payroll_admin              — Payroll Administrator (operasional)
 *   18  branch_manager             — Branch / Site Manager
 *   19  department_head            — Department Head / Kepala Bagian
 *   20  supervisor                 — Supervisor / Team Lead
 *   21  it_admin                   — IT System Administrator
 *   22  internal_auditor           — Internal Auditor (read-only)
 *   23  legal_compliance           — Legal & Compliance Officer
 *   24  data_privacy_officer       — Data Protection / DPO
 *
 * Semua role di-mark `is_system = 1` artinya tidak boleh di-delete via
 * UI normal tapi BISA di-customize permission-nya per company (HR Admin
 * tinggal pilih role yang paling cocok, lalu adjust permission mapping
 * di Settings → Roles).
 *
 * Idempotent: kalau slug role atau mapping sudah ada, skip — aman
 * dijalankan ulang. Sesuai ADR 0004 (additive-only).
 */
class SeedEnterpriseRoles extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $db  = \Config\Database::connect();

        // ─── 1. Insert role baru ────────────────────────────────────
        foreach (self::roles() as $r) {
            $exists = $db->table('roles')->where('slug', $r['slug'])->countAllResults();
            if ($exists > 0) continue;

            $db->table('roles')->insert([
                'id'         => $r['id'],
                'company_id' => null,        // system role lintas-company
                'slug'       => $r['slug'],
                'name'       => $r['name'],
                'description'=> $r['description'] ?? null,
                'is_system'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ─── 2. Map permission ke setiap role ───────────────────────
        // Lookup semua permission yang ada untuk match by slug
        $allPerms = $db->table('permissions')->get()->getResultArray();
        $bySlug = [];
        foreach ($allPerms as $p) {
            $bySlug[$p['slug']] = (int) $p['id'];
        }

        foreach (self::permissionMatrix() as $roleSlug => $permSlugs) {
            $role = $db->table('roles')->where('slug', $roleSlug)->get()->getRowArray();
            if (! $role) continue;
            $roleId = (int) $role['id'];

            foreach ($permSlugs as $permSlug) {
                if (! isset($bySlug[$permSlug])) continue;
                $permId = $bySlug[$permSlug];

                $exists = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permId)
                    ->countAllResults();
                if ($exists > 0) continue;

                $db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => $permId,
                    'created_at'    => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Hapus mapping role_permissions + roles. HATI-HATI: kalau ada
        // user yang sudah pakai role ini, drop akan cascade — di
        // production sebaiknya jangan jalankan down().
        $db = \Config\Database::connect();
        foreach (array_column(self::roles(), 'slug') as $slug) {
            $role = $db->table('roles')->where('slug', $slug)->get()->getRowArray();
            if (! $role) continue;
            $db->table('role_permissions')->where('role_id', (int) $role['id'])->delete();
            $db->table('roles')->where('id', (int) $role['id'])->delete();
        }
    }

    /**
     * @return array<int, array{id:int, slug:string, name:string, description?:string}>
     */
    private static function roles(): array
    {
        return [
            [
                'id'   => 10, 'slug' => 'ceo', 'name' => 'CEO / Direktur Utama',
                'description' => 'Akses read-only ke seluruh data agregat untuk decision making. '
                    . 'Tidak edit data operasional — hanya melihat dashboard, laporan, dan disposisi keputusan.',
            ],
            [
                'id'   => 11, 'slug' => 'cfo', 'name' => 'CFO / Direktur Keuangan',
                'description' => 'Pemilik keputusan finance: approver tertinggi payroll, reimbursement, loan, bonus. '
                    . 'Punya akses penuh ke semua laporan keuangan & audit trail.',
            ],
            [
                'id'   => 12, 'slug' => 'hr_director', 'name' => 'HR Director / CHRO',
                'description' => 'Pimpinan HR. Approver tertinggi disciplinary, severance, promosi (job change), '
                    . 'manpower planning, dan kebijakan HR (regulation, contract template).',
            ],
            [
                'id'   => 13, 'slug' => 'hr_business_partner', 'name' => 'HR Business Partner (HRBP)',
                'description' => 'HR strategic per business unit / divisi. Mediator antara manajemen dan karyawan. '
                    . 'Akses penuh data karyawan di unit yang di-handle, plus performance review & disciplinary.',
            ],
            [
                'id'   => 14, 'slug' => 'recruiter', 'name' => 'Recruiter / Talent Acquisition',
                'description' => 'Spesialis rekrutmen. Manage job posting (career portal), seleksi kandidat, '
                    . 'onboarding pre-employment, hingga proses hire. Tidak akses payroll atau performance.',
            ],
            [
                'id'   => 15, 'slug' => 'learning_development', 'name' => 'Learning & Development',
                'description' => 'Spesialis training & pengembangan. Manage program training, sertifikat, '
                    . 'budget training, dan kalender pelatihan. Akses view ke performance untuk gap analysis.',
            ],
            [
                'id'   => 16, 'slug' => 'compensation_benefits', 'name' => 'Compensation & Benefits',
                'description' => 'Spesialis remunerasi. Design struktur gaji, bonus scheme, allowance mapping, '
                    . 'BPJS, PPh21. Tidak approve payroll final (itu CFO/Finance Manager) tapi setup formula & rate.',
            ],
            [
                'id'   => 17, 'slug' => 'payroll_admin', 'name' => 'Payroll Administrator',
                'description' => 'Operasional payroll bulanan: kalkulasi, generate payslip, distribusi BPJS, '
                    . 'PPh21 e-bupot. Tidak approve final, hanya prepare untuk approval Finance Manager.',
            ],
            [
                'id'   => 18, 'slug' => 'branch_manager', 'name' => 'Branch Manager / Kepala Cabang',
                'description' => 'Pemimpin cabang / site / pabrik. Approve attendance, leave, overtime, '
                    . 'business trip, reimbursement seluruh karyawan di cabangnya. Multi-departemen scope.',
            ],
            [
                'id'   => 19, 'slug' => 'department_head', 'name' => 'Department Head / Kepala Bagian',
                'description' => 'Kepala departemen (mis. Operations, Engineering, Sales). Approve permintaan '
                    . 'tim seperti leave, overtime, training. Owner KPI & performance review departemen.',
            ],
            [
                'id'   => 20, 'slug' => 'supervisor', 'name' => 'Supervisor / Team Lead',
                'description' => 'Front-line supervisor: shift leader, koordinator tim. Approve attendance harian '
                    . 'dan overtime tim kecil. Tidak punya akses agregat HR atau payroll.',
            ],
            [
                'id'   => 21, 'slug' => 'it_admin', 'name' => 'IT System Administrator',
                'description' => 'Administrator teknis sistem: manage user accounts, mesin absen (devices), '
                    . 'webhook integration, security settings. Tidak akses data HR atau payroll personal.',
            ],
            [
                'id'   => 22, 'slug' => 'internal_auditor', 'name' => 'Internal Auditor',
                'description' => 'Read-only access ke audit trail, payroll, attendance, finance reports. '
                    . 'Tidak boleh edit apa pun. Untuk SOX/ISO/SPI compliance review.',
            ],
            [
                'id'   => 23, 'slug' => 'legal_compliance', 'name' => 'Legal & Compliance Officer',
                'description' => 'Pengelola compliance: contract template, regulation (peraturan perusahaan), '
                    . 'disciplinary review, severance review. Penjaga compliance UU 13/2003, UU PPh, UU PDP.',
            ],
            [
                'id'   => 24, 'slug' => 'data_privacy_officer', 'name' => 'Data Protection Officer (DPO)',
                'description' => 'Pelindung data pribadi sesuai UU PDP (UU 27/2022). Akses ke privacy module: '
                    . 'consent management, data retention, audit access ke PII. Tidak edit data karyawan operasional.',
            ],
        ];
    }

    /**
     * Permission matrix per role. Slug yang tidak ada di sistem akan
     * di-skip otomatis (defensive — kalau modul belum ter-install di
     * tier tertentu, tidak fatal).
     *
     * @return array<string, array<int, string>>
     */
    private static function permissionMatrix(): array
    {
        // Modul-modul yang umum dipakai
        $viewAllEmployeeData = ['employee.view', 'employee.view_self',
            'attendance.view_all', 'attendance.view_all_branches',
            'leave.view', 'leave.team_calendar',
            'overtime.view', 'business_trip.view',
            'payslip.view_all', 'payroll.view',
            'reimbursement.view', 'loan.view',
            'training.view', 'certificate.view',
            'goal.view', 'performance.view',
            'org_structure.view', 'org_structure.manage',
            'mpp.view',
            'report.view', 'audit.view',
        ];

        return [
            // ─── EXECUTIVE / C-LEVEL ────────────────────────────────
            'ceo' => array_merge($viewAllEmployeeData, [
                // Tambahan: lihat semua tapi approve hanya hal strategic
                'company.view', 'branch.view', 'department.view', 'position.view',
                'mpp.approve',                  // Final approve manpower planning
                'employee_transfer.view', 'jic.view',
                'severance.view',
                'bonus.view', 'bonus.approve',  // Approve bonus run final
                'careers.manage',               // Bisa veto / approve job posting strategic
                'regulation.view',
                'helpdesk.view_all',
                'recruitment.view',
                'resignation.view',
                'disciplinary.view',
            ]),

            // ─── CFO ────────────────────────────────────────────────
            'cfo' => [
                'company.view', 'branch.view', 'department.view', 'position.view',
                'employee.view',
                'payroll.view', 'payroll.manage', 'payroll.calculate', 'payroll.finalize',
                'payslip.view_all',
                'reimbursement.view', 'reimbursement.approve',
                'loan.view', 'loan.approve',
                'business_trip.view', 'business_trip.approve', 'business_trip.settle',
                'bonus.view', 'bonus.manage', 'bonus.approve',
                'severance.view', 'severance.manage',
                'training_budget.manage',
                'audit.view',
                'report.view',
                'mpp.view', 'mpp.approve',                        // Hiring impact ke budget
                'overtime.view', 'overtime.approve',              // OT cost approver tertinggi
            ],

            // ─── HR DIRECTOR / CHRO ────────────────────────────────
            'hr_director' => [
                'company.view', 'branch.view', 'branch.manage',
                'department.view', 'department.manage',
                'position.view', 'position.manage',
                'employee.view', 'employee.create', 'employee.update', 'employee.delete',
                'attendance.view_all', 'attendance.view_all_branches', 'attendance.approve',
                'attendance.approve_change', 'attendance.review_anomaly',
                'leave.view', 'leave.approve', 'leave.manage_types', 'leave_balance.manage',
                'overtime.view', 'overtime.approve',
                'business_trip.view', 'business_trip.approve', 'business_trip.manage',
                'payroll.view', 'payslip.view_all',
                'reimbursement.view', 'reimbursement.approve',
                'loan.view', 'loan.approve',
                'recruitment.view', 'recruitment.manage', 'recruitment.hire',
                'mpp.view', 'mpp.submit', 'mpp.approve', 'mpp.fulfill',
                'careers.manage',
                'onboarding.view', 'onboarding.manage',
                'training.view', 'training.manage', 'training_budget.manage',
                'certificate.view', 'certificate.manage',
                'goal.view', 'goal.manage', 'goal.approve',
                'performance.view', 'performance.manage',
                'review.view_team', 'review.conduct',
                'disciplinary.view', 'disciplinary.manage',
                'resignation.view', 'resignation.manage',
                'severance.view', 'severance.manage',
                'employee_transfer.view', 'employee_transfer.create', 'employee_transfer.approve',
                'employee_transfer.implement',
                'jic.view', 'jic.create', 'jic.approve', 'jic.implement', 'jic.manage',
                'org_structure.view', 'org_structure.manage',
                'contract_template.view', 'contract_template.manage', 'contract.generate',
                'regulation.view', 'regulation.manage',
                'helpdesk.view_all', 'helpdesk.handle', 'helpdesk.internal',
                'shift.view', 'shift.manage', 'shift_pattern.view', 'shift_pattern.manage',
                'roster.view', 'roster.manage',
                'work_location.view', 'work_location.manage',
                'approval_chain.view', 'approval_chain.manage',
                'delegation.view', 'delegation.manage',
                'bonus.view', 'bonus.manage', 'bonus.approve',
                'audit.view', 'report.view', 'settings.manage',
                'asset.view',
            ],

            // ─── HR BUSINESS PARTNER ────────────────────────────────
            'hr_business_partner' => [
                'employee.view', 'employee.create', 'employee.update',
                'attendance.view_all', 'attendance.approve_change', 'attendance.review_anomaly',
                'leave.view', 'leave.approve', 'leave.team_calendar',
                'overtime.view', 'overtime.approve',
                'business_trip.view', 'business_trip.approve',
                'payslip.view_all',
                'reimbursement.view', 'reimbursement.approve',
                'recruitment.view', 'recruitment.manage',
                'mpp.view', 'mpp.submit',
                'onboarding.view', 'onboarding.manage',
                'training.view', 'training.register',
                'certificate.view',
                'goal.view', 'goal.approve',
                'performance.view', 'review.view_team', 'review.conduct',
                'disciplinary.view', 'disciplinary.manage',
                'resignation.view', 'resignation.manage',
                'employee_transfer.view', 'employee_transfer.create',
                'jic.view', 'jic.create',
                'org_structure.view',
                'helpdesk.view_all', 'helpdesk.handle',
                'shift.view', 'roster.view',
                'bonus.view',
                'report.view',
                'employee.view_self', 'attendance.clock', 'attendance.request_change',
                'leave.create', 'overtime.create', 'reimbursement.create',
                'loan.view', 'loan.create', 'payslip.view_self',
            ],

            // ─── RECRUITER ──────────────────────────────────────────
            'recruiter' => [
                'employee.view', 'employee.create',
                'recruitment.view', 'recruitment.manage', 'recruitment.hire',
                'mpp.view', 'mpp.fulfill',
                'careers.manage',
                'onboarding.view', 'onboarding.manage',
                'org_structure.view',
                'position.view', 'department.view', 'branch.view',
                'contract_template.view', 'contract.generate',
                'helpdesk.view',
                'employee.view_self', 'attendance.clock',
                'leave.view', 'leave.create',
                'payslip.view_self',
                'report.view',
            ],

            // ─── LEARNING & DEVELOPMENT ─────────────────────────────
            'learning_development' => [
                'employee.view',
                'training.view', 'training.manage', 'training.register',
                'training_budget.manage',
                'certificate.view', 'certificate.manage',
                'goal.view', 'performance.view',
                'review.view_team',
                'org_structure.view', 'department.view', 'position.view',
                'employee.view_self', 'attendance.clock',
                'leave.view', 'leave.create',
                'overtime.view', 'overtime.create',
                'reimbursement.view', 'reimbursement.create',
                'payslip.view_self',
                'helpdesk.view',
                'report.view',
            ],

            // ─── COMPENSATION & BENEFITS ───────────────────────────
            'compensation_benefits' => [
                'employee.view',
                'payroll.view', 'payroll.manage', 'payroll.calculate',
                'payslip.view_all',
                'bonus.view', 'bonus.manage',
                'reimbursement.view', 'loan.view',
                'severance.view', 'severance.manage',
                'business_trip.view',
                'overtime.view',
                'org_structure.view',
                'position.view', 'position.manage',
                'department.view', 'branch.view',
                'settings.manage',                 // BPJS rate, PPh21 setting
                'audit.view', 'report.view',
                'employee.view_self', 'attendance.clock',
                'leave.view', 'leave.create',
                'payslip.view_self',
            ],

            // ─── PAYROLL ADMINISTRATOR ──────────────────────────────
            'payroll_admin' => [
                'employee.view',
                'payroll.view', 'payroll.calculate',
                'payslip.view_all',
                'bonus.view',
                'reimbursement.view', 'loan.view',
                'overtime.view',
                'business_trip.view',
                'severance.view',
                'attendance.view_all',
                'leave.view',
                'org_structure.view',
                'audit.view',
                'employee.view_self', 'attendance.clock',
                'leave.create', 'reimbursement.create',
                'payslip.view_self',
                'helpdesk.view',
                'report.view',
            ],

            // ─── BRANCH MANAGER ─────────────────────────────────────
            'branch_manager' => [
                'employee.view',
                'attendance.view_all', 'attendance.approve_change', 'attendance.correct',
                'attendance.approve', 'attendance.review_anomaly',
                'leave.view', 'leave.approve', 'leave.team_calendar',
                'overtime.view', 'overtime.approve',
                'business_trip.view', 'business_trip.approve',
                'reimbursement.view', 'reimbursement.approve',
                'loan.view', 'loan.approve',
                'mpp.view', 'mpp.submit',
                'recruitment.view',
                'onboarding.view',
                'training.view',
                'goal.view', 'goal.approve',
                'performance.view', 'review.view_team', 'review.conduct',
                'disciplinary.view',
                'resignation.view',
                'employee_transfer.view',
                'org_structure.view',
                'shift.view', 'roster.view', 'roster.manage',
                'helpdesk.view_all',
                'asset.view',
                'bonus.view',
                'employee.view_self', 'attendance.clock', 'attendance.request_change',
                'leave.create', 'overtime.create', 'reimbursement.create',
                'business_trip.settle',
                'loan.create', 'payslip.view_self',
                'report.view',
            ],

            // ─── DEPARTMENT HEAD ────────────────────────────────────
            'department_head' => [
                'employee.view',
                'attendance.view_all', 'attendance.approve_change',
                'leave.view', 'leave.approve', 'leave.team_calendar',
                'overtime.view', 'overtime.approve',
                'business_trip.view', 'business_trip.approve',
                'reimbursement.view', 'reimbursement.approve',
                'mpp.view', 'mpp.submit',
                'training.view', 'training.register',
                'goal.view', 'goal.manage', 'goal.approve',
                'performance.view', 'review.view_team', 'review.conduct',
                'recruitment.view',
                'org_structure.view',
                'shift.view', 'roster.view',
                'helpdesk.view',
                'asset.view',
                'employee.view_self', 'attendance.clock', 'attendance.request_change',
                'leave.create', 'overtime.create', 'reimbursement.create',
                'business_trip.settle',
                'loan.view', 'loan.create', 'payslip.view_self',
                'delegation.manage_self',
                'report.view',
            ],

            // ─── SUPERVISOR / TEAM LEAD ─────────────────────────────
            'supervisor' => [
                'employee.view',
                'attendance.view_all', 'attendance.approve_change',
                'leave.view', 'leave.approve', 'leave.team_calendar',
                'overtime.view', 'overtime.approve',
                'goal.view', 'review.view_team',
                'shift.view', 'roster.view',
                'employee.view_self', 'attendance.clock', 'attendance.request_change',
                'leave.create', 'overtime.create',
                'reimbursement.view', 'reimbursement.create',
                'loan.view', 'loan.create',
                'payslip.view_self',
                'business_trip.view', 'business_trip.settle',
                'helpdesk.view',
                'training.view', 'training.register',
                'delegation.manage_self',
            ],

            // ─── IT ADMIN ───────────────────────────────────────────
            'it_admin' => [
                'user.manage', 'role.manage',
                'attendance.manage_devices',
                'settings.manage',
                'audit.view',
                'company.view', 'branch.view', 'department.view', 'position.view',
                'org_structure.view',
                'employee.view',                   // Need to onboard user accounts
                'helpdesk.view_all', 'helpdesk.handle', 'helpdesk.internal',
                'employee.view_self', 'attendance.clock',
                'leave.view', 'leave.create',
                'payslip.view_self',
                'asset.view', 'asset.manage',      // IT manages laptop/device assets
            ],

            // ─── INTERNAL AUDITOR ───────────────────────────────────
            'internal_auditor' => [
                // Read-only across the board, NO write/approve permissions
                'company.view', 'branch.view', 'department.view', 'position.view',
                'employee.view',
                'attendance.view_all', 'attendance.view_all_branches',
                'leave.view',
                'overtime.view',
                'business_trip.view',
                'payroll.view', 'payslip.view_all',
                'bonus.view',
                'reimbursement.view', 'loan.view',
                'severance.view',
                'recruitment.view', 'mpp.view',
                'training.view', 'certificate.view',
                'goal.view', 'performance.view',
                'disciplinary.view', 'resignation.view',
                'employee_transfer.view', 'jic.view',
                'org_structure.view',
                'contract_template.view', 'regulation.view',
                'shift.view', 'roster.view',
                'asset.view',
                'audit.view', 'report.view',
                'employee.view_self', 'attendance.clock',
                'leave.create', 'payslip.view_self',
            ],

            // ─── LEGAL & COMPLIANCE ────────────────────────────────
            'legal_compliance' => [
                'employee.view',
                'contract_template.view', 'contract_template.manage', 'contract.generate',
                'regulation.view', 'regulation.manage',
                'disciplinary.view', 'disciplinary.manage',
                'resignation.view',
                'severance.view', 'severance.manage',
                'jic.view', 'jic.create', 'jic.manage',
                'audit.view',
                'org_structure.view', 'department.view', 'position.view', 'branch.view',
                'helpdesk.view_all',
                'employee.view_self', 'attendance.clock',
                'leave.create', 'reimbursement.create',
                'payslip.view_self',
                'report.view',
            ],

            // ─── DATA PRIVACY OFFICER ──────────────────────────────
            'data_privacy_officer' => [
                'employee.view',
                'audit.view',
                'settings.manage',                 // Privacy retention rules
                'org_structure.view',
                'helpdesk.view_all',
                // DPO juga punya akses ke privacy module — slug spesifik
                // di-introduce di module privacy. Kalau modul belum ada,
                // tetap ok karena loop di atas skip yang tidak ditemukan.
                'employee.view_self', 'attendance.clock',
                'leave.create',
                'payslip.view_self',
                'report.view',
            ],
        ];
    }
}