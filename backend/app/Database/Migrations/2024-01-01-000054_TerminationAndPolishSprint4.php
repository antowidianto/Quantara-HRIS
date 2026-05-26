<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 4 — Termination & Polish.
 *
 * Mengimplementasikan 4 item dari `docs/LIFECYCLE_AUDIT_2026.md`:
 *
 * 1. **OFF-5 PHK Formal Workflow** — tabel baru `termination_requests`:
 *    Beda dari resignation (voluntary) — di sini pemutusan oleh perusahaan
 *    dengan alasan sah (efisiensi, force majeure, pelanggaran berat, dst).
 *    Wajib bipartite/tripartite, kalkulasi pesangon dengan multiplier sesuai
 *    PP 35/2021 Pasal 41-58, link ke disciplinary_records (kalau ada SP1-3),
 *    PHK letter generation, dan severance auto-create.
 *
 *    Workflow status:
 *      DRAFT → PENDING_BIPARTITE → PENDING_HR_DIRECTOR
 *           → PENDING_BOD → APPROVED → EXECUTED / CANCELLED / REJECTED
 *
 * 2. **OFF-1 Exit Interview Digital** — tabel baru `exit_interviews`:
 *    Wawancara terstruktur saat karyawan resign / PHK dengan question matrix
 *    + analytics flag (would_rehire, primary_reason, satisfaction). Link ke
 *    resignation_requests ATAU termination_requests.
 *
 * 3. **CC-4 Employee 360** — tidak butuh tabel baru, hanya aggregator
 *    endpoint `GET /employees/{id}/360`. Migration ini hanya tambah
 *    permission `employee.view_360`.
 *
 * 4. **CC-5 Compliance Reports** — tabel baru `compliance_report_runs`:
 *    Cache hasil generate report (WLKP, BPJS bulanan, PKWT akan habis,
 *    PHK list, audit summary) untuk reproducibility & history. Permission
 *    `compliance.report.view` + `compliance.report.export`.
 *
 * Idempotent: cek tableExists & getFieldNames sebelum action.
 */
