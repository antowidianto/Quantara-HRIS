<?php

declare(strict_types=1);

namespace App\Libraries\Recruitment;

use App\Repositories\Recruitment\OnlineTestRepository;
use CodeIgniter\Database\BaseConnection;

/** Application use cases for tenant-owned online-test definitions. */
final class OnlineTestAdminService
{
    private BaseConnection $db;
    private OnlineTestRepository $repository;

    public function __construct(?BaseConnection $db = null, ?OnlineTestRepository $repository = null)
    {
        $this->db = $db ?? \Config\Database::connect();
        $this->repository = $repository ?? new OnlineTestRepository($this->db);
    }

    /** @return array{id: int, code: string} */
    public function createTest(int $companyId, int $actorUserId, array $input): array
    {
        $this->assertCompany($companyId);
        $code = trim((string) ($input['code'] ?? ''));
        if ($code === '') {
            $code = strtoupper(($input['category'] ?? 'TEST') . '-' . date('Y') . '-' . substr(uniqid(), -5));
        }

        $exists = $this->db->table('online_tests')->where('company_id', $companyId)
            ->where('code', $code)->countAllResults();
        if ($exists > 0) {
            throw new OnlineTestAdminException('Kode test sudah dipakai', 422, 'TEST_CODE_DUPLICATE');
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id' => $companyId,
            'code' => $code,
            'name' => $input['name'],
            'category' => $input['category'] ?? 'CUSTOM',
            'description' => $input['description'] ?? null,
            'instructions_html' => $input['instructions_html'] ?? null,
            'duration_minutes' => (int) $input['duration_minutes'],
            'passing_score_pct' => (int) ($input['passing_score_pct'] ?? 60),
            'max_attempts' => max(1, (int) ($input['max_attempts'] ?? 1)),
            'shuffle_questions' => ! empty($input['shuffle_questions']) ? 1 : 0,
            'shuffle_choices' => ! empty($input['shuffle_choices']) ? 1 : 0,
            'show_result_to_candidate' => array_key_exists('show_result_to_candidate', $input)
                ? (! empty($input['show_result_to_candidate']) ? 1 : 0) : 1,
            'use_pool' => ! empty($input['use_pool']) ? 1 : 0,
            'pool_filters' => isset($input['pool_filters']) ? json_encode($input['pool_filters']) : null,
            'is_active' => array_key_exists('is_active', $input) ? (! empty($input['is_active']) ? 1 : 0) : 1,
            'created_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->db->table('online_tests')->insert($insert);
        $id = (int) $this->db->insertID();

        \Config\Services::audit()->forModule('RECRUITMENT')->logCreate(
            'online_test', $id, $insert, "Buat online test: {$insert['name']} ({$code})",
        );

        return ['id' => $id, 'code' => $code];
    }

    public function updateTest(int $id, int $companyId, array $input): void
    {
        $row = $this->requireMutableTest($id, $companyId, 'diubah');
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            throw new OnlineTestAdminException('Nama test wajib diisi', 422, 'INVALID_TEST_NAME');
        }

        $update = array_intersect_key($input, array_flip([
            'name', 'description', 'instructions_html', 'category',
            'duration_minutes', 'passing_score_pct', 'max_attempts',
            'shuffle_questions', 'shuffle_choices', 'show_result_to_candidate',
            'use_pool', 'pool_filters', 'is_active',
        ]));
        foreach (['shuffle_questions', 'shuffle_choices', 'show_result_to_candidate', 'use_pool', 'is_active'] as $boolean) {
            if (isset($update[$boolean])) $update[$boolean] = (int) (bool) $update[$boolean];
        }
        if (isset($update['pool_filters'])) {
            $update['pool_filters'] = $update['pool_filters'] !== null ? json_encode($update['pool_filters']) : null;
        }
        $update['updated_at'] = date('Y-m-d H:i:s');

        $this->db->table('online_tests')->where('id', (int) $row['id'])
            ->where('company_id', $companyId)->update($update);
    }

