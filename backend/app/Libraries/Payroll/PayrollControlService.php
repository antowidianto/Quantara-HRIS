<?php

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;

/** Enforces payroll state transitions and four-eyes controls outside HTTP. */
final class PayrollControlService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    public function submit(int $companyId, int $periodId, int $userId): array
    {
        $period = $this->period($companyId, $periodId);
        if ($period['status'] !== 'CALCULATED') {
            throw new \RuntimeException('Only a calculated payroll can be submitted');
        }
        if ((int) ($period['variance_requires_review'] ?? 0) === 1
            && empty($period['variance_reviewed_at'])
        ) {
            throw new \RuntimeException('Material payroll variance must be reviewed before submission');
        }
        $reconciliation = (new PayrollReconciliationService($this->db))->analyze($companyId, $periodId);
        if (! $reconciliation['is_reconciled']) {
            throw new \RuntimeException('Resolve payroll reconciliation exceptions before submission');
        }
        if ($this->db->tableExists('payroll_period_approvals')) {
            $this->db->table('payroll_period_approvals')
                ->where('company_id', $companyId)->where('payroll_period_id', $periodId)->delete();
        }
        $this->db->table('payroll_periods')->where('id', $periodId)->where('company_id', $companyId)->update([
            'submitted_by' => $userId,
            'submitted_at' => date('Y-m-d H:i:s'),
            'approved_by' => null,
            'approved_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->period($companyId, $periodId);
    }

    public function approve(int $companyId, int $periodId, int $userId): array
    {
        $period = $this->period($companyId, $periodId);
        if ($period['status'] !== 'CALCULATED' || empty($period['submitted_at'])) {
            throw new \RuntimeException('Payroll must be submitted before approval');
        }
        if ((int) ($period['prepared_by'] ?? 0) === $userId) {
            throw new \DomainException('Payroll preparer cannot approve their own payroll');
        }

        $required = $this->requiredApprovals($period);
        $now = date('Y-m-d H:i:s');
        $inserted = $this->db->table('payroll_period_approvals')->ignore(true)->insert([
            'company_id' => $companyId,
            'payroll_period_id' => $periodId,
            'approved_by' => $userId,
            'approval_type' => 'STANDARD',
            'created_at' => $now,
        ]);
        if ($inserted === false) {
            throw new \RuntimeException('Unable to record payroll approval');
        }
        $count = $this->db->table('payroll_period_approvals')
            ->where('company_id', $companyId)->where('payroll_period_id', $periodId)
            ->where('approval_type', 'STANDARD')->countAllResults();
        if ($count < $required) {
            $pending = $this->period($companyId, $periodId);
            $pending['approval_progress'] = ['approved' => $count, 'required' => $required];
            return $pending;
        }

        [$snapshotJson, $snapshotHash] = $this->buildSnapshot($period);
        $this->db->table('payroll_periods')->where('id', $periodId)->where('company_id', $companyId)->update([
            'status' => 'APPROVED',
            'approved_by' => $userId,
            'approved_at' => $now,
            'snapshot_json' => $snapshotJson,
            'snapshot_hash' => $snapshotHash,
            'snapshot_created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $approved = $this->period($companyId, $periodId);
        $approved['approval_progress'] = ['approved' => $count, 'required' => $required];
        return $approved;
    }

    public function breakGlassApprove(
        int $companyId,
        int $periodId,
        int $userId,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $period = $this->period($companyId, $periodId);
        if ($period['status'] !== 'CALCULATED') {
            throw new \RuntimeException('Break-glass is only available for calculated payroll');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 20) {
            throw new \InvalidArgumentException('Break-glass reason must be at least 20 characters');
        }

        [$snapshotJson, $snapshotHash] = $this->buildSnapshot($period);
        $now = date('Y-m-d H:i:s');
        $this->db->transStart();
        $this->db->table('payroll_period_approvals')->insert([
            'company_id' => $companyId,
            'payroll_period_id' => $periodId,
            'approved_by' => $userId,
            'approval_type' => 'BREAK_GLASS',
            'reason' => $reason,
            'ip_address' => $ipAddress,
            'user_agent' => mb_substr((string) $userAgent, 0, 255),
            'created_at' => $now,
        ]);
        $this->db->table('payroll_periods')->where('id', $periodId)->where('company_id', $companyId)->update([
            'status' => 'APPROVED',
            'submitted_by' => $userId,
            'submitted_at' => $now,
            'approved_by' => $userId,
            'approved_at' => $now,
            'snapshot_json' => $snapshotJson,
            'snapshot_hash' => $snapshotHash,
            'snapshot_created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            throw new \RuntimeException('Break-glass approval transaction failed');
        }
        return $this->period($companyId, $periodId);
    }

    public function assertCanFinalize(int $companyId, int $periodId, int $userId): array
    {
        $period = $this->period($companyId, $periodId);
        if ($period['status'] !== 'APPROVED') {
            throw new \RuntimeException('Payroll must be approved before finalization');
        }
        if ((int) ($period['prepared_by'] ?? 0) === $userId) {
            throw new \DomainException('Payroll preparer cannot finalize their own payroll');
        }
        if (empty($period['snapshot_hash']) || empty($period['snapshot_json'])) {
            throw new \RuntimeException('Approved payroll snapshot is missing');
        }
        (new PayrollSnapshotService($this->db))->assertValid($period);
        return $period;
    }

    private function period(int $companyId, int $periodId): array
    {
        $row = $this->db->table('payroll_periods')
            ->where('id', $periodId)->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) {
            throw new \InvalidArgumentException('Payroll period not found');
        }
        return $row;
    }

    private function requiredApprovals(array $period): int
    {
        $threshold = (new PayrollSettings())->highValueApprovalThreshold((int) $period['company_id']);
        return $threshold > 0 && (float) $period['total_net'] >= $threshold ? 2 : 1;
    }

    private function buildSnapshot(array $period): array
    {
        return (new PayrollSnapshotService($this->db))->build($period);
    }
}
