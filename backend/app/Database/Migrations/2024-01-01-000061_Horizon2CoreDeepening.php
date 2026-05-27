<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Horizon2CoreDeepening (Sprint 10–13).
 *
 * Migration kombinasi untuk semua schema change Horizon 2:
 *
 *   H2.1 PWA Enhancement       — frontend only, tidak butuh schema.
 *   H2.4 Candidate Scoring     — candidates.composite_score, manual_score_override
 *                                + tabel job_posting_scoring_criteria
 *   H2.2 Goals Cascading       — goals.parent_id sudah ada di 000024 (re-purpose)
 *                                + view tree via service.
 *   H2.3 360° Peer Feedback    — performance_reviews.review_type extension
 *                                + tabel peer_review_nominations
 *                                + performance_cycles weight config
 *   H2.5 Employee Referral     — tabel referral_links + referral_bonus_rules
 *                                + candidates.referral_bonus_paid_at
 *   H2.6 Turnover Analytics    — tabel turnover_metrics_snapshot untuk
 *                                cache hasil compute (trend dashboard).
 *   H2.7 Payroll Simulasi      — tidak butuh schema (in-memory)
 *   H2.8 Reimbursement OCR     — reimbursements.ocr_extracted_at, ocr_confidence
 *                                + tabel ocr_extraction_log untuk debugging
 *   H2.9 Cost Center Drill-Down— tidak butuh schema (re-use payroll/reimbursement
 *                                + cost_center_id existing)
 *
 * Idempotent: cek tabel/kolom sebelum tambah.
 */
