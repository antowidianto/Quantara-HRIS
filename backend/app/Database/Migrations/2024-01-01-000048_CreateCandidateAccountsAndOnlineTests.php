<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 21+ — Candidate self-service portal & Online Test engine.
 *
 * Layered di atas Career Portal yang sudah ada (CreateCareerPortalTables
 * 000030 + CreateRecruitmentTables 000020). Sebelumnya pelamar tracking
 * lewat token email (application_track_tokens). Sekarang ditambah:
 *
 *   1. `candidate_accounts`         — auth tersendiri (terpisah dari `users`)
 *      Kandidat bisa register, login, lihat semua lamarannya, dan ambil
 *      online test yang di-assign HR. JWT terpisah (bukan internal user JWT).
 *   2. `candidate_refresh_tokens`   — rotation refresh token, mirror skema
 *      `refresh_tokens` user internal supaya bisa di-revoke per-account.
 *   3. `candidate_password_resets`  — token forgot-password (60 menit TTL).
 *   4. `online_test_question_bank`  — bank soal master (reusable lintas test).
 *      Punya tag (verbal/numerik/logika/disc/english/technical/...).
 *   5. `online_tests`               — definisi test (judul, durasi, passing
 *      score, kategori TPA/ENGLISH/DISC/TECHNICAL/CUSTOM, pool randomization).
 *   6. `online_test_questions`      — soal langsung milik test (kalau bukan
 *      mode pool/random). Auto-link ke question bank kalau ditarik dari sana.
 *   7. `online_test_assignments`    — assignment HR ke kandidat dengan due
 *      date, max attempts, status PENDING/IN_PROGRESS/COMPLETED/EXPIRED.
 *   8. `online_test_attempts`       — attempt fisik kandidat (timer, started/
 *      submitted, score, persentase, hasil PASS/FAIL).
 *   9. `online_test_answers`        — jawaban per soal per attempt (untuk
 *      auto-grade & untuk manual review essay).
 *  10. `online_test_events`         — log tab-switch / fullscreen exit /
 *      copy attempts (anti-cheat best-effort, bukan proctoring berat).
 *  11. Extend `candidates`          — `candidate_account_id` FK + index.
 *  12. Permission baru `online_tests.manage` + auto-grant.
 *  13. Stage baru di pipeline candidate: `ONLINE_TEST` (string, tidak ENUM
 *      — pipeline pakai VARCHAR, lihat migration 000020).
 *
 * Semua tabel additive (tidak alter existing). Drop di down().
 */
