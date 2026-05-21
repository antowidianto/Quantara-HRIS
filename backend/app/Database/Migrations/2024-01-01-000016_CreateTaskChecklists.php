<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Task Checklists — Onboarding & Offboarding
 *
 * Modul untuk mengelola checklist tugas otomatis saat:
 *   - Karyawan baru bergabung (ONBOARD): tanda tangan kontrak, BPJS,
 *     setup laptop/email, training induksi, dst.
 *   - Karyawan keluar (OFFBOARD): kembalikan asset, tutup akses,
 *     exit interview, terima dokumen ketenagakerjaan, dst.
 *
 * Dua tabel:
 *   - `task_templates`: definisi template per perusahaan, daftar item
 *     dengan owner_role + due_offset_days.
 *   - `employee_tasks`: instance task per karyawan, dgn status & evidence.
 *
 * Idempotent: cek tabel sudah ada.
 */
class CreateTaskChecklists extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── task_templates ──────────────────────────────────────────
        if (! $db->tableExists('task_templates')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'code'         => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'mis. ONBOARD_DEFAULT, OFFBOARD_RESIGN'],
                'name'         => ['type' => 'VARCHAR', 'constraint' => 200],
                'type' => [
                    'type' => 'ENUM',
                    'constraint' => ['ONBOARD','OFFBOARD'],
                ],
                'description'  => ['type' => 'TEXT', 'null' => true],
                'is_default'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => 'Template default untuk type tertentu di perusahaan ini'],
                'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],

                'tasks' => [
                    'type' => 'JSON',
                    'null' => true,
                    'comment' => 'Array of {key, name, description, owner_role, due_offset_days, mandatory}',
                ],

                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey(['company_id', 'type', 'is_default']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('task_templates');
        }

        // ─── employee_tasks ──────────────────────────────────────────
        if (! $db->tableExists('employee_tasks')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'employee_id'     => ['type' => 'INT', 'unsigned' => true],
                'template_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Reference ke task_templates yang menjadi sumber'],
                'type' => [
                    'type' => 'ENUM',
                    'constraint' => ['ONBOARD','OFFBOARD'],
                ],

                'task_key'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'Slug task (mis. CONTRACT_SIGN, RETURN_LAPTOP)'],
                'task_name'       => ['type' => 'VARCHAR', 'constraint' => 200],
                'description'     => ['type' => 'TEXT', 'null' => true],
                'owner_role'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'HR / IT / FINANCE / SUPERVISOR / EMPLOYEE'],
                'assignee_user_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Resolved user yang ditugaskan; null = pakai owner_role'],
                'due_date'        => ['type' => 'DATE', 'null' => true],
                'mandatory'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'sort_order'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],

                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['PENDING','IN_PROGRESS','COMPLETED','SKIPPED','CANCELLED'],
                    'default' => 'PENDING',
                ],
                'completed_at'    => ['type' => 'DATETIME', 'null' => true],
                'completed_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'completion_note' => ['type' => 'TEXT', 'null' => true],
                'evidence_path'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true,
                    'comment' => 'Upload bukti: scan kontrak, BAST, dll'],

                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'employee_id', 'type']);
            $forge->addKey(['employee_id', 'status']);
            $forge->addKey(['assignee_user_id', 'status']);
            $forge->addKey(['company_id', 'status', 'due_date']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('template_id', 'task_templates', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('employee_tasks');
        }

        // ─── Permission baru ────────────────────────────────────────
        $newPermissions = [
            ['slug' => 'onboarding.view',   'name' => 'View Onboarding/Offboarding tasks', 'group' => 'hr'],
            ['slug' => 'onboarding.manage', 'name' => 'Manage Task Templates & Tasks',     'group' => 'hr'],
            ['slug' => 'onboarding.complete_self', 'name' => 'Complete own assigned tasks','group' => 'hr'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        // Grant: role 1 (super) + 2 (HR) → semua; role 3 (manager), 4 (employee), 5 (finance) → complete_self + view
        $grantMap = [
            1 => ['onboarding.view','onboarding.manage','onboarding.complete_self'],
            2 => ['onboarding.view','onboarding.manage','onboarding.complete_self'],
            3 => ['onboarding.view','onboarding.complete_self'],
            4 => ['onboarding.view','onboarding.complete_self'],
            5 => ['onboarding.view','onboarding.complete_self'],
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
                    'role_id' => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('employee_tasks'))  $this->forge->dropTable('employee_tasks', true);
        if ($this->db->tableExists('task_templates'))  $this->forge->dropTable('task_templates', true);
    }
}