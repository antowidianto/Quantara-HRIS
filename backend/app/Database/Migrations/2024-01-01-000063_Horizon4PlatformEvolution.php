<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Horizon4PlatformEvolution (Sprint 19+).
 *
 * Migration kombinasi untuk Platform Evolution items yang bisa di-deliver
 * sebagai backend foundation. Items ini mostly net-new modules dengan
 * minimal coupling ke schema existing supaya bisa dilepas bertahap.
 *
 * Yang dimasukkan:
 *   H4.1 AI-Powered HR Copilot
 *     - tabel `ai_chat_sessions` (per-user session dengan audit trail)
 *     - tabel `ai_chat_messages` (turn-by-turn log untuk privacy review)
 *
 *   H4.3 Workforce Planning & Scenario Modeling
 *     - tabel `workforce_scenarios` (saved scenarios per company)
 *     - tabel `workforce_scenario_runs` (Monte Carlo run results dengan
 *       p10/p50/p90 distribution)
 *
 *   H4.4 Employee Wellbeing & Mental Health
 *     - tabel `pulse_surveys` (master survey dengan question matrix)
 *     - tabel `pulse_survey_questions` (per question)
 *     - tabel `pulse_survey_responses` (ANONYMOUS — tidak ada employee_id)
 *     - tabel `pulse_survey_aggregates` (cache eNPS/burnout per dept)
 *
 *   H4.5 Compensation Intelligence
 *     - tabel `salary_benchmarks` (manual import / future API)
 *     - tabel `compensation_analyses` (cache compa-ratio + pay equity)
 *
 * H4.2 Multi-Country Payroll di-defer karena butuh refactor PayrollService
 * yang substantial (jurisdiction-aware tax engine, country adapter).
 *
 * Permission baru di-seed:
 *   ai_assistant.use, ai_assistant.audit
 *   workforce_planning.view, workforce_planning.manage
 *   wellbeing.respond (semua karyawan), wellbeing.view (HR)
 *   compensation_intelligence.view, compensation_intelligence.manage
 *
 * Idempotent: cek tableExists sebelum create.
 */
class Horizon4PlatformEvolution extends Migration
{
    public function up(): void
    {
        $db    = $this->db;
        $forge = $this->forge;

        // ─── H4.1 AI Copilot ────────────────────────────────────────
        if (! $db->tableExists('ai_chat_sessions')) {
            $forge->addField([
                'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'    => ['type' => 'INT', 'unsigned' => true],
                'user_id'       => ['type' => 'INT', 'unsigned' => true],
                'title'         => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
                    'comment' => 'First user message — auto-summary untuk list view'],
                'driver'        => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'mock',
                    'comment' => 'mock | openai | anthropic | local — ambil dari env saat session dibuat'],
                'model'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'message_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'token_count'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'is_archived'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
                'created_at'    => ['type' => 'DATETIME', 'null' => true],
                'updated_at'    => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'user_id', 'is_archived']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('ai_chat_sessions');
        }

