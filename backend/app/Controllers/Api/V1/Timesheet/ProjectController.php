<?php

namespace App\Controllers\Api\V1\Timesheet;

use App\Controllers\Api\V1\ApiController;
use Config\Database;

/**
 * Project master + assignment management.
 *
 * Endpoints:
 *   GET    /api/v1/projects                       ?status=&q=
 *   POST   /api/v1/projects
 *   GET    /api/v1/projects/{id}
 *   PUT    /api/v1/projects/{id}
 *   DELETE /api/v1/projects/{id}
 *   GET    /api/v1/projects/{id}/assignments
 *   POST   /api/v1/projects/{id}/assignments
 *   PUT    /api/v1/project-assignments/{id}
 *   DELETE /api/v1/project-assignments/{id}
 *   GET    /api/v1/projects/{id}/burn-rate
 */
class ProjectController extends ApiController
{
    public function index()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');
        }
        $b = Database::connect()->table('projects')
            ->where('company_id', $companyId)
            ->where('deleted_at', null);
        if ($s = $this->request->getGet('status')) $b->where('status', $s);
        if ($q = $this->request->getGet('q')) {
            $b->groupStart()
              ->like('code', $q)->orLike('name', $q)->orLike('client_name', $q)
              ->groupEnd();
        }
        $rows = $b->orderBy('id', 'DESC')->limit(500)->get()->getResultArray();
        return $this->ok($rows);
    }

    public function create()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $body = $this->validateOrFail($this->getJsonInput(), [
            'code' => 'required|min_length[2]|max_length[64]',
            'name' => 'required|min_length[3]|max_length[200]',
        ]);
        if ($body === null) return null;

        $db = Database::connect();
        $exists = $db->table('projects')
            ->where('company_id', $companyId)
            ->where('code', $body['code'])
            ->where('deleted_at', null)
            ->countAllResults();
        if ($exists) {
            return $this->fail('Code project sudah dipakai', 409, 'DUPLICATE_CODE');
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'company_id'      => $companyId,
            'cost_center_id'  => isset($body['cost_center_id']) ? (int) $body['cost_center_id'] : null,
            'code'            => $body['code'],
            'name'            => $body['name'],
            'description'     => $body['description']  ?? null,
            'client_name'     => $body['client_name']  ?? null,
            'currency'        => $body['currency']     ?? 'IDR',
            'budget_hours'    => $body['budget_hours'] ?? null,
            'budget_amount'   => $body['budget_amount']?? null,
            'billable_default'=> isset($body['billable_default']) ? (int) (bool) $body['billable_default'] : 1,
            'starts_on'       => $body['starts_on']    ?? null,
            'ends_on'         => $body['ends_on']      ?? null,
            'status'          => $body['status']       ?? 'ACTIVE',
            'project_manager_employee_id' => $body['project_manager_employee_id'] ?? null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $db->table('projects')->insert($row);
        return $this->ok(['id' => (int) $db->insertID()], 'Project created', 201);
    }

    public function show(int $id)
    {
        $companyId = $this->companyId();
        $row = Database::connect()->table('projects')
            ->where('id', $id)->where('company_id', $companyId)->where('deleted_at', null)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404, 'NOT_FOUND');
        return $this->ok($row);
    }

    public function update(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $existing = $db->table('projects')
            ->where('id', $id)->where('company_id', $companyId)->where('deleted_at', null)
            ->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404, 'NOT_FOUND');

        $body = $this->getJsonInput();
        $upd = ['updated_at' => date('Y-m-d H:i:s')];
        foreach ([
            'cost_center_id', 'name', 'description', 'client_name', 'currency',
            'budget_hours', 'budget_amount', 'billable_default',
            'starts_on', 'ends_on', 'status', 'project_manager_employee_id',
        ] as $f) {
            if (array_key_exists($f, $body)) {
                $upd[$f] = $body[$f] === '' ? null : $body[$f];
            }
        }
        $db->table('projects')->where('id', $id)->update($upd);
        return $this->ok(['id' => $id], 'Updated');
    }

    public function delete(int $id)
    {
        $companyId = $this->companyId();
        Database::connect()->table('projects')
            ->where('id', $id)->where('company_id', $companyId)
            ->update(['deleted_at' => date('Y-m-d H:i:s'), 'status' => 'CANCELLED']);
        return $this->ok(null, 'Deleted');
    }

    // ─── Assignments ────────────────────────────────────────────────

    public function listAssignments(int $projectId)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $project = $db->table('projects')
            ->where('id', $projectId)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $project) return $this->fail('Project not found', 404, 'NOT_FOUND');

        $rows = $db->table('project_assignments')
            ->where('project_id', $projectId)
            ->orderBy('is_active', 'DESC')->orderBy('id', 'DESC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createAssignment(int $projectId)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $project = $db->table('projects')
            ->where('id', $projectId)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $project) return $this->fail('Project not found', 404, 'NOT_FOUND');

        $body = $this->validateOrFail($this->getJsonInput(), [
            'employee_id'    => 'required|is_natural_no_zero',
            'allocation_pct' => 'permit_empty|numeric|greater_than[0]|less_than_equal_to[100]',
        ]);
        if ($body === null) return null;

        $exists = $db->table('project_assignments')
            ->where('project_id', $projectId)
            ->where('employee_id', (int) $body['employee_id'])
            ->countAllResults();
        if ($exists) {
            return $this->fail('Employee sudah di-assign ke project ini', 409, 'DUPLICATE_ASSIGNMENT');
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'project_id'    => $projectId,
            'employee_id'   => (int) $body['employee_id'],
            'role'          => $body['role']           ?? null,
            'allocation_pct'=> $body['allocation_pct'] ?? 100,
            'billable_rate' => $body['billable_rate']  ?? null,
            'cost_rate'     => $body['cost_rate']      ?? null,
            'starts_on'     => $body['starts_on']      ?? null,
            'ends_on'       => $body['ends_on']        ?? null,
            'is_active'     => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];
        $db->table('project_assignments')->insert($row);
        return $this->ok(['id' => (int) $db->insertID()], 'Assignment created', 201);
    }

    public function updateAssignment(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $assign = $db->table('project_assignments')->where('id', $id)->get()->getRowArray();
        if (! $assign) return $this->fail('Not found', 404, 'NOT_FOUND');
        $project = $db->table('projects')
            ->where('id', (int) $assign['project_id'])
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $project) return $this->fail('Forbidden', 403, 'FORBIDDEN');

        $body = $this->getJsonInput();
        $upd = ['updated_at' => date('Y-m-d H:i:s')];
        foreach ([
            'role', 'allocation_pct', 'billable_rate', 'cost_rate',
            'starts_on', 'ends_on', 'is_active',
        ] as $f) {
            if (array_key_exists($f, $body)) {
                $upd[$f] = $body[$f] === '' ? null : $body[$f];
            }
        }
        $db->table('project_assignments')->where('id', $id)->update($upd);
        return $this->ok(['id' => $id], 'Updated');
    }

    public function deleteAssignment(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $assign = $db->table('project_assignments')->where('id', $id)->get()->getRowArray();
        if (! $assign) return $this->fail('Not found', 404, 'NOT_FOUND');
        $project = $db->table('projects')
            ->where('id', (int) $assign['project_id'])
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $project) return $this->fail('Forbidden', 403, 'FORBIDDEN');

        $db->table('project_assignments')->where('id', $id)->update([
            'is_active'  => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(null, 'Deactivated');
    }

    public function burnRate(int $id)
    {
        $companyId = $this->companyId();
        $service = new \App\Libraries\Timesheet\TimesheetService();
        return $this->ok($service->burnRateForProject($companyId, $id));
    }
}