class Horizon2CoreDeepening extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        $forge = $this->forge;

        // ─── H2.4: Candidate Scoring ─────────────────────────────────
        if ($db->tableExists('candidates')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('candidates'));
            $newCols = [];
            if (! in_array('composite_score', $cols, true)) {
                $newCols['composite_score'] = [
                    'type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true,
                    'comment' => 'H2.4: skor weighted hasil scoring engine (0-100)',
                ];
            }
            if (! in_array('manual_score_override', $cols, true)) {
                $newCols['manual_score_override'] = [
                    'type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true,
                    'comment' => 'H2.4: HR override skor manual (jika berbeda dari composite)',
                ];
            }
            if (! in_array('score_breakdown', $cols, true)) {
                $newCols['score_breakdown'] = [
                    'type' => 'JSON', 'null' => true,
                    'comment' => 'H2.4: detail per kriteria untuk explain di UI',
                ];
            }
            if (! empty($newCols)) {
                $forge->addColumn('candidates', $newCols);
            }
        }

        if (! $db->tableExists('job_posting_scoring_criteria')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'job_posting_id'  => ['type' => 'INT', 'unsigned' => true],
                'criterion_type' => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'ONLINE_TEST_SCORE',     // hasil online test
                        'SALARY_FIT',             // expected vs job range
                        'EXPERIENCE_YEARS',       // years_experience
                        'AVAILABILITY_DAYS',      // available_from vs target
                        'EDUCATION_LEVEL',        // last_education_level enum
                        'INTERVIEW_RATING',       // rating dari candidate_stages
                    ],
                ],
                'weight'         => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 1,
                    'comment' => 'Bobot relative; engine normalize ke total = 100'],
                'target_value'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true,
                    'comment' => 'Target ideal (mis. 80 untuk online test, 5 tahun untuk experience)'],
                'config_json'    => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Konfigurasi tambahan (mis. mapping education S1→4, S2→5)'],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['job_posting_id', 'criterion_type'], 'uniq_jp_crit');
            $forge->addForeignKey('job_posting_id', 'job_postings', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('job_posting_scoring_criteria');
        }

        // ─── H2.3: 360° Peer Feedback ───────────────────────────────
        if (! $db->tableExists('peer_review_nominations')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'cycle_id'     => ['type' => 'INT', 'unsigned' => true],
                'reviewee_id'  => ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'Karyawan yang akan di-review'],
                'reviewer_id'  => ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'Karyawan yang akan memberi review'],
                'nomination_source' => [
                    'type' => 'ENUM',
                    'constraint' => ['SELF', 'SUPERVISOR', 'HR'],
                    'default' => 'SUPERVISOR',
                    'comment' => 'Siapa yang menominasi reviewer ini',
                ],
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['PENDING', 'APPROVED', 'REJECTED', 'COMPLETED', 'DECLINED'],
                    'default' => 'PENDING',
                ],
                'approved_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'approved_at'  => ['type' => 'DATETIME', 'null' => true],
                'review_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'FK ke performance_reviews saat reviewer submit'],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['cycle_id', 'reviewee_id', 'reviewer_id'], 'uniq_peer_nom');
            $forge->addKey(['cycle_id', 'status']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('cycle_id', 'performance_cycles', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('reviewee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('reviewer_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('peer_review_nominations');
        }

        if ($db->tableExists('performance_cycles')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('performance_cycles'));
            $newCols = [];
            if (! in_array('peer_review_enabled', $cols, true)) {
                $newCols['peer_review_enabled'] = [
                    'type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => 'H2.3: aktifkan 360 peer review untuk cycle ini',
                ];
            }
            if (! in_array('self_weight', $cols, true)) {
                $newCols['self_weight'] = [
                    'type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 20,
                ];
            }
            if (! in_array('supervisor_weight', $cols, true)) {
                $newCols['supervisor_weight'] = [
                    'type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 60,
                ];
            }
            if (! in_array('peer_weight', $cols, true)) {
                $newCols['peer_weight'] = [
                    'type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 20,
                ];
            }
            if (! in_array('peer_min_reviewers', $cols, true)) {
                $newCols['peer_min_reviewers'] = [
                    'type' => 'INT', 'unsigned' => true, 'default' => 3,
                    'comment' => 'Min jumlah peer reviewer untuk anonimitas (default 3)',
                ];
            }
            if (! empty($newCols)) {
                $forge->addColumn('performance_cycles', $newCols);
            }
        }

        if ($db->tableExists('performance_reviews')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('performance_reviews'));
            // Tambah review_type kalau belum ada (existing schema kemungkinan
            // sudah punya). Kita defensive saja.
            if (! in_array('review_type', $cols, true)) {
                $forge->addColumn('performance_reviews', [
                    'review_type' => [
                        'type' => 'ENUM',
                        'constraint' => ['SELF', 'SUPERVISOR', 'PEER', 'SUBORDINATE'],
                        'default' => 'SUPERVISOR',
                        'comment' => 'H2.3: tipe review dalam 360-degree feedback',
                    ],
                ]);
            }
        }

        // ─── H2.5: Employee Referral Program ─────────────────────────
        if (! $db->tableExists('referral_links')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'employee_id'    => ['type' => 'INT', 'unsigned' => true],
                'job_posting_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'NULL = generic link untuk semua posting aktif'],
                'slug'           => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'expires_at'     => ['type' => 'DATETIME', 'null' => true],
                'click_count'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'apply_count'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'is_active'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey('slug');
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('job_posting_id', 'job_postings', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('referral_links');
        }

        if (! $db->tableExists('referral_bonus_rules')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'position_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'NULL = berlaku untuk semua posisi'],
                'job_grade_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'NULL = berlaku untuk semua grade'],
                'bonus_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
                'note'         => ['type' => 'TEXT', 'null' => true],
                'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'is_active']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('referral_bonus_rules');
        }

        if ($db->tableExists('candidates')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('candidates'));
            $newCols = [];
            if (! in_array('referral_bonus_paid_at', $cols, true)) {
                $newCols['referral_bonus_paid_at'] = [
                    'type' => 'DATETIME', 'null' => true,
                    'comment' => 'H2.5: kapan referral bonus untuk referrer di-trigger',
                ];
            }
            if (! in_array('referral_link_id', $cols, true)) {
                $newCols['referral_link_id'] = [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'H2.5: track lewat link mana kandidat ini apply',
                ];
            }
            if (! empty($newCols)) {
                $forge->addColumn('candidates', $newCols);
            }
        }

        // ─── H2.6: Turnover Analytics Snapshot ──────────────────────
        if (! $db->tableExists('turnover_metrics_snapshot')) {
            $forge->addField([
                'id'                   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'           => ['type' => 'INT', 'unsigned' => true],
                'period_type'          => [
                    'type' => 'ENUM', 'constraint' => ['MONTH', 'YEAR'], 'default' => 'MONTH',
                ],
                'period_key'           => ['type' => 'VARCHAR', 'constraint' => 16,
                    'comment' => 'YYYY-MM untuk MONTH, YYYY untuk YEAR'],
                'period_start'         => ['type' => 'DATE'],
                'period_end'           => ['type' => 'DATE'],
                'headcount_start'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'headcount_end'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'avg_headcount'        => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
                'hires'                => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'separations_total'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'voluntary'            => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'involuntary'          => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'turnover_rate'        => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0,
                    'comment' => '% (separations / avg headcount)'],
                'voluntary_rate'       => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],
                'involuntary_rate'     => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],
                'retention_rate'       => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
                'early_attrition_rate' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0,
                    'comment' => '% separations dengan tenure < 12 bulan'],
                'regrettable_rate'     => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0,
                    'comment' => '% separations dengan last rating ≥ 4'],
                'created_at'           => ['type' => 'DATETIME', 'null' => true],
                'updated_at'           => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'period_type', 'period_key'], 'uniq_turnover_period');
            $forge->addKey(['company_id', 'period_type']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('turnover_metrics_snapshot');
        }

        // ─── H2.8: Reimbursement OCR ────────────────────────────────
        if ($db->tableExists('reimbursements')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('reimbursements'));
            $newCols = [];
            if (! in_array('ocr_extracted_at', $cols, true)) {
                $newCols['ocr_extracted_at'] = [
                    'type' => 'DATETIME', 'null' => true,
                    'comment' => 'H2.8: kapan OCR berhasil extract field',
                ];
            }
            if (! in_array('ocr_confidence', $cols, true)) {
                $newCols['ocr_confidence'] = [
                    'type' => 'DECIMAL', 'constraint' => '5,4', 'null' => true,
                    'comment' => 'H2.8: confidence score 0-1 dari OCR engine',
                ];
            }
            if (! in_array('ocr_raw_data', $cols, true)) {
                $newCols['ocr_raw_data'] = [
                    'type' => 'JSON', 'null' => true,
                    'comment' => 'H2.8: raw response dari OCR (untuk debugging + re-extract)',
                ];
            }
            if (! empty($newCols)) {
                $forge->addColumn('reimbursements', $newCols);
            }
        }

        // ─── Permissions baru untuk Horizon 2 ───────────────────────
        $this->seedHorizon2Permissions();
    }

    /**
     * Seed permission baru yang dipakai Horizon2Controller.
     * Permissions existing yang dipakai (recruitment.manage, performance.*,
     * payroll.manage, reimbursement.*) tidak perlu di-seed lagi.
     *
     * Yang baru hanya:
     *   - hr_analytics.view   → lihat turnover analytics
     *   - hr_analytics.manage → trigger snapshot persist
     *   - payroll.simulate    → simulate THP (HR + recruiter)
     *   - candidate.score.view → lihat composite score (auto granted ke
     *     recruitment.manage; ini hanya ditambah agar tampak di Edit Role).
     */
    private function seedHorizon2Permissions(): void
    {
        $db = $this->db;
        if (! $db->tableExists('permissions') || ! $db->tableExists('role_permissions')) return;

        $now = date('Y-m-d H:i:s');
        $permissions = [
            ['slug' => 'hr_analytics.view', 'name' => 'View HR Analytics',
                'group' => 'hr', 'description' => 'Lihat dashboard turnover, retention, regrettable loss'],
            ['slug' => 'hr_analytics.manage', 'name' => 'Manage HR Analytics Snapshots',
                'group' => 'hr', 'description' => 'Trigger snapshot turnover bulanan / tahunan'],
            ['slug' => 'payroll.simulate', 'name' => 'Payroll Simulation',
                'group' => 'payroll', 'description' => 'What-if simulator gaji untuk negosiasi & offer'],
            ['slug' => 'candidate.score.view', 'name' => 'View Candidate Score',
                'group' => 'recruitment', 'description' => 'Lihat composite score & breakdown kandidat'],
        ];

        // Default grants:
        //   1 super_admin → semua
        //   2 hr_admin    → semua
        //   3 manager     → hr_analytics.view, candidate.score.view
        //   5 finance     → payroll.simulate, hr_analytics.view
        $grantMap = [
            1 => ['hr_analytics.view', 'hr_analytics.manage', 'payroll.simulate', 'candidate.score.view'],
            2 => ['hr_analytics.view', 'hr_analytics.manage', 'payroll.simulate', 'candidate.score.view'],
            3 => ['hr_analytics.view', 'candidate.score.view'],
            5 => ['payroll.simulate', 'hr_analytics.view'],
        ];

        foreach ($permissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $db->table('permissions')->insert($p + ['created_at' => $now]);
        }

        foreach ($grantMap as $roleId => $slugs) {
            if ($db->table('roles')->where('id', $roleId)->countAllResults() === 0) continue;
            foreach ($slugs as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $alreadyGranted = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($alreadyGranted > 0) continue;
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
        $forge = $this->forge;

        if ($db->tableExists('reimbursements')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('reimbursements'));
            foreach (['ocr_extracted_at', 'ocr_confidence', 'ocr_raw_data'] as $c) {
                if (in_array($c, $cols, true)) $forge->dropColumn('reimbursements', $c);
            }
        }

        if ($db->tableExists('turnover_metrics_snapshot')) {
            $forge->dropTable('turnover_metrics_snapshot', true);
        }

        if ($db->tableExists('candidates')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('candidates'));
            foreach (['referral_bonus_paid_at', 'referral_link_id', 'composite_score',
                      'manual_score_override', 'score_breakdown'] as $c) {
                if (in_array($c, $cols, true)) $forge->dropColumn('candidates', $c);
            }
        }

        if ($db->tableExists('referral_bonus_rules')) $forge->dropTable('referral_bonus_rules', true);
        if ($db->tableExists('referral_links'))       $forge->dropTable('referral_links', true);
        if ($db->tableExists('peer_review_nominations')) $forge->dropTable('peer_review_nominations', true);
        if ($db->tableExists('job_posting_scoring_criteria')) $forge->dropTable('job_posting_scoring_criteria', true);

        if ($db->tableExists('performance_cycles')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('performance_cycles'));
            foreach (['peer_review_enabled', 'self_weight', 'supervisor_weight',
                      'peer_weight', 'peer_min_reviewers'] as $c) {
                if (in_array($c, $cols, true)) $forge->dropColumn('performance_cycles', $c);
            }
        }

        if ($db->tableExists('performance_reviews')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('performance_reviews'));
            if (in_array('review_type', $cols, true)) {
                $forge->dropColumn('performance_reviews', 'review_type');
            }
        }
    }
}