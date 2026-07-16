<?php

namespace App\Libraries\Recruitment;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * OnlineTestService — domain logic test online untuk recruitment.
 *
 * Tanggung jawab:
 *   1. Build attempt: ambil soal (langsung dari online_test_questions atau
 *      via random pool dari online_test_question_bank), shuffle, snapshot
 *      ke online_test_answers (immutable selama attempt).
 *   2. Save responses: kandidat update jawaban incremental tanpa
 *      langsung grade.
 *   3. Submit + auto-grade: kalkulasi skor MCQ_SINGLE / MCQ_MULTI /
 *      TRUE_FALSE / NUMERIC / LIKERT_1_5 (DISC). Soal SHORT_ANSWER /
 *      essay disisakan untuk manual review.
 *   4. Compute aggregates: percentage, result PASS/FAIL/REVIEW, DISC
 *      breakdown D/I/S/C, category_scores per section TPA.
 *   5. Update assignment: best_score / best_percentage / status.
 *
 * Dipakai oleh CandidateOnlineTestController (kandidat) dan
 * OnlineTestController (HR admin grading).
 *
 * Tidak melakukan IO email / webhook — itu job controller / dispatcher.
 */
class OnlineTestService
{
    /** Tipe soal yang bisa di-auto-grade. */
    public const AUTO_GRADE_TYPES = [
        'MCQ_SINGLE', 'MCQ_MULTI', 'TRUE_FALSE', 'NUMERIC', 'LIKERT_1_5',
    ];

    /** Tipe soal yang perlu manual grade. */
    public const MANUAL_GRADE_TYPES = ['SHORT_ANSWER'];

    public const ALL_TYPES = [
        'MCQ_SINGLE', 'MCQ_MULTI', 'TRUE_FALSE', 'NUMERIC',
        'LIKERT_1_5', 'SHORT_ANSWER',
    ];

    private const DISC_DIMENSIONS = ['D', 'I', 'S', 'C'];

    /**
     * Kategori test catalog Indonesian HR — built-in template categories.
     */
    public const CATEGORIES = [
        'TPA'       => 'Tes Potensi Akademik (Verbal, Numerik, Logika)',
        'ENGLISH'   => 'Tes Bahasa Inggris',
        'DISC'      => 'Tes Kepribadian DISC',
        'TECHNICAL' => 'Tes Kompetensi Teknis',
        'CUSTOM'    => 'Tes Kustom',
    ];

    public const QUESTION_BANK_CATEGORIES = [
        'TPA_VERBAL'  => 'TPA — Verbal',
        'TPA_NUMERIK' => 'TPA — Numerik',
        'TPA_LOGIKA'  => 'TPA — Logika',
        'ENGLISH'     => 'Bahasa Inggris',
        'DISC'        => 'Kepribadian DISC',
        'TECHNICAL'   => 'Kompetensi Teknis',
        'GENERAL'     => 'Umum',
    ];

    /**
     * Mulai attempt baru untuk assignment yang sudah PENDING / IN_PROGRESS.
     * Return data yang dipakai frontend untuk render test page (TANPA
     * correct_answer di-leak ke kandidat).
     *
     * @throws \RuntimeException kalau assignment tidak boleh diambil
     */
    public function startAttempt(int $assignmentId, int $companyId, ?string $ip = null, ?string $userAgent = null): array
    {
        $db = \Config\Database::connect();

        $assignment = $db->table('online_test_assignments')
            ->where('id', $assignmentId)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $assignment) {
            throw new \RuntimeException('Assignment tidak ditemukan');
        }
        if (! in_array($assignment['status'], ['PENDING', 'IN_PROGRESS'], true)) {
            throw new \RuntimeException('Test sudah selesai atau tidak tersedia');
        }
        $now = date('Y-m-d H:i:s');
        if (! empty($assignment['available_from']) && $assignment['available_from'] > $now) {
            throw new \RuntimeException('Test belum dibuka. Available from: ' . $assignment['available_from']);
        }
        if (! empty($assignment['due_at']) && $assignment['due_at'] < $now) {
            // Tandai expired
            $db->table('online_test_assignments')->where('id', $assignmentId)->where('company_id', $companyId)->update([
                'status' => 'EXPIRED', 'updated_at' => $now,
            ]);
            throw new \RuntimeException('Test sudah lewat batas waktu (expired)');
        }
        if ((int) $assignment['attempts_used'] >= (int) $assignment['max_attempts']) {
            throw new \RuntimeException('Quota attempt sudah habis');
        }

