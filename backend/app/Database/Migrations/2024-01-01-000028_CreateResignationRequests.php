<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Resignation Requests
 *
 * Self-service resignation flow:
 *   1. Karyawan ajukan surat resign (DRAFT → PENDING_SUPERVISOR).
 *   2. Atasan langsung approve/reject (PENDING_SUPERVISOR → PENDING_HR / REJECTED).
 *   3. HR final review + accept (PENDING_HR → APPROVED / REJECTED).
 *   4. Saat APPROVED: trigger otomatis
 *      - set employees.resign_date + resign_reason
 *      - generate offboarding tasks via OnboardingService (template OFFBOARD)
 *      - notify IT/Finance/Supervisor untuk task masing-masing
 *
 * Karyawan tetap bisa CANCEL selama status masih PENDING_SUPERVISOR atau
 * PENDING_HR (sebelum HR finalize). Setelah APPROVED, hanya HR yang bisa
 * REVOKE (skenario karyawan menarik resignation tapi sudah disetujui — perlu
 * audit trail terpisah, tidak di-handle migration ini).
 *
 * Multi-tenant: company_id sesuai employee. Privacy: hanya pemilik request
 * + supervisor + HR yang bisa view.
 *
 * Lihat docs/RESIGNATION.md untuk flow lengkap.
 */
class CreateResignationRequests extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('resignation_requests')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'employee_id'         => ['type' => 'INT', 'unsigned' => true],

                // Input pengajuan
                'submitted_at'        => ['type' => 'DATETIME', 'null' => true],
                'last_working_date'   => [
                    'type' => 'DATE',
                    'comment' => 'Tanggal terakhir bekerja yang diajukan karyawan (last day in office)',
                ],
                'effective_date'      => [
                    'type' => 'DATE',
                    'comment' => 'Tanggal resmi berakhir hubungan kerja (biasanya last_working_date + cuti pengganti)',
                ],
                'reason_category'     => [
                    'type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'PERSONAL|CAREER|HEALTH|FAMILY|EDUCATION|RETIREMENT|OTHER',
                ],
                'reason_text'         => [
                    'type' => 'TEXT', 'null' => true,
                    'comment' => 'Detail alasan dari karyawan (untuk surat resign)',
                ],
                'attachment_path'     => [
                    'type' => 'VARCHAR', 'constraint' => 500, 'null' => true,
                    'comment' => 'Path surat resign yang sudah ditandatangani (PDF) di FileStorage',
                ],
                'notice_period_days'  => [
                    'type' => 'INT', 'unsigned' => true, 'default' => 30,
                    'comment' => 'Hitungan hari pemberitahuan saat submit; UU 13/2003 minimum 30 hari',
                ],

                // Workflow
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'DRAFT',
                        'PENDING_SUPERVISOR',
                        'PENDING_HR',
                        'APPROVED',
                        'REJECTED',
                        'CANCELLED',
                    ],
                    'default' => 'DRAFT',
                ],

                // Approval atasan langsung
                'supervisor_user_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'supervisor_action_at'=> ['type' => 'DATETIME', 'null' => true],
                'supervisor_note'     => ['type' => 'TEXT', 'null' => true],
                'supervisor_decision' => [
                    'type' => 'ENUM',
                    'constraint' => ['APPROVED', 'REJECTED'],
                    'null' => true,
                ],

                // Final review HR
                'hr_user_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'hr_action_at'        => ['type' => 'DATETIME', 'null' => true],
                'hr_note'             => ['type' => 'TEXT', 'null' => true],
                'hr_decision'         => [
                    'type' => 'ENUM',
                    'constraint' => ['APPROVED', 'REJECTED'],
                    'null' => true,
                ],

                // Eligibility severance (terkait kategori) — di-set HR saat approve
                'severance_calculation_id' => [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Link ke severance_calculations (nullable; resign sukarela biasanya tanpa pesangon, hanya UPH)',
                ],
                // Link ke task offboarding yang ter-generate setelah approve
                'offboarding_template_id' => [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'task_templates.id yang dipakai untuk generate tasks',
                ],
                'tasks_generated_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],

                // Cancellation (bisa dari karyawan atau HR)
                'cancelled_at'        => ['type' => 'DATETIME', 'null' => true],
                'cancelled_by'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'cancellation_reason' => ['type' => 'TEXT', 'null' => true],

                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['employee_id', 'status']);
            $forge->addKey(['company_id', 'status']);
            $forge->addKey('last_working_date');
            $forge->addForeignKey('company_id',  'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('severance_calculation_id', 'severance_calculations', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('resignation_requests');
        }

        // Permission baru — fine-grained:
        //   resignation.submit  → setiap user employed (tidak perlu grant
        //                         eksplisit, di-allow di controller)
        //   resignation.view    → HR boleh lihat semua di company
        //   resignation.approve → atasan + HR (cek di controller)
        //   resignation.manage  → HR (override, cancel, set offboarding template)
        $newPermissions = [
            ['slug' => 'resignation.view',    'name' => 'View Resignation Requests', 'group' => 'hr'],
            ['slug' => 'resignation.manage',  'name' => 'Manage Resignation Requests (HR final review)', 'group' => 'hr'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        // Grant ke role 1 (super admin) & 2 (HR admin).
        foreach ([1, 2] as $roleId) {
            foreach (array_column($newPermissions, 'slug') as $slug) {
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
    }

    public function down(): void
    {
        if ($this->db->tableExists('resignation_requests')) {
            $this->forge->dropTable('resignation_requests', true);
        }
    }
}