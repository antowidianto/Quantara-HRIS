<?php

namespace App\Libraries\Payroll;

use App\Models\Payroll\LoanModel;
use App\Models\Payroll\LoanInstallmentModel;
use CodeIgniter\Database\Exceptions\DatabaseException;

class LoanService
{
    protected $db;
    protected $loanModel;
    protected $installmentModel;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->loanModel = new LoanModel();
        $this->installmentModel = new LoanInstallmentModel();
    }

    /**
     * Apply for a new loan.
     */
    public function applyLoan(int $companyId, int $employeeId, float $principal, float $interestRate, int $tenorMonths, string $reason): array
    {
        // Calculate flat interest
        // Interest rate is flat percentage per year.
        // Formula: Total Interest = Principal * (Interest Rate / 100) * (Tenor / 12)
        $totalInterest = $principal * ($interestRate / 100) * ($tenorMonths / 12);
        $totalAmount = $principal + $totalInterest;
        $installmentAmount = $totalAmount / $tenorMonths;

        // Generate Loan Number
        $year = date('Y');
        $count = $this->loanModel->where('company_id', $companyId)->where('YEAR(created_at)', $year)->countAllResults();
        $loanNumber = sprintf('LOAN-%s-%04d', $year, $count + 1);

        $data = [
            'company_id'         => $companyId,
            'employee_id'        => $employeeId,
            'loan_number'        => $loanNumber,
            'principal'          => $principal,
            'interest_rate'      => $interestRate,
            'total_interest'     => $totalInterest,
            'total_amount'       => $totalAmount,
            'installments'       => $tenorMonths,
            'installment_amount' => $installmentAmount,
            'paid_installments'  => 0,
            'remaining'          => $totalAmount,
            'status'             => 'PENDING',
            'reason'             => $reason,
        ];

        $loanId = $this->loanModel->createScoped($data);
        if (!$loanId) {
            throw new \RuntimeException('Failed to apply for loan');
        }

        return $this->loanModel->findScoped($loanId);
    }

    /**
     * Approve a loan and generate installments.
     */
    public function approveLoan(int $loanId, int $approverUserId, string $firstInstallmentDate): bool
    {
        $loan = $this->loanModel->findScoped($loanId);
        if (!$loan || $loan['status'] !== 'PENDING') {
            throw new \InvalidArgumentException('Loan not found or not in PENDING status');
        }

        $this->db->transStart();

        $this->loanModel->updateScoped($loanId, [
            'status'       => 'RUNNING',
            'approved_by'  => $approverUserId,
            'approved_at'  => date('Y-m-d H:i:s'),
        ]);

        $installmentAmount = (float)$loan['installment_amount'];
        $tenor = (int)$loan['installments'];
        $dueDate = new \DateTime($firstInstallmentDate);

        for ($i = 1; $i <= $tenor; $i++) {
            $this->installmentModel->createScoped([
                'loan_id'            => $loanId,
                'company_id'         => $loan['company_id'],
                'installment_number' => $i,
                'due_date'           => $dueDate->format('Y-m-d'),
                'amount'             => $installmentAmount,
                'status'             => 'UNPAID',
            ]);

            // Increment to next month
            $dueDate->modify('+1 month');
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            throw new DatabaseException('Failed to approve loan and generate installments');
        }

        return true;
    }

    /**
     * Reject a loan application.
     */
    public function rejectLoan(int $loanId, int $approverUserId): bool
    {
        $loan = $this->loanModel->findScoped($loanId);
        if (!$loan || $loan['status'] !== 'PENDING') {
            throw new \InvalidArgumentException('Loan not found or not in PENDING status');
        }

        return $this->loanModel->updateScoped($loanId, [
            'status'      => 'REJECTED',
            'approved_by' => $approverUserId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
