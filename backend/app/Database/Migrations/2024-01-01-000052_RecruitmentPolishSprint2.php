<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 2 — Recruitment Polish.
 *
 * 1. **REC-1 Offer Letter Management** — tabel `candidate_offers` untuk track
 *    pengajuan offer ke kandidat dengan status (SENT/ACCEPTED/REJECTED/...),
 *    response deadline, breakdown gaji & tunjangan, link ke generated_contracts
 *    row tipe OFFER_LETTER (kalau di-render dari template).
 *
 * 2. **HIRE-1 Probation Auto-Tracking** — tambah field di `employees`:
 *    - `probation_status` (ONGOING/PASSED/FAILED/EXTENDED)
 *    - `probation_decision_at`, `probation_decision_by`, `probation_decision_note`
 *    Auto-fill `probation_end_date = join_date + 3 bulan` di RecruitmentService
 *    saat hire dengan employment_status=PKWTT (UU 13/2003 Pasal 60: max 3 bulan).
 *
 * 3. **ONB-1 30/60/90-Day Milestones** — diimplementasi via task_templates
 *    dengan task_key MILESTONE_30D / 60D / 90D + due_offset_days positif. Tidak
 *    butuh schema baru. Seeder di-update ke ONBOARD template default.
 *
 * 4. Permissions baru:
 *    - `recruitment.offer.view`   — lihat offer
 *    - `recruitment.offer.manage` — create/sent/cancel offer (HR)
 *    - `recruitment.offer.respond`— respond offer (kandidat lewat magic link
 *      sebenarnya tidak perlu auth perm, tapi kita track dari sisi HR-as-proxy)
 *    - `probation.manage`         — keputusan PASS/FAIL probation (HR)
 *
 * Idempotent.
 */
class RecruitmentPolishSprint2 extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── 1. candidate_offers ─────────────────────────────────────
        if (! $db->tableExists('candidate_offers')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'candidate_id'        => ['type' => 'INT', 'unsigned' => true],
                'offer_number'        => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Unik per company, mis. OFR-2026-00001'],

                // Komponen offering
                'job_posting_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'position_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'department_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'branch_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'employment_status'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
                    'comment' => 'PKWTT/PKWT/PROBATION/MAGANG/OUTSOURCING'],
                'proposed_join_date'  => ['type' => 'DATE', 'null' => true],
                'probation_months'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => 'Lama probation kalau PKWTT (max 3 bulan UU 13/2003)'],

                // Compensation
                'basic_salary'        => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
                'allowances_json'     => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Array of {label, amount, type, is_taxable}'],
                'joining_bonus'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Sign-on bonus, 1x bayar bareng gaji bulan pertama'],
                'benefits_json'       => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Object: bpjs_kesehatan_class, extra_leave_days, transport_allowance, etc.'],
                'currency'            => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'IDR'],

                // Workflow
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT','SENT','ACCEPTED','REJECTED','EXPIRED','WITHDRAWN'],
                    'default' => 'DRAFT',
                ],
                'response_deadline'   => ['type' => 'DATE', 'null' => true,
                    'comment' => 'Tanggal terakhir kandidat boleh respond. Default H+7 dari sent_at'],
                'sent_at'             => ['type' => 'DATETIME', 'null' => true],
                'sent_by'             => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'responded_at'        => ['type' => 'DATETIME', 'null' => true],
                'response_note'       => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Pesan kandidat saat accept/reject (mis. negosiasi balik)'],
                'rejection_reason'    => ['type' => 'TEXT', 'null' => true],

                // Dokumen
                'generated_contract_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true,
                    'comment' => 'FK ke generated_contracts row tipe OFFER_LETTER (kalau di-render dari template)'],
                'signed_pdf_path'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true,
                    'comment' => 'Upload offer yang sudah ditandatangani kandidat'],

                // Notes
                'internal_notes'      => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Catatan HR internal — tidak diexpose ke kandidat'],

                'created_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'offer_number']);
            $forge->addKey(['company_id', 'candidate_id']);
            $forge->addKey(['candidate_id', 'status']);
            $forge->addKey('response_deadline');
            $forge->addForeignKey('company_id',   'companies',  'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('candidate_id', 'candidates', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('candidate_offers');
        }

        // ─── 2. employees: probation tracking fields ─────────────────
        if ($db->tableExists('employees')) {
            $existing = $db->getFieldNames('employees');
            $newCols = [];
            if (! in_array('probation_status', $existing, true)) {
                $newCols['probation_status'] = [
                    'type' => 'ENUM',
                    'constraint' => ['ONGOING', 'PASSED', 'FAILED', 'EXTENDED', 'NA'],
                    'default' => 'NA',
                    'comment' => 'NA = bukan PKWTT atau tidak ada probation. UU 13/2003 Pasal 60: max 3 bulan untuk PKWTT.',
                ];
            }
            if (! in_array('probation_decision_at', $existing, true)) {
                $newCols['probation_decision_at'] = ['type' => 'DATETIME', 'null' => true];
            }
            if (! in_array('probation_decision_by', $existing, true)) {
                $newCols['probation_decision_by'] = ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'User ID yang memutuskan PASS/FAIL/EXTEND'];
            }
            if (! in_array('probation_decision_note', $existing, true)) {
                $newCols['probation_decision_note'] = ['type' => 'TEXT', 'null' => true];
            }
            if (! empty($newCols)) {
                $forge->addColumn('employees', $newCols);
            }
        }

        // ─── 3. Permissions baru ─────────────────────────────────────
        $newPermissions = [
            ['slug' => 'recruitment.offer.view',    'name' => 'View Candidate Offers',                 'group' => 'recruitment'],
            ['slug' => 'recruitment.offer.manage',  'name' => 'Manage Candidate Offers (create/send)', 'group' => 'recruitment'],
            ['slug' => 'recruitment.offer.respond', 'name' => 'Record Candidate Offer Response (HR proxy)', 'group' => 'recruitment'],
            ['slug' => 'probation.manage',          'name' => 'Manage Probation Decisions (PASS/FAIL/EXTEND)', 'group' => 'hr'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        // Grant: Super admin (1) & HR admin (2) → semua. Manager (3) →
        // probation.manage (supervisor punya keputusan formal).
        $grantMap = [
            1 => ['recruitment.offer.view', 'recruitment.offer.manage', 'recruitment.offer.respond', 'probation.manage'],
            2 => ['recruitment.offer.view', 'recruitment.offer.manage', 'recruitment.offer.respond', 'probation.manage'],
            3 => ['probation.manage'],
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
    }

    public function down(): void
    {
        $db = $this->db;
        if ($db->tableExists('candidate_offers')) {
            $this->forge->dropTable('candidate_offers', true);
        }
        if ($db->tableExists('employees')) {
            $existing = $db->getFieldNames('employees');
            foreach (['probation_status', 'probation_decision_at', 'probation_decision_by', 'probation_decision_note'] as $c) {
                if (in_array($c, $existing, true)) {
                    try { $this->forge->dropColumn('employees', $c); } catch (\Throwable $e) {}
                }
            }
        }
        // Permissions tidak di-drop di down — tidak harm
    }
}