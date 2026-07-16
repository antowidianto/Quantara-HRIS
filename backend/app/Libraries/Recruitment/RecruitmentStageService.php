<?php

declare(strict_types=1);

namespace App\Libraries\Recruitment;

use App\Repositories\Recruitment\RecruitmentRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class RecruitmentStageService
{
    private readonly BaseConnection $db;
    private readonly RecruitmentRepository $repository;

    public function __construct(?BaseConnection $connection = null)
    {
        $this->db = $connection ?? Database::connect();
        $this->repository = new RecruitmentRepository($this->db);
    }

    /** @param array<string, mixed> $params */
    public function moveStage(int $candidateId, int $companyId, string $newStage, array $params = []): int
    {
        $candidate = $this->repository->findCandidate($candidateId, $companyId);
        if (! $candidate) {
            throw new \InvalidArgumentException('Candidate not found');
        }
        $pipeline = $this->repository->resolvePipelineStages((int) $candidate['job_posting_id'], $companyId);
        $newStage = strtoupper(trim($newStage));
        $this->assertTransitionAllowed($candidate, $companyId, $newStage, $pipeline);

        $now = date('Y-m-d H:i:s');
        $this->db->transBegin();
        try {
            $this->db->table('candidate_stages')
                ->where('candidate_id', $candidateId)
                ->where('company_id', $companyId)
                ->where('result', 'PENDING')
                ->update(['result' => 'PASSED', 'completed_at' => $now, 'updated_at' => $now]);

            $stage = [
                'company_id' => $companyId,
                'candidate_id' => $candidateId,
                'stage' => $newStage,
                'result' => $newStage === 'HIRED' ? 'PASSED' : 'PENDING',
                'scheduled_at' => $params['scheduled_at'] ?? null,
                'interviewer_id' => $params['interviewer_id'] ?? null,
                'feedback' => $params['feedback'] ?? null,
                'rating' => $params['rating'] ?? null,
                'created_by' => $params['created_by'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($newStage === 'HIRED') {
                $stage['completed_at'] = $now;
            }
            $this->db->table('candidate_stages')->insert($stage);
            $stageId = (int) $this->db->insertID();

            $this->db->table('candidates')
                ->where('id', $candidateId)
                ->where('company_id', $companyId)
                ->update(['current_stage' => $newStage, 'updated_at' => $now]);
            $this->recordHistory($candidateId, $companyId, $newStage, $params, $now);

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Gagal menyimpan perpindahan stage kandidat');
            }
            $this->db->transCommit();
            return $stageId;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function reject(int $candidateId, int $companyId, string $reason, ?int $userId = null): void
    {
        $candidate = $this->repository->findCandidate($candidateId, $companyId);
        if (! $candidate) {
            throw new \InvalidArgumentException('Candidate not found');
        }
        if (in_array($candidate['current_stage'], RecruitmentService::TERMINAL_STAGES, true)) {
            throw new \RuntimeException("Candidate sudah di stage final {$candidate['current_stage']}");
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Alasan penolakan wajib diisi');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->transBegin();
        try {
            $this->db->table('candidate_stages')
                ->where('candidate_id', $candidateId)
                ->where('company_id', $companyId)
                ->where('result', 'PENDING')
                ->update(['result' => 'FAILED', 'completed_at' => $now, 'updated_at' => $now]);
            $this->db->table('candidate_stages')->insert([
                'company_id' => $companyId,
                'candidate_id' => $candidateId,
                'stage' => 'REJECTED',
                'result' => 'FAILED',
                'completed_at' => $now,
                'feedback' => $reason,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->db->table('candidates')
                ->where('id', $candidateId)
                ->where('company_id', $companyId)
                ->update([
                    'current_stage' => 'REJECTED',
                    'rejection_reason' => $reason,
                    'updated_at' => $now,
                ]);
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Gagal menolak kandidat');
            }
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $candidate @param list<array<string, mixed>> $pipeline */
    private function assertTransitionAllowed(array $candidate, int $companyId, string $newStage, array $pipeline): void
    {
        if ($pipeline === []) {
            if (! in_array($newStage, RecruitmentService::STAGES, true)) {
                throw new \InvalidArgumentException("Stage tidak valid: {$newStage}");
            }
            if (in_array($candidate['current_stage'], RecruitmentService::TERMINAL_STAGES, true)
                && ! in_array($newStage, RecruitmentService::TERMINAL_STAGES, true)) {
                throw new \RuntimeException(
                    "Candidate sudah di stage final {$candidate['current_stage']}, tidak bisa kembali ke {$newStage}",
                );
            }
            if (in_array($newStage, RecruitmentService::POST_ONLINE_TEST_STAGES, true)) {
                $this->assertAllAssignmentsDone((int) $candidate['id'], $companyId);
            }
            return;
        }

        $stageCodes = array_column($pipeline, 'code');
        if (! in_array($newStage, $stageCodes, true)) {
            throw new \InvalidArgumentException(
                "Stage '{$newStage}' tidak ada di pipeline template kandidat. Stage valid: " . implode(', ', $stageCodes),
            );
        }
        $target = null;
        $terminalCodes = [];
        foreach ($pipeline as $stage) {
            if ((string) $stage['code'] === $newStage) {
                $target = $stage;
            }
            if ((int) $stage['is_terminal'] === 1) {
                $terminalCodes[] = (string) $stage['code'];
            }
        }
        if (in_array($candidate['current_stage'], $terminalCodes, true)
            && ! in_array($newStage, $terminalCodes, true)) {
            throw new \RuntimeException(
                "Candidate sudah di stage final {$candidate['current_stage']}, tidak bisa kembali ke {$newStage}",
            );
        }
        $this->assertPipelineTestGate((int) $candidate['id'], $companyId, $target, $pipeline);
    }

    /** @param array<string, mixed>|null $target @param list<array<string, mixed>> $pipeline */
    private function assertPipelineTestGate(int $candidateId, int $companyId, ?array $target, array $pipeline): void
    {
        if (! $target) {
            return;
        }
        $targetType = (string) ($target['stage_type'] ?? '');
        $targetOrder = (int) ($target['order_index'] ?? 0);
        if (in_array($targetType, ['REJECTED', 'WITHDRAWN', 'ONLINE_TEST'], true)) {
            return;
        }
        $priorTests = array_values(array_filter($pipeline, static fn (array $stage): bool =>
            (string) ($stage['stage_type'] ?? '') === 'ONLINE_TEST'
            && (int) ($stage['order_index'] ?? 0) < $targetOrder
        ));
        if ($priorTests === []) {
            return;
        }
        $categoryGate = array_filter($priorTests, static fn (array $stage): bool => ! empty($stage['required_test_category_id']));
        if ($categoryGate === []) {
            $this->assertAllAssignmentsDone($candidateId, $companyId);
            return;
        }

        foreach ($categoryGate as $stage) {
            $categoryId = (int) $stage['required_test_category_id'];
            $rows = $this->repository->onlineTestAssignments($candidateId, $companyId, $categoryId);
            if ($rows === []) {
                $name = $this->repository->onlineTestCategoryName($categoryId, $companyId);
                throw new \RuntimeException(
                    "Stage '{$stage['name']}' butuh test kategori '{$name}', tapi belum ada assignment untuk kandidat ini.",
                );
            }
            $complete = array_filter($rows, static fn (array $row): bool =>
                (string) $row['status'] === 'COMPLETED'
                && in_array((string) ($row['best_result'] ?? ''), ['PASSED', 'FAILED'], true)
            );
            if ($complete === []) {
                $status = RecruitmentService::analyzeOnlineTestRows($rows);
                throw new \RuntimeException(
                    "Stage '{$stage['name']}': " . ($status['blocker_reason'] ?? 'test belum selesai atau belum di-grade.'),
                );
            }
        }
    }

    private function assertAllAssignmentsDone(int $candidateId, int $companyId): void
    {
        $status = RecruitmentService::analyzeOnlineTestRows(
            $this->repository->onlineTestAssignments($candidateId, $companyId),
        );
        if ($status['has_assignments'] && ! $status['all_done']) {
            throw new \RuntimeException((string) $status['blocker_reason']);
        }
    }

    /** @param array<string, mixed> $params */
    private function recordHistory(int $candidateId, int $companyId, string $stage, array $params, string $now): void
    {
        if (! $this->db->tableExists('candidate_stage_history')) {
            return;
        }
        $open = $this->db->table('candidate_stage_history')
            ->where('candidate_id', $candidateId)
            ->where('company_id', $companyId)
            ->where('exited_at IS NULL')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        if ($open) {
            $entered = strtotime((string) $open['entered_at']);
            $this->db->table('candidate_stage_history')
                ->where('id', (int) $open['id'])
                ->where('company_id', $companyId)
                ->update([
                    'exited_at' => $now,
                    'duration_hours' => $entered > 0 ? round((time() - $entered) / 3600, 2) : null,
                ]);
        }
        $this->db->table('candidate_stage_history')->insert([
            'company_id' => $companyId,
            'candidate_id' => $candidateId,
            'stage' => $stage,
            'entered_at' => $now,
            'by_user_id' => $params['created_by'] ?? null,
            'note' => $params['feedback'] ?? null,
            'created_at' => $now,
        ]);
    }
}
