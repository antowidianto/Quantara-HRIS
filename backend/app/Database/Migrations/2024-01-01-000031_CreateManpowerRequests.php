<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Manpower Planning (MPP) Request
 *
 * Pengajuan kebutuhan SDM dari line manager (N+1) yang harus di-approve
 * sebelum bisa di-hiring. Setelah APPROVED, Talent Acquisition bisa
 * ubah MPP ini menjadi `job_postings` (lowongan), publish ke Career
 * Portal, dan tracking sampai kandidat HIRED + onboarding.
 *
 * Alur status:
 *   DRAFT → SUBMITTED → APPROVED → HIRING → FILLED → CLOSED
 *                    ↘ REJECTED
 *                    ↘ CANCELLED
 *
 * Tipe:
 *   BUDGET   — sudah masuk MPN tahunan / RKAP (approval ringan)
 *   UNBUDGET — di luar rencana, biasanya butuh approval direksi
 *
 * Reason:
 *   NEW_POSITION — posisi baru
 *   REPLACEMENT  — pengganti karyawan resign / mutasi
 *   EXPANSION    — ekspansi tim existing
 *   SEASONAL     — proyek / musiman / kontrak terbatas
 *
 * Tabel:
 *   1. `manpower_requests`           — header pengajuan
 *   2. `manpower_request_approvals`  — jejak approval per langkah
 *   3. `manpower_request_postings`   — link MPP ↔ job_postings (n:m,
 *      satu MPP boleh punya beberapa lowongan kalau kebutuhan
 *      di-split per cabang / lokasi).
 */
