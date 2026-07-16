<?php

namespace App\Libraries\Attendance;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class OvertimeApprovalWorkflowService
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    /** @return array<string,mixed> */
    public function approve(int $id, int $companyId, int $userId): array
    {
        $db = $this->db();
        $overtime = $this->pending($id, $companyId);
        $employee = $db->table('employees')->where('id', (int) $overtime['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $employee) {
            throw new OvertimeApprovalException('Employee not found in active tenant', 'EMPLOYEE_NOT_FOUND', 404);
        }

        $now = date('Y-m-d H:i:s');
        $compensationType = strtoupper((string) ($overtime['compensation_type'] ?? 'CASH'));
        $update = [
            'status' => 'APPROVED',
            'approved_by' => $userId,
            'approved_at' => $now,
            'updated_at' => $now,
        ];
        $amount = 0.0;
        $creditedHours = null;
        $balanceId = null;

        if ($compensationType === 'LEAVE') {
            [$creditedHours, $balanceId, $ratio] = $this->creditCompensatoryLeave($overtime, $companyId, $now);
            $update += [
                'leave_balance_id' => $balanceId,
                'leave_hours_credited' => $creditedHours,
                'leave_conversion_ratio' => $ratio,
                'computed_amount' => 0,
            ];
        } else {
            $compensationType = 'CASH';
            $amount = (new OvertimeCalculator())->calculate(
                (float) $employee['basic_salary'],
                (float) $overtime['hours'],
                (string) $overtime['day_type'],
            );
            $update['computed_amount'] = $amount;
        }

        $db->table('overtimes')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING')->update($update);
        if ($db->affectedRows() !== 1) {
            throw new OvertimeApprovalException('Overtime state changed; reload and retry', 'APPROVAL_CONFLICT', 409);
        }

        return [
            'overtime' => $overtime,
            'employee' => $employee,
            'compensation_type' => $compensationType,
            'amount' => $amount,
            'leave_hours_credited' => $creditedHours,
            'leave_balance_id' => $balanceId,
        ];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new OvertimeApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED');
        }
        $db = $this->db();
        $overtime = $this->pending($id, $companyId);
        $now = date('Y-m-d H:i:s');
        $db->table('overtimes')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING')->update([
                'status' => 'REJECTED',
                'approved_by' => $userId,
                'approved_at' => $now,
                'rejection_note' => $reason,
                'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new OvertimeApprovalException('Overtime state changed; reload and retry', 'APPROVAL_CONFLICT', 409);
        }
        $employee = $db->table('employees')->where('id', (int) $overtime['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();

        return ['overtime' => $overtime, 'employee' => $employee, 'reason' => $reason];
    }

    /** @return array<string,mixed> */
    private function pending(int $id, int $companyId): array
    {
        $row = $this->db()->table('overtimes')->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new OvertimeApprovalException('Overtime not found', 'RESOURCE_NOT_FOUND', 404);
        if ($row['status'] !== 'PENDING') {
            throw new OvertimeApprovalException('Overtime is not pending', 'APPROVAL_INVALID_STATE');
        }
        return $row;
    }

    /** @return array{0:float,1:int,2:float} */
    private function creditCompensatoryLeave(array $overtime, int $companyId, string $now): array
    {
        $db = $this->db();
        $ratio = (float) $this->setting($companyId, 'overtime_to_leave_ratio', '1.0');
        if ($ratio <= 0) $ratio = 1.0;
        $maxDays = (int) $this->setting($companyId, 'overtime_to_leave_max_days_year', '12');
        if ($maxDays <= 0) $maxDays = 12;

        $leaveType = $db->table('leave_types')->where('company_id', $companyId)
            ->where('source', 'OVERTIME_COMP')->where('is_active', 1)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $leaveType) {
            throw new OvertimeApprovalException(
                'Leave type "Cuti Pengganti Lembur" belum dikonfigurasi',
                'OVERTIME_LEAVE_TYPE_MISSING'
            );
        }

        $year = (int) date('Y', strtotime((string) $overtime['work_date']));
        $balance = $db->table('leave_balances')->where('company_id', $companyId)
            ->where('employee_id', (int) $overtime['employee_id'])
            ->where('leave_type_id', (int) $leaveType['id'])->where('period_year', $year)
            ->get()->getRowArray();
        if (! $balance) {
            $db->table('leave_balances')->insert([
                'company_id' => $companyId,
                'employee_id' => (int) $overtime['employee_id'],
                'leave_type_id' => (int) $leaveType['id'],
                'period_year' => $year,
                'allocated' => 0,
                'carried_over' => 0,
                'used' => 0,
                'remaining' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $balance = $db->table('leave_balances')->where('id', (int) $db->insertID())
                ->where('company_id', $companyId)->get()->getRowArray();
        }
        if (! $balance) throw new OvertimeApprovalException('Gagal membuat saldo cuti pengganti');

        $creditedHours = round((float) $overtime['hours'] * $ratio, 2);
        $hoursPerDay = 8.0;
        $allocated = (float) $balance['allocated'];
        $availableHours = max(0.0, ($maxDays * $hoursPerDay) - ($allocated * $hoursPerDay));
        if ($availableHours <= 0) {
            throw new OvertimeApprovalException("Kuota cuti pengganti tahun {$year} sudah penuh", 'OVERTIME_LEAVE_CAP_REACHED');
        }
        $creditedHours = min($creditedHours, $availableHours);
        $newAllocated = $allocated + round($creditedHours / $hoursPerDay, 2);
        $newRemaining = $newAllocated + (float) $balance['carried_over'] - (float) $balance['used'];

        $db->table('leave_balances')->where('id', (int) $balance['id'])
            ->where('company_id', $companyId)->where('allocated', $balance['allocated'])->update([
                'allocated' => $newAllocated,
                'remaining' => $newRemaining,
                'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new OvertimeApprovalException('Saldo cuti berubah; muat ulang dan coba lagi', 'BALANCE_CONFLICT', 409);
        }

        return [$creditedHours, (int) $balance['id'], $ratio];
    }

    private function setting(int $companyId, string $key, string $default): string
    {
        $db = $this->db();
        if (! $db->tableExists('settings')) return $default;
        $row = $db->table('settings')->where('company_id', $companyId)
            ->where('`key`', $key)->get()->getRowArray();
        return $row ? (string) $row['value'] : $default;
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
