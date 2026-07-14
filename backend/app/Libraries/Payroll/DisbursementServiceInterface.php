<?php

namespace App\Libraries\Payroll;

interface DisbursementServiceInterface
{
    /**
     * Executes disbursement for a list of payslip IDs.
     * 
     * @param array $payslipIds
     * @return array Returns an array containing ['success' => bool, 'batch_id' => string|null, 'error' => string|null]
     */
    public function disbursePayroll(array $payslipIds, int $companyId): array;

    /**
     * Checks the status of a specific disbursement transaction.
     * 
     * @param string $referenceId The reference ID or batch ID from the gateway
     * @return array Returns an array containing ['status' => string, 'details' => array]
     */
    public function checkDisbursementStatus(string $referenceId): array;
}
