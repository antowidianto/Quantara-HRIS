<?php

declare(strict_types=1);

namespace App\Repositories\Payroll;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class PayrollRepository
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    public function connection(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }

    private function requireCompany(int $companyId): void
    {
        if ($companyId <= 0) throw new \DomainException('Active company context is required for payroll operations');
    }

    /** @return array<string,mixed>|null */
    public function findPeriod(int $id, int $companyId): ?array
    {
        $this->requireCompany($companyId);
        return $this->connection()->table('payroll_periods')->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray() ?: null;
    }

    /** @param array<string,mixed> $period */
    public function clearCalculatedPayslips(array $period, int $companyId): void
    {
        $this->requireCompany($companyId);
        if ((int) $period['company_id'] !== $companyId) throw new \DomainException('Payroll period tenant mismatch');
        $db = $this->connection();
        $db->transBegin();
        try {
            $payslips = $db->table('payslips')->where('company_id', $companyId)
                ->where('payroll_period_id', (int) $period['id'])->get()->getResultArray();
            $prefix = 'PS-' . $period['code'] . '-';
            $orphans = $db->table('payslips')->where('company_id', $companyId)
                ->like('slip_number', $prefix, 'after')->get()->getResultArray();
            $ids = array_values(array_unique(array_map(
                'intval',
                array_column(array_merge($payslips, $orphans), 'id'),
            )));
            if ($ids !== []) {
                $db->table('payslip_details')->whereIn('payslip_id', $ids)->delete();
                $db->table('payslips')->where('company_id', $companyId)->whereIn('id', $ids)->delete();
            }
            if ($db->transStatus() === false) throw new \RuntimeException('Failed to clear recalculated payslips');
            $db->transCommit();
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $period @return list<array<string,mixed>> */
    public function employeesForPeriod(array $period, int $companyId): array
    {
        $this->requireCompany($companyId);
        return $this->connection()->table('employees')->where('company_id', $companyId)
            ->where('join_date <=', $period['end_date'])
            ->groupStart()->where('is_active', 1)->orGroupStart()
                ->where('resign_date >=', $period['start_date'])
                ->where('resign_date <=', $period['end_date'])
            ->groupEnd()->groupEnd()->get()->getResultArray();
    }

    /** @return array<int,list<array<string,mixed>>> */
    public function adjustmentsByEmployee(int $periodId, int $companyId): array
    {
        $this->requireCompany($companyId);
        $db = $this->connection();
        if (! $db->tableExists('payroll_adjustments')) return [];
        $rows = $db->table('payroll_adjustments')->where('company_id', $companyId)
            ->where('target_period_id', $periodId)->whereIn('status', ['APPROVED', 'POSTED'])
            ->orderBy('id', 'ASC')->get()->getResultArray();
        return $this->groupByEmployee($rows);
    }

    /** @return array<int,list<array<string,mixed>>> */
    public function oneTimeComponentsByEmployee(int $periodId, int $companyId): array
    {
        $this->requireCompany($companyId);
        $db = $this->connection();
        if (! $db->tableExists('payroll_one_time_components')) return [];
        $rows = $db->table('payroll_one_time_components')->where('company_id', $companyId)
            ->where('payroll_period_id', $periodId)->orderBy('id', 'ASC')->get()->getResultArray();
        return $this->groupByEmployee($rows);
    }

    /** @param array<string,float|int> $totals */
    public function completeCalculation(int $periodId, int $companyId, int $userId, array $totals): void
    {
        $this->requireCompany($companyId);
        $db = $this->connection();
        $now = date('Y-m-d H:i:s');
        $db->transBegin();
        try {
            $db->table('payroll_periods')->where('id', $periodId)->where('company_id', $companyId)->update([
                'status' => 'CALCULATED', 'total_gross' => $totals['total_gross'],
                'total_deduction' => $totals['total_deduction'], 'total_net' => $totals['total_net'],
                'prepared_by' => $userId > 0 ? $userId : null, 'prepared_at' => $now,
                'submitted_by' => null, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null,
                'snapshot_json' => null, 'snapshot_hash' => null, 'snapshot_created_at' => null, 'updated_at' => $now,
            ]);
            if ($db->tableExists('payroll_period_approvals')) {
                $db->table('payroll_period_approvals')->where('company_id', $companyId)
                    ->where('payroll_period_id', $periodId)->delete();
            }
            // Required payroll control table: fail closed if migrations are
            // incomplete. tableExists() is deliberately avoided because CI's
            // schema cache can be stale in long-running workers/tests.
            $reversalRows = $db->table('payroll_adjustments')->select('reversal_of_id')
                ->where('company_id', $companyId)->where('target_period_id', $periodId)
                ->where('status', 'APPROVED')->where('reversal_of_id IS NOT NULL')
                ->get()->getResultArray();
            $db->table('payroll_adjustments')->where('company_id', $companyId)
                ->where('target_period_id', $periodId)->where('status', 'APPROVED')->update([
                    'status' => 'POSTED', 'posted_by' => $userId > 0 ? $userId : null,
                    'posted_at' => $now, 'updated_at' => $now,
                ]);
            $reversedIds = array_values(array_unique(array_map(
                static fn (array $row): int => (int) $row['reversal_of_id'],
                $reversalRows
            )));
            if ($reversedIds !== []) {
                $db->table('payroll_adjustments')->where('company_id', $companyId)
                    ->whereIn('id', $reversedIds)->where('status', 'POSTED')->update([
                        'status' => 'REVERSED', 'updated_at' => $now,
                    ]);
            }
            if ($db->transStatus() === false) throw new \RuntimeException('Failed to complete payroll calculation');
            $db->transCommit();
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /** @param list<array<string,mixed>> $rows @return array<int,list<array<string,mixed>>> */
    private function groupByEmployee(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) $grouped[(int) $row['employee_id']][] = $row;
        return $grouped;
    }
}
