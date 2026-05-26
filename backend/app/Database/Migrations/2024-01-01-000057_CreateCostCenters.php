<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cost Center Integration (Sprint 6)
 *
 * Latar belakang:
 *   Sistem multi-tenant + multi-branch perlu dimensi accounting "cost
 *   center" untuk track ke mana biaya HR (gaji, allowance, lembur,
 *   reimbursement, pinjaman, bonus, training, severance, business trip,
 *   asset, recruitment) di-charge. Tanpa cost center:
 *     - Finance tidak bisa allocate biaya per profit center / lini bisnis
 *     - Budget vs actual hanya bisa di level company atau branch (terlalu kasar)
 *     - Laporan keuangan ke external (akuntansi, GL) tidak punya dimensi
 *       analitik yang dibutuhkan controller / management accounting.
 *
 * Konsep cost center:
 *   - Hierarchical: parent-child (mis. ROOT → DIRECTORATE → DEPARTMENT → TEAM)
 *   - Per company (multi-tenant safe)
 *   - Punya manager (employee) yang bertanggung jawab atas budget-nya
 *   - Punya budget tahunan dengan periodisasi (annual/quarterly/monthly)
 *   - Bisa di-link DEFAULT ke department dan/atau branch — sehingga setiap
 *     karyawan/transaksi yang lewat dept atau branch tersebut otomatis
 *     ter-tag ke cost center yang benar tanpa pilih manual.
 *
 * Resolusi cost_center_id untuk transaksi (chain of responsibility):
 *   1. Override eksplisit di payload (caller pilih sendiri)
 *   2. employee.cost_center_id (kalau di-set di profil — tinggi-spesifik)
 *   3. department.default_cost_center_id
 *   4. branch.default_cost_center_id
 *   5. company default (cost center root)
 *   6. NULL (best-effort, transaksi tetap bisa lanjut)
 *
 * Schema changes:
 *   1. `cost_centers` — tabel master (id, code, name, parent_id, manager_id,
 *      default_branch_id, default_department_id, budget_yearly, is_active)
 *   2. `cost_center_budgets` — periodisasi budget (year, period_type, period_no, amount)
 *   3. Tambah `cost_center_id` ke:
 *      - employees, departments, branches (default)
 *      - manpower_requests, job_postings (planning)
 *      - payroll_entries (transactional, populated saat calculate)
 *      - reimbursements, loans, business_trips, business_trip_expenses
 *      - bonus_runs (header), training_budgets (per dept/year)
 *      - severance_calculations, asset_assignments
 *      - employee_allowances (kalau tabel ada)
 *   4. Permissions: `cost_center.view`, `cost_center.manage`
 *
 * Idempotent: cek tableExists & getFieldNames sebelum modifikasi.
 *
 * Lihat docs/COST_CENTER.md.
 */
