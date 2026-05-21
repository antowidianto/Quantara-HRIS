<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Recruitment / ATS (Applicant Tracking System)
 *
 * 4 tabel untuk pipeline rekrutmen:
 *   1. `job_postings` — lowongan kerja
 *   2. `candidates`   — pelamar
 *   3. `candidate_stages` — tahapan progress per kandidat (NEW → SCREENING → ...)
 *   4. `candidate_documents` — CV, foto, ijazah, dst.
 *
 * Setelah HIRED, candidate bisa di-convert ke `employees` dengan tombol
 * khusus, auto-trigger ONBOARD_DEFAULT template via OnboardingService.
 */
class CreateRecruitmentTables extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('job_postings')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'reference_number'=> ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'JOB-<YEAR>-<5digit>'],
                'title'           => ['type' => 'VARCHAR', 'constraint' => 200],
                'department_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'position_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'employment_type' => [
                    'type' => 'ENUM',
                    'constraint' => ['PKWT','PKWTT','MAGANG','OUTSOURCING','PARTTIME','CONTRACT'],
                    'default' => 'PKWTT',
                ],
                'description'     => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Deskripsi pekerjaan'],
                'requirements'    => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Persyaratan kandidat'],
                'salary_min'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'salary_max'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'salary_visible'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,
                    'comment' => 'Public-kan range gaji ke kandidat'],
                'headcount'       => ['type' => 'INT', 'default' => 1,
                    'comment' => 'Jumlah orang yang dibutuhkan'],
                'opened_at'       => ['type' => 'DATE', 'null' => true],
                'closed_at'       => ['type' => 'DATE', 'null' => true,
                    'comment' => 'Deadline / tanggal tutup posting'],
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT','OPEN','PAUSED','CLOSED','FILLED'],
                    'default' => 'DRAFT',
                ],
                'hiring_manager_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'User HR/manager penanggung jawab posting'],
                'public_url_slug' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true,
                    'comment' => 'Slug untuk public URL (kalau diaktifkan)'],
                'notes'           => ['type' => 'TEXT', 'null' => true],

                'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'updated_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'reference_number']);
            $forge->addKey(['company_id', 'status']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('job_postings');
        }

        if (! $db->tableExists('candidates')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'job_posting_id'  => ['type' => 'INT', 'unsigned' => true],

                // Profil kandidat
                'full_name'       => ['type' => 'VARCHAR', 'constraint' => 200],
                'email'           => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'phone'           => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'birth_date'      => ['type' => 'DATE', 'null' => true],
                'gender'          => [
                    'type' => 'ENUM', 'constraint' => ['M','F','O'], 'null' => true,
                ],
                'address'         => ['type' => 'TEXT', 'null' => true],
                'current_position'=> ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'current_company' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'years_experience'=> ['type' => 'INT', 'null' => true],
                'expected_salary' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'available_from'  => ['type' => 'DATE', 'null' => true,
                    'comment' => 'Tanggal kandidat siap mulai'],
                'source'          => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true,
                    'comment' => 'WEBSITE / LINKEDIN / JOBSTREET / REFERRAL / WALK_IN / OTHER'],
                'referral_employee_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                // Stage saat ini
                'current_stage' => [
                    'type' => 'VARCHAR', 'constraint' => 32, 'default' => 'NEW',
                    'comment' => 'NEW / SCREENING / INTERVIEW_HR / INTERVIEW_USER / OFFER / HIRED / REJECTED / WITHDRAWN',
                ],
                'rating'        => ['type' => 'TINYINT', 'null' => true,
                    'comment' => '1-5 bintang dari recruiter'],
                'rejection_reason'=> ['type' => 'TEXT', 'null' => true],

                // Kalau sudah di-convert ke employee
                'converted_employee_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'converted_at'    => ['type' => 'DATETIME', 'null' => true],

                'notes'           => ['type' => 'TEXT', 'null' => true],
                'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'job_posting_id']);
            $forge->addKey(['company_id', 'current_stage']);
            $forge->addKey('email');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('job_posting_id', 'job_postings', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('candidates');
        }

        if (! $db->tableExists('candidate_stages')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'candidate_id'    => ['type' => 'INT', 'unsigned' => true],
                'stage'           => ['type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'Same set: NEW/SCREENING/INTERVIEW_HR/INTERVIEW_USER/OFFER/HIRED/REJECTED/WITHDRAWN'],
                'result' => [
                    'type' => 'ENUM',
                    'constraint' => ['PENDING','PASSED','FAILED','SKIPPED'],
                    'default' => 'PENDING',
                ],
                'scheduled_at'    => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Untuk INTERVIEW_*: jadwal interview'],
                'completed_at'    => ['type' => 'DATETIME', 'null' => true],
                'interviewer_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'User yang melakukan interview/screening'],
                'feedback'        => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Catatan dari interviewer'],
                'rating'          => ['type' => 'TINYINT', 'null' => true,
                    'comment' => '1-5 untuk stage ini'],

                'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['candidate_id', 'stage']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('candidate_id', 'candidates', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('candidate_stages');
        }

        if (! $db->tableExists('candidate_documents')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'candidate_id'    => ['type' => 'INT', 'unsigned' => true],
                'document_type'   => [
                    'type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'CV / KTP / IJAZAH / TRANSKRIP / SERTIFIKAT / PORTFOLIO / OTHER',
                ],
                'file_name'       => ['type' => 'VARCHAR', 'constraint' => 255],
                'file_path'       => ['type' => 'VARCHAR', 'constraint' => 500],
                'mime_type'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'file_size'       => ['type' => 'INT', 'null' => true],
                'notes'           => ['type' => 'TEXT', 'null' => true],
                'uploaded_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['candidate_id', 'document_type']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('candidate_id', 'candidates', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('candidate_documents');
        }

        // Permission baru
        $newPermissions = [
            ['slug' => 'recruitment.view',   'name' => 'View Recruitment',    'group' => 'recruitment'],
            ['slug' => 'recruitment.manage', 'name' => 'Manage Recruitment',  'group' => 'recruitment'],
            ['slug' => 'recruitment.hire',   'name' => 'Convert candidate to employee', 'group' => 'recruitment'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }

        // Auto-grant: role 1 super + 2 HR → semua, role 3 manager → view+manage (untuk
        // request pembukaan posisi & interview), role 4 employee → tidak ada akses.
        $grantMap = [
            1 => ['recruitment.view','recruitment.manage','recruitment.hire'],
            2 => ['recruitment.view','recruitment.manage','recruitment.hire'],
            3 => ['recruitment.view','recruitment.manage'],
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
        if ($this->db->tableExists('candidate_documents')) $this->forge->dropTable('candidate_documents', true);
        if ($this->db->tableExists('candidate_stages'))    $this->forge->dropTable('candidate_stages', true);
        if ($this->db->tableExists('candidates'))          $this->forge->dropTable('candidates', true);
        if ($this->db->tableExists('job_postings'))        $this->forge->dropTable('job_postings', true);
    }
}