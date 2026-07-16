<?php

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class ReimbursementApprovalWorkflowService
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    /** @return array<string,mixed> */
    public function approve(int $id, int $companyId, int $userId): array
    {
        $db = $this->db();
        $reimbursement = $this->pending($id, $companyId);
        $policy = $db->table('benefit_policies')->where('company_id', $companyId)
            ->where('name', (string) $reimbursement['category'])->get()->getRowArray();
        $balanceId = null;
        if ($policy) {
            $year = (int) date('Y', strtotime((string) $reimbursement['expense_date']));
            $balance = $db->table('employee_benefit_balances')->where('company_id', $companyId)
                ->where('employee_id', (int) $reimbursement['employee_id'])
                ->where('policy_id', (int) $policy['id'])->where('year', $year)
                ->get()->getRowArray();
            $amount = (float) $reimbursement['amount'];
            if ($balance) {
                $used = (float) $balance['used_amount'];
                $allocated = (float) $balance['allocated_amount'];
                if ($used + $amount > $allocated) {
                    throw new ReimbursementApprovalException(
                        'Saldo benefit tidak cukup saat final approval',
                        'BENEFIT_BALANCE_EXCEEDED'
                    );
                }
                $db->table('employee_benefit_balances')->where('id', (int) $balance['id'])
                    ->where('company_id', $companyId)->where('used_amount', $balance['used_amount'])
                    ->update(['used_amount' => $used + $amount, 'updated_at' => date('Y-m-d H:i:s')]);
                if ($db->affectedRows() !== 1) {
                    throw new ReimbursementApprovalException(
                        'Saldo benefit berubah; muat ulang dan coba lagi',
                        'BENEFIT_BALANCE_CONFLICT',
                        409
                    );
                }
                $balanceId = (int) $balance['id'];
            } else {
                if ($amount > (float) $policy['limit_amount']) {
                    throw new ReimbursementApprovalException(
                        'Klaim melebihi plafon benefit',
                        'BENEFIT_BALANCE_EXCEEDED'
                    );
                }
                $db->table('employee_benefit_balances')->insert([
                    'company_id' => $companyId,
                    'employee_id' => (int) $reimbursement['employee_id'],
                    'policy_id' => (int) $policy['id'],
                    'year' => $year,
                    'allocated_amount' => $policy['limit_amount'],
                    'used_amount' => $amount,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $balanceId = (int) $db->insertID();
            }
        }

        $now = date('Y-m-d H:i:s');
        $db->table('reimbursements')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING')->update([
                'status' => 'APPROVED', 'approved_by' => $userId,
                'approved_at' => $now, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new ReimbursementApprovalException('Request berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        $employee = $db->table('employees')->where('id', (int) $reimbursement['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        return [
            'reimbursement' => $reimbursement,
            'employee' => $employee,
            'benefit_balance_id' => $balanceId,
        ];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ReimbursementApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED');
        }
        $db = $this->db();
        $reimbursement = $this->pending($id, $companyId);
        $now = date('Y-m-d H:i:s');
        $db->table('reimbursements')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING')->update([
                'status' => 'REJECTED', 'approved_by' => $userId,
                'approved_at' => $now, 'rejection_note' => $reason, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new ReimbursementApprovalException('Request berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        $employee = $db->table('employees')->where('id', (int) $reimbursement['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        return ['reimbursement' => $reimbursement, 'employee' => $employee, 'reason' => $reason];
    }

    /** @return array<string,mixed> */
    private function pending(int $id, int $companyId): array
    {
        $row = $this->db()->table('reimbursements')->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new ReimbursementApprovalException('Reimbursement not found', 'RESOURCE_NOT_FOUND', 404);
        if ($row['status'] !== 'PENDING') {
            throw new ReimbursementApprovalException('Reimbursement is not pending', 'APPROVAL_INVALID_STATE');
        }
        return $row;
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
