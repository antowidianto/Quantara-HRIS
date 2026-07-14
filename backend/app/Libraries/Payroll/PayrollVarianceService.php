<?php

declare(strict_types=1);

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;

final class PayrollVarianceService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    public function analyzeAndStore(int $companyId, int $periodId, float $thresholdPercent = 10.0): array
    {
        $period = $this->period($companyId, $periodId);
        $previous = $this->db->table('payroll_periods')
            ->where('company_id', $companyId)
            ->where('start_date <', $period['start_date'])
            ->whereIn('status', ['APPROVED', 'PAID', 'CLOSED'])
            ->orderBy('start_date', 'DESC')->limit(1)->get()->getRowArray();

        $currentRows = $this->payslips($companyId, $periodId);
        $previousRows = $previous ? $this->payslips($companyId, (int) $previous['id']) : [];
        $analysis = self::compare($currentRows, $previousRows, $thresholdPercent);
        $analysis['period_id'] = $periodId;
        $analysis['baseline_period_id'] = $previous ? (int) $previous['id'] : null;

        $this->db->table('payroll_periods')
            ->where('id', $periodId)->where('company_id', $companyId)
            ->update([
                'variance_json' => json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'variance_requires_review' => $analysis['requires_review'] ? 1 : 0,
                'variance_reviewed_by' => null,
                'variance_reviewed_at' => null,
                'variance_review_note' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $analysis;
    }

    public function review(int $companyId, int $periodId, int $reviewerId, string $note): array
    {
        $period = $this->period($companyId, $periodId);
        if ($period['status'] !== 'CALCULATED') {
            throw new \RuntimeException('Variance can only be reviewed for calculated payroll');
        }
        if (empty($period['variance_json'])) {
            throw new \RuntimeException('Payroll variance analysis is missing; recalculate the period');
        }
        $note = trim($note);
        if ((int) ($period['variance_requires_review'] ?? 0) === 1 && mb_strlen($note) < 10) {
            throw new \InvalidArgumentException('Material payroll variance review note must be at least 10 characters');
        }

        $this->db->table('payroll_periods')
            ->where('id', $periodId)->where('company_id', $companyId)
            ->update([
                'variance_reviewed_by' => $reviewerId,
                'variance_reviewed_at' => date('Y-m-d H:i:s'),
                'variance_review_note' => $note !== '' ? $note : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->period($companyId, $periodId);
    }

    public static function compare(array $currentRows, array $previousRows, float $thresholdPercent = 10.0): array
    {
        $thresholdPercent = max(0.0, $thresholdPercent);
        $current = self::byEmployee($currentRows);
        $previous = self::byEmployee($previousRows);
        $currentNet = array_sum(array_column($current, 'take_home_pay'));
        $previousNet = array_sum(array_column($previous, 'take_home_pay'));
        $totalVariancePercent = self::percentageChange($currentNet, $previousNet);
        $exceptions = [];

        foreach (array_unique(array_merge(array_keys($current), array_keys($previous))) as $employeeId) {
            $now = $current[$employeeId] ?? null;
            $before = $previous[$employeeId] ?? null;
            $percent = self::percentageChange(
                (float) ($now['take_home_pay'] ?? 0),
                (float) ($before['take_home_pay'] ?? 0),
            );
            if ($now === null || $before === null || abs($percent) >= $thresholdPercent) {
                $exceptions[] = [
                    'employee_id' => (int) $employeeId,
                    'employee_name' => $now['employee_name'] ?? $before['employee_name'] ?? null,
                    'previous_take_home_pay' => (float) ($before['take_home_pay'] ?? 0),
                    'current_take_home_pay' => (float) ($now['take_home_pay'] ?? 0),
                    'variance_percent' => $percent,
                    'reason' => $before === null ? 'NEW_EMPLOYEE' : ($now === null ? 'MISSING_EMPLOYEE' : 'MATERIAL_CHANGE'),
                ];
            }
        }

        $baselineMissing = $previousRows === [];
        return [
            'threshold_percent' => $thresholdPercent,
            'baseline_missing' => $baselineMissing,
            'previous_total_net' => round($previousNet, 2),
            'current_total_net' => round($currentNet, 2),
            'total_variance_percent' => $totalVariancePercent,
            'exceptions' => $baselineMissing ? [] : $exceptions,
            'requires_review' => ! $baselineMissing
                && (abs($totalVariancePercent) >= $thresholdPercent || $exceptions !== []),
        ];
    }

    private static function byEmployee(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['employee_id']] = [
                'employee_name' => $row['employee_name'] ?? null,
                'take_home_pay' => (float) ($row['take_home_pay'] ?? 0),
            ];
        }
        return $result;
    }

    private static function percentageChange(float $current, float $previous): float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : 100.0;
        }
        return round(($current - $previous) / abs($previous) * 100, 2);
    }

    private function payslips(int $companyId, int $periodId): array
    {
        return $this->db->table('payslips')
            ->select('employee_id, employee_name, take_home_pay')
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $periodId)
            ->get()->getResultArray();
    }

    private function period(int $companyId, int $periodId): array
    {
        $period = $this->db->table('payroll_periods')
            ->where('id', $periodId)->where('company_id', $companyId)->get()->getRowArray();
        if (! $period) {
            throw new \InvalidArgumentException('Payroll period not found');
        }
        return $period;
    }
}
