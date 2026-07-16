<?php

namespace App\Libraries\Recruitment;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class HireApprovalWorkflowService
{
    /** @var callable(array<string,mixed>):array<string,mixed>|null */
    private $approvalStarter;
    /** @var callable(int,int,array<string,mixed>):array<string,mixed>|null */
    private $hireConverter;

    public function __construct(
        private readonly ?BaseConnection $connection = null,
        ?callable $approvalStarter = null,
        ?callable $hireConverter = null,
    ) {
        $this->approvalStarter = $approvalStarter;
        $this->hireConverter = $hireConverter;
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    public function submit(int $candidateId, int $companyId, int $userId, array $overrides): array
    {
        $context = $this->context($candidateId, $companyId);
        $candidate = $context['candidate'];
        if (! empty($candidate['converted_employee_id'])) {
            throw new HireApprovalException('Kandidat sudah dikonversi menjadi employee');
        }
        if (($candidate['hire_approval_status'] ?? null) === 'PENDING') {
            throw new HireApprovalException('Konversi kandidat sedang menunggu approval');
        }
        $actor = $this->db()->table('users')->where('id', $userId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        $actorEmployeeId = (int) ($actor['employee_id'] ?? 0);
        if ($actorEmployeeId <= 0) {
            throw new HireApprovalException(
                'Pembuat transaksi hire harus user aktif yang terhubung ke employee',
                'HIRE_MAKER_INVALID',
                422
            );
        }
        $overrides['_actor_user_id'] = $userId;
        $policy = $this->validatePayload($overrides, $context, $companyId);
        $payloadJson = (string) json_encode($overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', $payloadJson);
        $encrypted = Services::encryption()->encrypt($payloadJson);
        if ($encrypted === null || $encrypted === '') throw new \RuntimeException('Gagal mengenkripsi payload hire');

        $now = date('Y-m-d H:i:s');
        $db = $this->db();
        $db->transBegin();
        try {
            $db->table('candidates')->where('id', $candidateId)->where('company_id', $companyId)
                ->groupStart()->where('hire_approval_status IS NULL')->orWhereIn('hire_approval_status', ['REJECTED', 'CANCELED'])->groupEnd()
                ->where('converted_employee_id IS NULL')->update([
                    'hire_approval_status' => 'PENDING',
                    'hire_payload_encrypted' => $encrypted,
                    'hire_payload_hash' => $payloadHash,
                    'hire_submitted_by' => $userId,
                    'hire_submitted_at' => $now,
                    'hire_approved_by' => null,
                    'hire_approved_at' => null,
                    'hire_rejected_by' => null,
                    'hire_rejected_at' => null,
                    'hire_rejection_reason' => null,
                    'updated_at' => $now,
                ]);
            if ($db->affectedRows() !== 1) throw new HireApprovalException('Kandidat berubah; muat ulang data');
            $params = [
                'company_id' => $companyId,
                'module' => 'HIRE_CONVERSION',
                'entity_table' => 'candidates',
                'entity_id' => $candidateId,
                'employee_id' => $actorEmployeeId,
                'meta' => [
                    'amount' => (float) $context['offer']['basic_salary'],
                    'is_policy_exception' => $policy['is_policy_exception'] ? 1 : 0,
                    'join_date_changed' => $policy['join_date_changed'] ? 1 : 0,
                    'job_posting_id' => (int) $context['job']['id'],
                    'hire_payload_hash' => $payloadHash,
                    'hire_snapshot_hash' => $this->snapshotHash($context, $payloadHash),
                    'submitted_by' => $userId,
                ],
            ];
            $approval = $this->approvalStarter !== null
                ? ($this->approvalStarter)($params) : Services::approval()->start($params);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat workflow approval hire');
            $db->transCommit();
            return ['candidate' => $this->candidate($candidateId, $companyId), 'approval' => $approval];
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function approve(array $request, int $userId): array
    {
        $candidateId = (int) $request['entity_id'];
        $companyId = (int) $request['company_id'];
        $context = $this->context($candidateId, $companyId);
        $candidate = $context['candidate'];
        if (($candidate['hire_approval_status'] ?? null) !== 'PENDING' || ! empty($candidate['converted_employee_id'])) {
            throw new HireApprovalException('Konversi kandidat tidak sedang menunggu approval');
        }
        $payloadJson = Services::encryption()->decrypt((string) ($candidate['hire_payload_encrypted'] ?? ''));
        if ($payloadJson === null || $payloadJson === '') throw new HireApprovalException('Payload hire tidak dapat didekripsi');
        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) throw new HireApprovalException('Payload hire tidak valid');
        $payloadHash = hash('sha256', $payloadJson);
        $meta = $this->meta($request);
        if (! hash_equals((string) ($candidate['hire_payload_hash'] ?? ''), $payloadHash)
            || ! hash_equals((string) ($meta['hire_payload_hash'] ?? ''), $payloadHash)
            || ! hash_equals((string) ($meta['hire_snapshot_hash'] ?? ''), $this->snapshotHash($context, $payloadHash))) {
            throw new HireApprovalException('Data kandidat, offer, atau payload hire berubah setelah submit');
        }
        $this->validatePayload($payload, $context, $companyId);
        $result = $this->hireConverter !== null
            ? ($this->hireConverter)($candidateId, $companyId, $payload)
            : (new RecruitmentHireService($this->db()))->convertToEmployee($candidateId, $companyId, $payload);
        $now = date('Y-m-d H:i:s');
        $this->db()->table('candidates')->where('id', $candidateId)->where('company_id', $companyId)
            ->where('hire_approval_status', 'PENDING')->where('converted_employee_id', (int) $result['employee_id'])
            ->update(['hire_approval_status' => 'APPROVED', 'hire_approved_by' => $userId,
                'hire_approved_at' => $now, 'updated_at' => $now]);
        if ($this->db()->affectedRows() !== 1) throw new HireApprovalException('Finalisasi bukti approval hire gagal');
        return $result + ['candidate_id' => $candidateId, 'status' => 'HIRED'];
    }

    /** @return array<string,mixed> */
    public function reject(int $candidateId, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new HireApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED', 422);
        }
        $candidate = $this->candidate($candidateId, $companyId);
        if (($candidate['hire_approval_status'] ?? null) !== 'PENDING') {
            throw new HireApprovalException('Konversi kandidat tidak sedang menunggu approval');
        }
        $now = date('Y-m-d H:i:s');
        $this->db()->table('candidates')->where('id', $candidateId)->where('company_id', $companyId)
            ->where('hire_approval_status', 'PENDING')->update([
                'hire_approval_status' => 'REJECTED',
                'hire_rejected_by' => $userId,
                'hire_rejected_at' => $now,
                'hire_rejection_reason' => $reason,
                'updated_at' => $now,
            ]);
        if ($this->db()->affectedRows() !== 1) throw new HireApprovalException('Kandidat berubah; muat ulang data');
        return ['candidate_id' => $candidateId, 'status' => 'HIRE_REJECTED',
            'candidate_name' => $candidate['full_name'] ?? null];
    }

    /** @param array<string,mixed> $payload @param array<string,array<string,mixed>> $context @return array<string,bool> */
    private function validatePayload(array $payload, array $context, int $companyId): array
    {
        $offer = $context['offer'];
        $job = $context['job'];
        $material = [
            'basic_salary' => (float) $offer['basic_salary'],
            'employment_status' => (string) $offer['employment_status'],
            'position_id' => (int) ($offer['position_id'] ?: $job['position_id'] ?? 0),
            'department_id' => (int) ($offer['department_id'] ?: $job['department_id'] ?? 0),
            'branch_id' => (int) ($offer['branch_id'] ?: $job['branch_id'] ?? 0),
        ];
        foreach ($material as $field => $expected) {
            if (! array_key_exists($field, $payload) || $payload[$field] === '' || $payload[$field] === null) continue;
            $actual = $field === 'basic_salary' ? (float) $payload[$field]
                : ($field === 'employment_status' ? (string) $payload[$field] : (int) $payload[$field]);
            if ($actual !== $expected) {
                throw new HireApprovalException(
                    "{$field} berbeda dari offer yang diterima; revisi dan minta persetujuan kandidat melalui offer baru",
                    'HIRE_OFFER_MISMATCH',
                    422
                );
            }
        }
        $references = ['department_id' => 'departments', 'position_id' => 'positions',
            'branch_id' => 'branches', 'job_grade_id' => 'job_grades', 'manager_id' => 'employees'];
        foreach ($references as $field => $table) {
            $id = (int) ($payload[$field] ?? 0);
            if ($id <= 0) continue;
            $builder = $this->db()->table($table)->where('id', $id)->where('company_id', $companyId);
            if ($this->db()->fieldExists('deleted_at', $table)) $builder->where('deleted_at IS NULL');
            if (! $builder->get()->getRowArray()) {
                throw new HireApprovalException("{$field} tidak valid untuk tenant ini", 'HIRE_REFERENCE_INVALID', 422);
            }
        }
        $offerJoinDate = (string) ($offer['proposed_join_date'] ?? '');
        $joinDateChanged = ! empty($payload['join_date']) && $offerJoinDate !== ''
            && (string) $payload['join_date'] !== $offerJoinDate;
        return ['join_date_changed' => $joinDateChanged, 'is_policy_exception' => $joinDateChanged];
    }

    /** @return array{candidate:array<string,mixed>,offer:array<string,mixed>,job:array<string,mixed>} */
    private function context(int $candidateId, int $companyId): array
    {
        $candidate = $this->candidate($candidateId, $companyId);
        $offer = $this->db()->table('candidate_offers')->where('candidate_id', $candidateId)
            ->where('company_id', $companyId)->where('status', 'ACCEPTED')->where('deleted_at IS NULL')
            ->orderBy('responded_at', 'DESC')->get()->getRowArray();
        if (! $offer) throw new HireApprovalException('Kandidat hanya dapat di-hire setelah offer diterima', 'OFFER_REQUIRED', 422);
        $job = $this->db()->table('job_postings')->where('id', (int) $candidate['job_posting_id'])
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $job) throw new HireApprovalException('Job posting kandidat tidak tersedia', 'RESOURCE_NOT_FOUND', 404);
        return ['candidate' => $candidate, 'offer' => $offer, 'job' => $job];
    }

    /** @param array<string,array<string,mixed>> $context */
    private function snapshotHash(array $context, string $payloadHash): string
    {
        $candidate = $context['candidate']; $offer = $context['offer']; $job = $context['job'];
        $snapshot = [
            'candidate' => array_intersect_key($candidate, array_flip(['id', 'job_posting_id', 'current_stage', 'converted_employee_id'])),
            'offer' => array_intersect_key($offer, array_flip(['id', 'candidate_id', 'job_posting_id', 'employment_status',
                'proposed_join_date', 'basic_salary', 'position_id', 'department_id', 'branch_id', 'status', 'responded_at'])),
            'job' => array_intersect_key($job, array_flip(['id', 'position_id', 'department_id', 'branch_id', 'status', 'headcount'])),
            'payload_hash' => $payloadHash,
        ];
        return hash('sha256', (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function candidate(int $candidateId, int $companyId): array
    {
        $row = $this->db()->table('candidates')->where('id', $candidateId)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) throw new HireApprovalException('Candidate not found', 'RESOURCE_NOT_FOUND', 404);
        return $row;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function meta(array $request): array
    {
        $meta = $request['meta'] ?? [];
        if (is_string($meta)) $meta = json_decode($meta, true) ?: [];
        return is_array($meta) ? $meta : [];
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
