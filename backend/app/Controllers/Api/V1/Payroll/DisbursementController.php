<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Payroll\MidtransIrisDisbursementService;

class DisbursementController extends ApiController
{
    /**
     * Trigger disbursement for a finalized payroll period.
     */
    public function disburse($periodId)
    {
        $db = \Config\Database::connect();
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->fail('Company context required', 422, 'COMPANY_REQUIRED');
        
        // 1. Check period
        $period = $db->table('payroll_periods')
            ->where('id', (int) $periodId)
            ->where('company_id', $companyId)
            ->get()->getRowArray();

        if (! $period) {
            return $this->fail('Payroll period not found', 404);
        }

        // Only allow if finalized and not already disbursed/in-progress
        if ($period['status'] !== 'FINALIZED') {
            return $this->fail('Payroll period must be FINALIZED before disbursement', 422);
        }

        if (in_array($period['disbursement_status'], ['IN_PROGRESS', 'COMPLETED'])) {
            return $this->fail("Disbursement is already {$period['disbursement_status']}", 422);
        }

        // 2. Fetch all payslips for this period that have take_home_pay > 0
        $payslips = $db->table('payslips')
            ->select('id')
            ->where('payroll_period_id', $period['id'])
            ->where('company_id', $companyId)
            ->where('take_home_pay >', 0)
            ->where('disbursement_status !=', 'COMPLETED')
            ->get()->getResultArray();

        if (empty($payslips)) {
            return $this->fail('No eligible payslips found for disbursement', 422);
        }

        $payslipIds = array_column($payslips, 'id');

        // 3. Call Iris Service
        $irisService = new MidtransIrisDisbursementService();
        $result = $irisService->disbursePayroll($payslipIds);

        if ($result['success']) {
            // Update period status
            $db->table('payroll_periods')->where('id', $period['id'])->where('company_id', $companyId)->update([
                'disbursement_status' => 'IN_PROGRESS',
                'disbursement_reference' => $result['batch_id'],
            ]);

            \Config\Services::audit()->log('disbursement.start', 'payroll_periods', $period['id'], "Started mass disbursement via Iris. Batch ID: {$result['batch_id']}");

            return $this->ok([
                'batch_id' => $result['batch_id'],
                'message' => 'Disbursement initiated successfully'
            ]);
        } else {
            // Update period error
            $db->table('payroll_periods')->where('id', $period['id'])->where('company_id', $companyId)->update([
                'disbursement_status' => 'FAILED',
                'disbursement_error' => $result['error'],
            ]);

            \Config\Services::audit()->log('disbursement.failed', 'payroll_periods', $period['id'], "Failed to start mass disbursement: {$result['error']}");

            return $this->fail($result['error'] ?? 'Disbursement failed', 500);
        }
    }

    /**
     * Check the status of a disbursement batch
     */
    public function checkStatus($periodId)
    {
        $db = \Config\Database::connect();
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->fail('Company context required', 422, 'COMPANY_REQUIRED');
        
        $period = $db->table('payroll_periods')
            ->where('id', (int) $periodId)
            ->where('company_id', $companyId)
            ->get()->getRowArray();

        if (! $period) {
            return $this->fail('Payroll period not found', 404);
        }

        if (! $period['disbursement_reference']) {
            return $this->fail('No disbursement reference found for this period', 400);
        }

        $irisService = new MidtransIrisDisbursementService();
        $result = $irisService->checkDisbursementStatus($period['disbursement_reference']);

        // Sync period status if it has changed
        if ($result['status'] !== 'UNKNOWN' && $result['status'] !== $period['disbursement_status']) {
            $db->table('payroll_periods')->where('id', $period['id'])->where('company_id', $companyId)->update([
                'disbursement_status' => $result['status'],
                'status' => $result['status'] === 'COMPLETED' ? 'PAID' : $period['status'],
            ]);
            
            // Assume if batch is completed, all payslips are completed for now. 
            // In a real app, you would check per-payslip status via Iris API.
            if ($result['status'] === 'COMPLETED') {
                $db->table('payslips')
                    ->where('disbursement_reference', $period['disbursement_reference'])
                    ->where('company_id', $companyId)
                    ->update(['disbursement_status' => 'COMPLETED']);
            }
        }

        return $this->ok([
            'status' => $result['status'],
            'details' => $result['details']
        ]);
    }
}