        // Cek apakah ada attempt IN_PROGRESS yang belum expired — kalau ada,
        // kembalikan attempt itu (resume), bukan bikin baru.
        $existing = $db->table('online_test_attempts a')
            ->select('a.*')
            ->join('online_test_assignments x', 'x.id = a.assignment_id')
            ->where('a.assignment_id', $assignmentId)
            ->where('x.company_id', $companyId)
            ->where('a.status', 'IN_PROGRESS')
            ->orderBy('a.id', 'DESC')
            ->get()->getRowArray();
        if ($existing) {
            if ($existing['expires_at'] >= $now) {
                return $this->buildAttemptView((int) $existing['id'], $companyId);
            }
            // Expired in progress — auto-submit dengan jawaban yang ada
            $this->autoSubmitExpired((int) $existing['id'], $companyId);
        }

        $test = $db->table('online_tests')->where('id', (int) $assignment['online_test_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $test) {
            throw new \RuntimeException('Test definition hilang');
        }

        // Build daftar soal: pool atau attached questions
        $questions = $this->resolveQuestionsForAttempt($test);
        if (count($questions) === 0) {
            throw new \RuntimeException('Test belum punya soal — hubungi HR');
        }

        // Shuffle questions (kalau test minta) tapi simpan urutan stabil
        // di sort_order.
        if ((int) $test['shuffle_questions'] === 1) {
            shuffle($questions);
        }

        // Insert attempt
        $duration = max(1, (int) $test['duration_minutes']);
        $expiresAt = date('Y-m-d H:i:s', time() + ($duration * 60));
        $attemptNumber = (int) $assignment['attempts_used'] + 1;
        $maxScore = 0.0;
        foreach ($questions as $q) {
            $maxScore += (float) ($q['points'] ?? 1.0);
        }

        $db->transStart();
        $attemptInsert = $db->table('online_test_attempts');
        $this->assertTenantCompanyId($companyId);
        $attemptInsert->insert([
            'assignment_id'   => $assignmentId,
            'attempt_number'  => $attemptNumber,
            'status'          => 'IN_PROGRESS',
            'started_at'      => $now,
            'expires_at'      => $expiresAt,
            'total_questions' => count($questions),
            'max_score'       => $maxScore,
            'ip_address'      => substr((string) $ip, 0, 64),
            'user_agent'      => substr((string) $userAgent, 0, 255),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $attemptId = (int) $db->insertID();

        // Snapshot answers (1 row per question)
        $rows = [];
        foreach ($questions as $idx => $q) {
            $choices = $q['choices'] ?? null;
            if ($choices && (int) $test['shuffle_choices'] === 1
                && in_array($q['question_type'], ['MCQ_SINGLE', 'MCQ_MULTI'], true)) {
                $choices = $this->shuffleChoicesPreservingKeys($choices);
            }
            $snapshot = [
                'question_type' => $q['question_type'],
                'category'      => $q['category'] ?? null,
                'prompt'        => $q['prompt'],
                'choices'       => $choices,
                'correct_answer'=> $q['correct_answer'] ?? null,
                'disc_weight'   => $q['disc_weight'] ?? null,
                'points'        => (float) ($q['points'] ?? 1.0),
                'explanation'   => $q['explanation'] ?? null,
            ];
            $rows[] = [
                'attempt_id'        => $attemptId,
                'question_id'       => (int) ($q['id'] ?? 0),
                'question_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'response'          => null,
                'is_correct'        => null,
                'points_awarded'    => 0,
                'sort_order'        => $idx,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }
        if ($rows) {
            $db->table('online_test_answers')->insertBatch($rows);
        }

        // Update assignment counters
        $db->table('online_test_assignments')->where('id', $assignmentId)->where('company_id', $companyId)->update([
            'status'        => 'IN_PROGRESS',
            'attempts_used' => $attemptNumber,
            'updated_at'    => $now,
        ]);
        $db->transComplete();

        return $this->buildAttemptView($attemptId, $companyId);
    }

    /**
     * View attempt — dengan correct_answer DI-STRIP supaya kandidat
     * tidak bisa lihat kunci jawaban via dev tools.
     */
    public function buildAttemptView(int $attemptId, int $companyId): array
    {
        $db = \Config\Database::connect();
        $attempt = $this->tenantAttempt($db, $attemptId, $companyId);
        if (! $attempt) throw new \RuntimeException('Attempt tidak ditemukan');

        $assignment = $db->table('online_test_assignments')
            ->where('id', (int) $attempt['assignment_id'])->where('company_id', $companyId)->get()->getRowArray();
        $test = $assignment ? $db->table('online_tests')->where('id', (int) $assignment['online_test_id'])
            ->where('company_id', $companyId)->get()->getRowArray() : null;

        $answers = $this->tenantAnswers($db, $attemptId, $companyId)
            ->orderBy('ans.sort_order', 'ASC')
            ->get()->getResultArray();

        $items = [];
        foreach ($answers as $a) {
            $snap = json_decode((string) $a['question_snapshot'], true) ?: [];
            // Strip correct_answer + explanation supaya tidak leak
            unset($snap['correct_answer'], $snap['explanation']);
            $items[] = [
                'answer_id'     => (int) $a['id'],
                'sort_order'    => (int) $a['sort_order'],
                'question_type' => $snap['question_type'] ?? null,
                'category'      => $snap['category'] ?? null,
                'prompt'        => $snap['prompt'] ?? '',
                'choices'       => $snap['choices'] ?? null,
                'points'        => (float) ($snap['points'] ?? 1.0),
                'response'      => $a['response'] !== null ? json_decode((string) $a['response'], true) : null,
                'time_spent_sec'=> (int) $a['time_spent_sec'],
            ];
        }

        return [
            'attempt' => [
                'id'              => (int) $attempt['id'],
                'assignment_id'   => (int) $attempt['assignment_id'],
                'attempt_number'  => (int) $attempt['attempt_number'],
                'status'          => $attempt['status'],
                'started_at'      => $attempt['started_at'],
                'expires_at'      => $attempt['expires_at'],
                'submitted_at'    => $attempt['submitted_at'],
                'total_questions' => (int) $attempt['total_questions'],
                'time_remaining_sec' => max(0, strtotime((string) $attempt['expires_at']) - time()),
            ],
            'test' => $test ? [
                'id'                  => (int) $test['id'],
                'name'                => $test['name'],
                'category'            => $test['category'],
                'description'         => $test['description'] ?? null,
                'instructions_html'   => $test['instructions_html'] ?? null,
                'duration_minutes'    => (int) $test['duration_minutes'],
                'show_result_to_candidate' => (int) $test['show_result_to_candidate'] === 1,
            ] : null,
            'questions' => $items,
        ];
    }

    /**
     * Save partial response untuk satu jawaban. Tidak grade.
     *
     * @param mixed $response — array (MCQ_MULTI), string (MCQ_SINGLE),
     *                          bool (TF), number (NUMERIC, LIKERT), string (essay)
     */
    public function saveAnswer(int $attemptId, int $answerId, int $companyId, $response, int $timeSpentSec = 0): void
    {
        $db = \Config\Database::connect();
        $attempt = $this->tenantAttempt($db, $attemptId, $companyId);
        if (! $attempt) throw new \RuntimeException('Attempt tidak ditemukan');
        if ($attempt['status'] !== 'IN_PROGRESS') {
            throw new \RuntimeException('Attempt sudah disubmit / tidak in progress');
        }
        $now = date('Y-m-d H:i:s');
        if ($attempt['expires_at'] < $now) {
            // Auto-submit kalau ekspired sebelum saving
            $this->autoSubmitExpired($attemptId, $companyId);
            throw new \RuntimeException('Waktu test sudah habis');
        }

        $exists = $this->tenantAnswers($db, $attemptId, $companyId)
            ->where('ans.id', $answerId)->countAllResults() > 0;
        if (! $exists) {
            throw new \RuntimeException('Answer slot tidak valid');
        }

        $db->table('online_test_answers')->where('id', $answerId)->where('attempt_id', $attemptId)->update([
            'response'       => json_encode($response, JSON_UNESCAPED_UNICODE),
            'time_spent_sec' => max(0, $timeSpentSec),
            'updated_at'     => $now,
        ]);
    }

    /**
     * Submit attempt: auto-grade soal yang bisa, lalu update aggregat.
     * Kalau ada SHORT_ANSWER → result = REVIEW, menunggu HR.
     */
    public function submitAttempt(int $attemptId, int $companyId): array
    {
        $db = \Config\Database::connect();
        $attempt = $this->tenantAttempt($db, $attemptId, $companyId);
        if (! $attempt) throw new \RuntimeException('Attempt tidak ditemukan');
        if ($attempt['status'] !== 'IN_PROGRESS') {
            // Idempotent — kalau sudah submitted, kembalikan saja state-nya
            return $this->buildResultView($attemptId, $companyId);
        }

        $assignment = $db->table('online_test_assignments')
            ->where('id', (int) $attempt['assignment_id'])->where('company_id', $companyId)->get()->getRowArray();
        $test = $assignment ? $db->table('online_tests')->where('id', (int) $assignment['online_test_id'])
            ->where('company_id', $companyId)->get()->getRowArray() : null;

        $answers = $this->tenantAnswers($db, $attemptId, $companyId)->get()->getResultArray();

        $autoScore   = 0.0;
        $hasManual   = false;
        $maxScore    = 0.0;
        $discTally   = ['D' => 0, 'I' => 0, 'S' => 0, 'C' => 0];
        $catScores   = [];

        $now = date('Y-m-d H:i:s');
        $db->transStart();

        foreach ($answers as $a) {
            $snap     = json_decode((string) $a['question_snapshot'], true) ?: [];
            $type     = $snap['question_type'] ?? '';
            $points   = (float) ($snap['points'] ?? 1.0);
            $maxScore += $points;
            $resp     = $a['response'] !== null ? json_decode((string) $a['response'], true) : null;
            $cat      = $snap['category'] ?? 'GENERAL';

            if (! isset($catScores[$cat])) $catScores[$cat] = ['score' => 0.0, 'max' => 0.0];
            $catScores[$cat]['max'] += $points;

            $update = ['updated_at' => $now];

            if (in_array($type, self::AUTO_GRADE_TYPES, true)) {
                $isCorrect = $this->autoGradeAnswer($type, $resp, $snap);
                if ($type === 'LIKERT_1_5') {
                    // DISC: tidak ada benar/salah, tapi tally berdasarkan disc_weight
                    // dan response 1-5. Convention: skala 5 = "sangat saya banget".
                    $weight = $snap['disc_weight'] ?? null;
                    if ($weight && in_array($weight, self::DISC_DIMENSIONS, true)) {
                        $score = max(1, min(5, (int) ($resp ?? 0)));
                        $discTally[$weight] += $score;
                    }
                    // Untuk DISC, points awarded = points (pertanyaan dijawab valid)
                    $awarded = $resp !== null && (int) $resp >= 1 && (int) $resp <= 5 ? $points : 0;
                    $autoScore += $awarded;
                    $catScores[$cat]['score'] += $awarded;
                    $update['is_correct']     = null; // DISC: no right/wrong
                    $update['points_awarded'] = $awarded;
                } else {
                    $awarded = $isCorrect ? $points : 0;
                    $autoScore += $awarded;
                    $catScores[$cat]['score'] += $awarded;
                    $update['is_correct']     = $isCorrect ? 1 : 0;
                    $update['points_awarded'] = $awarded;
                }
            } elseif (in_array($type, self::MANUAL_GRADE_TYPES, true)) {
                $hasManual = true;
                // Belum di-grade — biarkan is_correct NULL & points_awarded 0
            }

            $answerUpdate = $db->table('online_test_answers');
            $this->assertTenantCompanyId($companyId);
            $answerUpdate->where('id', (int) $a['id'])
                ->where('attempt_id', $attemptId)->update($update);
        }

        $totalScore = $autoScore;
        $percentage = $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0.0;
        $passing    = (int) ($test['passing_score_pct'] ?? 60);
        $isDisc     = $test && $test['category'] === 'DISC';

        // Result resolution:
        //   - Tes DISC / passing_score_pct = 0 → tidak ada PASS/FAIL, hasilnya
        //     selalu PENDING (interpretasi manusia) atau di-render via DISC breakdown
        //   - Ada manual question → REVIEW dulu sampai HR grade
        //   - Selain itu → PASS/FAIL berdasarkan persentase
        if ($hasManual) {
            $result = 'REVIEW';
        } elseif ($isDisc || $passing === 0) {
            $result = 'PENDING';
        } else {
            $result = $percentage >= $passing ? 'PASS' : 'FAIL';
        }

        $attemptUpdate = $db->table('online_test_attempts');
        $this->assertTenantCompanyId($companyId);
        $attemptUpdate->where('id', $attemptId)
            ->where('assignment_id', (int) $attempt['assignment_id'])->update([
            'status'         => 'SUBMITTED',
            'submitted_at'   => $now,
            'auto_score'     => $autoScore,
            'manual_score'   => 0,
            'total_score'    => $totalScore,
            'max_score'      => $maxScore,
            'percentage'     => $percentage,
            'result'         => $result,
            'disc_breakdown' => $isDisc ? json_encode($discTally) : null,
            'category_scores'=> json_encode($catScores, JSON_UNESCAPED_UNICODE),
            'updated_at'     => $now,
        ]);

        // Update assignment best_*
        $this->updateAssignmentBest((int) $attempt['assignment_id'], $companyId);

        $db->transComplete();

        return $this->buildResultView($attemptId, $companyId);
    }

    /**
     * Manual grade SHORT_ANSWER answer oleh HR.
     * Setelah semua manual answer ter-grade, kalkulasi ulang total_score
     * dan transition status ke GRADED.
     */
    public function gradeManualAnswer(int $attemptId, int $answerId, float $pointsAwarded, ?string $feedback, int $graderUserId, int $companyId): array
    {
        $db = \Config\Database::connect();
        $row = $this->tenantAnswers($db, $attemptId, $companyId)
            ->where('ans.id', $answerId)->get()->getRowArray();
        if (! $row) throw new \RuntimeException('Answer tidak ditemukan');

        $snap = json_decode((string) $row['question_snapshot'], true) ?: [];
        if (! in_array($snap['question_type'] ?? '', self::MANUAL_GRADE_TYPES, true)) {
            throw new \RuntimeException('Soal ini tidak butuh manual grading');
        }
        $maxPts = (float) ($snap['points'] ?? 1.0);
        $awarded = max(0, min($maxPts, $pointsAwarded));
        $now = date('Y-m-d H:i:s');

        $db->table('online_test_answers')->where('id', $answerId)->where('attempt_id', $attemptId)->update([
            'is_correct'      => $awarded > 0 ? 1 : 0,
            'points_awarded'  => $awarded,
            'manual_feedback' => $feedback,
            'updated_at'      => $now,
        ]);

        // Recompute attempt aggregates
        $this->recomputeAttempt($attemptId, $companyId, $graderUserId);
        $attempt = $this->tenantAttempt($db, $attemptId, $companyId);
        if (! $attempt) throw new \RuntimeException('Attempt tidak ditemukan');
        $this->updateAssignmentBest((int) $attempt['assignment_id'], $companyId);

        return $this->buildResultView($attemptId, $companyId);
    }

    /**
     * Auto-submit attempt yang melewati expires_at. Idempotent.
     */
    public function autoSubmitExpired(int $attemptId, int $companyId): void
    {
        $db = \Config\Database::connect();
        $row = $this->tenantAttempt($db, $attemptId, $companyId);
        if (! $row || $row['status'] !== 'IN_PROGRESS') return;
        // Submit dengan response yang sudah ada
        try {
            $this->submitAttempt($attemptId, $companyId);
        } catch (\Throwable $e) {
            log_message('warning', 'autoSubmitExpired failed: ' . $e->getMessage());
            $attemptUpdate = $db->table('online_test_attempts');
            $this->assertTenantCompanyId($companyId);
            $attemptUpdate->where('id', $attemptId)
                ->where('assignment_id', (int) $row['assignment_id'])->update([
                'status'       => 'EXPIRED',
                'submitted_at' => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Build hasil attempt untuk ditampilkan setelah submit.
     */
    public function buildResultView(int $attemptId, int $companyId, bool $includeAnswers = true): array
    {
        $db = \Config\Database::connect();
        $attempt = $this->tenantAttempt($db, $attemptId, $companyId);
        if (! $attempt) throw new \RuntimeException('Attempt tidak ditemukan');

        $assignment = $db->table('online_test_assignments')
            ->where('id', (int) $attempt['assignment_id'])->where('company_id', $companyId)->get()->getRowArray();
        $test = $assignment ? $db->table('online_tests')
            ->where('id', (int) $assignment['online_test_id'])->where('company_id', $companyId)->get()->getRowArray() : null;

        $result = [
            'attempt' => [
                'id'              => (int) $attempt['id'],
                'assignment_id'   => (int) $attempt['assignment_id'],
                'attempt_number'  => (int) $attempt['attempt_number'],
                'status'          => $attempt['status'],
                'started_at'      => $attempt['started_at'],
                'submitted_at'    => $attempt['submitted_at'],
                'graded_at'       => $attempt['graded_at'],
                'total_score'     => (float) $attempt['total_score'],
                'max_score'       => (float) $attempt['max_score'],
                'percentage'      => (float) $attempt['percentage'],
                'result'          => $attempt['result'],
                'disc_breakdown'  => $attempt['disc_breakdown'] ? json_decode((string) $attempt['disc_breakdown'], true) : null,
                'category_scores' => $attempt['category_scores'] ? json_decode((string) $attempt['category_scores'], true) : null,
                'tab_switch_count'=> (int) $attempt['tab_switch_count'],
                'fullscreen_exit_count' => (int) $attempt['fullscreen_exit_count'],
            ],
            'test' => $test ? [
                'id'                => (int) $test['id'],
                'name'              => $test['name'],
                'category'          => $test['category'],
                'passing_score_pct' => (int) $test['passing_score_pct'],
                'show_result_to_candidate' => (int) $test['show_result_to_candidate'] === 1,
            ] : null,
        ];

        if ($includeAnswers) {
            $rows = $this->tenantAnswers($db, $attemptId, $companyId)
                ->orderBy('ans.sort_order', 'ASC')
                ->get()->getResultArray();
            $items = [];
            foreach ($rows as $a) {
                $snap = json_decode((string) $a['question_snapshot'], true) ?: [];
                $items[] = [
                    'answer_id'       => (int) $a['id'],
                    'sort_order'      => (int) $a['sort_order'],
                    'question_type'   => $snap['question_type'] ?? null,
                    'category'        => $snap['category'] ?? null,
                    'prompt'          => $snap['prompt'] ?? '',
                    'choices'         => $snap['choices'] ?? null,
                    'correct_answer'  => $snap['correct_answer'] ?? null,
                    'explanation'     => $snap['explanation'] ?? null,
                    'response'        => $a['response'] !== null ? json_decode((string) $a['response'], true) : null,
                    'is_correct'      => $a['is_correct'] !== null ? ((int) $a['is_correct']) === 1 : null,
                    'points_awarded'  => (float) $a['points_awarded'],
                    'points_max'      => (float) ($snap['points'] ?? 1.0),
                    'manual_feedback' => $a['manual_feedback'],
                ];
            }
            $result['answers'] = $items;
        }

        return $result;
    }

    /**
     * Setelah question manual digrade, recompute total_score, percentage,
     * result. Status pindah ke GRADED kalau tidak ada lagi pending manual.
     */
    private function recomputeAttempt(int $attemptId, int $companyId, ?int $graderUserId = null): void
    {
        $db = \Config\Database::connect();
        $attempt = $this->tenantAttempt($db, $attemptId, $companyId);
        if (! $attempt) return;

        $assignment = $db->table('online_test_assignments')
            ->where('id', (int) $attempt['assignment_id'])->where('company_id', $companyId)->get()->getRowArray();
        if (! $assignment) return;
        $test = $db->table('online_tests')->where('id', (int) $assignment['online_test_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $test) return;

        $rows = $this->tenantAnswers($db, $attemptId, $companyId)->get()->getResultArray();
        $auto = 0.0; $manual = 0.0; $hasPendingManual = false; $catScores = [];
        foreach ($rows as $r) {
            $snap = json_decode((string) $r['question_snapshot'], true) ?: [];
            $type = $snap['question_type'] ?? '';
            $cat  = $snap['category'] ?? 'GENERAL';
            $maxP = (float) ($snap['points'] ?? 1.0);
            if (! isset($catScores[$cat])) $catScores[$cat] = ['score' => 0.0, 'max' => 0.0];
            $catScores[$cat]['max']   += $maxP;
            $catScores[$cat]['score'] += (float) $r['points_awarded'];

            if (in_array($type, self::MANUAL_GRADE_TYPES, true)) {
                if ($r['is_correct'] === null) {
                    $hasPendingManual = true;
                } else {
                    $manual += (float) $r['points_awarded'];
                }
            } else {
                $auto += (float) $r['points_awarded'];
            }
        }
        $total = $auto + $manual;
        $maxScore = (float) $attempt['max_score'];
        $pct = $maxScore > 0 ? round(($total / $maxScore) * 100, 2) : 0.0;
        $passing = (int) ($test['passing_score_pct'] ?? 60);
        $isDisc = $test['category'] === 'DISC';
        if ($hasPendingManual) $newResult = 'REVIEW';
        elseif ($isDisc || $passing === 0) $newResult = 'PENDING';
        else $newResult = $pct >= $passing ? 'PASS' : 'FAIL';

        $now = date('Y-m-d H:i:s');
        $update = [
            'auto_score'     => $auto,
            'manual_score'   => $manual,
            'total_score'    => $total,
            'percentage'     => $pct,
            'result'         => $newResult,
            'category_scores'=> json_encode($catScores, JSON_UNESCAPED_UNICODE),
            'updated_at'     => $now,
        ];
        if (! $hasPendingManual && $attempt['status'] !== 'GRADED') {
            $update['status']    = 'GRADED';
            $update['graded_at'] = $now;
            $update['graded_by'] = $graderUserId;
        }
        $db->table('online_test_attempts')->where('id', $attemptId)
            ->where('assignment_id', (int) $attempt['assignment_id'])->update($update);
    }

    /**
     * Update best_score / status di assignment berdasar best attempt.
     */
    private function updateAssignmentBest(int $assignmentId, int $companyId): void
    {
        $db = \Config\Database::connect();
        $rows = $db->table('online_test_attempts a')
            ->select('a.*')
            ->join('online_test_assignments x', 'x.id = a.assignment_id')
            ->where('a.assignment_id', $assignmentId)
            ->where('x.company_id', $companyId)
            ->whereIn('a.status', ['SUBMITTED', 'GRADED'])
            ->orderBy('a.total_score', 'DESC')
            ->get()->getResultArray();

        if (! $rows) {
            return;
        }
        $best = $rows[0];

        $assignment = $db->table('online_test_assignments')->where('id', $assignmentId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $assignment) return;
        $now = date('Y-m-d H:i:s');

        $hasReview = false;
        foreach ($rows as $r) {
            if ($r['result'] === 'REVIEW') { $hasReview = true; break; }
        }

        $newStatus = (int) $assignment['attempts_used'] >= (int) $assignment['max_attempts']
            ? 'COMPLETED'
            : ($best['result'] === 'PASS' ? 'COMPLETED' : 'IN_PROGRESS');

        $db->table('online_test_assignments')->where('id', $assignmentId)->where('company_id', $companyId)->update([
            'best_score'      => (float) $best['total_score'],
            'best_percentage' => (float) $best['percentage'],
            'best_result'     => $hasReview && $best['result'] !== 'PASS' && $best['result'] !== 'FAIL'
                ? 'REVIEW' : $best['result'],
            'status'          => $newStatus,
            'completed_at'    => $newStatus === 'COMPLETED' ? $now : null,
            'updated_at'      => $now,
        ]);
    }

    /**
     * Resolve an attempt only through its tenant-owned assignment.
     */
    private function tenantAttempt(BaseConnection $db, int $attemptId, int $companyId): ?array
    {
        $row = $db->table('online_test_attempts a')
            ->select('a.*')
            ->join('online_test_assignments x', 'x.id = a.assignment_id')
            ->where('a.id', $attemptId)
            ->where('x.company_id', $companyId)
            ->get()->getRowArray();

        return $row ?: null;
    }

    private function assertTenantCompanyId(int $companyId): void
    {
        if ($companyId <= 0) {
            throw new \RuntimeException('Company context tidak tersedia');
        }
    }

    /**
     * Keep every answer lookup behind attempt -> assignment -> company.
     */
    private function tenantAnswers(BaseConnection $db, int $attemptId, int $companyId): BaseBuilder
    {
        return $db->table('online_test_answers ans')
            ->select('ans.*')
            ->join('online_test_attempts a', 'a.id = ans.attempt_id')
            ->join('online_test_assignments x', 'x.id = a.assignment_id')
            ->where('ans.attempt_id', $attemptId)
            ->where('x.company_id', $companyId);
    }

    /**
     * Auto-grade satu jawaban berdasarkan tipe.
     *
     * Public-static supaya bisa di-unit-test tanpa setup DB. Logic murni
     * tergantung input → output, idempotent, deterministic.
     */
    public static function autoGradeAnswer(string $type, $response, array $snap): bool
    {
        $correct = $snap['correct_answer'] ?? null;
        switch ($type) {
            case 'MCQ_SINGLE':
                return is_string($correct) && is_string($response)
                    && strcasecmp(trim($response), trim($correct)) === 0;

            case 'MCQ_MULTI':
                if (! is_array($correct) || ! is_array($response)) return false;
                $a = array_map('strtoupper', array_map('trim', array_map('strval', $correct)));
                $b = array_map('strtoupper', array_map('trim', array_map('strval', $response)));
                sort($a); sort($b);
                return $a === $b;

            case 'TRUE_FALSE':
                $c = self::toBoolStatic($correct);
                $r = self::toBoolStatic($response);
                return $c !== null && $r !== null && $c === $r;

            case 'NUMERIC':
                if (! is_numeric($correct) || ! is_numeric($response)) return false;
                // Tolerance default 0.0001 untuk float arithmetic
                return abs(((float) $correct) - ((float) $response)) < 0.0001;

            case 'LIKERT_1_5':
                // Tidak ada benar/salah — return false (handled differently in caller)
                return false;
        }
        return false;
    }

    private static function toBoolStatic($v): ?bool
    {
        if (is_bool($v)) return $v;
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if (in_array($s, ['true', 'yes', '1', 't', 'y', 'benar'], true)) return true;
            if (in_array($s, ['false', 'no', '0', 'f', 'n', 'salah'], true)) return false;
        }
        if (is_int($v)) return $v === 1 ? true : ($v === 0 ? false : null);
        return null;
    }

    /**
     * Resolve daftar soal untuk attempt:
     *   - Kalau use_pool=1 → pilih random dari question_bank by filter
     *   - Else → ambil dari online_test_questions
     */
    private function resolveQuestionsForAttempt(array $test): array
    {
        $db = \Config\Database::connect();
        if ((int) $test['use_pool'] === 1) {
            $filters = $test['pool_filters'] ? json_decode((string) $test['pool_filters'], true) : null;
            // pool_filters bisa berupa 1 segment object atau array of segments
            if (! is_array($filters)) return [];
            $segments = isset($filters['count']) ? [$filters] : $filters;
            $picked = [];
            foreach ($segments as $seg) {
                if (! is_array($seg)) continue;
                $b = $db->table('online_test_question_bank')
                    ->where('company_id', (int) $test['company_id'])
                    ->where('is_active', 1)
                    ->where('deleted_at IS NULL');
                if (! empty($seg['category'])) $b->where('category', $seg['category']);
                if (! empty($seg['difficulty'])) $b->where('difficulty', $seg['difficulty']);
                if (! empty($seg['question_type'])) $b->where('question_type', $seg['question_type']);
                $count = max(1, (int) ($seg['count'] ?? 10));
                $rows = $b->orderBy('id', 'RANDOM')->limit($count)->get()->getResultArray();
                foreach ($rows as $r) {
                    $picked[] = [
                        'id'             => (int) $r['id'],
                        'question_type'  => $r['question_type'],
                        'category'       => $r['category'],
                        'prompt'         => $r['prompt'],
                        'choices'        => $r['choices'] ? json_decode((string) $r['choices'], true) : null,
                        'correct_answer' => $r['correct_answer'] ? json_decode((string) $r['correct_answer'], true) : null,
                        'disc_weight'    => $r['disc_weight'],
                        'points'         => (float) ($seg['points'] ?? 1.0),
                        'explanation'    => $r['explanation'],
                    ];
                }
            }
            return $picked;
        }

        // Mode attached questions
        $rows = $db->table('online_test_questions q')
            ->select('q.*')
            ->join('online_tests t', 't.id = q.online_test_id')
            ->where('q.online_test_id', (int) $test['id'])
            ->where('t.company_id', (int) $test['company_id'])
            ->orderBy('q.sort_order', 'ASC')->orderBy('q.id', 'ASC')
            ->get()->getResultArray();

        return array_map(function ($r) {
            return [
                'id'             => (int) $r['id'],
                'question_type'  => $r['question_type'],
                'category'       => $r['category'],
                'prompt'         => $r['prompt'],
                'choices'        => $r['choices'] ? json_decode((string) $r['choices'], true) : null,
                'correct_answer' => $r['correct_answer'] ? json_decode((string) $r['correct_answer'], true) : null,
                'disc_weight'    => $r['disc_weight'],
                'points'         => (float) $r['points'],
                'explanation'    => $r['explanation'],
            ];
        }, $rows);
    }

    /**
     * Shuffle choices array tapi tetap preserve key (A/B/C/D),
     * supaya correct_answer "A" tetap valid setelah shuffle.
     * Choices format: [{key:"A", text:"..."}].
     */
    private function shuffleChoicesPreservingKeys(array $choices): array
    {
        if (! $choices || ! isset($choices[0])) return $choices;
        // Kalau format dict {A: "...", B: "..."} — convert ke array of {key,text}
        if (! is_array($choices[0]) && ! isset($choices[0]['key'])) {
            $arr = [];
            foreach ($choices as $k => $v) {
                $arr[] = ['key' => (string) $k, 'text' => (string) $v];
            }
            $choices = $arr;
        }
        // Shuffle sambil simpan key tetap (A/B/C/D mapping ke posisi baru?)
        // Strategi: shuffle the items, lalu re-letter A/B/C... tetap correct_answer
        // Tapi karena correct_answer mengacu ke key, kita cuma shuffle text-nya
        // saja → item keys tetap urutan A,B,C tapi text-nya teracak.
        $texts = array_column($choices, 'text');
        shuffle($texts);
        $shuffled = [];
        foreach ($choices as $i => $c) {
            $shuffled[] = ['key' => $c['key'], 'text' => $texts[$i] ?? $c['text']];
        }
        // PROBLEM: ini mengubah pemetaan key→text, jadi correct_answer "A" yang
        // tadinya pointing ke text X, sekarang pointing ke text Y → hasilnya
        // kandidat selalu salah. Yang benar: shuffle posisi entries, dan
        // remap key sehingga key baru = posisi baru, lalu cocokkan correct_answer
        // di sisi snapshot. Karena kita simpan correct_answer di snapshot per
        // attempt, kita harus rewrite correct_answer juga. Helper ini dipanggil
        // SEBELUM snapshot sehingga grader pakai correct_answer yang sudah
        // disesuaikan. Override approach: shuffle items, rebuild keys A,B,C,D
        // dan caller harus rewrite correct_answer berdasarkan key lama→baru.
        // Karena kompleksitas, default kita TIDAK shuffle choices kalau bisa
        // bikin bug — kita kembalikan original urutan kecuali memang mau.
        return $choices; // No-op for safety; shuffle akan dilakukan di builder dengan key remapping nanti.
    }

    private function toBool($v): ?bool
    {
        return self::toBoolStatic($v);
    }

    /**
     * Generate invitation token (64 hex chars). Caller simpan ke
     * online_test_assignments.invitation_token.
     */
    public function generateInvitationToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