class CreateCostCenters extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── 1. cost_centers master ──────────────────────────────────
        if (! $db->tableExists('cost_centers')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'parent_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Parent cost center (untuk hierarki ROOT → DIRECTORATE → DEPT)'],
                'code'           => ['type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'Unik per company, mis. CC-IT-DEV, CC-OPS-JKT'],
                'name'           => ['type' => 'VARCHAR', 'constraint' => 200],
                'description'    => ['type' => 'TEXT', 'null' => true],

                // Mapping default — saat employee/transaksi tidak punya cost_center_id
                // eksplisit dan default-nya null, sistem akan resolve via:
                //   employee → department.default_cost_center_id
                //            → branch.default_cost_center_id
                //            → company root
                // Field di sini optional untuk klasifikasi master.
                'default_branch_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Branch yang default-nya pakai cost center ini (display only)'],
                'default_department_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Department yang default-nya pakai cost center ini (display only)'],

                'manager_employee_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Penanggung jawab budget cost center'],

                // Budget tahunan default — kalau detail per periode di-set
                // di cost_center_budgets, field ini hanya cache total.
                'budget_yearly'   => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0,
                    'comment' => 'Cache total budget tahun berjalan (informational)'],

                'is_active'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'sort_order'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],

                'notes'          => ['type' => 'TEXT', 'null' => true],
                'created_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'updated_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey(['company_id', 'is_active']);
            $forge->addKey('parent_id');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('cost_centers');
        }

        // ─── 2. cost_center_budgets (periodisasi) ────────────────────
        // Memungkinkan budget di-pecah per quarter atau bulan untuk
        // cash-flow planning yang lebih akurat (mis. THR di Q2 lebih besar).
        if (! $db->tableExists('cost_center_budgets')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'cost_center_id'  => ['type' => 'INT', 'unsigned' => true],
                'period_year'     => ['type' => 'YEAR'],
                'period_type'     => [
                    'type' => 'ENUM',
                    'constraint' => ['ANNUAL', 'QUARTERLY', 'MONTHLY'],
                    'default' => 'ANNUAL',
                ],
                'period_no'       => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true,
                    'comment' => '1-4 untuk quarterly, 1-12 untuk monthly, NULL untuk annual'],
                'amount'          => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
                'category'        => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
                    'comment' => 'Optional — SALARY/ALLOWANCE/BONUS/TRAINING/dst untuk breakdown'],
                'notes'           => ['type' => 'TEXT', 'null' => true],
                'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'updated_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey([
                'cost_center_id', 'period_year', 'period_type', 'period_no', 'category',
            ], 'cc_budget_period_uniq');
            $forge->addKey(['company_id', 'period_year']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('cost_center_id', 'cost_centers', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('cost_center_budgets');
        }

        // ─── 3. Tambah cost_center_id ke tabel terkait ───────────────
        // Helper untuk add column kalau belum ada.
        $addCostCenterCol = function (string $table, string $comment) use ($db, $forge) {
            if (! $db->tableExists($table)) return false;
            $cols = $db->getFieldNames($table);
            if (in_array('cost_center_id', $cols, true)) return false;
            $forge->addColumn($table, [
                'cost_center_id' => [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => $comment,
                ],
            ]);
            return true;
        };

        // Master records: dapat default cost center
        $addCostCenterCol('departments', 'Default cost center untuk karyawan & transaksi di department ini');
        $addCostCenterCol('branches',    'Default cost center untuk karyawan & transaksi di branch ini');
        $addCostCenterCol('employees',   'Cost center karyawan (override department/branch default kalau di-set)');

        // Recruitment / planning chain
        $addCostCenterCol('manpower_requests', 'Cost center yang akan menanggung gaji karyawan baru — di-plan saat MPP submit');
        $addCostCenterCol('job_postings',      'Cost center carry-over dari MPP, fallback dari department/branch default');

        // Transactional records — di-populate saat insert/calculate
        // payslips: tabel utama transaksional payroll (per employee per period)
        $addCostCenterCol('payslips',                'Cost center untuk allocation biaya gaji ke GL (per payslip)');
        $addCostCenterCol('payroll_entries',         'Legacy alias untuk payslips (kalau schema lama). Best-effort.');
        $addCostCenterCol('reimbursements',          'Cost center untuk reimbursement, default dari employee');
        $addCostCenterCol('loans',                   'Cost center untuk pinjaman (asset finance perspective)');
        $addCostCenterCol('business_trips',          'Cost center untuk biaya perjalanan dinas');
        $addCostCenterCol('business_trip_expenses',  'Cost center per item expense (override trip-level kalau perlu)');
        $addCostCenterCol('bonus_runs',              'Cost center default untuk semua recipient di bonus run ini');
        $addCostCenterCol('training_budgets',        'Cost center untuk budget training (selain department & branch)');
        $addCostCenterCol('severance_calculations',  'Cost center untuk biaya pesangon & UPMK');
        $addCostCenterCol('asset_assignments',       'Cost center untuk depresiasi/biaya asset selama dipegang karyawan');

        // Employee allowances (kalau tabel ada — Sprint 9+)
        if ($db->tableExists('employee_allowances')) {
            $addCostCenterCol('employee_allowances', 'Cost center allowance — biasanya ikut employee.cost_center_id');
        }

        // ─── 4. Permissions baru ─────────────────────────────────────
        $newPermissions = [
            ['slug' => 'cost_center.view',   'name' => 'View Cost Centers',    'group' => 'finance'],
            ['slug' => 'cost_center.manage', 'name' => 'Manage Cost Centers + Budget', 'group' => 'finance'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        // Grant default:
        //   role 1 (super) + 2 (HR) + 5 (finance) → view + manage
        //   role 3 (manager) → view (untuk dashboard budget)
        $grantMap = [
            1 => ['cost_center.view', 'cost_center.manage'],
            2 => ['cost_center.view', 'cost_center.manage'],
            5 => ['cost_center.view', 'cost_center.manage'],
            3 => ['cost_center.view'],
        ];
        foreach ($grantMap as $roleId => $slugs) {
            foreach ($slugs as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $exists = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($exists > 0) continue;
                $db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at'    => $now,
                ]);
            }
        }

        // ─── 5. Backfill ROOT cost center per company ──────────────
        // Setiap company dapat 1 root cost center "Default" supaya transaksi
        // legacy yang belum di-tag tetap bisa direport (resolve fallback ke
        // root). Code = "DEFAULT", parent_id NULL, is_active 1.
        $companies = $db->table('companies')->where('deleted_at IS NULL')->get()->getResultArray();
        foreach ($companies as $c) {
            $exists = $db->table('cost_centers')
                ->where('company_id', (int) $c['id'])
                ->where('code', 'DEFAULT')
                ->countAllResults();
            if ($exists > 0) continue;
            $db->table('cost_centers')->insert([
                'company_id'   => (int) $c['id'],
                'code'         => 'DEFAULT',
                'name'         => 'Default Cost Center',
                'description'  => 'Auto-created saat migration — fallback untuk transaksi yang belum di-tag eksplisit. HR bisa edit nama / hapus setelah master cost center sebenarnya di-setup.',
                'is_active'    => 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        $db = $this->db;

        // Drop kolom-kolom tambahan dari tabel transaksional & master
        $tables = [
            'departments', 'branches', 'employees',
            'manpower_requests', 'job_postings',
            'payslips', 'payroll_entries',
            'reimbursements', 'loans',
            'business_trips', 'business_trip_expenses',
            'bonus_runs', 'training_budgets',
            'severance_calculations', 'asset_assignments',
            'employee_allowances',
        ];
        foreach ($tables as $t) {
            if (! $db->tableExists($t)) continue;
            $cols = $db->getFieldNames($t);
            if (in_array('cost_center_id', $cols, true)) {
                try { $this->forge->dropColumn($t, 'cost_center_id'); } catch (\Throwable $e) {}
            }
        }

        if ($db->tableExists('cost_center_budgets')) {
            $this->forge->dropTable('cost_center_budgets', true);
        }
        if ($db->tableExists('cost_centers')) {
            $this->forge->dropTable('cost_centers', true);
        }

        // Drop permissions
        foreach (['cost_center.view', 'cost_center.manage'] as $slug) {
            $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
            if (! $perm) continue;
            $db->table('role_permissions')->where('permission_id', (int) $perm['id'])->delete();
            $db->table('permissions')->where('id', (int) $perm['id'])->delete();
        }
    }
}