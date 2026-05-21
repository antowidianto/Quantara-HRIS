<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * HR Helpdesk / Ticket System
 *
 * 2 tabel:
 *   1. `hr_tickets` — tiket dari karyawan ke HR
 *   2. `hr_ticket_comments` — thread komentar bolak-balik
 *
 * Setiap tiket punya:
 *   - Subject, body, kategori (PAYROLL/LEAVE/ATTENDANCE/BENEFITS/OTHER)
 *   - Priority (LOW/MEDIUM/HIGH/URGENT)
 *   - Status: OPEN → IN_PROGRESS → ANSWERED → CLOSED
 *   - SLA: due_at = created_at + sla_hours sesuai priority
 *   - Assignee (HR yg handle)
 *   - is_internal di komentar — supaya HR bisa diskusi internal tanpa terlihat karyawan
 */
class CreateHelpdeskTickets extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('hr_tickets')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'ticket_number'   => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'TKT-<YEAR>-<5-digit>'],

                // Pelapor
                'employee_id'     => ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'Karyawan yang membuat tiket'],
                'created_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                // Konten
                'subject'         => ['type' => 'VARCHAR', 'constraint' => 200],
                'body'            => ['type' => 'TEXT'],
                'category' => [
                    'type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'PAYROLL/LEAVE/ATTENDANCE/BENEFITS/CONTRACT/IT/FACILITY/OTHER',
                ],
                'priority' => [
                    'type' => 'ENUM',
                    'constraint' => ['LOW','MEDIUM','HIGH','URGENT'],
                    'default' => 'MEDIUM',
                ],
                'attachment_path' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],

                // Status & assignee
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['OPEN','IN_PROGRESS','ANSWERED','CLOSED','REOPENED'],
                    'default' => 'OPEN',
                ],
                'assignee_user_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'assigned_at'     => ['type' => 'DATETIME', 'null' => true],

                // SLA
                'sla_due_at'      => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'created_at + jam SLA sesuai priority'],
                'first_response_at'=> ['type' => 'DATETIME', 'null' => true],
                'resolved_at'     => ['type' => 'DATETIME', 'null' => true],
                'closed_at'       => ['type' => 'DATETIME', 'null' => true],

                // Rating CSAT (1-5) saat close
                'satisfaction_rating' => ['type' => 'TINYINT', 'null' => true],
                'satisfaction_note'   => ['type' => 'TEXT', 'null' => true],

                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'ticket_number']);
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['company_id', 'status']);
            $forge->addKey(['company_id', 'assignee_user_id']);
            $forge->addKey(['company_id', 'category']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('hr_tickets');
        }

        if (! $db->tableExists('hr_ticket_comments')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'ticket_id'       => ['type' => 'INT', 'unsigned' => true],

                'author_user_id'  => ['type' => 'INT', 'unsigned' => true],
                'author_role' => [
                    'type' => 'VARCHAR', 'constraint' => 16,
                    'comment' => 'EMPLOYEE | HR | ADMIN | SYSTEM',
                ],
                'body'            => ['type' => 'TEXT'],
                'is_internal'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,
                    'comment' => '1 = catatan internal HR, tidak tampil ke karyawan'],
                'attachment_path' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],

                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['ticket_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('ticket_id', 'hr_tickets', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('hr_ticket_comments');
        }

        // Permissions
        $newPermissions = [
            ['slug' => 'helpdesk.view',       'name' => 'View Own Tickets',       'group' => 'helpdesk'],
            ['slug' => 'helpdesk.view_all',   'name' => 'View All Tickets',       'group' => 'helpdesk'],
            ['slug' => 'helpdesk.handle',     'name' => 'Assign & Handle Tickets','group' => 'helpdesk'],
            ['slug' => 'helpdesk.internal',   'name' => 'See Internal Comments',  'group' => 'helpdesk'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }

        // Auto-grant: semua role bisa view+create ticket sendiri
        // HR (role 2) bisa view_all + handle + internal
        $grantMap = [
            1 => ['helpdesk.view','helpdesk.view_all','helpdesk.handle','helpdesk.internal'],
            2 => ['helpdesk.view','helpdesk.view_all','helpdesk.handle','helpdesk.internal'],
            3 => ['helpdesk.view'],
            4 => ['helpdesk.view'],
            5 => ['helpdesk.view'],
        ];
        foreach ($grantMap as $roleId => $slugs) {
            foreach ($slugs as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $exists = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])->countAllResults();
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
        if ($this->db->tableExists('hr_ticket_comments')) $this->forge->dropTable('hr_ticket_comments', true);
        if ($this->db->tableExists('hr_tickets'))         $this->forge->dropTable('hr_tickets', true);
    }
}