<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Payroll\MasterRateApprovalService;
use Config\Services;

final class MasterRateChangeController extends ApiController
{
    public function createAllowanceType() { return $this->submit('ALLOWANCE_TYPE', 'CREATE', null); }
    public function updateAllowanceType($id = null) { return $this->submit('ALLOWANCE_TYPE', 'UPDATE', (int) $id); }
    public function deleteAllowanceType($id = null) { return $this->submit('ALLOWANCE_TYPE', 'DELETE', (int) $id); }
    public function createAllowanceRate() { return $this->submit('ALLOWANCE_RATE', 'CREATE', null); }
    public function updateAllowanceRate($id = null) { return $this->submit('ALLOWANCE_RATE', 'UPDATE', (int) $id); }
    public function deleteAllowanceRate($id = null) { return $this->submit('ALLOWANCE_RATE', 'DELETE', (int) $id); }
    public function createPayrollComponent() { return $this->submit('PAYROLL_COMPONENT', 'CREATE', null); }
    public function updatePayrollComponent($id = null) { return $this->submit('PAYROLL_COMPONENT', 'UPDATE', (int) $id); }
    public function deletePayrollComponent($id = null) { return $this->submit('PAYROLL_COMPONENT', 'DELETE', (int) $id); }

    private function submit(string $type, string $action, ?int $targetId)
    {
        try {
            $companyId = $this->requireCompanyId(); if ($companyId === null) return null;
            $result = (new MasterRateApprovalService())->submit($type, $action, $targetId, $this->getJsonInput(), $companyId, $this->userId());
            Services::audit()->forModule('PAYROLL')->log(
                action: 'master_rate.submit', entity: 'master_rate_change_requests',
                entityId: (int) ($result['request']['id'] ?? 0),
                description: "{$action} {$type} diajukan untuk approval Finance checker",
            );
            return $this->ok($result, 'Perubahan master rate diajukan untuk approval', 202);
        } catch (\InvalidArgumentException $e) { return $this->fail($e->getMessage(), 422); }
        catch (\Throwable $e) { return $this->fail($e->getMessage(), 409); }
    }
}
