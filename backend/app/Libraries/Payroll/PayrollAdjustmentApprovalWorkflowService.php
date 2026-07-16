<?php

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class PayrollAdjustmentApprovalWorkflowService
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    /** @return array<string,mixed> */
    public function submit(int $id, int $companyId, int $userId): array
    {
        $db = $this->db();
        $row = $this->adjustment($id, $companyId);
        if ($row['status'] !== 'DRAFT') {
            throw new PayrollAdjustmentApprovalException('Hanya adjustment DRAFT yang dapat diajukan');
        }
        $maker = $db->table('users')->where('id', $userId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        $requesterEmployeeId = (int) ($maker['employee_id'] ?? 0);
        if ($requesterEmployeeId <= 0) {
            throw new PayrollAdjustmentApprovalException(
                'Pembuat adjustment harus terhubung ke employee tenant aktif',
                'APPROVAL_REQUESTER_REQUIRED'
            );
        }
        $this->assertPeriodsStillValid($row, $companyId);
        $now = date('Y-m-d H:i:s');
        $db->transBegin();
        try {
            $db->table('payroll_adjustments')->where('id', $id)->where('company_id', $companyId)
                ->where('status', 'DRAFT')->update([
                    'status' => 'PENDING_APPROVAL', 'submitted_by' => $userId,
                    'submitted_at' => $now, 'updated_at' => $now,
                ]);
            if ($db->affectedRows() !== 1) {
                throw new PayrollAdjustmentApprovalException('Adjustment berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
            }
            $approval = Services::approval()->start([
                'company_id' => $companyId,
                'module' => 'PAYROLL_ADJUSTMENT',
                'entity_table' => 'payroll_adjustments',
                'entity_id' => $id,
                'employee_id' => $requesterEmployeeId,
                'meta' => [
                    'amount' => (float) $row['amount'],
                    'adjustment_type' => (string) $row['adjustment_type'],
                    'target_employee_id' => (int) $row['employee_id'],
                    'original_period_id' => (int) $row['original_period_id'],
                    'target_period_id' => (int) $row['target_period_id'],
                    'is_reversal' => ! empty($row['reversal_of_id']),
                    'submitted_by' => $userId,
                ],
            ]);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat workflow approval adjustment');
            $db->transCommit();
            return ['status' => 'PENDING_APPROVAL', 'approval' => $approval];
        } catch (\Throwable $exception) {
            $db->transRollback();
            if ($exception instanceof PayrollAdjustmentApprovalException) throw $exception;
            throw new PayrollAdjustmentApprovalException(
                'Gagal membuat workflow approval adjustment: ' . $exception->getMessage(),
                'APPROVAL_START_FAILED',
                409
            );
        }
    }

    /** @return array<string,mixed> */
    public function approve(int $id, int $companyId, int $userId): array
    {
        $db = $this->db();
        $row = $this->pending($id, $companyId);
        $this->assertPeriodsStillValid($row, $companyId);
        $now = date('Y-m-d H:i:s');
        $db->table('payroll_adjustments')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'APPROVED', 'approved_by' => $userId,
                'approved_at' => $now, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new PayrollAdjustmentApprovalException('Adjustment berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        return ['payroll_adjustment' => $row, 'approved_at' => $now];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new PayrollAdjustmentApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED');
        }
        $db = $this->db();
        $row = $this->pending($id, $companyId);
        $now = date('Y-m-d H:i:s');
        $db->table('payroll_adjustments')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'REJECTED', 'rejected_by' => $userId,
                'rejected_at' => $now, 'rejection_reason' => $reason, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new PayrollAdjustmentApprovalException('Adjustment berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        return ['payroll_adjustment' => $row, 'reason' => $reason, 'rejected_by' => $userId];
    }

    /** @param array<string,mixed> $row */
    private function assertPeriodsStillValid(array $row, int $companyId): void
    {
        $db = $this->db();
        $original = $db->table('payroll_periods')->where('id', (int) $row['original_period_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        $target = $db->table('payroll_periods')->where('id', (int) $row['target_period_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $original || ! in_array($original['status'], ['FINALIZED', 'PAID', 'CLOSED'], true)) {
            throw new PayrollAdjustmentApprovalException('Original payroll tidak lagi valid');
        }
        if (! $target || ! in_array($target['status'], ['DRAFT', 'CALCULATED'], true)) {
            throw new PayrollAdjustmentApprovalException('Target payroll sudah terkunci', 'PAYROLL_PERIOD_LOCKED', 409);
        }
    }

    /** @return array<string,mixed> */
    private function pending(int $id, int $companyId): array
    {
        $row = $this->adjustment($id, $companyId);
        if ($row['status'] !== 'PENDING_APPROVAL') {
            throw new PayrollAdjustmentApprovalException('Adjustment tidak sedang menunggu approval');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function adjustment(int $id, int $companyId): array
    {
        $row = $this->db()->table('payroll_adjustments')->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new PayrollAdjustmentApprovalException('Payroll adjustment tidak ditemukan', 'RESOURCE_NOT_FOUND', 404);
        return $row;
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