    /** @return 'soft'|'hard' */
    public function deleteTest(int $id, int $companyId): string
    {
        $row = $this->requireMutableTest($id, $companyId, 'dihapus');
        $assigned = $this->db->table('online_test_assignments')->where('company_id', $companyId)
            ->where('online_test_id', (int) $row['id'])->countAllResults();

        $builder = $this->db->table('online_tests')->where('id', (int) $row['id'])
            ->where('company_id', $companyId);
        if ($assigned > 0) {
            $builder->update(['deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 0]);
            return 'soft';
        }
        $builder->delete();
        return 'hard';
    }

    public function createQuestion(int $testId, int $companyId, array $input): int
    {
        $this->assertCompany($companyId);
        if (! $this->repository->findTest($testId, $companyId)) {
            throw new OnlineTestAdminException('Test tidak ditemukan', 404);
        }
        $maxOrder = (int) ($this->db->table('online_test_questions q')
            ->selectMax('sort_order', 'mx')->join('online_tests t', 't.id = q.online_test_id')
            ->where('q.online_test_id', $testId)->where('t.company_id', $companyId)
            ->get()->getRowArray()['mx'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $insert = [
            'online_test_id' => $testId,
            'bank_question_id' => isset($input['bank_question_id']) ? (int) $input['bank_question_id'] : null,
            'question_type' => $input['question_type'],
            'category' => $input['category'] ?? null,
            'prompt' => $input['prompt'],
            'choices' => isset($input['choices']) ? json_encode($input['choices'], JSON_UNESCAPED_UNICODE) : null,
            'correct_answer' => array_key_exists('correct_answer', $input) ? json_encode($input['correct_answer']) : null,
            'disc_weight' => $input['disc_weight'] ?? null,
            'points' => (float) ($input['points'] ?? 1.0),
            'explanation' => $input['explanation'] ?? null,
            'sort_order' => $maxOrder + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->db->table('online_test_questions')->insert($insert);
        return (int) $this->db->insertID();
    }

    public function updateQuestion(int $id, int $companyId, array $input): void
    {
        $this->assertCompany($companyId);
        $row = $this->repository->findQuestion($id, $companyId);
        if (! $row) throw new OnlineTestAdminException('Question tidak ditemukan', 404);
        if (array_key_exists('prompt', $input) && trim((string) $input['prompt']) === '') {
            throw new OnlineTestAdminException('Prompt soal wajib diisi', 422, 'INVALID_QUESTION_PROMPT');
        }
        $update = array_intersect_key($input, array_flip([
            'prompt', 'question_type', 'category', 'disc_weight', 'points', 'explanation', 'sort_order',
        ]));
        if (array_key_exists('choices', $input)) {
            $update['choices'] = $input['choices'] !== null ? json_encode($input['choices'], JSON_UNESCAPED_UNICODE) : null;
        }
        if (array_key_exists('correct_answer', $input)) {
            $update['correct_answer'] = $input['correct_answer'] !== null ? json_encode($input['correct_answer']) : null;
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('online_test_questions')->where('id', $id)
            ->where('online_test_id', (int) $row['online_test_id'])->update($update);
    }

    public function deleteQuestion(int $id, int $companyId): void
    {
        $this->assertCompany($companyId);
        $row = $this->repository->findQuestion($id, $companyId);
        if (! $row) throw new OnlineTestAdminException('Question tidak ditemukan', 404);
        $this->db->table('online_test_questions')->where('id', $id)
            ->where('online_test_id', (int) $row['online_test_id'])->delete();
    }

    public function seedQuestions(int $testId, int $companyId, array $seeds): int
    {
        $this->assertCompany($companyId);
        if (! $this->repository->findTest($testId, $companyId)) {
            throw new OnlineTestAdminException('Test tidak ditemukan', 404);
        }
        if ($this->repository->countQuestions($testId, $companyId) > 0) {
            throw new OnlineTestAdminException('Test ini sudah punya soal — hapus dulu sebelum seed template');
        }
        if ($seeds === []) throw new OnlineTestAdminException('Template seed untuk kategori ini belum tersedia');

        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($seeds as $index => $question) {
            $rows[] = $this->questionPayload($testId, $question, $index, $now);
        }
        $this->db->table('online_test_questions')->insertBatch($rows);
        return count($rows);
    }

    public function importQuestionsFromBank(int $testId, int $companyId, array $bankQuestionIds): int
    {
        $this->assertCompany($companyId);
        if (! $this->repository->findTest($testId, $companyId)) {
            throw new OnlineTestAdminException('Test tidak ditemukan', 404);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $bankQuestionIds))));
        if ($ids === []) throw new OnlineTestAdminException('bank_question_ids wajib (array of int)');

        $bankRows = $this->db->table('online_test_question_bank')->whereIn('id', $ids)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getResultArray();
        if (count($bankRows) !== count($ids)) {
            throw new OnlineTestAdminException('Sebagian soal bank tidak ditemukan pada tenant ini');
        }
        $maxOrder = (int) ($this->db->table('online_test_questions q')
            ->selectMax('q.sort_order', 'mx')->join('online_tests t', 't.id = q.online_test_id')
            ->where('q.online_test_id', $testId)->where('t.company_id', $companyId)
            ->get()->getRowArray()['mx'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($bankRows as $index => $bank) {
            $rows[] = [
                'online_test_id' => $testId,
                'bank_question_id' => (int) $bank['id'],
                'question_type' => $bank['question_type'],
                'category' => $bank['category'],
                'prompt' => $bank['prompt'],
                'choices' => $bank['choices'],
                'correct_answer' => $bank['correct_answer'],
                'disc_weight' => $bank['disc_weight'],
                'points' => 1.0,
                'explanation' => $bank['explanation'],
                'sort_order' => $maxOrder + $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->db->table('online_test_questions')->insertBatch($rows);
        return count($rows);
    }

    public function createBankQuestion(int $companyId, int $actorUserId, array $input): int
    {
        $this->assertCompany($companyId);
        $now = date('Y-m-d H:i:s');
        $insert = $this->bankPayload($input) + [
            'company_id' => $companyId,
            'created_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->db->table('online_test_question_bank')->insert($insert);
        return (int) $this->db->insertID();
    }

    public function updateBankQuestion(int $id, int $companyId, array $input): void
    {
        $this->assertCompany($companyId);
        if (! $this->repository->findBankQuestion($id, $companyId)) {
            throw new OnlineTestAdminException('Bank question tidak ditemukan', 404);
        }
        $update = array_intersect_key($this->bankPayload($input), $input);
        $update['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('online_test_question_bank')->where('id', $id)
            ->where('company_id', $companyId)->update($update);
    }

    public function deleteBankQuestion(int $id, int $companyId): void
    {
        $this->assertCompany($companyId);
        if (! $this->repository->findBankQuestion($id, $companyId)) {
            throw new OnlineTestAdminException('Bank question tidak ditemukan', 404);
        }
        $this->db->table('online_test_question_bank')->where('id', $id)
            ->where('company_id', $companyId)->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /** @param list<array<string, mixed>> $rows */
    public function importBankRows(int $companyId, int $actorUserId, array $rows): int
    {
        $this->assertCompany($companyId);
        if ($rows === []) return 0;
        $now = date('Y-m-d H:i:s');
        $tenantRows = [];
        foreach ($rows as $row) {
            $tenantRows[] = [
                'company_id' => $companyId,
                'is_active' => 1,
                'created_by' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ] + $row;
        }
        $this->db->table('online_test_question_bank')->insertBatch($tenantRows);
        return count($tenantRows);
    }

    /** @return array{id: int, invitation_token: string, assignment: array<string, mixed>, test: array<string, mixed>, candidate: array<string, mixed>, created_at: string} */
    public function createAssignment(int $companyId, int $actorUserId, array $input): array
    {
        $this->assertCompany($companyId);
        $this->assertDateRange($input['available_from'] ?? null, $input['due_at'] ?? null);
        $test = $this->repository->findTest((int) $input['online_test_id'], $companyId);
        if (! $test) throw new OnlineTestAdminException('Test tidak ditemukan', 404);
        $candidate = $this->repository->findCandidate((int) $input['candidate_id'], $companyId);
        if (! $candidate) throw new OnlineTestAdminException('Kandidat tidak ditemukan', 404);
        if (($candidate['current_stage'] ?? '') === 'HIRED' || ! empty($candidate['converted_employee_id'])) {
            throw new OnlineTestAdminException(
                'Kandidat sudah HIRED dan tidak bisa ditugaskan online test lagi', 422, 'CANDIDATE_ALREADY_HIRED',
            );
        }
        $existing = $this->db->table('online_test_assignments')->where('company_id', $companyId)
            ->where('candidate_id', (int) $input['candidate_id'])
            ->where('online_test_id', (int) $input['online_test_id'])
            ->whereIn('status', ['PENDING', 'IN_PROGRESS', 'COMPLETED'])->countAllResults();
        if ($existing > 0) {
            throw new OnlineTestAdminException(
                'Kandidat sudah ditugaskan test ini sebelumnya', 422, 'TEST_ALREADY_ASSIGNED',
            );
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id' => $companyId,
            'online_test_id' => (int) $input['online_test_id'],
            'candidate_id' => (int) $input['candidate_id'],
            'candidate_account_id' => $candidate['candidate_account_id'] ?? null,
            'job_posting_id' => $candidate['job_posting_id'] ?? null,
            'status' => 'PENDING',
            'available_from' => $input['available_from'] ?? null,
            'due_at' => $input['due_at'] ?? null,
            'max_attempts' => max(1, (int) ($input['max_attempts'] ?? $test['max_attempts'] ?? 1)),
            'invitation_token' => bin2hex(random_bytes(32)),
            'notes' => $input['notes'] ?? null,
            'assigned_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->db->transStart();
        $this->db->table('online_test_assignments')->insert($insert);
        $assignmentId = (int) $this->db->insertID();
        $targetStage = ! empty($input['advance_stage'])
            ? (is_string($input['advance_stage']) ? strtoupper($input['advance_stage']) : 'ONLINE_TEST')
            : null;
        if ($targetStage !== null) {
            $stageIndexes = array_flip(RecruitmentService::STAGES);
            $currentIndex = $stageIndexes[$candidate['current_stage']] ?? -1;
            $targetIndex = $stageIndexes[$targetStage] ?? -1;
            if ($currentIndex >= 0 && $targetIndex >= 0 && $currentIndex < $targetIndex) {
                $this->db->table('candidates')->where('id', (int) $input['candidate_id'])
                    ->where('company_id', $companyId)->update(['current_stage' => $targetStage, 'updated_at' => $now]);
                $this->db->table('candidate_stages')->insert([
                    'company_id' => $companyId,
                    'candidate_id' => (int) $input['candidate_id'],
                    'stage' => $targetStage,
                    'result' => 'PENDING',
                    'created_by' => $actorUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            throw new OnlineTestAdminException('Gagal membuat assignment', 500);
        }

        \Config\Services::audit()->forModule('RECRUITMENT')->logCreate(
            'online_test_assignment', $assignmentId, $insert,
            "Assign test '{$test['name']}' ke kandidat #{$input['candidate_id']}",
        );
        return [
            'id' => $assignmentId,
            'invitation_token' => (string) $insert['invitation_token'],
            'assignment' => $insert,
            'test' => $test,
            'candidate' => $candidate,
            'created_at' => $now,
        ];
    }

    public function updateAssignment(int $id, int $companyId, array $input): void
    {
        $this->assertCompany($companyId);
        $row = $this->repository->findAssignment($id, $companyId);
        if (! $row) throw new OnlineTestAdminException('Assignment tidak ditemukan', 404);
        if ($row['status'] !== 'PENDING') {
            throw new OnlineTestAdminException('Assignment tidak bisa diubah setelah test dimulai');
        }
        $availableFrom = $input['available_from'] ?? $row['available_from'];
        $dueAt = $input['due_at'] ?? $row['due_at'];
        $this->assertDateRange($availableFrom, $dueAt);
        if (isset($input['max_attempts']) && (int) $input['max_attempts'] < (int) $row['attempts_used']) {
            throw new OnlineTestAdminException(
                'max_attempts tidak boleh lebih kecil dari attempts_used', 422, 'INVALID_ATTEMPT_LIMIT',
            );
        }
        $update = array_intersect_key($input, array_flip(['available_from', 'due_at', 'max_attempts', 'notes']));
        $update['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('online_test_assignments')->where('id', $id)
            ->where('company_id', $companyId)->update($update);
    }

    public function cancelAssignment(int $id, int $companyId): void
    {
        $this->assertCompany($companyId);
        $row = $this->repository->findAssignment($id, $companyId);
        if (! $row) throw new OnlineTestAdminException('Assignment tidak ditemukan', 404);
        if (in_array($row['status'], ['COMPLETED', 'CANCELLED', 'EXPIRED'], true)) {
            throw new OnlineTestAdminException('Assignment sudah selesai/dibatalkan');
        }
        $this->db->table('online_test_assignments')->where('id', $id)
            ->where('company_id', $companyId)->update([
                'status' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function deleteAssignment(int $id, int $companyId): void
    {
        $this->assertCompany($companyId);
        $row = $this->repository->findAssignment($id, $companyId);
        if (! $row) throw new OnlineTestAdminException('Assignment tidak ditemukan', 404);
        if ($row['status'] !== 'PENDING') {
            throw new OnlineTestAdminException('Hanya assignment PENDING yang boleh dihapus');
        }
        $this->db->table('online_test_assignments')->where('id', $id)
            ->where('company_id', $companyId)->delete();
    }

    private function assertDateRange($availableFrom, $dueAt): void
    {
        if ($availableFrom && $dueAt && $dueAt < $availableFrom) {
            throw new OnlineTestAdminException(
                'due_at harus setelah atau sama dengan available_from', 422, 'INVALID_DATE_RANGE',
            );
        }
    }

    /** @return array<string, mixed> */
    private function questionPayload(int $testId, array $question, int $sortOrder, string $now): array
    {
        return [
            'online_test_id' => $testId,
            'bank_question_id' => null,
            'question_type' => $question['question_type'],
            'category' => $question['category'] ?? null,
            'prompt' => $question['prompt'],
            'choices' => isset($question['choices']) ? json_encode($question['choices'], JSON_UNESCAPED_UNICODE) : null,
            'correct_answer' => isset($question['correct_answer']) ? json_encode($question['correct_answer']) : null,
            'disc_weight' => $question['disc_weight'] ?? null,
            'points' => (float) ($question['points'] ?? 1.0),
            'explanation' => $question['explanation'] ?? null,
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array<string, mixed> */
    private function bankPayload(array $input): array
    {
        $payload = array_intersect_key($input, array_flip([
            'category', 'difficulty', 'question_type', 'prompt', 'disc_weight', 'explanation', 'is_active',
        ]));
        $payload['category'] ??= 'GENERAL';
        $payload['difficulty'] ??= 'MEDIUM';
        foreach (['choices', 'correct_answer', 'tags'] as $json) {
            if (array_key_exists($json, $input)) {
                $payload[$json] = $input[$json] !== null ? json_encode($input[$json], JSON_UNESCAPED_UNICODE) : null;
            }
        }
        if (isset($payload['is_active'])) $payload['is_active'] = (int) (bool) $payload['is_active'];
        else $payload['is_active'] = 1;
        return $payload;
    }

    /** @return array<string, mixed> */
    private function requireMutableTest(int $id, int $companyId, string $operation): array
    {
        $this->assertCompany($companyId);
        $row = $this->repository->findTest($id, $companyId);
        if (! $row) throw new OnlineTestAdminException('Test tidak ditemukan', 404);
        if ((int) ($row['is_template'] ?? 0) === 1) {
            throw new OnlineTestAdminException("Test template built-in tidak dapat {$operation}");
        }
        return $row;
    }

    private function assertCompany(int $companyId): void
    {
        if ($companyId <= 0) throw new OnlineTestAdminException('Company context tidak tersedia', 403);
    }
}
