<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Payroll\PayrollAdjustmentService;
use App\Libraries\Payroll\PayrollAdjustmentApprovalException;
use App\Libraries\Payroll\PayrollAdjustmentApprovalWorkflowService;
use Config\Services;

final class PayrollAdjustmentController extends ApiController
{
    public function index()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $rows = \Config\Database::connect()->table('payroll_adjustments')
            ->where('company_id', $companyId)->orderBy('id', 'DESC')->limit(200)->get()->getResultArray();
        return $this->ok($rows);
    }

    public function create()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        try {
            $row = (new PayrollAdjustmentService())->create($companyId, $this->userId(), $this->getJsonInput());
            Services::audit()->forModule('PAYROLL')->logCreate(
                'payroll_adjustment', (int) $row['id'], $row, 'Payroll correction created without rewriting history'
            );
            return $this->ok($row, 'Payroll adjustment created', 201);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'PAYROLL_ADJUSTMENT_INVALID');
        }
    }

    public function submit($id = null)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        try {
            $result = (new PayrollAdjustmentApprovalWorkflowService())->submit(
                (int) $id, $companyId, $this->userId()
            );
            Services::audit()->forModule('PAYROLL')->log(
                'payroll_adjustment.submit', 'payroll_adjustment', (int) $id,
                after: ['approval_request_id' => $result['approval']['request_id'] ?? null],
                description: 'Payroll adjustment submitted to governed approval', severity: 'CRITICAL'
            );
            return $this->ok($result, 'Payroll adjustment submitted for approval');
        } catch (PayrollAdjustmentApprovalException $e) {
            return $this->fail($e->getMessage(), $e->httpStatus, $e->errorCode);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'PAYROLL_ADJUSTMENT_BLOCKED');
        }
    }

    public function reverse($id = null)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $data = $this->getJsonInput();
        try {
            $row = (new PayrollAdjustmentService())->reverse(
                $companyId, (int) $id, $this->userId(),
                (int) ($data['target_period_id'] ?? 0), (string) ($data['reason'] ?? '')
            );
            Services::audit()->forModule('PAYROLL')->log(
                'payroll_adjustment.reverse', 'payroll_adjustment', (int) $id,
                after: ['reversal_adjustment_id' => $row['id']],
                description: 'Posted payroll adjustment reversed through a later period', severity: 'CRITICAL'
            );
            return $this->ok($row, 'Draft reversal created; submit it for approval');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'PAYROLL_REVERSAL_BLOCKED');
        }
    }

    public function cancel($id = null)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $data = $this->getJsonInput();
        try {
            $row = (new PayrollAdjustmentService())->cancel(
                $companyId, (int) $id, $this->userId(), (string) ($data['reason'] ?? '')
            );
            Services::audit()->forModule('PAYROLL')->log(
                'payroll_adjustment.cancel', 'payroll_adjustment', (int) $id,
                after: ['reason' => $data['reason'] ?? null],
                description: 'Payroll adjustment cancelled with pending workflow closure', severity: 'WARNING'
            );
            return $this->ok($row, 'Payroll adjustment cancelled');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'PAYROLL_ADJUSTMENT_CANCEL_BLOCKED');
        }
    }
}
