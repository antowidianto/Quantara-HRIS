<?php

namespace App\Libraries\Recruitment;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class CandidateOfferApprovalWorkflowService
{
    /** @var callable(array<string,mixed>):array<string,mixed>|null */
    private $approvalStarter;

    public function __construct(
        private readonly ?BaseConnection $connection = null,
        ?callable $approvalStarter = null
    ) {
        $this->approvalStarter = $approvalStarter;
    }

    /** @return array<string,mixed> */
    public function submit(int $id, int $companyId, int $userId, ?string $note = null): array
    {
        $db = $this->db();
        $offer = $this->offer($id, $companyId);
        if (! in_array($offer['status'], ['DRAFT', 'APPROVAL_REJECTED'], true)) {
            throw new CandidateOfferApprovalException('Offer tidak dapat diajukan dari status ' . $offer['status']);
        }
        $context = $this->assertContext($offer, $companyId);
        $actor = $db->table('users')->where('id', $userId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        $actorEmployeeId = (int) ($actor['employee_id'] ?? 0);
        if ($actorEmployeeId <= 0) {
            throw new CandidateOfferApprovalException(
                'Pembuat offer harus user aktif yang terhubung ke employee',
                'OFFER_MAKER_INVALID',
                422
            );
        }
        $policy = $this->policy($offer, $context['posting']);
        $now = date('Y-m-d H:i:s');
        $db->transBegin();
        try {
            $db->table('candidate_offers')->where('id', $id)->where('company_id', $companyId)
                ->whereIn('status', ['DRAFT', 'APPROVAL_REJECTED'])->update([
                    'status' => 'PENDING_APPROVAL',
                    'approval_rejected_by' => null, 'approval_rejected_at' => null,
                    'approval_rejection_reason' => null, 'updated_at' => $now,
                ]);
            if ($db->affectedRows() !== 1) {
                throw new CandidateOfferApprovalException('Offer berubah; muat ulang data', 'OFFER_APPROVAL_CONFLICT');
            }
            $params = [
                'company_id' => $companyId, 'module' => 'CANDIDATE_OFFER',
                'entity_table' => 'candidate_offers', 'entity_id' => $id,
                'employee_id' => $actorEmployeeId,
                'meta' => [
                    'amount' => $policy['total_compensation'],
                    'is_policy_exception' => $policy['is_policy_exception'] ? 1 : 0,
                    'salary_variance_pct' => $policy['salary_variance_pct'],
                    'job_posting_id' => (int) $context['posting']['id'],
                    'salary_min' => $policy['salary_min'], 'salary_max' => $policy['salary_max'],
                    'joining_bonus' => (float) $offer['joining_bonus'],
                    'offer_snapshot_hash' => $this->snapshotHash($offer),
                    'submitted_by' => $userId,
                    'submission_note' => $note,
                ],
            ];
            $approval = $this->approvalStarter !== null
                ? ($this->approvalStarter)($params)
                : Services::approval()->start($params);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat workflow approval offer');
            $db->transCommit();
            return ['offer' => $this->offer($id, $companyId), 'approval' => $approval];
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function approve(array $request, int $userId): array
    {
        $id = (int) $request['entity_id'];
        $companyId = (int) $request['company_id'];
        $offer = $this->pending($id, $companyId);
        $context = $this->assertContext($offer, $companyId);
        $meta = $this->meta($request);
        if (! hash_equals((string) ($meta['offer_snapshot_hash'] ?? ''), $this->snapshotHash($offer))) {
            throw new CandidateOfferApprovalException(
                'Isi offer berubah setelah submit; ajukan ulang approval',
                'OFFER_APPROVAL_CONFLICT'
            );
        }
        $policy = $this->policy($offer, $context['posting']);
        if (abs($policy['salary_min'] - (float) ($meta['salary_min'] ?? 0)) > 0.005
            || abs($policy['salary_max'] - (float) ($meta['salary_max'] ?? 0)) > 0.005) {
            throw new CandidateOfferApprovalException(
                'Salary band lowongan berubah setelah submit; ajukan ulang approval',
                'OFFER_POLICY_CHANGED'
            );
        }
        $now = date('Y-m-d H:i:s');
        $deadline = $offer['response_deadline']
            ?: date('Y-m-d', strtotime('+' . OfferService::DEFAULT_RESPONSE_WINDOW_DAYS . ' days'));
        $this->db()->table('candidate_offers')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'SENT', 'sent_at' => $now, 'sent_by' => $userId,
                'response_deadline' => $deadline, 'updated_at' => $now,
            ]);
        if ($this->db()->affectedRows() !== 1) {
            throw new CandidateOfferApprovalException('Offer berubah; muat ulang data', 'OFFER_APPROVAL_CONFLICT');
        }
        return [
            'id' => $id, 'status' => 'SENT', 'offer_number' => $offer['offer_number'],
            'candidate_id' => (int) $offer['candidate_id'],
            'candidate_user_id' => (int) ($context['candidate']['user_id'] ?? 0),
            'response_deadline' => $deadline,
        ];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new CandidateOfferApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED', 422);
        }
        $offer = $this->pending($id, $companyId);
        $now = date('Y-m-d H:i:s');
        $this->db()->table('candidate_offers')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'APPROVAL_REJECTED', 'approval_rejected_by' => $userId,
                'approval_rejected_at' => $now, 'approval_rejection_reason' => $reason,
                'updated_at' => $now,
            ]);
        if ($this->db()->affectedRows() !== 1) {
            throw new CandidateOfferApprovalException('Offer berubah; muat ulang data', 'OFFER_APPROVAL_CONFLICT');
        }
        return ['id' => $id, 'status' => 'APPROVAL_REJECTED', 'offer_number' => $offer['offer_number']];
    }

    /** @param array<string,mixed> $offer @return array{candidate:array<string,mixed>,posting:array<string,mixed>} */
    private function assertContext(array $offer, int $companyId): array
    {
        if ((float) $offer['basic_salary'] <= 0) {
            throw new CandidateOfferApprovalException('basic_salary harus > 0', 'OFFER_INVALID', 422);
        }
        $candidate = $this->db()->table('candidates')->where('id', (int) $offer['candidate_id'])
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $candidate || ! in_array($candidate['current_stage'], ['OFFER', 'INTERVIEW_USER'], true)) {
            throw new CandidateOfferApprovalException('Candidate tidak lagi berada pada stage yang dapat menerima offer');
        }
        $posting = $this->db()->table('job_postings')->where('id', (int) $offer['job_posting_id'])
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $posting || empty($posting['hiring_manager_id'])) {
            throw new CandidateOfferApprovalException(
                'Job posting harus memiliki hiring manager aktif',
                'HIRING_MANAGER_REQUIRED',
                422
            );
        }
        $manager = $this->db()->table('users')->where('id', (int) $posting['hiring_manager_id'])
            ->where('company_id', $companyId)->where('is_active', 1)->get()->getRowArray();
        if (! $manager) {
            throw new CandidateOfferApprovalException('Hiring manager tidak aktif', 'HIRING_MANAGER_REQUIRED', 422);
        }
        return ['candidate' => $candidate, 'posting' => $posting];
    }

    /** @param array<string,mixed> $offer @param array<string,mixed> $posting @return array<string,mixed> */
    private function policy(array $offer, array $posting): array
    {
        $allowances = json_decode((string) ($offer['allowances_json'] ?? '[]'), true) ?: [];
        $allowanceTotal = 0.0;
        foreach ($allowances as $allowance) $allowanceTotal += (float) ($allowance['amount'] ?? 0);
        $salary = (float) $offer['basic_salary'];
        $min = (float) ($posting['salary_min'] ?? 0); $max = (float) ($posting['salary_max'] ?? 0);
        $outsideBand = ($min > 0 && $salary < $min) || ($max > 0 && $salary > $max);
        $mid = $min > 0 && $max > 0 ? ($min + $max) / 2 : 0.0;
        return [
            'salary_min' => $min, 'salary_max' => $max,
            'salary_variance_pct' => $mid > 0 ? round((($salary - $mid) / $mid) * 100, 4) : 0.0,
            'is_policy_exception' => $outsideBand || (float) $offer['joining_bonus'] > 0,
            'total_compensation' => $salary + $allowanceTotal + (float) $offer['joining_bonus'],
        ];
    }

    /** @param array<string,mixed> $offer */
    private function snapshotHash(array $offer): string
    {
        $fields = ['candidate_id', 'job_posting_id', 'position_id', 'department_id', 'branch_id',
            'employment_status', 'proposed_join_date', 'probation_months', 'basic_salary',
            'allowances_json', 'joining_bonus', 'benefits_json', 'currency', 'response_deadline'];
        $snapshot = [];
        foreach ($fields as $field) $snapshot[$field] = $offer[$field] ?? null;
        return hash('sha256', (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function pending(int $id, int $companyId): array
    {
        $offer = $this->offer($id, $companyId);
        if ($offer['status'] !== 'PENDING_APPROVAL') {
            throw new CandidateOfferApprovalException('Offer tidak sedang menunggu approval');
        }
        return $offer;
    }

    /** @return array<string,mixed> */
    private function offer(int $id, int $companyId): array
    {
        $offer = $this->db()->table('candidate_offers')->where('id', $id)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $offer) throw new CandidateOfferApprovalException('Offer tidak ditemukan', 'RESOURCE_NOT_FOUND', 404);
        return $offer;
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
