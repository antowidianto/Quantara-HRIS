<?php

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;
use Config\Services;

/** Pre-disbursement register, bank-account, duplicate, and allocation controls. */
final class PayrollReconciliationService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    public function analyze(int $companyId, int $periodId): array
    {
        $period = $this->db->table('payroll_periods')->where('id', $periodId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $period) throw new \InvalidArgumentException('Payroll period not found');

        $rows = $this->db->table('payslips p')
            ->select('p.id, p.employee_id, p.employee_number, p.employee_name, p.take_home_pay, p.cost_center_id,'
                . ' e.bank_name, e.bank_account_encrypted, e.bank_account_holder')
            ->join('employees e', 'e.id = p.employee_id AND e.company_id = p.company_id')
            ->where('p.company_id', $companyId)->where('p.payroll_period_id', $periodId)
            ->orderBy('p.id', 'ASC')->get()->getResultArray();
        foreach ($rows as &$row) {
            try {
                $row['bank_account'] = $row['bank_account_encrypted']
                    ? Services::encryption()->decrypt((string) $row['bank_account_encrypted']) : '';
            } catch (\Throwable) {
                $row['bank_account'] = '';
            }
            unset($row['bank_account_encrypted']);
        }
        unset($row);

        $analysis = self::analyzeRecords($rows, (float) $period['total_net']);
        $this->db->table('payroll_periods')->where('id', $periodId)->where('company_id', $companyId)->update([
            'reconciliation_json' => json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'reconciliation_checked_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $analysis;
    }

    public static function analyzeRecords(array $rows, float $expectedNet): array
    {
        $exceptions = [];
        $actualNet = round(array_sum(array_map(
            static fn (array $row): float => (float) ($row['take_home_pay'] ?? 0),
            $rows,
        )), 2);
        if (abs($actualNet - round($expectedNet, 2)) > 0.01) {
            $exceptions[] = ['code' => 'REGISTER_TOTAL_MISMATCH', 'expected' => $expectedNet, 'actual' => $actualNet];
        }

        $accounts = [];
        foreach ($rows as $row) {
            if ((float) ($row['take_home_pay'] ?? 0) <= 0) continue;
            $employeeId = (int) ($row['employee_id'] ?? 0);
            $account = preg_replace('/\D+/', '', (string) ($row['bank_account'] ?? '')) ?? '';
            $bank = strtoupper(trim((string) ($row['bank_name'] ?? '')));
            if ($account === '' || $bank === '') {
                $exceptions[] = ['code' => 'MISSING_BANK_ACCOUNT', 'employee_id' => $employeeId];
            } else {
                $accounts[$bank . '|' . $account][] = $employeeId;
            }
            if ((int) ($row['cost_center_id'] ?? 0) <= 0) {
                $exceptions[] = ['code' => 'MISSING_COST_CENTER', 'employee_id' => $employeeId];
            }
        }
        foreach ($accounts as $employeeIds) {
            $employeeIds = array_values(array_unique($employeeIds));
            if (count($employeeIds) > 1) {
                $exceptions[] = ['code' => 'DUPLICATE_BANK_ACCOUNT', 'employee_ids' => $employeeIds];
            }
        }

        return [
            'register_total' => $actualNet,
            'bank_file_total' => $actualNet,
            'record_count' => count($rows),
            'exception_count' => count($exceptions),
            'exceptions' => $exceptions,
            'is_reconciled' => $exceptions === [],
        ];
    }
}