        if (! $db->tableExists('ai_chat_messages')) {
            $forge->addField([
                'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'session_id' => ['type' => 'INT', 'unsigned' => true],
                'role'       => [
                    'type' => 'ENUM',
                    'constraint' => ['system', 'user', 'assistant', 'function'],
                    'comment' => 'Role per OpenAI / Anthropic message format',
                ],
                'content'    => ['type' => 'TEXT'],
                'function_name' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'Kalau role=function, nama tool yang dipanggil'],
                'function_args_json' => ['type' => 'TEXT', 'null' => true],
                'tokens'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'redacted'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 kalau auto-redactor menemukan PII (NIK/NPWP/dll) dan menggantinya dengan placeholder'],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['session_id', 'created_at']);
            $forge->addForeignKey('session_id', 'ai_chat_sessions', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('ai_chat_messages');
        }

        // ─── H4.3 Workforce Planning ────────────────────────────────
        if (! $db->tableExists('workforce_scenarios')) {
            $forge->addField([
                'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'    => ['type' => 'INT', 'unsigned' => true],
                'name'          => ['type' => 'VARCHAR', 'constraint' => 200],
                'description'   => ['type' => 'TEXT', 'null' => true],
                'horizon_months' => ['type' => 'INT', 'unsigned' => true, 'default' => 12,
                    'comment' => 'Forecast horizon dalam bulan (12, 24, 36)'],
                'assumptions_json' => ['type' => 'TEXT',
                    'comment' => 'JSON: {monthly_attrition_rate, hiring_plan_per_month[], salary_inflation_pct, dst}'],
                'created_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'is_baseline'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 = scenario yang dipilih sebagai baseline / default'],
                'created_at'    => ['type' => 'DATETIME', 'null' => true],
                'updated_at'    => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'is_baseline']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('workforce_scenarios');
        }

        if (! $db->tableExists('workforce_scenario_runs')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'scenario_id'    => ['type' => 'INT', 'unsigned' => true],
                'iterations'     => ['type' => 'INT', 'unsigned' => true, 'default' => 1000,
                    'comment' => 'Berapa Monte Carlo trial yang di-run'],
                'p10_headcount_end' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
                'p50_headcount_end' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
                'p90_headcount_end' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
                'p10_total_cost'    => ['type' => 'DECIMAL', 'constraint' => '18,2', 'null' => true],
                'p50_total_cost'    => ['type' => 'DECIMAL', 'constraint' => '18,2', 'null' => true],
                'p90_total_cost'    => ['type' => 'DECIMAL', 'constraint' => '18,2', 'null' => true],
                'distribution_json' => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Per-month projection: {month: [p10, p50, p90]}'],
                'run_at'         => ['type' => 'DATETIME', 'null' => true],
                'run_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['scenario_id', 'run_at']);
            $forge->addForeignKey('scenario_id', 'workforce_scenarios', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('workforce_scenario_runs');
        }

        // ─── H4.4 Employee Wellbeing ────────────────────────────────
        if (! $db->tableExists('pulse_surveys')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'title'           => ['type' => 'VARCHAR', 'constraint' => 200],
                'description'     => ['type' => 'TEXT', 'null' => true],
                'frequency'       => [
                    'type' => 'ENUM',
                    'constraint' => ['ONE_TIME', 'WEEKLY', 'BIWEEKLY', 'MONTHLY', 'QUARTERLY'],
                    'default' => 'QUARTERLY',
                ],
                'starts_at'       => ['type' => 'DATETIME'],
                'ends_at'         => ['type' => 'DATETIME', 'null' => true],
                'min_participation_for_dept_view' => ['type' => 'INT', 'unsigned' => true, 'default' => 5,
                    'comment' => 'Min responden per dept supaya hasil bisa di-view tanpa risiko de-anonymisasi'],
                'status'          => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT', 'OPEN', 'CLOSED', 'ARCHIVED'],
                    'default' => 'DRAFT',
                ],
                'created_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'status']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('pulse_surveys');
        }

        if (! $db->tableExists('pulse_survey_questions')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'survey_id'    => ['type' => 'INT', 'unsigned' => true],
                'sort_order'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'question_text'=> ['type' => 'TEXT'],
                'question_type'=> [
                    'type' => 'ENUM',
                    'constraint' => ['ENPS', 'LIKERT_5', 'LIKERT_7', 'BURNOUT', 'YES_NO', 'OPEN_TEXT'],
                    'default' => 'LIKERT_5',
                    'comment' => 'ENPS = 0-10 (eNPS classic), LIKERT 1-5/1-7, BURNOUT = 5 question MBI shortcut',
                ],
                'is_required'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'reverse_score'=> ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => 'Untuk burnout: 1 = answer tinggi berarti burnout tinggi (skor di-reverse di compute)'],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['survey_id', 'sort_order']);
            $forge->addForeignKey('survey_id', 'pulse_surveys', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('pulse_survey_questions');
        }

        // CRITICAL: tabel response TANPA `employee_id`. Anonimitas absolut.
        // Yang disimpan hanya hash dari (employee_id + survey_id + salt) untuk
        // anti-double-submit, dan demographic bucket (department, branch, level)
        // untuk drilldown — tapi minimal ukuran bucket di-enforce di query.
        if (! $db->tableExists('pulse_survey_responses')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'survey_id'       => ['type' => 'INT', 'unsigned' => true],
                'submission_hash' => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'SHA256(employee_id . survey_id . salt) — anti-double-submit, tidak reversible'],
                'department_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'tenure_bucket'   => [
                    'type' => 'ENUM',
                    'constraint' => ['LESS_1Y', '1_3Y', '3_5Y', '5_10Y', 'OVER_10Y'],
                    'null' => true,
                ],
                'gender'          => ['type' => 'ENUM', 'constraint' => ['M', 'F'], 'null' => true],
                'answers_json'    => ['type' => 'TEXT',
                    'comment' => 'JSON map: {question_id: answer_value}'],
                'submitted_at'    => ['type' => 'DATETIME', 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['survey_id', 'submission_hash'], 'uniq_pulse_response');
            $forge->addKey(['survey_id', 'department_id']);
            $forge->addForeignKey('survey_id', 'pulse_surveys', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('pulse_survey_responses');
        }

