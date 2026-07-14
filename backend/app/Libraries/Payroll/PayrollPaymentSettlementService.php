<?php

namespace App\Libraries\Payroll;

use App\Libraries\Lifecycle\PkwtCompensationService;
use CodeIgniter\Database\BaseConnection;

/** Applies financial side-effects only after the bank confirms payment. */
final class PayrollPaymentSettlementService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    public function complete(int $companyId, int $periodId): void
    {
        $period = $this->db->table('payroll_periods')->where('id', $periodId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $period || ! in_array($period['status'], ['FINALIZED', 'PAID'], true)) {
            throw new \RuntimeException('Only finalized payroll can be settled');
        }
        // Never apply loan, reimbursement, or contract side-effects from
        // payroll evidence that is missing or has been altered.
        (new PayrollSnapshotService($this->db))->assertValid($period);
        if ($period['status'] === 'PAID') {
            return;
        }

        $this->db->transStart();
        $this->db->table('reimbursements')->where('company_id', $companyId)
            ->where('paid_in_payroll_period_id', $periodId)->where('status', 'APPROVED')
            ->update(['status' => 'PAID']);

        $payslips = $this->db->table('payslips')->where('company_id', $companyId)
            ->where('payroll_period_id', $periodId)->get()->getResultArray();
        foreach ($payslips as $payslip) {
            $remainingDeduction = (float) ($payslip['loan_deduction'] ?? 0);
            $loans = $this->db->table('loans')->where('company_id', $companyId)
                ->where('employee_id', (int) $payslip['employee_id'])->where('status', 'RUNNING')
                ->where('remaining >', 0)->orderBy('id', 'ASC')->get()->getResultArray();
            foreach ($loans as $loan) {
                if ($remainingDeduction <= 0) break;
                $applied = min($remainingDeduction, (float) $loan['installment_amount'], (float) $loan['remaining']);
                $remaining = (float) $loan['remaining'] - $applied;
                $this->db->table('loans')->where('id', (int) $loan['id'])->where('company_id', $companyId)->update([
                    'remaining' => $remaining,
                    'paid_installments' => (int) $loan['paid_installments'] + 1,
                    'status' => $remaining <= 0 ? 'COMPLETED' : 'RUNNING',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $installment = $this->db->table('loan_installments')->where('company_id', $companyId)
                    ->where('loan_id', (int) $loan['id'])->where('status', 'UNPAID')
                    ->orderBy('installment_number', 'ASC')->get()->getRowArray();
                if ($installment) {
                    $this->db->table('loan_installments')->where('id', (int) $installment['id'])
                        ->where('company_id', $companyId)->update([
                            'status' => 'PAID', 'paid_at' => date('Y-m-d H:i:s'),
                            'payroll_period_id' => $periodId, 'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                }
                $remainingDeduction -= $applied;
            }
        }

        if ($this->db->fieldExists('pkwt_compensation_paid', 'employee_contracts')) {
            $contracts = $this->db->table('employee_contracts')->select('id')->where('company_id', $companyId)
                ->where('contract_type', 'PKWT')->where('end_date >=', $period['start_date'])
                ->where('end_date <=', $period['end_date'])->groupStart()
                ->where('pkwt_compensation_paid', 0)->orWhere('pkwt_compensation_paid IS NULL')->groupEnd()
                ->get()->getResultArray();
            $service = new PkwtCompensationService();
            foreach ($contracts as $contract) {
                $calculation = $service->compensationForContract((int) $contract['id']);
                if ($calculation['amount'] > 0) {
                    $service->markPaid((int) $contract['id'], $periodId, (float) $calculation['amount']);
                }
            }
        }

        $this->db->table('payroll_periods')->where('id', $periodId)->where('company_id', $companyId)->update([
            'status' => 'PAID', 'disbursement_status' => 'COMPLETED', 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            throw new \RuntimeException('Payroll payment settlement failed');
        }
    }
}
