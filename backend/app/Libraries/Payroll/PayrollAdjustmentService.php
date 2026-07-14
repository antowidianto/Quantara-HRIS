<?php

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;

/** Append-only payroll correction workflow; historical payslips are never mutated. */
final class PayrollAdjustmentService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    public function create(int $companyId, int $userId, array $data): array
    {
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $originalId = (int) ($data['original_period_id'] ?? 0);
        $targetId = (int) ($data['target_period_id'] ?? 0);
        $type = strtoupper((string) ($data['adjustment_type'] ?? ''));
        $amount = (float) ($data['amount'] ?? 0);
        $reason = trim((string) ($data['reason'] ?? ''));
        $triggerType = RetroTriggerPolicy::normalize($data['trigger_type'] ?? null);
        if ($employeeId <= 0 || $originalId <= 0 || $targetId <= 0
            || ! in_array($type, ['EARNING', 'DEDUCTION'], true) || $amount <= 0 || mb_strlen($reason) < 10) {
            throw new \InvalidArgumentException('Invalid payroll adjustment payload');
        }
        $original = $this->period($companyId, $originalId);
        $target = $this->period($companyId, $targetId);
        if (! in_array($original['status'], ['FINALIZED', 'PAID', 'CLOSED'], true)) {
            throw new \RuntimeException('Original payroll must be finalized');
        }
        if (! in_array($target['status'], ['DRAFT', 'CALCULATED'], true)) {
            throw new \RuntimeException('Target payroll must still be editable');
        }
        if ($this->db->table('payslips')->where('company_id', $companyId)
            ->where('payroll_period_id', $originalId)->where('employee_id', $employeeId)->countAllResults() === 0) {
            throw new \InvalidArgumentException('Employee has no payslip in the original period');
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('payroll_adjustments')->insert([
            'company_id' => $companyId, 'employee_id' => $employeeId,
            'original_period_id' => $originalId, 'target_period_id' => $targetId,
            'adjustment_type' => $type, 'amount' => $amount, 'reason' => $reason,
            'trigger_type' => $triggerType,
            'is_taxable' => array_key_exists('is_taxable', $data) ? (int) ((bool) $data['is_taxable']) : 1,
            'status' => 'DRAFT', 'created_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return $this->find($companyId, (int) $this->db->insertID());
    }

    public function approve(int $companyId, int $id, int $userId): array
    {
        $row = $this->find($companyId, $id);
        if ($row['status'] !== 'DRAFT') throw new \RuntimeException('Only draft adjustment can be approved');
        if ((int) $row['created_by'] === $userId) throw new \DomainException('Adjustment maker cannot approve it');
        $this->db->table('payroll_adjustments')->where('id', $id)->where('company_id', $companyId)->update([
            'status' => 'APPROVED', 'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->find($companyId, $id);
    }

    public function reverse(int $companyId, int $id, int $userId, int $targetPeriodId, string $reason): array
    {
        $row = $this->find($companyId, $id);
        if ($row['status'] !== 'POSTED') throw new \RuntimeException('Only posted adjustment can be reversed');
        $target = $this->period($companyId, $targetPeriodId);
        if (! in_array($target['status'], ['DRAFT', 'CALCULATED'], true)) {
            throw new \RuntimeException('Reversal target payroll must still be editable');
        }
        if (mb_strlen(trim($reason)) < 10) throw new \InvalidArgumentException('Reversal reason is required');
        $now = date('Y-m-d H:i:s');
        $this->db->transStart();
        $this->db->table('payroll_adjustments')->insert([
            'company_id' => $companyId, 'employee_id' => $row['employee_id'],
            'original_period_id' => $row['original_period_id'], 'target_period_id' => $targetPeriodId,
            'reversal_of_id' => $id,
            'adjustment_type' => $row['adjustment_type'] === 'EARNING' ? 'DEDUCTION' : 'EARNING',
            'amount' => $row['amount'], 'reason' => trim($reason), 'status' => 'APPROVED',
            'is_taxable' => $row['is_taxable'],
            'created_by' => $userId, 'approved_by' => $userId, 'approved_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $newId = (int) $this->db->insertID();
        $this->db->table('payroll_adjustments')->where('id', $id)->where('company_id', $companyId)
            ->update(['status' => 'REVERSED', 'updated_at' => $now]);
        $this->db->transComplete();
        if (! $this->db->transStatus()) throw new \RuntimeException('Adjustment reversal failed');
        return $this->find($companyId, $newId);
    }

    public function find(int $companyId, int $id): array
    {
        $row = $this->db->table('payroll_adjustments')->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException('Payroll adjustment not found');
        return $row;
    }

    private function period(int $companyId, int $id): array
    {
        $row = $this->db->table('payroll_periods')->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException('Payroll period not found');
        return $row;
    }
}
