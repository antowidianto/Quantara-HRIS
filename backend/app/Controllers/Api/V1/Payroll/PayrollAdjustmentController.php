<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Payroll\PayrollAdjustmentService;
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

    public function approve($id = null)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        try {
            $row = (new PayrollAdjustmentService())->approve($companyId, (int) $id, $this->userId());
            Services::audit()->forModule('PAYROLL')->log(
                'payroll_adjustment.approve', 'payroll_adjustment', (int) $row['id'],
                description: 'Payroll adjustment independently approved', severity: 'CRITICAL'
            );
            return $this->ok($row, 'Payroll adjustment approved');
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage(), 403, 'SEGREGATION_OF_DUTIES');
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
            return $this->ok($row, 'Reversal adjustment created');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'PAYROLL_REVERSAL_BLOCKED');
        }
    }
}