class TerminationAndPolishSprint4 extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;
        $now   = date('Y-m-d H:i:s');

        // ─── 1. termination_requests (OFF-5) ─────────────────────────
        if (! $db->tableExists('termination_requests')) {
            $forge->addField([
                'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'        => ['type' => 'INT', 'unsigned' => true],
                'employee_id'       => ['type' => 'INT', 'unsigned' => true],
                'reference_number'  => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true,
                    'comment' => 'PHK-{YEAR}-{5digit}'],

                // Klasifikasi PHK
                'termination_type'  => [
                    'type' => 'ENUM',
                    'constraint' => ['UNILATERAL', 'MUTUAL', 'CONTRACT_END', 'RETIREMENT', 'DEATH', 'DISABILITY'],
                    'comment' => 'UNILATERAL=PHK sepihak, MUTUAL=kesepakatan, CONTRACT_END=PKWT habis, dll',
                ],
                'reason_code'       => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Slug dari SeveranceCalculator::REASONS (EFFICIENCY_LOSS, VIOLATION_GROSS, dst)'],
                'reason_detail'     => ['type' => 'TEXT', 'null' => true],

                // Tanggal-tanggal kunci
                'planned_termination_date' => ['type' => 'DATE',
                    'comment' => 'Tanggal target efektif PHK'],
                'effective_date'    => ['type' => 'DATE', 'null' => true,
                    'comment' => 'Tanggal aktual PHK setelah eksekusi'],

                // Disciplinary chain (untuk PHK karena pelanggaran SP3)
                'last_disciplinary_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'FK ke disciplinary_records — SP3 / TERMINATION level'],

                // Bipartite / tripartite negotiation tracking
                'bipartite_held_at' => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Timestamp pertemuan bipartite sudah dilakukan'],
                'bipartite_outcome' => [
                    'type' => 'ENUM',
                    'constraint' => ['AGREED', 'DISAGREED', 'PENDING_TRIPARTITE'],
                    'null' => true,
                ],
                'bipartite_minutes'  => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Notulen pertemuan bipartite'],
                'tripartite_required' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 kalau bipartite gagal & perlu mediasi disnaker'],

                // Severance link (auto-created saat APPROVED)
                'severance_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'FK ke severance_calculations — auto-create saat APPROVED'],

                // PHK letter (generated di stage APPROVED)
                'phk_letter_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'FK ke generated_contracts — surat PHK formal'],
                'phk_letter_generated_at' => ['type' => 'DATETIME', 'null' => true],

                // Status workflow
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'DRAFT', 'PENDING_BIPARTITE',
                        'PENDING_HR_DIRECTOR', 'PENDING_BOD',
                        'APPROVED', 'EXECUTED',
                        'REJECTED', 'CANCELLED',
                    ],
                    'default' => 'DRAFT',
                ],

                // Initiator + approvers tracking
                'initiated_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'user_id HR yang inisiasi PHK'],
                'submitted_at'      => ['type' => 'DATETIME', 'null' => true],

                'hr_director_user_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'hr_director_action_at' => ['type' => 'DATETIME', 'null' => true],
                'hr_director_decision'  => [
                    'type' => 'ENUM', 'constraint' => ['APPROVED', 'REJECTED'], 'null' => true,
                ],
                'hr_director_note'   => ['type' => 'TEXT', 'null' => true],

                'bod_user_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'bod_action_at'      => ['type' => 'DATETIME', 'null' => true],
                'bod_decision'       => [
                    'type' => 'ENUM', 'constraint' => ['APPROVED', 'REJECTED'], 'null' => true,
                ],
                'bod_note'           => ['type' => 'TEXT', 'null' => true],

                'executed_by'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'executed_at'        => ['type' => 'DATETIME', 'null' => true],

                'rejected_at'        => ['type' => 'DATETIME', 'null' => true],
                'rejection_reason'   => ['type' => 'TEXT', 'null' => true],
                'cancelled_at'       => ['type' => 'DATETIME', 'null' => true],
                'cancelled_by'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'cancellation_reason'=> ['type' => 'TEXT', 'null' => true],

                'attachment_path'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true,
                    'comment' => 'Bukti pendukung (contoh: dokumen finansial untuk efisiensi)'],

                'created_at'         => ['type' => 'DATETIME', 'null' => true],
                'updated_at'         => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'         => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['company_id', 'status']);
            $forge->addKey(['employee_id', 'status']);
            $forge->addKey('reference_number');
            $forge->addForeignKey('company_id',  'companies',  'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees',  'id', 'CASCADE', 'CASCADE');
            $forge->createTable('termination_requests');
        }

        // ─── 2. exit_interviews (OFF-1) ──────────────────────────────
        if (! $db->tableExists('exit_interviews')) {
            $forge->addField([
                'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'        => ['type' => 'INT', 'unsigned' => true],
                'employee_id'       => ['type' => 'INT', 'unsigned' => true],

                // Source: bisa dari resignation ATAU termination
                'resignation_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'termination_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                // Conduct metadata
                'conducted_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'user_id HR yang wawancara'],
                'conducted_at'      => ['type' => 'DATETIME', 'null' => true],
                'mode' => [
                    'type' => 'ENUM',
                    'constraint' => ['IN_PERSON', 'VIDEO', 'PHONE', 'WRITTEN', 'SELF_FILL'],
                    'default' => 'IN_PERSON',
                ],

                // Analytics flags
                'would_rehire' => [
                    'type' => 'ENUM',
                    'constraint' => ['YES', 'NO', 'CONDITIONAL', 'UNKNOWN'],
                    'default' => 'UNKNOWN',
                    'comment' => 'Apakah perusahaan mau rehire orang ini di masa depan',
                ],
                'primary_reason' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'Alasan utama keluar (better_offer, mgmt_issue, work_life_balance, dst)'],
                'secondary_reasons_json' => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Array alasan sekunder'],
                'satisfaction_score' => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true,
                    'comment' => '1-10 skala overall satisfaction'],
                'recommend_score' => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true,
                    'comment' => '1-10 NPS — apakah merekomendasikan perusahaan ke kerabat'],

                // Detailed scores (1-5 Likert)
                'score_compensation'    => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
                'score_management'      => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
                'score_work_environment'=> ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
                'score_career_growth'   => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
                'score_work_life_balance'=> ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
                'score_team_culture'    => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],

                // Open-ended responses
                'most_liked'        => ['type' => 'TEXT', 'null' => true],
                'most_disliked'     => ['type' => 'TEXT', 'null' => true],
                'suggestions'       => ['type' => 'TEXT', 'null' => true],
                'additional_notes'  => ['type' => 'TEXT', 'null' => true],

                // Status: kalau karyawan diminta isi sendiri (SELF_FILL),
                // bisa DRAFT dulu sebelum dikunci.
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT', 'COMPLETED', 'SKIPPED'],
                    'default' => 'DRAFT',
                ],
                'completed_at'      => ['type' => 'DATETIME', 'null' => true],

                'created_by'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'        => ['type' => 'DATETIME', 'null' => true],
                'updated_at'        => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'        => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['resignation_id']);
            $forge->addKey(['termination_id']);
            $forge->addKey(['status']);
            $forge->addForeignKey('company_id',  'companies',  'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees',  'id', 'CASCADE', 'CASCADE');
            $forge->createTable('exit_interviews');
        }

        // ─── 3. compliance_report_runs (CC-5) ───────────────────────
        if (! $db->tableExists('compliance_report_runs')) {
            $forge->addField([
                'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'        => ['type' => 'INT', 'unsigned' => true],

                'report_type' => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'WLKP_ANNUAL', 'BPJS_MONTHLY', 'PKWT_EXPIRING',
                        'PHK_LIST', 'AUDIT_SUMMARY', 'PROBATION_OVERDUE',
                        'BPJS_OVERDUE', 'TURNOVER_SUMMARY',
                    ],
                ],
                'period_year'       => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
                'period_month'      => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true,
                    'comment' => '1-12 untuk monthly report; null untuk yearly'],

                'parameters_json'   => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Filter run (mis. {"include_inactive": false})'],
                'result_json'       => ['type' => 'LONGTEXT', 'null' => true,
                    'comment' => 'Cached result data'],
                'row_count'         => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'export_format'     => [
                    'type' => 'ENUM',
                    'constraint' => ['JSON', 'CSV', 'XLSX', 'PDF'],
                    'default' => 'JSON',
                ],
                'export_path'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],

                'generated_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'generated_at'      => ['type' => 'DATETIME', 'null' => true],
                'expires_at'        => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Cache TTL — biasanya 24 jam dari generated_at'],

                'created_at'        => ['type' => 'DATETIME', 'null' => true],
                'updated_at'        => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'        => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'report_type']);
            $forge->addKey(['company_id', 'period_year', 'period_month']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('compliance_report_runs');
        }

        // ─── 4. Permissions baru ─────────────────────────────────────
        $newPermissions = [
            ['slug' => 'termination.view',    'name' => 'View Termination (PHK) Requests',          'group' => 'hr'],
            ['slug' => 'termination.manage',  'name' => 'Manage Termination (initiate, edit)',      'group' => 'hr'],
            ['slug' => 'termination.approve', 'name' => 'Approve Termination (HR Director / BOD)',  'group' => 'hr'],
            ['slug' => 'termination.execute', 'name' => 'Execute Termination (final step)',         'group' => 'hr'],

            ['slug' => 'exit_interview.view',   'name' => 'View Exit Interviews',          'group' => 'hr'],
            ['slug' => 'exit_interview.manage', 'name' => 'Conduct & Edit Exit Interviews','group' => 'hr'],

            ['slug' => 'employee.view_360',  'name' => 'View Employee 360 Profile (HR/manager)', 'group' => 'hr'],

            ['slug' => 'compliance.report.view',   'name' => 'View Compliance Reports',   'group' => 'hr'],
            ['slug' => 'compliance.report.export', 'name' => 'Export Compliance Reports', 'group' => 'hr'],
        ];
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        $grantMap = [
            // Super admin & HR admin: full access
            1 => [
                'termination.view', 'termination.manage', 'termination.approve', 'termination.execute',
                'exit_interview.view', 'exit_interview.manage',
                'employee.view_360',
                'compliance.report.view', 'compliance.report.export',
            ],
            2 => [
                'termination.view', 'termination.manage', 'termination.execute',
                'exit_interview.view', 'exit_interview.manage',
                'employee.view_360',
                'compliance.report.view', 'compliance.report.export',
            ],
            // Manager: bisa lihat 360 untuk team-nya
            3 => ['employee.view_360', 'termination.view', 'exit_interview.view'],
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

        // ─── 5. Extend contract_templates.contract_type ENUM dengan PHK_LETTER ───
        if ($db->tableExists('contract_templates') && $db->DBDriver === 'MySQLi') {
            try {
                $row = $db->query(
                    "SHOW COLUMNS FROM contract_templates WHERE Field = 'contract_type'"
                )->getRowArray();
                $current = $row['Type'] ?? '';
                if (stripos($current, "'PHK_LETTER'") === false) {
                    $db->query(
                        "ALTER TABLE contract_templates MODIFY contract_type "
                        . "ENUM('PKWT','PKWTT','PROBATION','PKHL','MAGANG','OUTSOURCING',"
                        . "'PAKLARING','OFFER_LETTER','PHK_LETTER','GENERIC') NOT NULL"
                    );
                }
            } catch (\Throwable $e) {
                log_message('warning', 'Could not extend contract_templates ENUM with PHK_LETTER: ' . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        $db = $this->db;
        foreach (['compliance_report_runs', 'exit_interviews', 'termination_requests'] as $t) {
            if ($db->tableExists($t)) {
                $this->forge->dropTable($t, true);
            }
        }
        // Permissions tidak di-drop di down — biarkan, tidak harm.
    }
}