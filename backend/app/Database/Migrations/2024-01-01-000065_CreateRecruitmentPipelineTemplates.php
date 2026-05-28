<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration #065 — Customizable Recruitment Pipeline + Custom Test Categories.
 *
 * **Latar belakang.** Sebelumnya pipeline rekrutmen di-hardcode 7 stages
 * (NEW → SCREENING → ONLINE_TEST → INTERVIEW_HR → INTERVIEW_USER → OFFER → HIRED)
 * dan jenis test online dibatasi ENUM-style (TPA / ENGLISH / DISC / TECHNICAL
 * / CUSTOM). Praktik HR Indonesia jauh lebih beragam:
 *
 *   - Kadang Online Test sebelum HR Interview, kadang sesudah.
 *   - Banyak posisi pakai Pauli/Kraepelin, MBTI, Wartegg, Holland (RIASEC),
 *     Tes Koran, Big Five, IST, dst — yg tidak masuk 4 kategori built-in.
 *   - Untuk posisi sales bisa ada FGD (Focus Group Discussion) sebagai
 *     stage dedicated.
 *   - Untuk eksekutif bisa ada User Interview ganda (HR → Direktur → BOD).
 *
 * **Solusi.** Tiga tabel master baru + extend 2 tabel existing:
 *
 *   1. `online_test_categories` — master kategori test (replace ENUM).
 *      Built-in seeded otomatis (12 kategori populer ID), HR boleh tambah
 *      custom.
 *   2. `recruitment_pipeline_templates` — template urutan stages.
 *      Multiple template per company (mis. "Standard", "Eksekutif",
 *      "Pabrik", "Sales").
 *   3. `recruitment_pipeline_stages` — stages dalam template, dengan
 *      `order_index`, `stage_type`, optional `required_test_category_id`
 *      untuk gate "stage ini harus test category X selesai".
 *   4. `job_postings.pipeline_template_id` — opsional, kalau NULL pakai
 *      hardcoded default (backward compat).
 *   5. `online_test_question_bank.category_id` + `online_tests.category_id`
 *      — FK opsional, paralel ke kolom string `category` lama (tetap
 *      dipakai untuk backward compat sampai data dimigrasi).
 *
 * Idempotent. Auto-backfill kolom string → FK kalau bisa di-match.
 */
