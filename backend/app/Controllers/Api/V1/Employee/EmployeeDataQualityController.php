<?php

namespace App\Controllers\Api\V1\Employee;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Employee\EmployeeDataQualityService;

final class EmployeeDataQualityController extends ApiController
{
    public function index()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;
        $limit = max(1, min(100, (int) ($this->request->getGet('sample_limit') ?: 50)));
        return $this->ok((new EmployeeDataQualityService())->dashboard($companyId, $limit));
    }
}
