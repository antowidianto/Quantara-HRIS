<?php

namespace App\Controllers\Api\V1\Employee;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Employee\EmployeeEffectiveHistoryService;

final class EmployeeEffectiveHistoryController extends ApiController
{
    public function index(int $employeeId)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;
        try {
            return $this->ok((new EmployeeEffectiveHistoryService())->history($companyId, $employeeId));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'INVALID_EFFECTIVE_HISTORY');
        }
    }

    public function showAsOf(int $employeeId)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;
        try {
            $date = (string) $this->request->getGet('date');
            $snapshot = (new EmployeeEffectiveHistoryService())->asOf($companyId, $employeeId, $date);
            return $snapshot === null ? $this->fail('Snapshot tidak ditemukan.', 404, 'SNAPSHOT_NOT_FOUND') : $this->ok($snapshot);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'INVALID_AS_OF_DATE');
        }
    }

    public function create(int $employeeId)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;
        $input = $this->getJsonInput();
        try {
            $row = (new EmployeeEffectiveHistoryService())->schedule(
                $companyId,
                $employeeId,
                (string) ($input['valid_from'] ?? ''),
                is_array($input['changes'] ?? null) ? $input['changes'] : [],
                $this->userId(),
                'MANUAL',
                null,
                $this->userId(),
            );
            return $this->ok($row, 'Perubahan efektif telah disetujui dan dijadwalkan.', 201);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422, 'EFFECTIVE_CHANGE_REJECTED');
        }
    }
}