class CreateManpowerRequests extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('manpower_requests')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'reference_number'=> ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'MPP-<YEAR>-<5digit>'],

                // Pengaju (N+1 / line manager)
                'requested_by_user_id'    => ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'User yang submit pengajuan'],
                'requested_by_employee_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'department_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'position_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Posisi yang dibutuhkan (boleh null kalau posisi baru)'],
                'branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                // Klasifikasi kebutuhan
                'request_type' => [
                    'type' => 'ENUM',
                    'constraint' => ['BUDGET', 'UNBUDGET'],
                    'default' => 'BUDGET',
                    'comment' => 'BUDGET=sudah di RKAP, UNBUDGET=di luar rencana'],
                'reason' => [
                    'type' => 'ENUM',
                    'constraint' => ['NEW_POSITION', 'REPLACEMENT', 'EXPANSION', 'SEASONAL', 'OTHER'],
                    'default' => 'REPLACEMENT'],
                'replacement_for_employee_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Karyawan yang digantikan (untuk reason=REPLACEMENT)'],

                // Detail kebutuhan
                'proposed_title'  => ['type' => 'VARCHAR', 'constraint' => 200,
                    'comment' => 'Judul posisi yang diusulkan (akan jadi job_postings.title)'],
                'headcount'       => ['type' => 'INT', 'unsigned' => true, 'default' => 1,
                    'comment' => 'Jumlah orang yang dibutuhkan'],
                'employment_type' => [
                    'type' => 'ENUM',
                    'constraint' => ['PKWT','PKWTT','MAGANG','OUTSOURCING','PARTTIME','CONTRACT'],
                    'default' => 'PKWTT'],
                'salary_min'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'salary_max'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'target_start_date' => ['type' => 'DATE', 'null' => true,
                    'comment' => 'Target karyawan baru sudah on-board'],
                'justification'   => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Justifikasi kebutuhan SDM (wajib untuk UNBUDGET)'],
                'job_description' => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Deskripsi pekerjaan (akan jadi job_postings.description)'],
                'requirements'    => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Persyaratan kandidat (akan jadi job_postings.requirements)'],

                // Status / lifecycle
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT','SUBMITTED','APPROVED','REJECTED','HIRING','FILLED','CLOSED','CANCELLED'],
                    'default' => 'DRAFT'],
                'submitted_at'    => ['type' => 'DATETIME', 'null' => true],
                'approved_at'     => ['type' => 'DATETIME', 'null' => true],
                'approved_by_user_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'rejected_at'     => ['type' => 'DATETIME', 'null' => true],
                'rejection_reason'=> ['type' => 'TEXT', 'null' => true],
                'closed_at'       => ['type' => 'DATETIME', 'null' => true],

                // Tracking progress hire
                'hired_count'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0,
                    'comment' => 'Jumlah kandidat yang sudah HIRED + converted ke employee'],

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
            $forge->addKey(['company_id', 'department_id']);
            $forge->addKey('requested_by_user_id');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('manpower_requests');
        }

        if (! $db->tableExists('manpower_request_approvals')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'manpower_request_id' => ['type' => 'INT', 'unsigned' => true],
                'step'           => ['type' => 'INT', 'unsigned' => true, 'default' => 1,
                    'comment' => 'Urutan langkah approval (1, 2, 3...)'],
                'approver_role'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'role_key dari organization_structures (HRD_MANAGER, DIRECTOR_HR, dll)'],
                'expected_approver_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'User yang seharusnya approve (resolved dari org_structures.employee_id saat submit)'],
                'expected_approver_employee_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Employee penjabat (display only)'],
                'approver_user_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'User yang melakukan approval (filled saat decided)'],
                'decision' => [
                    'type' => 'ENUM',
                    'constraint' => ['PENDING','APPROVED','REJECTED','SKIPPED'],
                    'default' => 'PENDING'],
                'decided_at'     => ['type' => 'DATETIME', 'null' => true],
                'comment'        => ['type' => 'TEXT', 'null' => true],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['manpower_request_id', 'step']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('manpower_request_id', 'manpower_requests', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('manpower_request_approvals');
        }

        if (! $db->tableExists('manpower_request_postings')) {
            // Pivot: 1 MPP bisa di-fulfil dengan beberapa job_postings,
            // misal hire 5 orang di-split jadi 2 lowongan (Jakarta + Bandung).
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'manpower_request_id' => ['type' => 'INT', 'unsigned' => true],
                'job_posting_id'      => ['type' => 'INT', 'unsigned' => true],
                'created_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['manpower_request_id', 'job_posting_id']);
            $forge->addKey('job_posting_id');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('manpower_request_id', 'manpower_requests', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('job_posting_id', 'job_postings', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('manpower_request_postings');
        }

        // Permissions baru — granular: view (untuk semua HR/manager),
        // submit (line manager), approve (HRD), fulfill (TA).
        $newPermissions = [
            ['slug' => 'mpp.view',    'name' => 'View Manpower Planning',     'group' => 'recruitment'],
            ['slug' => 'mpp.submit',  'name' => 'Submit MPP request',          'group' => 'recruitment'],
            ['slug' => 'mpp.approve', 'name' => 'Approve MPP (HRD/Director)',  'group' => 'recruitment'],
            ['slug' => 'mpp.fulfill', 'name' => 'Fulfill MPP via job posting (Talent Acquisition)', 'group' => 'recruitment'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }

        // Auto-grant default ke role bawaan:
        // 1 super, 2 HR → semua perm MPP (view + submit + approve + fulfill)
        // 3 manager      → view + submit (manager line bisa ajukan MPP)
        // 4 employee     → tidak ada akses
        $grantMap = [
            1 => ['mpp.view','mpp.submit','mpp.approve','mpp.fulfill'],
            2 => ['mpp.view','mpp.submit','mpp.approve','mpp.fulfill'],
            3 => ['mpp.view','mpp.submit'],
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
        if ($this->db->tableExists('manpower_request_postings'))  $this->forge->dropTable('manpower_request_postings', true);
        if ($this->db->tableExists('manpower_request_approvals')) $this->forge->dropTable('manpower_request_approvals', true);
        if ($this->db->tableExists('manpower_requests'))          $this->forge->dropTable('manpower_requests', true);
    }
}