class CreateRecruitmentPipelineTemplates extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;
        $now   = date('Y-m-d H:i:s');

        // ─── 1. online_test_categories ─────────────────────────────
        if (! $db->tableExists('online_test_categories')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'NULL = built-in (system-wide), NOT NULL = custom per company'],
                'code'         => ['type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'Slug unik per scope. Mis. PAULI, KRAEPELIN, MBTI'],
                'name'         => ['type' => 'VARCHAR', 'constraint' => 200],
                'description'  => ['type' => 'TEXT', 'null' => true],
                'group_label'  => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true,
                    'comment' => 'Pengelompokan UI: KOGNITIF, KEPRIBADIAN, KEMAMPUAN, BAHASA, TEKNIS'],
                'scoring_type' => ['type' => 'ENUM',
                    'constraint' => ['SCORE', 'NO_RIGHT_ANSWER', 'PROFILE'],
                    'default'    => 'SCORE',
                    'comment'    => 'SCORE = ada benar/salah; NO_RIGHT_ANSWER = Likert (DISC/MBTI); PROFILE = output profil (Holland)'],
                'icon'         => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'is_built_in'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 = built-in tidak boleh di-delete'],
                'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            // Unique per (company_id, code). NULL company_id (built-in)
            // diperlakukan sebagai grup global — dijamin code unik via
            // seed control, bukan unique key (MySQL treat NULL ≠ NULL).
            $forge->addKey(['company_id', 'code']);
            $forge->addKey(['is_active', 'group_label']);
            $forge->createTable('online_test_categories');
        }

        // Seed kategori built-in. Idempotent: cek by code.
        $this->seedBuiltInCategories($db, $now);

        // ─── 2. recruitment_pipeline_templates ─────────────────────
        if (! $db->tableExists('recruitment_pipeline_templates')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'code'         => ['type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'Slug unik per company'],
                'name'         => ['type' => 'VARCHAR', 'constraint' => 200],
                'description'  => ['type' => 'TEXT', 'null' => true],
                'is_default'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 = default kalau job_posting tidak set template eksplisit'],
                'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey(['company_id', 'is_default']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('recruitment_pipeline_templates');
        }

        // ─── 3. recruitment_pipeline_stages ────────────────────────
        if (! $db->tableExists('recruitment_pipeline_stages')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'template_id'    => ['type' => 'INT', 'unsigned' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'order_index'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0,
                    'comment' => '0-based urutan stage'],
                'code'           => ['type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'Identifier stage, mis. ONLINE_TEST_PSIKO, INTERVIEW_USER_BOD'],
                'name'           => ['type' => 'VARCHAR', 'constraint' => 200,
                    'comment' => 'Display name UI, mis. "Psikotest (Pauli + Kraepelin)"'],
                'stage_type'     => ['type' => 'ENUM',
                    'constraint' => ['SCREENING', 'ONLINE_TEST', 'INTERVIEW', 'ASSESSMENT',
                                     'OFFER', 'BACKGROUND_CHECK', 'MEDICAL_CHECK', 'CUSTOM',
                                     'HIRED', 'REJECTED', 'WITHDRAWN'],
                    'default'    => 'CUSTOM',
                    'comment'    => 'Tipe untuk routing UI + notifikasi'],
                'required_test_category_id' => [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Untuk stage_type=ONLINE_TEST: stage ini gate kalau test kategori ini belum selesai'],
                'description'    => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Instruksi untuk recruiter atau script wawancara'],
                'sla_hours'      => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Threshold "stagnant" — kandidat di stage ini > SLA jam = alert'],
                'is_terminal'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 = HIRED/REJECTED/WITHDRAWN (final state)'],
                'is_optional'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 = HR boleh skip stage ini (mis. "Background check kalau perlu")'],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['template_id', 'code']);
            $forge->addKey(['template_id', 'order_index']);
            $forge->addKey('required_test_category_id');
            $forge->addForeignKey('template_id', 'recruitment_pipeline_templates', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('required_test_category_id', 'online_test_categories', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('recruitment_pipeline_stages');
        }

        // ─── 4. Extend job_postings.pipeline_template_id ───────────
        if ($db->tableExists('job_postings')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('job_postings'));
            if (! in_array('pipeline_template_id', $cols, true)) {
                $forge->addColumn('job_postings', [
                    'pipeline_template_id' => [
                        'type'     => 'INT',
                        'unsigned' => true,
                        'null'     => true,
                        'comment'  => 'NULL = pakai default hardcoded stages',
                    ],
                ]);
            }
            if (! $this->indexExists('job_postings', 'idx_jp_pipeline_template')) {
                $db->query('CREATE INDEX idx_jp_pipeline_template ON job_postings (pipeline_template_id)');
            }
        }

        // ─── 5. Extend online_tests + bank dengan FK kategori ──────
        // Paralel ke kolom string `category` lama (tetap ada untuk
        // backward compat). Service code akan prefer FK kalau ada.
        foreach (['online_tests', 'online_test_question_bank'] as $tbl) {
            if (! $db->tableExists($tbl)) continue;
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData($tbl));
            if (! in_array('category_id', $cols, true)) {
                $forge->addColumn($tbl, [
                    'category_id' => [
                        'type'     => 'INT',
                        'unsigned' => true,
                        'null'     => true,
                        'comment'  => 'FK ke online_test_categories. NULL = pakai kolom string lama',
                    ],
                ]);
            }
            $idx = "idx_{$tbl}_category";
            if (! $this->indexExists($tbl, $idx)) {
                $db->query("CREATE INDEX `{$idx}` ON `{$tbl}` (category_id)");
            }
        }

        // Backfill: link kolom string `category` ke FK by matching code.
        $this->backfillCategoryFk($db, $now);

        // ─── 6. Extend candidate_stages dengan stage_id (opsional) ─
        // Sebelumnya hanya kolom string `stage`. Sekarang kalau pipeline
        // template digunakan, kita simpan id stage juga supaya bisa
        // join cepat ke template config (SLA, test gate, dll).
        if ($db->tableExists('candidate_stages')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('candidate_stages'));
            if (! in_array('pipeline_stage_id', $cols, true)) {
                $forge->addColumn('candidate_stages', [
                    'pipeline_stage_id' => [
                        'type'     => 'INT',
                        'unsigned' => true,
                        'null'     => true,
                        'comment'  => 'FK ke recruitment_pipeline_stages saat job pakai template',
                    ],
                ]);
            }
        }

        // ─── 7. Permissions ────────────────────────────────────────
        $this->seedPermissions([
            ['slug' => 'recruitment_pipeline.view',   'name' => 'View Pipeline Templates',
             'group' => 'recruitment', 'description' => 'Lihat master pipeline rekrutmen + stages'],
            ['slug' => 'recruitment_pipeline.manage', 'name' => 'Manage Pipeline Templates',
             'group' => 'recruitment', 'description' => 'CRUD pipeline templates + stages'],
            ['slug' => 'online_test_category.manage', 'name' => 'Manage Online Test Categories',
             'group' => 'recruitment', 'description' => 'Tambah/edit jenis test custom'],
        ]);

        // ─── 8. Seed default pipeline template per existing company ─
        $this->seedDefaultPipelinePerCompany($db, $now);
    }

    public function down(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // Drop FK columns first (SET NULL FK akan auto-handle)
        if ($db->tableExists('candidate_stages')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('candidate_stages'));
            if (in_array('pipeline_stage_id', $cols, true)) {
                try { $forge->dropColumn('candidate_stages', 'pipeline_stage_id'); } catch (\Throwable $e) {}
            }
        }

        foreach (['online_tests', 'online_test_question_bank'] as $tbl) {
            if (! $db->tableExists($tbl)) continue;
            $idx = "idx_{$tbl}_category";
            if ($this->indexExists($tbl, $idx)) {
                try { $db->query("DROP INDEX `{$idx}` ON `{$tbl}`"); } catch (\Throwable $e) {}
            }
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData($tbl));
            if (in_array('category_id', $cols, true)) {
                try { $forge->dropColumn($tbl, 'category_id'); } catch (\Throwable $e) {}
            }
        }

        if ($db->tableExists('job_postings')) {
            if ($this->indexExists('job_postings', 'idx_jp_pipeline_template')) {
                try { $db->query('DROP INDEX idx_jp_pipeline_template ON job_postings'); } catch (\Throwable $e) {}
            }
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('job_postings'));
            if (in_array('pipeline_template_id', $cols, true)) {
                try { $forge->dropColumn('job_postings', 'pipeline_template_id'); } catch (\Throwable $e) {}
            }
        }

        foreach (['recruitment_pipeline_stages', 'recruitment_pipeline_templates', 'online_test_categories']
            as $tbl) {
            if ($db->tableExists($tbl)) {
                try { $forge->dropTable($tbl, true); } catch (\Throwable $e) {}
            }
        }

        // Permission cleanup
        foreach (['recruitment_pipeline.view', 'recruitment_pipeline.manage', 'online_test_category.manage']
            as $slug) {
            $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
            if ($perm) {
                $db->table('role_permissions')->where('permission_id', (int) $perm['id'])->delete();
                $db->table('permissions')->where('id', (int) $perm['id'])->delete();
            }
        }
    }

    /**
     * Seed kategori built-in yang umum di HR Indonesia. Idempotent: cek by code.
     *
     * Daftar 12 kategori — bisa diperluas via UI master category.
     */
    private function seedBuiltInCategories($db, string $now): void
    {
        $catalog = [
            // KOGNITIF / TPA
            ['code' => 'TPA_VERBAL',  'name' => 'TPA Verbal',  'group_label' => 'KOGNITIF',
             'description' => 'Sinonim, antonim, analogi kata.',                  'scoring_type' => 'SCORE'],
            ['code' => 'TPA_NUMERIK', 'name' => 'TPA Numerik', 'group_label' => 'KOGNITIF',
             'description' => 'Aritmatika, deret angka, perbandingan kuantitatif.','scoring_type' => 'SCORE'],
            ['code' => 'TPA_LOGIKA',  'name' => 'TPA Logika',  'group_label' => 'KOGNITIF',
             'description' => 'Silogisme, logika spasial, pola gambar.',          'scoring_type' => 'SCORE'],
            ['code' => 'IST',         'name' => 'IST (Intelligence Structure Test)', 'group_label' => 'KOGNITIF',
             'description' => 'Tes inteligensi struktur (Amthauer).',             'scoring_type' => 'SCORE'],

            // KEMAMPUAN KERJA
            ['code' => 'PAULI',       'name' => 'Tes Pauli',     'group_label' => 'KEMAMPUAN',
             'description' => 'Penjumlahan angka panjang ("tes koran") untuk ketelitian + ketahanan kerja.',
             'scoring_type' => 'PROFILE'],
            ['code' => 'KRAEPELIN',   'name' => 'Tes Kraepelin', 'group_label' => 'KEMAMPUAN',
             'description' => 'Variasi tes hitung untuk konsentrasi & stabilitas emosi.',
             'scoring_type' => 'PROFILE'],

            // KEPRIBADIAN
            ['code' => 'DISC',        'name' => 'DISC Personality',  'group_label' => 'KEPRIBADIAN',
             'description' => 'Profil kepribadian D/I/S/C (Likert no right answer).',
             'scoring_type' => 'NO_RIGHT_ANSWER'],
            ['code' => 'MBTI',        'name' => 'MBTI (Myers-Briggs)','group_label' => 'KEPRIBADIAN',
             'description' => '16 tipe kepribadian (E/I, S/N, T/F, J/P).',
             'scoring_type' => 'NO_RIGHT_ANSWER'],
            ['code' => 'BIG_FIVE',    'name' => 'Big Five (OCEAN)',  'group_label' => 'KEPRIBADIAN',
             'description' => 'Openness/Conscientiousness/Extraversion/Agreeableness/Neuroticism.',
             'scoring_type' => 'NO_RIGHT_ANSWER'],
            ['code' => 'WARTEGG',     'name' => 'Wartegg',           'group_label' => 'KEPRIBADIAN',
             'description' => 'Tes proyektif gambar 8 kotak. Manual scoring oleh psikolog.',
             'scoring_type' => 'PROFILE'],
            ['code' => 'HOLLAND',     'name' => 'Holland (RIASEC)',  'group_label' => 'KEPRIBADIAN',
             'description' => 'Profil minat karir 6 tipe (Realistic/Investigative/Artistic/Social/Enterprising/Conventional).',
             'scoring_type' => 'PROFILE'],

            // BAHASA
            ['code' => 'ENGLISH',     'name' => 'English Proficiency', 'group_label' => 'BAHASA',
             'description' => 'Grammar, reading comprehension, vocabulary.',      'scoring_type' => 'SCORE'],

            // TEKNIS
            ['code' => 'TECHNICAL',   'name' => 'Technical Test',      'group_label' => 'TEKNIS',
             'description' => 'Tes kompetensi spesifik per posisi (coding, akuntansi, dsb).',
             'scoring_type' => 'SCORE'],
            ['code' => 'GENERAL',     'name' => 'General Knowledge',   'group_label' => 'TEKNIS',
             'description' => 'Pengetahuan umum / industri.',                     'scoring_type' => 'SCORE'],
        ];

        foreach ($catalog as $c) {
            $exists = $db->table('online_test_categories')
                ->where('company_id IS NULL')
                ->where('code', $c['code'])
                ->countAllResults();
            if ($exists > 0) continue;
            $db->table('online_test_categories')->insert([
                'company_id'   => null,
                'code'         => $c['code'],
                'name'         => $c['name'],
                'description'  => $c['description'],
                'group_label'  => $c['group_label'],
                'scoring_type' => $c['scoring_type'],
                'is_built_in'  => 1,
                'is_active'    => 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    /**
     * Backfill `category_id` di `online_tests` & `online_test_question_bank`
     * dari kolom string `category`. Match by code (built-in atau per-company).
     */
    private function backfillCategoryFk($db, string $now): void
    {
        // Build map code → built-in category id
        $builtInMap = [];
        foreach ($db->table('online_test_categories')
                    ->where('company_id IS NULL')
                    ->where('deleted_at IS NULL')->get()->getResultArray() as $r) {
            $builtInMap[strtoupper((string) $r['code'])] = (int) $r['id'];
        }

        foreach (['online_tests', 'online_test_question_bank'] as $tbl) {
            if (! $db->tableExists($tbl)) continue;
            $rows = $db->table($tbl)
                ->where('category_id IS NULL')
                ->where('category IS NOT NULL')
                ->get()->getResultArray();
            foreach ($rows as $r) {
                $cat = strtoupper(trim((string) ($r['category'] ?? '')));
                if ($cat === '') continue;
                // Coba match exact, lalu fallback "TPA" → first TPA_* category
                $catId = $builtInMap[$cat] ?? null;
                if ($catId === null && $cat === 'TPA') {
                    $catId = $builtInMap['TPA_VERBAL'] ?? null; // fallback
                }
                if ($catId === null && $cat === 'CUSTOM') {
                    continue; // CUSTOM tidak punya FK, biarkan NULL
                }
                if ($catId !== null) {
                    $db->table($tbl)->where('id', (int) $r['id'])->update([
                        'category_id' => $catId,
                        'updated_at'  => $now,
                    ]);
                }
            }
        }
    }

    /**
     * Buat default pipeline template "Standard" per company aktif, dengan 7
     * stages yang setara hardcoded lama. Idempotent: skip company yang sudah
     * punya template.
     */
    private function seedDefaultPipelinePerCompany($db, string $now): void
    {
        $companies = $db->table('companies')
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        $defaultStages = [
            ['code' => 'NEW',            'name' => 'Baru Masuk',      'stage_type' => 'SCREENING',  'order' => 0],
            ['code' => 'SCREENING',      'name' => 'CV Screening',    'stage_type' => 'SCREENING',  'order' => 1],
            ['code' => 'ONLINE_TEST',    'name' => 'Online Test',     'stage_type' => 'ONLINE_TEST','order' => 2],
            ['code' => 'INTERVIEW_HR',   'name' => 'Interview HR',    'stage_type' => 'INTERVIEW',  'order' => 3],
            ['code' => 'INTERVIEW_USER', 'name' => 'Interview User',  'stage_type' => 'INTERVIEW',  'order' => 4],
            ['code' => 'OFFER',          'name' => 'Offer Letter',    'stage_type' => 'OFFER',      'order' => 5],
            ['code' => 'HIRED',          'name' => 'Hired',           'stage_type' => 'HIRED',      'order' => 6, 'is_terminal' => 1],
            ['code' => 'REJECTED',       'name' => 'Ditolak',         'stage_type' => 'REJECTED',   'order' => 90, 'is_terminal' => 1],
            ['code' => 'WITHDRAWN',      'name' => 'Mengundurkan Diri','stage_type' => 'WITHDRAWN', 'order' => 91, 'is_terminal' => 1],
        ];

        foreach ($companies as $c) {
            $companyId = (int) $c['id'];
            $existing = $db->table('recruitment_pipeline_templates')
                ->where('company_id', $companyId)
                ->where('code', 'STANDARD')
                ->where('deleted_at IS NULL')
                ->countAllResults();
            if ($existing > 0) continue;

            $db->table('recruitment_pipeline_templates')->insert([
                'company_id'  => $companyId,
                'code'        => 'STANDARD',
                'name'        => 'Pipeline Standar',
                'description' => 'Pipeline default 7-tahap. Boleh diduplikasi dan disesuaikan untuk role tertentu.',
                'is_default'  => 1,
                'is_active'   => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $tplId = (int) $db->insertID();

            foreach ($defaultStages as $s) {
                $db->table('recruitment_pipeline_stages')->insert([
                    'template_id'  => $tplId,
                    'company_id'   => $companyId,
                    'order_index'  => (int) $s['order'],
                    'code'         => $s['code'],
                    'name'         => $s['name'],
                    'stage_type'   => $s['stage_type'],
                    'is_terminal'  => $s['is_terminal'] ?? 0,
                    'is_optional'  => 0,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            foreach ($this->db->getIndexData($table) as $idx) {
                if ($idx->name === $indexName) return true;
            }
        } catch (\Throwable $e) {}
        return false;
    }

    private function seedPermissions(array $permissions): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($permissions as $p) {
            $exists = $this->db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists === 0) {
                $p['created_at'] = $now;
                $this->db->table('permissions')->insert($p);
            }
            $perm = $this->db->table('permissions')->where('slug', $p['slug'])->get()->getRowArray();
            if (! $perm) continue;
            // Auto-grant ke super_admin (1) & hr_admin (2)
            foreach ([1, 2] as $roleId) {
                $has = $this->db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($has > 0) continue;
                $this->db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at'    => $now,
                ]);
            }
        }
    }
}