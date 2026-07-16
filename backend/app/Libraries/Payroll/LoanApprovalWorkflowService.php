<?php

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class LoanApprovalWorkflowService
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    /** @return array<string,mixed> */
    public function approve(int $id, int $companyId, int $userId): array
    {
        $db = $this->db();
        $loan = $this->pending($id, $companyId);
        $firstDate = trim((string) ($loan['first_installment_date'] ?? ''));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $firstDate);
        if (! $date || $date->format('Y-m-d') !== $firstDate) {
            throw new LoanApprovalException('Tanggal cicilan pertama tidak valid', 'FIRST_INSTALLMENT_DATE_REQUIRED');
        }
        if ((int) $loan['installments'] < 1 || (float) $loan['installment_amount'] <= 0) {
            throw new LoanApprovalException('Konfigurasi cicilan pinjaman tidak valid', 'INVALID_INSTALLMENT_PLAN');
        }
        if ($db->table('loan_installments')->where('company_id', $companyId)
            ->where('loan_id', $id)->countAllResults() > 0) {
            throw new LoanApprovalException('Jadwal cicilan sudah terbentuk', 'INSTALLMENT_PLAN_CONFLICT', 409);
        }

        $now = date('Y-m-d H:i:s');
        $db->table('loans')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING')->update([
                'status' => 'RUNNING', 'approved_by' => $userId,
                'approved_at' => $now, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new LoanApprovalException('Request berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }

        $tenor = (int) $loan['installments'];
        for ($number = 1; $number <= $tenor; $number++) {
            $db->table('loan_installments')->insert([
                'loan_id' => $id,
                'company_id' => $companyId,
                'installment_number' => $number,
                'due_date' => $date->format('Y-m-d'),
                'amount' => $loan['installment_amount'],
                'status' => 'UNPAID',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $date = $date->modify('+1 month');
        }
        $employee = $db->table('employees')->where('id', (int) $loan['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        return ['loan' => $loan, 'employee' => $employee, 'installments_created' => $tenor];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new LoanApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED');
        }
        $db = $this->db();
        $loan = $this->pending($id, $companyId);
        $now = date('Y-m-d H:i:s');
        $db->table('loans')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING')->update([
                'status' => 'REJECTED', 'approved_by' => $userId,
                'approved_at' => $now, 'rejection_note' => $reason, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new LoanApprovalException('Request berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        return ['loan' => $loan, 'reason' => $reason];
    }

    /** @return array<string,mixed> */
    private function pending(int $id, int $companyId): array
    {
        $row = $this->db()->table('loans')->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new LoanApprovalException('Loan not found', 'RESOURCE_NOT_FOUND', 404);
        if ($row['status'] !== 'PENDING') {
            throw new LoanApprovalException('Loan is not pending', 'APPROVAL_INVALID_STATE');
        }
        return $row;
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
