<?php

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;

/** Creates append-only salary corrections when a JIC is implemented retroactively. */
final class JicRetroPayrollService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    public static function salaryDelta(
        float $oldMonthlySalary,
        float $newMonthlySalary,
        string $periodStart,
        string $periodEnd,
        string $effectiveDate,
    ): float {
        $start = new \DateTimeImmutable(max($periodStart, $effectiveDate));
        $end = new \DateTimeImmutable($periodEnd);
        if ($start > $end) {
            return 0.0;
        }

        $periodDays = (new \DateTimeImmutable($periodStart))->diff($end)->days + 1;
        $affectedDays = $start->diff($end)->days + 1;

        return round(($newMonthlySalary - $oldMonthlySalary) * $affectedDays / max(1, $periodDays), 2);
    }

    /** Resolve the original monthly salary from the immutable finalized snapshot. */
    public static function snapshotMonthlySalary(
        string $snapshotJson,
        string $snapshotHash,
        int $employeeId,
    ): float {
        if ($snapshotJson === '' || $snapshotHash === '' || ! hash_equals($snapshotHash, hash('sha256', $snapshotJson))) {
            throw new \RuntimeException('Original payroll snapshot integrity check failed');
        }

        $snapshot = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
        foreach (($snapshot['payslips'] ?? []) as $payslip) {
            if ((int) ($payslip['employee_id'] ?? 0) !== $employeeId) continue;
            $salary = (float) ($payslip['employee_master']['basic_salary'] ?? 0);
            if ($salary <= 0) {
                throw new \RuntimeException('Original payroll snapshot lacks the employee salary basis');
            }
            return $salary;
        }

        throw new \RuntimeException('Employee is absent from the original payroll snapshot');
    }

    /**
     * @return list<int> Created adjustment IDs.
     */
    public function createForJic(array $jic, int $actorUserId): array
    {
        $companyId = (int) ($jic['company_id'] ?? 0);
        $employeeId = (int) ($jic['employee_id'] ?? 0);
        $jicId = (int) ($jic['id'] ?? 0);
        $effectiveDate = (string) ($jic['effective_date'] ?? '');
        $oldSalary = (float) ($jic['old_basic_salary'] ?? 0);
        $newSalary = (float) ($jic['new_basic_salary'] ?? $oldSalary);

        if ($companyId <= 0 || $employeeId <= 0 || $jicId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException('JIC retro context is incomplete');
        }
        if ($effectiveDate === '' || $effectiveDate >= date('Y-m-d') || $newSalary === $oldSalary) {
            return [];
        }

        $originals = $this->db->table('payroll_periods')
            ->where('company_id', $companyId)
            ->whereIn('status', ['FINALIZED', 'PAID', 'CLOSED'])
            ->where('end_date >=', $effectiveDate)
            ->where('start_date <', date('Y-m-d'))
            ->orderBy('start_date', 'ASC')->get()->getResultArray();
        if ($originals === []) {
            return [];
        }

        $lastEnd = (string) end($originals)['end_date'];
        $target = $this->db->table('payroll_periods')
            ->where('company_id', $companyId)
            ->whereIn('status', ['DRAFT', 'CALCULATED'])
            ->where('start_date >', $lastEnd)
            ->orderBy('start_date', 'ASC')->get()->getRowArray();
        if (! $target) {
            throw new \RuntimeException('Create an editable payroll period before implementing this retroactive JIC');
        }

        $created = [];
        $now = date('Y-m-d H:i:s');
        foreach ($originals as $original) {
            if ($this->db->table('payslips')->where('company_id', $companyId)
                ->where('payroll_period_id', (int) $original['id'])
                ->where('employee_id', $employeeId)->countAllResults() === 0) {
                continue;
            }
            if ($this->db->table('payroll_adjustments')->where('company_id', $companyId)
                ->where('source_type', 'JIC')->where('source_id', $jicId)
                ->where('original_period_id', (int) $original['id'])->countAllResults() > 0) {
                continue;
            }

            $snapshotSalary = self::snapshotMonthlySalary(
                (string) ($original['snapshot_json'] ?? ''),
                (string) ($original['snapshot_hash'] ?? ''),
                $employeeId,
            );
            $delta = self::salaryDelta(
                $snapshotSalary,
                $newSalary,
                (string) $original['start_date'],
                (string) $original['end_date'],
                $effectiveDate,
            );
            if (abs($delta) < 0.01) {
                continue;
            }

            $this->db->table('payroll_adjustments')->insert([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'original_period_id' => (int) $original['id'],
                'target_period_id' => (int) $target['id'],
                'source_type' => 'JIC',
                'source_id' => $jicId,
                'trigger_type' => 'SALARY',
                'adjustment_type' => $delta > 0 ? 'EARNING' : 'DEDUCTION',
                'amount' => abs($delta),
                'reason' => sprintf('Koreksi gaji retroaktif JIC #%d efektif %s', $jicId, $effectiveDate),
                'is_taxable' => 1,
                'status' => 'DRAFT',
                'created_by' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created[] = (int) $this->db->insertID();
        }

        return $created;
    }
}