        if (! $db->tableExists('pulse_survey_aggregates')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'survey_id'    => ['type' => 'INT', 'unsigned' => true],
                'scope_type'   => [
                    'type' => 'ENUM',
                    'constraint' => ['COMPANY', 'DEPARTMENT', 'BRANCH', 'TENURE', 'GENDER'],
                ],
                'scope_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'department_id / branch_id; null untuk COMPANY'],
                'scope_label'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'response_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'enps_score'   => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true,
                    'comment' => '-100..100, classic NPS formula'],
                'burnout_score'=> ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true,
                    'comment' => '0-100, semakin tinggi semakin burnout'],
                'satisfaction_avg' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
                'computed_at'  => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['survey_id', 'scope_type', 'scope_id'], 'uniq_pulse_agg');
            $forge->addForeignKey('survey_id', 'pulse_surveys', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('pulse_survey_aggregates');
        }

        // ─── H4.5 Compensation Intelligence ─────────────────────────
        if (! $db->tableExists('salary_benchmarks')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'job_grade_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'position_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'role_title'     => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
                    'comment' => 'Free-form title kalau tidak match ke FK (mis. "Senior Backend Dev")'],
                'source'         => [
                    'type' => 'ENUM',
                    'constraint' => ['MANUAL', 'GLINTS', 'JOBSTREET', 'MERCER', 'KORN_FERRY', 'INTERNAL'],
                    'default' => 'MANUAL',
                ],
                'p10_salary'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'p25_salary'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'p50_salary'     => ['type' => 'DECIMAL', 'constraint' => '15,2',
                    'comment' => 'Median market — referensi compa-ratio'],
                'p75_salary'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'p90_salary'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'sample_size'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'effective_date' => ['type' => 'DATE'],
                'expires_at'     => ['type' => 'DATE', 'null' => true],
                'notes'          => ['type' => 'TEXT', 'null' => true],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'job_grade_id', 'effective_date']);
            $forge->addKey(['company_id', 'position_id', 'effective_date']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('job_grade_id', 'job_grades', 'id', 'CASCADE', 'SET NULL');
            $forge->addForeignKey('position_id', 'positions', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('salary_benchmarks');
        }

        if (! $db->tableExists('compensation_analyses')) {
            $forge->addField([
                'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'        => ['type' => 'INT', 'unsigned' => true],
                'analysis_type'     => [
                    'type' => 'ENUM',
                    'constraint' => ['COMPA_RATIO', 'PAY_EQUITY_GENDER', 'PAY_EQUITY_GRADE', 'BAND_RECOMMENDATION'],
                ],
                'period_label'      => ['type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'YYYY-MM atau YYYY untuk versioning hasil'],
                'result_json'       => ['type' => 'TEXT',
                    'comment' => 'Hasil compute lengkap untuk reproducibility'],
                'summary_metric'    => ['type' => 'DECIMAL', 'constraint' => '8,4', 'null' => true,
                    'comment' => 'Single number summary (compa avg, gini, etc)'],
                'computed_at'       => ['type' => 'DATETIME', 'null' => true],
                'computed_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'        => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'analysis_type', 'period_label']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('compensation_analyses');
        }

        // ─── Permissions baru ───────────────────────────────────────
        $this->seedHorizon4Permissions();
    }

    /**
     * Seed permission Horizon 4 + auto-grant ke role default.
     */
    private function seedHorizon4Permissions(): void
    {
        $db = $this->db;
        if (! $db->tableExists('permissions') || ! $db->tableExists('role_permissions')) return;

        $now = date('Y-m-d H:i:s');
        $permissions = [
            // AI Copilot (H4.1)
            ['slug' => 'ai_assistant.use', 'name' => 'Use AI HR Assistant',
                'group' => 'platform', 'description' => 'Akses chat copilot HR untuk query data'],
            ['slug' => 'ai_assistant.audit', 'name' => 'Audit AI Sessions',
                'group' => 'platform', 'description' => 'Lihat session/log seluruh user (privacy review)'],

            // Workforce Planning (H4.3)
            ['slug' => 'workforce_planning.view', 'name' => 'View Workforce Scenarios',
                'group' => 'hr', 'description' => 'Lihat scenario simulasi tenaga kerja'],
            ['slug' => 'workforce_planning.manage', 'name' => 'Manage Workforce Scenarios',
                'group' => 'hr', 'description' => 'CRUD scenario + run Monte Carlo simulation'],

            // Wellbeing (H4.4)
            ['slug' => 'wellbeing.respond', 'name' => 'Respond to Pulse Survey',
                'group' => 'self_service', 'description' => 'Karyawan submit jawaban pulse survey'],
            ['slug' => 'wellbeing.manage', 'name' => 'Manage Pulse Surveys',
                'group' => 'hr', 'description' => 'CRUD survey + view aggregate (anonim)'],
            ['slug' => 'wellbeing.view_aggregate', 'name' => 'View Wellbeing Aggregates',
                'group' => 'hr', 'description' => 'Lihat eNPS / burnout aggregate (min participation enforced)'],

            // Compensation Intelligence (H4.5)
            ['slug' => 'compensation_intelligence.view', 'name' => 'View Comp Intelligence',
                'group' => 'payroll', 'description' => 'Lihat compa-ratio + pay equity dashboard'],
            ['slug' => 'compensation_intelligence.manage', 'name' => 'Manage Salary Benchmarks',
                'group' => 'payroll', 'description' => 'Import benchmark + run analysis'],
        ];

        // Default grants:
        //   1 super_admin → semua
        //   2 hr_admin    → semua kecuali ai_assistant.audit (compliance role separation)
        //   3 manager     → ai_assistant.use, workforce_planning.view,
        //                   wellbeing.view_aggregate, compensation_intelligence.view
        //   default karyawan → ai_assistant.use + wellbeing.respond
        $grantMap = [
            1 => ['ai_assistant.use', 'ai_assistant.audit',
                  'workforce_planning.view', 'workforce_planning.manage',
                  'wellbeing.respond', 'wellbeing.manage', 'wellbeing.view_aggregate',
                  'compensation_intelligence.view', 'compensation_intelligence.manage'],
            2 => ['ai_assistant.use',
                  'workforce_planning.view', 'workforce_planning.manage',
                  'wellbeing.respond', 'wellbeing.manage', 'wellbeing.view_aggregate',
                  'compensation_intelligence.view', 'compensation_intelligence.manage'],
            3 => ['ai_assistant.use', 'workforce_planning.view',
                  'wellbeing.respond', 'wellbeing.view_aggregate',
                  'compensation_intelligence.view'],
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
        $forge = $this->forge;

        foreach (['compensation_analyses', 'salary_benchmarks',
            'pulse_survey_aggregates', 'pulse_survey_responses',
            'pulse_survey_questions', 'pulse_surveys',
            'workforce_scenario_runs', 'workforce_scenarios',
            'ai_chat_messages', 'ai_chat_sessions'] as $tbl) {
            if ($db->tableExists($tbl)) $forge->dropTable($tbl, true);
        }
    }
}