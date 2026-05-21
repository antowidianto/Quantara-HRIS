<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Training & Sertifikasi
 *
 * 4 tabel:
 *   1. `trainings`              — master training (judul, type, biaya, kuota, periode)
 *   2. `training_registrations` — pendaftaran karyawan ke training (state machine)
 *   3. `certificates`           — sertifikat dgn expiry tracking
 *   4. `training_budgets`       — alokasi & realisasi budget per departemen per tahun
 *
 * State registration: REGISTERED → APPROVED → ATTENDING → COMPLETED → CERTIFIED
 *                                ↓
 *                          REJECTED / CANCELLED / NO_SHOW
 */
class CreateTrainingTables extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // 1. trainings
        if (! $db->tableExists('trainings')) {
            $forge->addField([
                'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'  => ['type' => 'INT', 'unsigned' => true],
                'code'        => ['type' => 'VARCHAR', 'constraint' => 50,
                    'comment' => 'TRN-<YEAR>-<5digit>'],
                'title'       => ['type' => 'VARCHAR', 'constraint' => 200],
                'description' => ['type' => 'TEXT', 'null' => true],
                'category' => [
                    'type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'TECHNICAL/SOFT_SKILL/COMPLIANCE/SAFETY/LEADERSHIP/OTHER',
                ],
                'training_type' => [
                    'type' => 'VARCHAR', 'constraint' => 16,
                    'default' => 'INTERNAL',
                    'comment' => 'INTERNAL | EXTERNAL | ONLINE | HYBRID',
                ],
                'provider'    => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
                    'comment' => 'Vendor / penyelenggara'],
                'instructor'  => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'location'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],

                // Schedule
                'start_date'  => ['type' => 'DATE'],
                'end_date'    => ['type' => 'DATE'],
                'duration_hours' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0,
                    'comment' => 'Total jam pembelajaran'],

                // Capacity
                'max_participants' => ['type' => 'INT', 'unsigned' => true, 'default' => 0,
                    'comment' => '0 = unlimited'],
                'min_participants' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],

                // Cost
                'cost_per_participant' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Biaya per peserta'],
                'cost_total_actual' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Total biaya aktual yg sudah keluar'],
                'department_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Charged to specific department (null = pool)'],

                // Certificate template
                'issues_certificate' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'certificate_validity_months' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Berlaku N bulan; null = no expiry'],
                'passing_score' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true,
                    'comment' => 'Skor minimum lulus (0-100); null = no test'],

                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT','PUBLISHED','ONGOING','COMPLETED','CANCELLED'],
                    'default' => 'DRAFT',
                ],
                'notes' => ['type' => 'TEXT', 'null' => true],
                'created_at'  => ['type' => 'DATETIME', 'null' => true],
                'updated_at'  => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'  => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey(['company_id', 'status']);
            $forge->addKey(['company_id', 'category']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('trainings');
        }

        // 2. training_registrations
        if (! $db->tableExists('training_registrations')) {
            $forge->addField([
                'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'  => ['type' => 'INT', 'unsigned' => true],
                'training_id' => ['type' => 'INT', 'unsigned' => true],
                'employee_id' => ['type' => 'INT', 'unsigned' => true],

                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['REGISTERED','APPROVED','ATTENDING','COMPLETED','CERTIFIED','REJECTED','CANCELLED','NO_SHOW'],
                    'default' => 'REGISTERED',
                ],
                'registered_at' => ['type' => 'DATETIME'],
                'approved_at'   => ['type' => 'DATETIME', 'null' => true],
                'approved_by'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'completed_at'  => ['type' => 'DATETIME', 'null' => true],

                'attendance_percent' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true,
                    'comment' => 'Persentase kehadiran 0-100'],
                'final_score' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true,
                    'comment' => 'Skor akhir 0-100'],
                'is_passed' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true,
                    'comment' => 'Lulus passing_score?'],

                'cost'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Biaya yg dialokasikan untuk peserta ini'],
                'notes'  => ['type' => 'TEXT', 'null' => true],
                'rejection_reason' => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['training_id', 'employee_id']);
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['company_id', 'status']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('training_id', 'trainings', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('training_registrations');
        }

        // 3. certificates — bisa generated dari training_registration atau di-upload manual
        if (! $db->tableExists('certificates')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'employee_id'  => ['type' => 'INT', 'unsigned' => true],
                'training_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Null kalau di-upload manual (sertifikat eksternal)'],
                'registration_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                'certificate_number' => ['type' => 'VARCHAR', 'constraint' => 100,
                    'comment' => 'CERT-<YEAR>-<5digit> atau dari issuer eksternal'],
                'title'        => ['type' => 'VARCHAR', 'constraint' => 200],
                'category' => [
                    'type' => 'VARCHAR', 'constraint' => 32,
                    'default' => 'TRAINING',
                    'comment' => 'TRAINING/PROFESSIONAL/COMPLIANCE/LICENSE/OTHER',
                ],
                'issuer'       => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'issued_date'  => ['type' => 'DATE'],
                'expires_date' => ['type' => 'DATE', 'null' => true,
                    'comment' => 'Null = tidak expired (lifetime)'],
                'score'        => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
                'file_path'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true,
                    'comment' => 'Path PDF sertifikat'],

                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['ACTIVE','EXPIRED','REVOKED'],
                    'default' => 'ACTIVE',
                ],
                'last_alert_sent_at' => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Untuk avoid spam alerts'],
                'notes'        => ['type' => 'TEXT', 'null' => true],
                'created_by'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'certificate_number']);
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['expires_date']);
            $forge->addKey(['company_id', 'status']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('training_id', 'trainings', 'id', 'SET NULL', 'CASCADE');
            $forge->createTable('certificates');
        }

        // 4. training_budgets — alokasi per dept per tahun
        if (! $db->tableExists('training_budgets')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'department_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Null = company-wide pool'],
                'year'           => ['type' => 'INT', 'unsigned' => true],
                'allocated_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
                'used_amount'    => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Sum dari training cost yang sudah COMPLETED'],
                'reserved_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Sum dari training cost yg masih APPROVED/ATTENDING'],
                'notes'          => ['type' => 'TEXT', 'null' => true],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'department_id', 'year']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('training_budgets');
        }

        // Permissions
        $newPermissions = [
            ['slug' => 'training.view',       'name' => 'View Trainings',           'group' => 'training'],
            ['slug' => 'training.manage',     'name' => 'Manage Trainings',         'group' => 'training'],
            ['slug' => 'training.register',   'name' => 'Register to Trainings',    'group' => 'training'],
            ['slug' => 'certificate.view',    'name' => 'View Certificates',        'group' => 'training'],
            ['slug' => 'certificate.manage',  'name' => 'Manage Certificates',      'group' => 'training'],
            ['slug' => 'training_budget.manage','name'=>'Manage Training Budget',   'group' => 'training'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }

        // Auto-grant: role 1 & 2 dapat semua; role 3-5 dapat view + register
        $grantMap = [
            1 => ['training.view','training.manage','training.register','certificate.view','certificate.manage','training_budget.manage'],
            2 => ['training.view','training.manage','training.register','certificate.view','certificate.manage','training_budget.manage'],
            3 => ['training.view','training.register','certificate.view'],
            4 => ['training.view','training.register','certificate.view'],
            5 => ['training.view','training.register','certificate.view'],
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
        if ($this->db->tableExists('certificates'))            $this->forge->dropTable('certificates', true);
        if ($this->db->tableExists('training_registrations'))  $this->forge->dropTable('training_registrations', true);
        if ($this->db->tableExists('training_budgets'))        $this->forge->dropTable('training_budgets', true);
        if ($this->db->tableExists('trainings'))               $this->forge->dropTable('trainings', true);
    }
}