class CreateCandidateAccountsAndOnlineTests extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;
        $now   = date('Y-m-d H:i:s');

        // ─── 1. candidate_accounts ────────────────────────────────────
        // Auth pelamar (di-scope per company karena slug/portal company-aware).
        // Email unique per company supaya pelamar yang sama bisa daftar di
        // 2 perusahaan berbeda.
        if (! $db->tableExists('candidate_accounts')) {
            $forge->addField([
                'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'        => ['type' => 'INT', 'unsigned' => true],
                'email'             => ['type' => 'VARCHAR', 'constraint' => 200],
                'email_verified_at' => ['type' => 'DATETIME', 'null' => true],
                'password_hash'     => ['type' => 'VARCHAR', 'constraint' => 255],
                'full_name'         => ['type' => 'VARCHAR', 'constraint' => 200],
                'phone'             => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'is_active'         => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'last_login_at'     => ['type' => 'DATETIME', 'null' => true],
                'last_login_ip'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'failed_login_count'=> ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'locked_until'      => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Auto-lock setelah N failed login (lihat CandidateAuthService)'],
                'created_at'        => ['type' => 'DATETIME', 'null' => true],
                'updated_at'        => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'        => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'email']);
            $forge->addKey('email');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('candidate_accounts');
        }

        // ─── 2. candidate_refresh_tokens ──────────────────────────────
        if (! $db->tableExists('candidate_refresh_tokens')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'jti'                 => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'UUIDv4 dari payload JWT'],
                'candidate_account_id'=> ['type' => 'INT', 'unsigned' => true],
                'expires_at'          => ['type' => 'DATETIME'],
                'revoked'             => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
                'revoked_at'          => ['type' => 'DATETIME', 'null' => true],
                'user_agent'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'ip_address'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey('jti');
            $forge->addKey(['candidate_account_id', 'revoked']);
            $forge->addForeignKey('candidate_account_id', 'candidate_accounts', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('candidate_refresh_tokens');
        }

        // ─── 3. candidate_password_resets ─────────────────────────────
        if (! $db->tableExists('candidate_password_resets')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'candidate_account_id'=> ['type' => 'INT', 'unsigned' => true],
                'token_hash'          => ['type' => 'VARCHAR', 'constraint' => 128,
                    'comment' => 'SHA-256 dari token plaintext yang dikirim email'],
                'expires_at'          => ['type' => 'DATETIME'],
                'used_at'             => ['type' => 'DATETIME', 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey('token_hash');
            $forge->addKey('candidate_account_id');
            $forge->addForeignKey('candidate_account_id', 'candidate_accounts', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('candidate_password_resets');
        }

        // ─── 4. online_test_question_bank ────────────────────────────
        // Question bank reusable. category populer di HR Indonesia:
        //   TPA_VERBAL  — sinonim/antonim/analogi
        //   TPA_NUMERIK — aritmatika/deret
        //   TPA_LOGIKA  — silogisme/logika spasial
        //   ENGLISH     — grammar/reading
        //   DISC        — skala kepribadian (Likert 1-5, no right answer)
        //   TECHNICAL   — kompetensi spesifik per job
        //   GENERAL     — uncategorized
        if (! $db->tableExists('online_test_question_bank')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'category'     => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'GENERAL',
                    'comment' => 'TPA_VERBAL/TPA_NUMERIK/TPA_LOGIKA/ENGLISH/DISC/TECHNICAL/GENERAL'],
                'difficulty'   => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'MEDIUM',
                    'comment' => 'EASY/MEDIUM/HARD'],
                'question_type'=> ['type' => 'VARCHAR', 'constraint' => 24,
                    'comment' => 'MCQ_SINGLE/MCQ_MULTI/TRUE_FALSE/LIKERT_1_5/SHORT_ANSWER/NUMERIC'],
                'prompt'       => ['type' => 'TEXT',
                    'comment' => 'Soal — boleh markdown ringan (di-sanitize render)'],
                'choices'      => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Array choice [{key:"A",text:"..."}] untuk MCQ/TF'],
                'correct_answer'=> ['type' => 'JSON', 'null' => true,
                    'comment' => 'MCQ_SINGLE: "A". MCQ_MULTI: ["A","C"]. TF: true/false. NUMERIC: number. SHORT_ANSWER: null (manual). LIKERT: null'],
                'disc_weight'  => ['type' => 'VARCHAR', 'constraint' => 1, 'null' => true,
                    'comment' => 'D/I/S/C — hanya untuk DISC questions'],
                'explanation'  => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Pembahasan (untuk certificate/laporan, optional)'],
                'tags'         => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Tag bebas untuk filter ["sma","sinonim"]'],
                'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_by'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'category']);
            $forge->addKey(['company_id', 'is_active']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('online_test_question_bank');
        }

        // ─── 5. online_tests ──────────────────────────────────────────
        if (! $db->tableExists('online_tests')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'code'                => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Slug unik per company, mis. "TPA-2026"'],
                'name'                => ['type' => 'VARCHAR', 'constraint' => 200],
                'category'            => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'CUSTOM',
                    'comment' => 'TPA/ENGLISH/DISC/TECHNICAL/CUSTOM'],
                'description'         => ['type' => 'TEXT', 'null' => true],
                'instructions_html'   => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Petunjuk yang muncul sebelum mulai test'],
                'duration_minutes'    => ['type' => 'INT', 'unsigned' => true, 'default' => 30],
                'passing_score_pct'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 60,
                    'comment' => 'Persen min untuk PASS (0 = no passing rule, mis. tes kepribadian)'],
                'max_attempts'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'shuffle_questions'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'shuffle_choices'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'show_result_to_candidate' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1,
                    'comment' => 'Tampilkan skor ke kandidat setelah submit (0 = tutup)'],
                // Mode random pool — kalau pakai pool, controller pilih N soal
                // dari question_bank by filter saat attempt dimulai.
                'use_pool'            => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
                'pool_filters'        => ['type' => 'JSON', 'null' => true,
                    'comment' => '{"category":"TPA_VERBAL","difficulty":"MEDIUM","count":15}, atau array beberapa segment untuk multi-section'],
                'is_active'           => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'is_template'         => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => 'Built-in template (TPA / English / DISC) — tidak boleh dihapus'],
                'created_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey(['company_id', 'is_active']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('online_tests');
        }

        // ─── 6. online_test_questions ─────────────────────────────────
        // Soal yang attached langsung ke test (bukan via pool). Kalau
        // bank_question_id != null → ini referensi ke question_bank
        // (snapshot prompt/choices saat add). Kalau null → soal custom
        // langsung di test.
        if (! $db->tableExists('online_test_questions')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'online_test_id'  => ['type' => 'INT', 'unsigned' => true],
                'bank_question_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'question_type'   => ['type' => 'VARCHAR', 'constraint' => 24],
                'category'        => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'prompt'          => ['type' => 'TEXT'],
                'choices'         => ['type' => 'JSON', 'null' => true],
                'correct_answer'  => ['type' => 'JSON', 'null' => true],
                'disc_weight'     => ['type' => 'VARCHAR', 'constraint' => 1, 'null' => true],
                'points'          => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 1.0,
                    'comment' => 'Bobot poin soal (default 1.0)'],
                'explanation'     => ['type' => 'TEXT', 'null' => true],
                'sort_order'      => ['type' => 'INT', 'default' => 0],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['online_test_id', 'sort_order']);
            $forge->addKey('bank_question_id');
            $forge->addForeignKey('online_test_id', 'online_tests', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('online_test_questions');
        }

        // ─── 7. online_test_assignments ──────────────────────────────
        if (! $db->tableExists('online_test_assignments')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'online_test_id'      => ['type' => 'INT', 'unsigned' => true],
                'candidate_id'        => ['type' => 'INT', 'unsigned' => true],
                'candidate_account_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Auto-link kalau candidate sudah punya account'],
                'job_posting_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'status'              => [
                    'type' => 'ENUM',
                    'constraint' => ['PENDING','IN_PROGRESS','COMPLETED','EXPIRED','CANCELLED'],
                    'default' => 'PENDING',
                ],
                'available_from'      => ['type' => 'DATETIME', 'null' => true],
                'due_at'              => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Setelah waktu ini status auto-EXPIRED kalau belum mulai'],
                'max_attempts'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'attempts_used'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
                'best_score'          => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
                'best_percentage'     => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
                'best_result'         => [
                    'type' => 'ENUM',
                    'constraint' => ['PENDING','PASS','FAIL','REVIEW'],
                    'default' => 'PENDING',
                    'comment' => 'REVIEW = ada essay belum dinilai HR',
                ],
                'invitation_token'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'Token magic-link untuk email invite (64 hex)'],
                'notes'               => ['type' => 'TEXT', 'null' => true],
                'assigned_by'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'completed_at'        => ['type' => 'DATETIME', 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'candidate_id']);
            $forge->addKey(['company_id', 'status']);
            $forge->addKey('candidate_account_id');
            $forge->addUniqueKey('invitation_token');
            $forge->addForeignKey('online_test_id', 'online_tests', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('candidate_id', 'candidates', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('online_test_assignments');
        }

        // ─── 8. online_test_attempts ──────────────────────────────────
        if (! $db->tableExists('online_test_attempts')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'assignment_id'   => ['type' => 'INT', 'unsigned' => true],
                'attempt_number'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'status'          => [
                    'type' => 'ENUM',
                    'constraint' => ['IN_PROGRESS','SUBMITTED','GRADED','EXPIRED','VOIDED'],
                    'default' => 'IN_PROGRESS',
                ],
                'started_at'      => ['type' => 'DATETIME'],
                // Snapshot expiry — started_at + duration. Kalau current_time
                // > expires_at, attempt auto-submit dengan jawaban yang sudah
                // disimpan. Anti-cheat: tidak relax timer kalau user reload.
                'expires_at'      => ['type' => 'DATETIME'],
                'submitted_at'    => ['type' => 'DATETIME', 'null' => true],
                'graded_at'       => ['type' => 'DATETIME', 'null' => true],
                'graded_by'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'total_questions' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'auto_score'      => ['type' => 'DECIMAL', 'constraint' => '8,2', 'default' => 0,
                    'comment' => 'Sum dari soal MCQ/TF/Numeric yang auto-graded'],
                'manual_score'    => ['type' => 'DECIMAL', 'constraint' => '8,2', 'default' => 0,
                    'comment' => 'Sum dari soal essay/short_answer yang manual graded'],
                'total_score'     => ['type' => 'DECIMAL', 'constraint' => '8,2', 'default' => 0],
                'max_score'       => ['type' => 'DECIMAL', 'constraint' => '8,2', 'default' => 0],
                'percentage'      => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
                'result'          => [
                    'type' => 'ENUM',
                    'constraint' => ['PENDING','PASS','FAIL','REVIEW'],
                    'default' => 'PENDING',
                ],
                'disc_breakdown'  => ['type' => 'JSON', 'null' => true,
                    'comment' => '{"D":12,"I":18,"S":10,"C":8} untuk DISC test'],
                'category_scores' => ['type' => 'JSON', 'null' => true,
                    'comment' => '{"TPA_VERBAL":{"score":8,"max":10}, ...} untuk multi-section TPA'],
                'tab_switch_count'=> ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'fullscreen_exit_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'ip_address'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'user_agent'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['assignment_id', 'attempt_number']);
            $forge->addKey(['assignment_id', 'status']);
            $forge->addForeignKey('assignment_id', 'online_test_assignments', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('online_test_attempts');
        }

        // ─── 9. online_test_answers ──────────────────────────────────
        if (! $db->tableExists('online_test_answers')) {
            $forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'attempt_id'      => ['type' => 'INT', 'unsigned' => true],
                'question_id'     => ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'Snapshot dari online_test_questions atau bank_question_id (kalau pool)'],
                'question_snapshot' => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Snapshot prompt/choices/correct_answer saat attempt dimulai (immutable)'],
                'response'        => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Jawaban kandidat — bentuk tergantung question_type'],
                'is_correct'      => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true,
                    'comment' => 'NULL = belum dinilai (manual). 1/0 = auto-graded result'],
                'points_awarded'  => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],
                'manual_feedback' => ['type' => 'TEXT', 'null' => true],
                'time_spent_sec'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'sort_order'      => ['type' => 'INT', 'default' => 0,
                    'comment' => 'Urutan presentasi ke kandidat (sesudah shuffle)'],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['attempt_id', 'sort_order']);
            $forge->addKey('question_id');
            $forge->addForeignKey('attempt_id', 'online_test_attempts', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('online_test_answers');
        }

        // ─── 10. online_test_events (anti-cheat log) ─────────────────
        if (! $db->tableExists('online_test_events')) {
            $forge->addField([
                'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'attempt_id'   => ['type' => 'INT', 'unsigned' => true],
                'event_type'   => ['type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'TAB_SWITCH/FULLSCREEN_EXIT/COPY_ATTEMPT/PASTE_ATTEMPT/RIGHT_CLICK/RESIZE/DEVTOOLS_OPEN/HEARTBEAT'],
                'occurred_at'  => ['type' => 'DATETIME'],
                'meta'         => ['type' => 'JSON', 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['attempt_id', 'event_type']);
            $forge->addForeignKey('attempt_id', 'online_test_attempts', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('online_test_events');
        }

        // ─── 11. Extend candidates dengan candidate_account_id ───────
        if ($db->tableExists('candidates')) {
            $cols = $db->getFieldNames('candidates');
            $add = [];
            if (! in_array('candidate_account_id', $cols, true)) {
                $add['candidate_account_id'] = ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'FK ke candidate_accounts (auth pelamar). Nullable untuk backward compat dengan kandidat existing yang masuk via track-token'];
            }
            if ($add) {
                $forge->addColumn('candidates', $add);
                try {
                    $db->query('CREATE INDEX `idx_candidates_account` ON `candidates` (`candidate_account_id`)');
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        // ─── 12. Permission baru ─────────────────────────────────────
        $newPermissions = [
            ['slug' => 'online_tests.view',   'name' => 'View Online Tests',   'group' => 'recruitment'],
            ['slug' => 'online_tests.manage', 'name' => 'Manage Online Tests', 'group' => 'recruitment'],
            ['slug' => 'online_tests.grade',  'name' => 'Grade Online Test answers', 'group' => 'recruitment'],
        ];
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }

        // Auto-grant: super(1) & HR(2) → all. Manager(3) → view+grade.
        $grantMap = [
            1 => ['online_tests.view', 'online_tests.manage', 'online_tests.grade'],
            2 => ['online_tests.view', 'online_tests.manage', 'online_tests.grade'],
            3 => ['online_tests.view', 'online_tests.grade'],
        ];
        foreach ($grantMap as $roleId => $slugs) {
            $existsRole = $db->table('roles')->where('id', $roleId)->countAllResults() > 0;
            if (! $existsRole) continue;
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
        $db = $this->db;
        $forge = $this->forge;

        // Drop dalam urutan dependency reverse
        foreach ([
            'online_test_events',
            'online_test_answers',
            'online_test_attempts',
            'online_test_assignments',
            'online_test_questions',
            'online_tests',
            'online_test_question_bank',
            'candidate_password_resets',
            'candidate_refresh_tokens',
        ] as $table) {
            if ($db->tableExists($table)) {
                $forge->dropTable($table, true);
            }
        }

        // candidate_accounts last (FK target)
        if ($db->tableExists('candidate_accounts')) {
            $forge->dropTable('candidate_accounts', true);
        }

        // Drop kolom additive di candidates
        if ($db->tableExists('candidates')) {
            $cols = $db->getFieldNames('candidates');
            if (in_array('candidate_account_id', $cols, true)) {
                try { $forge->dropColumn('candidates', 'candidate_account_id'); } catch (\Throwable $e) {}
            }
        }

        // Permission cleanup
        foreach (['online_tests.view', 'online_tests.manage', 'online_tests.grade'] as $slug) {
            $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
            if ($perm) {
                $db->table('role_permissions')->where('permission_id', (int) $perm['id'])->delete();
                $db->table('permissions')->where('id', (int) $perm['id'])->delete();
            }
        }
    }
}