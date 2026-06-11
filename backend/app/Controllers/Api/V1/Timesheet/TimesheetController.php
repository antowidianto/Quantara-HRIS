<?php

namespace App\Controllers\Api\V1\Timesheet;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Timesheet\TimesheetService;
use Config\Database;

/**
 * Timesheet entry submission, approval, dan reporting.
 *
 * Endpoints:
 *   GET    /api/v1/timesheet                       ?from=&to=&project_id=&status=
 *   GET    /api/v1/timesheet/my                    ?from=&to=
 *   POST   /api/v1/timesheet                        upsert (insert/update DRAFT)
 *   POST   /api/v1/timesheet/{id}/submit
 *   POST   /api/v1/timesheet/{id}/approve
 *   POST   /api/v1/timesheet/{id}/reject
 *   GET    /api/v1/timesheet/utilization            ?employee_id=&from=&to=
 */
class TimesheetController extends ApiController
{
    private TimesheetService $service;

    public function __construct()
    {
        $this->service = new TimesheetService();
    }

    public function index()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');

        $b = Database::connect()->table('timesheet_entries')
            ->where('company_id', $companyId);
        if ($from = $this->request->getGet('from'))       $b->where('work_date >=', $from);
        if ($to   = $this->request->getGet('to'))         $b->where('work_date <=', $to);
        if ($pid  = $this->request->getGet('project_id')) $b->where('project_id', (int) $pid);
        if ($s    = $this->request->getGet('status'))     $b->where('status', $s);
        if ($eid  = $this->request->getGet('employee_id'))$b->where('employee_id', (int) $eid);
        $rows = $b->orderBy('work_date', 'DESC')->limit(500)->get()->getResultArray();
        return $this->ok($rows);
    }

    public function my()
    {
        $companyId  = $this->companyId();
        $employeeId = $this->resolveCurrentEmployeeId();
        if ($employeeId === null) return $this->fail('Employee not linked', 422, 'NO_EMPLOYEE');

        $from = (string) ($this->request->getGet('from') ?: date('Y-m-01'));
        $to   = (string) ($this->request->getGet('to')   ?: date('Y-m-d'));

        $rows = Database::connect()->table('timesheet_entries')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('work_date >=', $from)
            ->where('work_date <=', $to)
            ->orderBy('work_date', 'DESC')->orderBy('id', 'DESC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function upsert()
    {
        $companyId  = $this->requireCompanyId();
        if ($companyId === null) return null;
        $employeeId = $this->resolveCurrentEmployeeId();
        if ($employeeId === null) return $this->fail('Employee not linked', 422, 'NO_EMPLOYEE');

        try {
            $id = $this->service->upsertEntry($companyId, $employeeId, $this->getJsonInput());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'UPSERT_FAILED');
        }
        return $this->ok(['id' => $id], 'Saved as DRAFT', 201);
    }

    public function submit(int $id)
    {
        $employeeId = $this->resolveCurrentEmployeeId();
        if ($employeeId === null) return $this->fail('Employee not linked', 422, 'NO_EMPLOYEE');
        try {
            $this->service->submit($id, $employeeId);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'SUBMIT_FAILED');
        }
        return $this->ok(['id' => $id, 'status' => 'SUBMITTED']);
    }

    public function approve(int $id)
    {
        try {
            $this->service->approve($id, $this->userId());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'APPROVE_FAILED');
        }
        return $this->ok(['id' => $id, 'status' => 'APPROVED']);
    }

    public function reject(int $id)
    {
        $body = $this->validateOrFail($this->getJsonInput(), [
            'reason' => 'required|min_length[3]',
        ]);
        if ($body === null) return null;
        try {
            $this->service->reject($id, $this->userId(), (string) $body['reason']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'REJECT_FAILED');
        }
        return $this->ok(['id' => $id, 'status' => 'REJECTED']);
    }

    public function utilization()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');

        $employeeId = (int) ($this->request->getGet('employee_id') ?: 0);
        if ($employeeId <= 0) {
            $employeeId = $this->resolveCurrentEmployeeId() ?? 0;
            if ($employeeId <= 0) return $this->fail('employee_id required', 422, 'EMPLOYEE_REQUIRED');
        }
        $from = (string) ($this->request->getGet('from') ?: date('Y-m-01'));
        $to   = (string) ($this->request->getGet('to')   ?: date('Y-m-d'));

        return $this->ok($this->service->utilizationFor($companyId, $employeeId, $from, $to));
    }

    /**
     * Resolve employee_id berdasarkan user_id login. Pattern existing
     * di codebase: tabel `employees.user_id` link ke users.id.
     */
    private function resolveCurrentEmployeeId(): ?int
    {
        $userId = $this->userId();
        if ($userId <= 0) return null;
        $db = Database::connect();
        if (! $db->tableExists('employees')) return null;
        $row = $db->table('employees')
            ->select('id')
            ->where('user_id', $userId)
            ->limit(1)->get()->getRowArray();
        return $row ? (int) $row['id'] : null;
    }
}