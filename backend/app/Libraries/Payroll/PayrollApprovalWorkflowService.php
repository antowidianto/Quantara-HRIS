<?php

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class PayrollApprovalWorkflowService
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    /** @return array<string,mixed> */
    public function submit(int $periodId, int $companyId, int $userId): array
    {
        $db = $this->db();
        $maker = $db->table('users')->where('id', $userId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        $employeeId = (int) ($maker['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            // Payroll chain uses fixed ROLE steps, so an active tenant employee
            // may provide the engine's required subject context for admin makers.
            $subject = $db->table('employees')->select('id')->where('company_id', $companyId)
                ->where('is_active', 1)->where('deleted_at IS NULL')->orderBy('id', 'ASC')->get()->getRowArray();
            $employeeId = (int) ($subject['id'] ?? 0);
        }
        if ($employeeId <= 0) {
            throw new \RuntimeException('Tidak ada karyawan aktif untuk konteks approval payroll');
        }

        $db->transBegin();

        try {
            $period = (new PayrollControlService($db))->submit($companyId, $periodId, $userId);
            $approval = Services::approval()->start([
                'company_id' => $companyId,
                'module' => 'PAYROLL',
                'entity_table' => 'payroll_periods',
                'entity_id' => $periodId,
                'employee_id' => $employeeId,
                'meta' => [
                    'amount' => (float) ($period['total_net'] ?? 0),
                    'submitted_by' => $userId,
                    'prepared_by' => (int) ($period['prepared_by'] ?? 0),
                    'period_code' => $period['code'] ?? null,
                ],
            ]);
            if (!$db->transStatus()) {
                throw new \RuntimeException('Gagal membuat workflow approval payroll');
            }
            $db->transCommit();

            return ['period' => $period, 'approval' => $approval];
        } catch (\Throwable $exception) {
            $db->transRollback();

            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function approve(int $periodId, int $companyId, int $userId, string $note = ''): array
    {
        $approval = Services::approval()->approveEntity(
            'PAYROLL',
            'payroll_periods',
            $periodId,
            $companyId,
            $userId,
            $note,
        );
        $period = $this->period($periodId, $companyId);

        return ['period' => $period, 'approval' => $approval];
    }

    /** @return array<string,mixed> */
    public function applyFinalDecision(
        int $periodId,
        int $companyId,
        int $actorUserId,
        string $decision,
        string $note,
    ): array {
        $control = new PayrollControlService($this->db());
        $period = $decision === 'APPROVED'
            ? $control->approveFromWorkflow($companyId, $periodId, $actorUserId)
            : $control->rejectFromWorkflow($companyId, $periodId);

        return [
            'payroll_period_id' => $periodId,
            'status' => $period['status'],
            'decision' => $decision,
            'decided_by' => $actorUserId,
            'note' => $note,
        ];
    }

    /** @return array<string,mixed> */
    private function period(int $periodId, int $companyId): array
    {
        $row = $this->db()->table('payroll_periods')->where('id', $periodId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (!$row) {
            throw new \RuntimeException('Payroll period tidak ditemukan');
        }

        return $row;
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
