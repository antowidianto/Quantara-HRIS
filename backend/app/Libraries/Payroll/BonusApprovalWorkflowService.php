<?php

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class BonusApprovalWorkflowService
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    /** @return array<string,mixed> */
    public function submit(int $id, int $companyId, int $userId): array
    {
        $db = $this->db();
        $run = $this->run($id, $companyId);
        if ($run['status'] !== 'CALCULATED') {
            throw new BonusApprovalException('Hanya bonus CALCULATED yang dapat diajukan');
        }
        $recipientCount = $db->table('bonus_recipients')->where('bonus_run_id', $id)
            ->where('company_id', $companyId)->where('is_excluded', 0)->countAllResults();
        if ($recipientCount === 0) {
            throw new BonusApprovalException('Bonus run belum memiliki recipient eligible');
        }
        $maker = $db->table('users')->where('id', $userId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        $employeeId = (int) ($maker['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            throw new BonusApprovalException(
                'Pembuat pengajuan bonus harus terhubung ke employee tenant aktif',
                'APPROVAL_REQUESTER_REQUIRED'
            );
        }

        $now = date('Y-m-d H:i:s');
        $db->transBegin();
        try {
            $db->table('bonus_runs')->where('id', $id)->where('company_id', $companyId)
                ->where('status', 'CALCULATED')->update([
                    'status' => 'PENDING_APPROVAL', 'submitted_by' => $userId,
                    'submitted_at' => $now, 'rejected_by' => null, 'rejected_at' => null,
                    'rejection_reason' => null, 'updated_at' => $now,
                ]);
            if ($db->affectedRows() !== 1) {
                throw new BonusApprovalException('Bonus run berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
            }
            $approval = Services::approval()->start([
                'company_id' => $companyId,
                'module' => 'BONUS',
                'entity_table' => 'bonus_runs',
                'entity_id' => $id,
                'employee_id' => $employeeId,
                'meta' => [
                    'amount' => (float) $run['total_amount'],
                    'recipient_count' => $recipientCount,
                    'payroll_period_id' => $run['payroll_period_id'] ?? null,
                    'cost_center_id' => $run['cost_center_id'] ?? null,
                    'submitted_by' => $userId,
                ],
            ]);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat workflow approval bonus');
            $db->transCommit();
            return ['status' => 'PENDING_APPROVAL', 'approval' => $approval];
        } catch (\Throwable $exception) {
            $db->transRollback();
            if ($exception instanceof BonusApprovalException) throw $exception;
            throw new BonusApprovalException(
                'Gagal membuat workflow approval bonus: ' . $exception->getMessage(),
                'APPROVAL_START_FAILED',
                409
            );
        }
    }

    /** @return array<string,mixed> */
    public function approve(int $id, int $companyId, int $userId): array
    {
        $db = $this->db();
        $run = $this->pending($id, $companyId);
        $actual = $db->table('bonus_recipients')->where('bonus_run_id', $id)
            ->where('company_id', $companyId)->where('is_excluded', 0)->countAllResults();
        if ($actual === 0 || $actual !== (int) $run['recipient_count']) {
            throw new BonusApprovalException('Snapshot recipient bonus berubah setelah submit', 'APPROVAL_CONFLICT', 409);
        }
        $aggregate = $db->table('bonus_recipients')->selectSum('amount', 'total')
            ->where('bonus_run_id', $id)->where('company_id', $companyId)
            ->where('is_excluded', 0)->get()->getRowArray();
        if (abs((float) ($aggregate['total'] ?? 0) - (float) $run['total_amount']) > 0.005) {
            throw new BonusApprovalException('Total bonus berubah setelah submit', 'APPROVAL_CONFLICT', 409);
        }
        $now = date('Y-m-d H:i:s');
        $db->table('bonus_runs')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'APPROVED', 'approved_by' => $userId,
                'approved_at' => $now, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new BonusApprovalException('Bonus run berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        return ['bonus_run' => $run, 'approved_at' => $now];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new BonusApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED');
        }
        $db = $this->db();
        $run = $this->pending($id, $companyId);
        $now = date('Y-m-d H:i:s');
        $db->table('bonus_runs')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'REJECTED', 'rejected_by' => $userId,
                'rejected_at' => $now, 'rejection_reason' => $reason, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new BonusApprovalException('Bonus run berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        return ['bonus_run' => $run, 'reason' => $reason, 'rejected_by' => $userId];
    }

    /** @return array<string,mixed> */
    private function pending(int $id, int $companyId): array
    {
        $run = $this->run($id, $companyId);
        if ($run['status'] !== 'PENDING_APPROVAL') {
            throw new BonusApprovalException('Bonus run tidak sedang menunggu approval');
        }
        return $run;
    }

    /** @return array<string,mixed> */
    private function run(int $id, int $companyId): array
    {
        $row = $this->db()->table('bonus_runs')->where('id', $id)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) throw new BonusApprovalException('Bonus run tidak ditemukan', 'RESOURCE_NOT_FOUND', 404);
        return $row;
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
