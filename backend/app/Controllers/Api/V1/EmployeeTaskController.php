<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * EmployeeTaskController
 *
 * REST API untuk task instance per karyawan (Onboarding/Offboarding).
 *
 * Authorization:
 *   - `onboarding.view` → list/show all (HR/admin)
 *   - `onboarding.manage` → create instance, generate dari template, edit semua
 *   - `onboarding.complete_self` → complete task yang ditugaskan kepadanya
 */
class EmployeeTaskController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $tenant = $this->tenant();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(200, max(1, (int) ($this->request->getGet('per_page') ?? 50)));

        $employeeId = (int) ($this->request->getGet('employee_id') ?? 0);
        $type = strtoupper((string) ($this->request->getGet('type') ?? ''));
        $status = strtoupper((string) ($this->request->getGet('status') ?? ''));
        $assignedToMe = (int) ($this->request->getGet('assigned_to_me') ?? 0) === 1;
        $overdue = (int) ($this->request->getGet('overdue') ?? 0) === 1;

        $builder = $db->table('employee_tasks et')
            ->select('et.*, e.full_name AS employee_name, e.employee_number, e.join_date, e.resign_date,'
                . ' au.name AS assignee_name')
            ->join('employees e', 'e.id = et.employee_id')
            ->join('users au', 'au.id = et.assignee_user_id', 'left')
            ->where('et.deleted_at IS NULL');
        $tenant->applyScope($builder, 'et.company_id');

        $canViewAll = $tenant->isSuperAdmin()
            || $tenant->hasPermission('onboarding.view')
            || $tenant->hasPermission('onboarding.manage');
        if (! $canViewAll || $assignedToMe) {
            $builder->where('et.assignee_user_id', $tenant->getUserId());
        }

        if ($employeeId && $canViewAll) $builder->where('et.employee_id', $employeeId);
        if (in_array($type, ['ONBOARD','OFFBOARD'], true)) $builder->where('et.type', $type);
        if (in_array($status, ['PENDING','IN_PROGRESS','COMPLETED','SKIPPED','CANCELLED'], true)) {
            $builder->where('et.status', $status);
        }
        if ($overdue) {
            $builder->where('et.status', 'PENDING')
                ->where('et.due_date IS NOT NULL')
                ->where('et.due_date <', date('Y-m-d'));
        }

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('et.due_date', 'ASC')
            ->orderBy('et.sort_order', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        return $this->ok($row);
    }

    /**
     * GET /employee-tasks/employee/(:num)?type=ONBOARD
     * Daftar task + progress per karyawan untuk panel di profil.
     */
    public function forEmployee($employeeId = null)
    {
        $employeeId = (int) $employeeId;
        $type = strtoupper((string) ($this->request->getGet('type') ?? ''));
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);

        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $emp['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }
        $canViewAll = $tenant->isSuperAdmin()
            || $tenant->hasPermission('onboarding.view')
            || $tenant->hasPermission('onboarding.manage');
        if (! $canViewAll && $tenant->getEmployeeId() !== $employeeId) {
            return $this->fail('Forbidden', 403);
        }

        $builder = $db->table('employee_tasks et')
            ->select('et.*, au.name AS assignee_name')
            ->join('users au', 'au.id = et.assignee_user_id', 'left')
            ->where('et.employee_id', $employeeId)
            ->where('et.deleted_at IS NULL')
            ->orderBy('et.type')
            ->orderBy('et.sort_order')
            ->orderBy('et.id');
        if (in_array($type, ['ONBOARD','OFFBOARD'], true)) $builder->where('et.type', $type);
        $tasks = $builder->get()->getResultArray();

        return $this->ok([
            'employee' => $emp,
            'tasks'    => $tasks,
            'progress' => Services::onboarding()->progress($employeeId, $type ?: null),
        ]);
    }

    /**
     * POST /employee-tasks/generate
     * Body: { employee_id, type, template_id?, anchor_date? }
     * Generate task instance dari template untuk satu karyawan.
     */
    public function generate()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'employee_id' => 'required|integer',
            'type'        => 'required|in_list[ONBOARD,OFFBOARD]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', (int) $data['employee_id'])->get()->getRowArray();
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $emp['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        // Anchor: ONBOARD pakai join_date; OFFBOARD pakai resign_date atau today
        $anchor = $data['anchor_date'] ?? null;
        if (! $anchor) {
            $anchor = $data['type'] === 'ONBOARD'
                ? ($emp['join_date'] ?? date('Y-m-d'))
                : ($emp['resign_date'] ?? date('Y-m-d'));
        }

        try {
            $result = Services::onboarding()->applyTemplate(
                (int) $emp['id'],
                ! empty($data['template_id']) ? (int) $data['template_id'] : null,
                $data['type'],
                $anchor
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        Services::audit()->forModule('HR')->log(
            action: 'employee_tasks.generate',
            entity: 'employee', entityId: (int) $emp['id'],
            after: ['type' => $data['type'], 'created' => $result['created']],
            description: "Generate {$data['type']} tasks: {$result['created']} created, {$result['skipped']} skipped",
            severity: 'NOTICE'
        );
        return $this->ok($result, 'Tasks generated', 201);
    }

    /**
     * POST /employee-tasks (manual create satu task ad-hoc)
     */
    public function create()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'employee_id' => 'required|integer',
            'type'        => 'required|in_list[ONBOARD,OFFBOARD]',
            'task_name'   => 'required|max_length[200]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', (int) $data['employee_id'])->get()->getRowArray();
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);

        $assigneeId = ! empty($data['assignee_user_id'])
            ? (int) $data['assignee_user_id']
            : Services::onboarding()->resolveAssignee((string) ($data['owner_role'] ?? 'HR'), $emp);

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'      => (int) $emp['company_id'],
            'employee_id'     => (int) $emp['id'],
            'type'            => $data['type'],
            'task_key'        => ! empty($data['task_key']) ? strtoupper((string) $data['task_key']) : null,
            'task_name'       => $data['task_name'],
            'description'     => $data['description'] ?? null,
            'owner_role'      => ! empty($data['owner_role']) ? strtoupper((string) $data['owner_role']) : null,
            'assignee_user_id'=> $assigneeId,
            'due_date'        => $data['due_date'] ?? null,
            'mandatory'       => isset($data['mandatory']) ? (int) (bool) $data['mandatory'] : 1,
            'sort_order'      => (int) ($data['sort_order'] ?? 999),
            'status'          => 'PENDING',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $db->table('employee_tasks')->insert($insert);
        $id = (int) $db->insertID();
        $insert['id'] = $id;
        if ($assigneeId) Services::onboarding()->notifyAssignee($insert);

        return $this->ok(['id' => $id], 'Task dibuat', 201);
    }

    /**
     * POST /employee-tasks/(:num)/complete
     */
    public function complete($id = null)
    {
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        // Yang boleh complete: assignee, atau user dgn onboarding.manage
        $tenant = $this->tenant();
        $userId = $tenant->getUserId();
        $isAssignee = ((int) $row['assignee_user_id'] === $userId);
        $canManage = $tenant->isSuperAdmin() || $tenant->hasPermission('onboarding.manage');
        if (! $isAssignee && ! $canManage) {
            return $this->fail('Hanya assignee atau HR yang bisa complete', 403);
        }
        if ($row['status'] === 'COMPLETED') return $this->fail('Sudah complete', 422);
        if (in_array($row['status'], ['CANCELLED', 'SKIPPED'], true)) {
            return $this->fail('Task sudah ' . $row['status'], 422);
        }

        $data = $this->getJsonInput();
        $now = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('employee_tasks')->where('id', (int) $id)->update([
            'status'          => 'COMPLETED',
            'completed_at'    => $now,
            'completed_by'    => $userId,
            'completion_note' => $data['note'] ?? null,
            'evidence_path'   => $data['evidence_path'] ?? $row['evidence_path'],
            'updated_at'      => $now,
        ]);
        Services::audit()->forModule('HR')->log(
            action: 'employee_task.complete',
            entity: 'employee_task', entityId: (int) $id,
            description: "Complete task: {$row['task_name']}",
            severity: 'NOTICE'
        );
        return $this->ok(null, 'Task selesai');
    }

    public function update($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'task_name', 'description', 'owner_role', 'assignee_user_id',
            'due_date', 'mandatory', 'sort_order', 'status',
        ]));
        if (isset($update['status']) && ! in_array($update['status'], ['PENDING','IN_PROGRESS','SKIPPED','CANCELLED'], true)) {
            return $this->fail('Status tidak valid (gunakan endpoint /complete untuk complete)', 422);
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('employee_tasks')->where('id', (int) $id)->update($update);
        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        \Config\Database::connect()->table('employee_tasks')->where('id', (int) $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->forModule('HR')->logDelete('employee_task', (int) $id, $row);
        return $this->ok(null, 'Deleted');
    }

    /**
     * GET /employee-tasks/board?type=ONBOARD
     * Kanban view: list karyawan dengan progress task masing-masing.
     */
    public function board()
    {
        $type = strtoupper((string) ($this->request->getGet('type') ?? 'ONBOARD'));
        if (! in_array($type, ['ONBOARD','OFFBOARD'], true)) {
            return $this->fail('Type harus ONBOARD atau OFFBOARD', 422);
        }

        $db = \Config\Database::connect();
        $tenant = $this->tenant();

        // Ambil karyawan yang punya task type tersebut
        $builder = $db->table('employee_tasks et')
            ->select('et.employee_id, e.full_name, e.employee_number, e.join_date, e.resign_date,'
                . ' p.name AS position_name, d.name AS department_name,'
                . ' COUNT(*) AS total_tasks,'
                . ' SUM(CASE WHEN et.status = "COMPLETED" THEN 1 ELSE 0 END) AS completed_tasks,'
                . ' SUM(CASE WHEN et.status = "PENDING" AND et.due_date < CURRENT_DATE THEN 1 ELSE 0 END) AS overdue_tasks')
            ->join('employees e', 'e.id = et.employee_id')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('et.type', $type)
            ->where('et.deleted_at IS NULL')
            ->groupBy('et.employee_id, e.full_name, e.employee_number, e.join_date, e.resign_date, p.name, d.name')
            ->orderBy($type === 'ONBOARD' ? 'e.join_date' : 'e.resign_date', 'DESC');
        $tenant->applyScope($builder, 'et.company_id');
        $rows = $builder->limit(100)->get()->getResultArray();

        return $this->ok($rows);
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('onboarding.manage');
    }

    private function loadOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('employee_tasks et')
            ->select('et.*, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = et.employee_id')
            ->where('et.id', $id)
            ->where('et.deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Not found', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        // Akses: assignee, employee ybs, atau user dgn onboarding.view
        $userId = $tenant->getUserId();
        $empId = $tenant->getEmployeeId();
        $canViewAll = $tenant->isSuperAdmin()
            || $tenant->hasPermission('onboarding.view')
            || $tenant->hasPermission('onboarding.manage');
        if (! $canViewAll
            && (int) $row['assignee_user_id'] !== $userId
            && (int) $row['employee_id'] !== $empId
        ) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }
}