<?php

declare(strict_types=1);

namespace App\Libraries\Payroll;

use App\Repositories\Payroll\PayrollRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class PayrollFinalizationService
{
    private readonly BaseConnection $db;
    private readonly PayrollRepository $repository;

    public function __construct(?BaseConnection $connection = null)
    {
        $this->db = $connection ?? Database::connect();
        $this->repository = new PayrollRepository($this->db);
    }

    /** @return array{period:array<string,mixed>,recipients:list<array<string,mixed>>} */
    public function finalize(int $periodId, int $companyId, int $userId): array
    {
        $period = $this->repository->findPeriod($periodId, $companyId);
        if (! $period || $period['status'] !== 'APPROVED') {
            throw new \RuntimeException('Period must be in APPROVED status to finalize');
        }
        (new PayrollSnapshotService($this->db))->assertValid($period);

        $this->db->transBegin();
        try {
            $this->db->table('overtimes')->where('company_id', $companyId)
                ->whereIn('status', ['APPROVED', 'REALIZED'])
                ->where('work_date >=', $period['start_date'])->where('work_date <=', $period['end_date'])
                ->where('paid_in_payroll_period_id IS NULL')->update(['paid_in_payroll_period_id' => $periodId]);
            $this->db->table('reimbursements')->where('company_id', $companyId)
                ->where('status', 'APPROVED')->where('expense_date <=', $period['end_date'])
                ->where('paid_in_payroll_period_id IS NULL')->update(['paid_in_payroll_period_id' => $periodId]);

            $recipients = $this->db->table('payslips ps')
                ->select('ps.id, ps.employee_id, e.user_id')
                ->join('employees e', 'e.id = ps.employee_id AND e.company_id = ps.company_id', 'left')
                ->where('ps.company_id', $companyId)->where('ps.payroll_period_id', $periodId)
                ->get()->getResultArray();
            $now = date('Y-m-d H:i:s');
            $this->db->table('payroll_periods')->where('id', $periodId)->where('company_id', $companyId)
                ->where('status', 'APPROVED')->update([
                    'status' => 'FINALIZED', 'finalized_at' => $now,
                    'finalized_by' => $userId > 0 ? $userId : null, 'updated_at' => $now,
                ]);
            if ($this->db->affectedRows() !== 1) throw new \RuntimeException('Payroll period state changed; reload and retry');
            if ($this->db->transStatus() === false) throw new \RuntimeException('Payroll finalization transaction failed');
            $this->db->transCommit();
            return ['period' => $period, 'recipients' => $recipients];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
