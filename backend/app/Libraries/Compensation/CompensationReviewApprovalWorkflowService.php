<?php

namespace App\Libraries\Compensation;

use App\Libraries\Employee\EmployeeEffectiveHistoryService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CompensationReviewApprovalWorkflowService
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    /** @return array<string,mixed> */
    public function approve(int $id, int $companyId, int $userId): array
    {
        $db = $this->db();
        $proposal = $this->pending($id, $companyId);
        $cycle = $this->cycle((int) $proposal['cycle_id'], $companyId);
        if ($cycle['status'] !== 'OPEN') {
            throw new CompensationReviewApprovalException('Compensation cycle tidak lagi OPEN', 'CYCLE_LOCKED', 409);
        }
        $employee = $db->table('employees')->where('id', (int) $proposal['employee_id'])
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $employee) {
            throw new CompensationReviewApprovalException('Employee tidak ditemukan', 'RESOURCE_NOT_FOUND', 404);
        }
        if (abs((float) $employee['basic_salary'] - (float) $proposal['current_salary']) > 0.005) {
            throw new CompensationReviewApprovalException(
                'Gaji berjalan berubah setelah proposal dibuat; lakukan proposal ulang',
                'APPROVAL_CONFLICT',
                409
            );
        }
        $this->assertBudget($companyId, $proposal);
        $snapshot = (new EmployeeEffectiveHistoryService($db))->schedule(
            $companyId,
            (int) $proposal['employee_id'],
            (string) $cycle['effective_date'],
            ['basic_salary' => (float) $proposal['proposed_salary']],
            $userId,
            'COMPENSATION_REVIEW',
            $id,
            (int) $proposal['proposed_by']
        );
        $now = date('Y-m-d H:i:s');
        $db->table('compensation_review_proposals')->where('id', $id)
            ->where('company_id', $companyId)->where('status', 'SUBMITTED')->update([
                'status' => 'APPROVED',
                'approval_level' => (int) $cycle['approval_levels'],
                'approved_by' => $userId, 'approved_at' => $now,
                'history_snapshot_id' => (int) $snapshot['id'],
                'letter_reference' => sprintf('COMP/%s/%06d', $cycle['code'], $id),
                'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new CompensationReviewApprovalException('Proposal berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        return ['proposal' => $proposal, 'history_snapshot_id' => (int) $snapshot['id']];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new CompensationReviewApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED');
        }
        $db = $this->db();
        $proposal = $this->pending($id, $companyId);
        $now = date('Y-m-d H:i:s');
        $db->table('compensation_review_proposals')->where('id', $id)
            ->where('company_id', $companyId)->where('status', 'SUBMITTED')->update([
                'status' => 'REJECTED', 'rejected_by' => $userId,
                'rejected_at' => $now, 'rejection_reason' => $reason,
                'manager_note' => $reason, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new CompensationReviewApprovalException('Proposal berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        return ['proposal' => $proposal, 'reason' => $reason, 'rejected_by' => $userId];
    }

    /** @param array<string,mixed> $proposal */
    private function assertBudget(int $companyId, array $proposal): void
    {
        $db = $this->db();
        $budgets = $db->table('compensation_review_budgets')->where('company_id', $companyId)
            ->where('cycle_id', $proposal['cycle_id'])
            ->groupStart()->where('department_id', $proposal['department_id'])->orWhere('department_id IS NULL')->groupEnd()
            ->groupStart()->where('cost_center_id', $proposal['cost_center_id'])->orWhere('cost_center_id IS NULL')->groupEnd()
            ->get()->getResultArray();
        $specificity = array_map(
            static fn (array $row): int => ($row['department_id'] !== null ? 1 : 0)
                + ($row['cost_center_id'] !== null ? 1 : 0),
            $budgets
        );
        $highest = $specificity === [] ? -1 : max($specificity);
        $budget = 0.0;
        foreach ($budgets as $index => $row) {
            if ($specificity[$index] === $highest) $budget += (float) $row['budget_amount'];
        }
        $approved = $db->table('compensation_review_proposals')
            ->selectSum('proposed_salary')->selectSum('current_salary')
            ->where('company_id', $companyId)->where('cycle_id', $proposal['cycle_id'])
            ->where('status', 'APPROVED')->get()->getRowArray();
        $used = (float) ($approved['proposed_salary'] ?? 0) - (float) ($approved['current_salary'] ?? 0);
        $increase = (float) $proposal['proposed_salary'] - (float) $proposal['current_salary'];
        if ($increase > 0 && ($budget <= 0 || $used + $increase > $budget)) {
            throw new CompensationReviewApprovalException('Merit budget would be exceeded.', 'BUDGET_EXCEEDED', 409);
        }
    }

    /** @return array<string,mixed> */
    private function pending(int $id, int $companyId): array
    {
        $row = $this->db()->table('compensation_review_proposals')->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new CompensationReviewApprovalException('Proposal tidak ditemukan', 'RESOURCE_NOT_FOUND', 404);
        if ($row['status'] !== 'SUBMITTED') {
            throw new CompensationReviewApprovalException('Proposal tidak sedang menunggu approval');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function cycle(int $id, int $companyId): array
    {
        $row = $this->db()->table('compensation_review_cycles')->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new CompensationReviewApprovalException('Cycle tidak ditemukan', 'RESOURCE_NOT_FOUND', 404);
        return $row;
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
