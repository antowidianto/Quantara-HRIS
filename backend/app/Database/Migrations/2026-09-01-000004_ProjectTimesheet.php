<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Modul Baru — Project-Based Timesheet.
 *
 * Membuka segmen konsultan, kontraktor, agency, dan jasa profesional
 * yang membutuhkan **time-tracking per project** untuk:
 *   - Project costing & billable hours invoicing ke klien
 *   - Cost charging ke project code (override cost_center default)
 *   - Utilization rate per consultant (billable / total worked hours)
 *   - Burn-rate vs budget per project
 *
 * Tiga tabel:
 *   - projects               : master project (code, name, client, budget_hours)
 *   - project_assignments    : pemetaan karyawan ke project (default %)
 *   - timesheet_entries      : entry per hari per project per task
 *
 * Idempotent guard. Aman re-run.
 *
 * Permission:
 *   - timesheet.submit       : karyawan submit timesheet
 *   - timesheet.approve      : supervisor approve
 *   - timesheet.report       : HR/PM lihat laporan utilization & burn-rate
 *   - project.manage         : kelola master project
 */
class ProjectTimesheet extends Migration
{
    public function up(): void
    {
        $db    = $this->db;
        $forge = $this->forge;

        // ─── projects ──────────────────────────────────────────────
        if (! $db->tableExists('projects')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'cost_center_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Default cost center untuk biaya project ini'],
                'code'            => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Kode unik project per company (mis. PRJ-2026-001)'],
                'name'            => ['type' => 'VARCHAR', 'constraint' => 200],
                'description'     => ['type' => 'TEXT', 'null' => true],
                'client_name'     => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'currency'        => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'IDR'],
                'budget_hours'    => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
                'budget_amount'   => ['type' => 'DECIMAL', 'constraint' => '18,2', 'null' => true],
                'billable_default'=> ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1,
                    'comment' => '1 = jam pada project ini default billable ke klien'],
                'starts_on'       => ['type' => 'DATE', 'null' => true],
                'ends_on'         => ['type' => 'DATE', 'null' => true],
                'status'          => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'ACTIVE',
                    'comment'    => 'PLANNING | ACTIVE | ON_HOLD | COMPLETED | CANCELLED',
                ],
                'project_manager_employee_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey(['company_id', 'status']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('projects');
        }

        // ─── project_assignments ──────────────────────────────────
        if (! $db->tableExists('project_assignments')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'project_id'      => ['type' => 'INT', 'unsigned' => true],
                'employee_id'     => ['type' => 'INT', 'unsigned' => true],
                'role'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true,
                    'comment' => 'Role karyawan di project ini (mis. Lead, Engineer, Analyst)'],
                'allocation_pct'  => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 100,
                    'comment' => 'Persentase alokasi planning (mis. 50% = part-time pada project ini)'],
                'billable_rate'   => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true,
                    'comment' => 'Tarif/jam billable ke klien (override company default jika diisi)'],
                'cost_rate'       => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true,
                    'comment' => 'Internal cost rate/jam (HPP). Untuk margin calculation.'],
                'starts_on'       => ['type' => 'DATE', 'null' => true],
                'ends_on'         => ['type' => 'DATE', 'null' => true],
                'is_active'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['project_id', 'is_active']);
            $forge->addKey(['employee_id', 'is_active']);
            $forge->addUniqueKey(['project_id', 'employee_id']);
            $forge->addForeignKey('project_id', 'projects', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('project_assignments');
        }

        // ─── timesheet_entries ────────────────────────────────────
        if (! $db->tableExists('timesheet_entries')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'employee_id'     => ['type' => 'INT', 'unsigned' => true],
                'project_id'      => ['type' => 'INT', 'unsigned' => true],
                'work_date'       => ['type' => 'DATE'],
                'hours'           => ['type' => 'DECIMAL', 'constraint' => '5,2',
                    'comment' => 'Jam dengan presisi 0.25 (15 menit). Max 24 per hari per submission.'],
                'task_description'=> ['type' => 'VARCHAR', 'constraint' => 500],
                'is_billable'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'is_overtime'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 jika di luar jam kerja standar (link ke overtime module nanti)'],
                'status'          => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'DRAFT',
                    'comment'    => 'DRAFT | SUBMITTED | APPROVED | REJECTED',
                ],
                'submitted_at'    => ['type' => 'DATETIME', 'null' => true],
                'approver_user_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'approved_at'     => ['type' => 'DATETIME', 'null' => true],
                'rejection_reason'=> ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'employee_id', 'work_date']);
            $forge->addKey(['project_id', 'work_date', 'status']);
            $forge->addKey(['status', 'submitted_at']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('project_id', 'projects', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('timesheet_entries');
        }

        // ─── Permissions ────────────────────────────────────────────
        if ($db->tableExists('permissions')) {
            $perms = [
                ['slug' => 'timesheet.submit',  'name' => 'Submit Timesheet',     'group' => 'timesheet', 'description' => 'Submit timesheet entry pribadi'],
                ['slug' => 'timesheet.approve', 'name' => 'Approve Timesheet',    'group' => 'timesheet', 'description' => 'Approve timesheet bawahan'],
                ['slug' => 'timesheet.report',  'name' => 'View Timesheet Report','group' => 'timesheet', 'description' => 'Lihat laporan utilization & burn-rate per project'],
                ['slug' => 'project.manage',    'name' => 'Manage Projects',      'group' => 'timesheet', 'description' => 'Kelola master project & assignment'],
            ];
            $now = date('Y-m-d H:i:s');
            foreach ($perms as $p) {
                $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
                if (! $exists) {
                    $db->table('permissions')->insert($p + ['created_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $db    = $this->db;
        $forge = $this->forge;

        foreach ([
            'timesheet_entries',
            'project_assignments',
            'projects',
        ] as $tbl) {
            if ($db->tableExists($tbl)) {
                $forge->dropTable($tbl, true);
            }
        }
        if ($db->tableExists('permissions')) {
            $db->table('permissions')->whereIn('slug', [
                'timesheet.submit',
                'timesheet.approve',
                'timesheet.report',
                'project.manage',
            ])->delete();
        }
    }
}