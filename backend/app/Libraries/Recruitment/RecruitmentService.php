<?php

namespace App\Libraries\Recruitment;

use App\Repositories\Recruitment\RecruitmentRepository;

/**
 * RecruitmentService
 *
 * Logika domain Recruitment / ATS.
 *
 * Tanggung jawab:
 *  1. Generate `reference_number` posting unik (`JOB-<YEAR>-<5digit>`).
 *  2. Move candidate antar stage (track history di `candidate_stages`).
 *  3. Convert candidate HIRED ke `employees` row (clone profile dgn
 *     auto-generate employee_number, link `converted_employee_id`),
 *     trigger ONBOARD_DEFAULT via `OnboardingService`.
 *  4. Validasi: hanya HIRED candidate bisa di-convert; tidak boleh
 *     double-convert.
 */
class RecruitmentService
{
    private function repository(): RecruitmentRepository
    {
        return new RecruitmentRepository();
    }

    public const STAGES = [
        'NEW',
        'SCREENING',
        'ONLINE_TEST',
        'INTERVIEW_HR',
        'INTERVIEW_USER',
        'OFFER',
        'HIRED',
        'REJECTED',
        'WITHDRAWN',
    ];

    public const TERMINAL_STAGES = ['HIRED', 'REJECTED', 'WITHDRAWN'];

    public function nextJobReferenceNumber(int $companyId): string
    {
        return $this->repository()->nextJobReferenceNumber($companyId, date('Y'));
    }

    /** Stage di mana online test biasanya dikerjakan — saat keluar dari
     * stage ini ke stage lebih lanjut (atau langsung HIRED), kita harus
     * pastikan semua test assignment-nya sudah selesai dikerjakan & di-grade
     * oleh HR. */
    public const ONLINE_TEST_STAGE = 'ONLINE_TEST';

    /** Stage yang dianggap "lebih lanjut" dari ONLINE_TEST. Pindah ke
     * salah satu dari ini wajib lewat gate online test verification. */
    public const POST_ONLINE_TEST_STAGES = [
        'INTERVIEW_HR', 'INTERVIEW_USER', 'OFFER', 'HIRED',
    ];

    /**
     * Move candidate ke stage baru. Tutup stage lama (kalau masih PENDING),
     * buat row stage baru dengan PENDING, update candidate.current_stage.
     *
     * Sprint 23+ — gate ONLINE_TEST: kalau kandidat punya assignment online
     * test yang belum selesai (status != COMPLETED) atau belum di-grade
     * tuntas (best_result PENDING/REVIEW), tolak pemindahan ke stage di
     * atas online test (INTERVIEW_HR / INTERVIEW_USER / OFFER / HIRED)
     * supaya HR tidak accidentally lewatkan tahap test atau review-nya.
     *
     * Migration #065 — kalau job_posting kandidat pakai pipeline_template,
     * stage divalidasi terhadap stages template (bisa custom seperti
     * PSIKOTEST, FGD, INTERVIEW_BOD). Online test gate juga lebih granular:
     * cek per-stage `required_test_category_id` (kalau di-set), bukan blanket
     * check semua test.
     */
    public function moveStage(int $candidateId, int $companyId, string $newStage, array $params = []): int
    {
        return (new RecruitmentStageService())->moveStage($candidateId, $companyId, $newStage, $params);
    }

