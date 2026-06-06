<?php

namespace App\Libraries\Payroll;

use Config\Services;

class MidtransIrisDisbursementService implements DisbursementServiceInterface
{
    protected $serverKey;
    protected $baseUrl;

    public function __construct()
    {
        // Typically read from .env
        // e.g. IRIS_SERVER_KEY=...
        $this->serverKey = env('IRIS_SERVER_KEY', 'sandbox-server-key');
        $isProduction = env('IRIS_IS_PRODUCTION', false);
        $this->baseUrl = $isProduction 
            ? 'https://app.midtrans.com/iris/api/v1' 
            : 'https://app.sandbox.midtrans.com/iris/api/v1';
    }

    /**
     * Executes disbursement for a list of payslip IDs.
     * 
     * @param array $payslipIds
     * @return array
     */
    public function disbursePayroll(array $payslipIds): array
    {
        $db = \Config\Database::connect();
        
        // Fetch payslips with employee bank details
        $builder = $db->table('payslips p')
            ->select('p.id as payslip_id, p.take_home_pay, e.full_name as employee_name, e.bank_account_number, e.bank_name, e.email')
            ->join('employees e', 'e.id = p.employee_id')
            ->whereIn('p.id', $payslipIds);
            
        $payslips = $builder->get()->getResultArray();
        
        if (empty($payslips)) {
            return ['success' => false, 'error' => 'No payslips found for disbursement'];
        }

        $payouts = [];
        foreach ($payslips as $slip) {
            // Mapping bank codes. Iris needs standard bank codes (e.g. bca, bni, bri)
            // Assuming e.bank_name is already standardized in the system.
            $payouts[] = [
                'beneficiary_name' => $slip['employee_name'],
                'beneficiary_account' => $slip['bank_account_number'],
                'beneficiary_bank' => strtolower($slip['bank_name']),
                'beneficiary_email' => $slip['email'],
                'amount' => (string) $slip['take_home_pay'],
                'notes' => 'Salary Disbursement',
            ];
        }

        $payload = [
            'payouts' => $payouts
        ];

        // Send to Midtrans Iris via cURL or Guzzle
        $client = \Config\Services::curlrequest([
            'baseURI' => $this->baseUrl . '/',
        ]);

        try {
            $response = $client->post('payouts', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody(), true);

            if ($statusCode >= 200 && $statusCode < 300) {
                $batchId = $body['reference_no'] ?? uniqid('batch-');
                
                // Update payslips status to IN_PROGRESS
                $db->table('payslips')->whereIn('id', $payslipIds)->update([
                    'disbursement_status' => 'IN_PROGRESS',
                    'disbursement_reference' => $batchId,
                ]);

                return ['success' => true, 'batch_id' => $batchId, 'error' => null];
            } else {
                $errorMsg = $body['error_message'] ?? 'Failed to send to Iris';
                return ['success' => false, 'error' => $errorMsg];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Checks the status of a specific disbursement transaction.
     * 
     * @param string $referenceId The reference ID or batch ID from the gateway
     * @return array
     */
    public function checkDisbursementStatus(string $referenceId): array
    {
        $client = \Config\Services::curlrequest([
            'baseURI' => $this->baseUrl . '/',
        ]);

        try {
            $response = $client->get("payouts/{$referenceId}", [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody(), true);

            if ($statusCode >= 200 && $statusCode < 300) {
                // Determine our internal status from Iris status
                // Iris statuses: queued, processing, completed, failed, rejected
                $status = 'PENDING';
                $irisStatus = $body['status'] ?? '';
                
                if ($irisStatus === 'completed') {
                    $status = 'COMPLETED';
                } elseif (in_array($irisStatus, ['failed', 'rejected'])) {
                    $status = 'FAILED';
                } elseif (in_array($irisStatus, ['queued', 'processing'])) {
                    $status = 'IN_PROGRESS';
                }

                return ['status' => $status, 'details' => $body];
            }

            return ['status' => 'UNKNOWN', 'details' => $body];
        } catch (\Exception $e) {
            return ['status' => 'UNKNOWN', 'details' => ['error' => $e->getMessage()]];
        }
    }
}