    /**
     * Sprint 9 / H1.4 — Funnel metrics per job_posting atau global.
     *
     * Ambil rata-rata `duration_hours` per stage dari `candidate_stage_history`
     * untuk kandidat dalam `job_posting_id` atau company tertentu (dalam
     * rentang tanggal opsional).
     *
     * @param int|null    $jobPostingId Filter ke job posting tertentu
     * @param int|null    $companyId    Filter ke company (wajib kalau jobPostingId NULL)
     * @param string|null $since        ISO date YYYY-MM-DD, default 90 hari ke belakang
     *
     * @return array<int, array{stage: string, sample: int, avg_hours: float, max_hours: float}>
     */
    public function funnelMetrics(?int $jobPostingId, int $companyId, ?string $since = null): array
    {
        if ($companyId <= 0) {
            throw new \DomainException('Active company context is required');
        }
        $rows = $this->repository()->funnelMetrics(
            $jobPostingId,
            $companyId,
            $since ?: date('Y-m-d', strtotime('-90 days')),
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'stage'      => (string) $r['stage'],
                'sample'     => (int) $r['sample'],
                'avg_hours'  => round((float) $r['avg_hours'], 2),
                'max_hours'  => round((float) $r['max_hours'], 2),
            ];
        }
        return $out;
    }

    /**
     * Sprint 9 / H1.4 — kandidat yang stagnan > $thresholdDays hari di stage
     * non-terminal saat ini. Dipakai command notifikasi recruiter +
     * dashboard alert.
     *
     * @return array<int, array<string,mixed>>
     */
    public function staleCandidates(int $companyId, int $thresholdDays = 7): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$thresholdDays} days"));
        return $this->repository()->staleCandidates($companyId, $cutoff, self::TERMINAL_STAGES);
    }

    /**
     * Sprint 23+ — verifikasi status semua online_test_assignments milik
     * kandidat. Dipakai sebagai gate sebelum HR boleh maju dari stage
     * ONLINE_TEST ke stage berikutnya.
     *
     * Aturan:
     *   - Kalau kandidat tidak punya assignment sama sekali → boleh lewat
     *     (asumsi posisi ini memang tidak butuh online test).
     *   - Kalau ada assignment dengan status PENDING/IN_PROGRESS → blocked
     *     (kandidat belum mengerjakan / sedang mengerjakan).
     *   - Kalau ada assignment status COMPLETED tapi best_result masih
     *     PENDING/REVIEW → blocked (HR belum review essay/manual grade).
     *   - Status EXPIRED/CANCELLED tidak block — anggap sudah selesai
     *     (mungkin kandidat tidak mengerjakan tepat waktu, HR sengaja batal).
     *
     * @return array{
     *   has_assignments: bool,
     *   all_done: bool,
     *   pending: int,
     *   in_progress: int,
     *   awaiting_review: int,
     *   blocker_reason: string|null,
     * }
     */
    public function onlineTestCompletionStatus(int $candidateId, int $companyId): array
    {
        if (! $this->repository()->findCandidate($candidateId, $companyId)) {
            throw new \InvalidArgumentException('Candidate not found');
        }
        return self::analyzeOnlineTestRows(
            $this->repository()->onlineTestAssignments($candidateId, $companyId),
        );
    }

    /**
     * Pure analyzer untuk daftar assignment online test seorang kandidat.
     * Dipisah dari query DB supaya bisa di-unit-test tanpa setup migrasi.
     *
     * @param array<int, array{status?: string, best_result?: string|null, test_name?: string|null}> $rows
     * @return array{
     *   has_assignments: bool,
     *   all_done: bool,
     *   pending: int,
     *   in_progress: int,
     *   awaiting_review: int,
     *   blocker_reason: string|null,
     * }
     */
    public static function analyzeOnlineTestRows(array $rows): array
    {
        if (empty($rows)) {
            return [
                'has_assignments' => false,
                'all_done'        => true,
                'pending'         => 0,
                'in_progress'     => 0,
                'awaiting_review' => 0,
                'blocker_reason'  => null,
            ];
        }

        $pending = 0;
        $inProgress = 0;
        $awaitingReview = 0;
        $blockers = [];
        foreach ($rows as $r) {
            $status = (string) ($r['status'] ?? '');
            $result = (string) ($r['best_result'] ?? 'PENDING');
            $testName = (string) ($r['test_name'] ?? 'Online Test');
            if ($status === 'PENDING') {
                $pending++;
                $blockers[] = "{$testName}: belum dikerjakan kandidat";
            } elseif ($status === 'IN_PROGRESS') {
                $inProgress++;
                $blockers[] = "{$testName}: sedang dikerjakan";
            } elseif ($status === 'COMPLETED' && in_array($result, ['PENDING', 'REVIEW'], true)) {
                $awaitingReview++;
                $blockers[] = "{$testName}: menunggu review HR (essay/manual grading)";
            }
            // EXPIRED & CANCELLED tidak block
        }

        $allDone = ($pending + $inProgress + $awaitingReview) === 0;
        $reason = null;
        if (! $allDone) {
            $reason = 'Tidak bisa maju ke stage berikutnya — online test belum selesai: '
                . implode('; ', array_slice($blockers, 0, 3))
                . (count($blockers) > 3 ? ' (+' . (count($blockers) - 3) . ' lainnya)' : '')
                . '.';
        }

        return [
            'has_assignments' => true,
            'all_done'        => $allDone,
            'pending'         => $pending,
            'in_progress'     => $inProgress,
            'awaiting_review' => $awaitingReview,
            'blocker_reason'  => $reason,
        ];
    }

    /**
     * Reject candidate dengan alasan. Stage berubah ke REJECTED.
     */
    public function reject(int $candidateId, int $companyId, string $reason, ?int $userId = null): void
    {
        (new RecruitmentStageService())->reject($candidateId, $companyId, $reason, $userId);
    }

    /**
     * Convert candidate dengan offer ACCEPTED ke employee dan stage HIRED.
     *
     * @return array{employee_id: int, user_id: ?int, user_email: ?string, temp_password: ?string, auto_completed_tasks: int}
     *     employee_id baru, plus credential ONE-TIME (kalau auto-create
     *     user akun berhasil) yang harus diteruskan ke HR untuk
     *     dikomunikasikan ke karyawan baru.
     */
    public function convertToEmployee(int $candidateId, int $companyId, array $employeeOverrides = []): array
    {
        return (new RecruitmentHireService())->convertToEmployee($candidateId, $companyId, $employeeOverrides);
    }

    /**
     * Generate employee_number unik. Format `EMP-<YEAR>-<5digit>`.
     */
    public function nextEmployeeNumber(int $companyId): string
    {
        return (new RecruitmentHireService())->nextEmployeeNumber($companyId);
    }

    /**
     * Stats untuk dashboard recruitment. Kalau job_posting punya pipeline
     * template, return dict pakai stage codes dari template (urutan
     * mengikuti order_index). Kalau tidak, fallback ke STAGES hardcoded.
     */
    public function pipelineStats(int $companyId, ?int $jobPostingId = null): array
    {
        $rows = $this->repository()->pipelineCounts($companyId, $jobPostingId);

        // Resolve template buat ordering kalau ada
        $tplStages = $jobPostingId !== null ? $this->resolvePipelineStages($jobPostingId, $companyId) : [];
        if (! empty($tplStages)) {
            $stats = [];
            foreach ($tplStages as $s) $stats[$s['code']] = 0;
        } else {
            $stats = array_fill_keys(self::STAGES, 0);
        }
        foreach ($rows as $r) {
            $stage = (string) $r['current_stage'];
            // Selalu hitung — bahkan stage di luar template (kandidat lama
            // sebelum template di-assign) supaya HR aware ada inkonsistensi.
            if (! isset($stats[$stage])) $stats[$stage] = 0;
            $stats[$stage] = (int) $r['total'];
        }
        return $stats;
    }

    /**
     * Resolve daftar stages aktif untuk satu job_posting.
     *
     * Algoritma:
     *   1. Lihat `job_postings.pipeline_template_id`.
     *   2. Kalau NULL, fallback ke template `is_default=1` di company.
     *   3. Kalau masih tidak ada, return array kosong → caller pakai
     *      STAGES hardcoded (backward compat).
     *
     * @return array<int, array{id:int, code:string, name:string, stage_type:string,
     *   order_index:int, required_test_category_id:?int, is_terminal:int,
     *   is_optional:int, sla_hours:?int}>
     */
    public function resolvePipelineStages(int $jobPostingId, int $companyId): array
    {
        return $this->repository()->resolvePipelineStages($jobPostingId, $companyId);
    }

}
