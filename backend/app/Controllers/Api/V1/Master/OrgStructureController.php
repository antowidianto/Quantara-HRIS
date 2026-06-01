<?php

namespace App\Controllers\Api\V1\Master;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Approval\ReportingChainService;
use Config\Database;
use Config\Services;

/**
 * Organization Development — Easy Setting.
 *
 * Endpoint untuk halaman "Org Structure" di frontend, di mana admin bisa
 * tarik-lepas (drag-and-drop) untuk:
 *  - Mengubah parent department (pohon hirarki).
 *  - Memindahkan position antar department.
 *  - Mengganti manager department.
 *
 * Schema yang dipakai sudah ada (tidak perlu migrasi tambahan):
 *  - `departments.parent_id` → self-reference untuk hirarki.
 *  - `departments.manager_employee_id` → manager (kepala).
 *  - `positions.department_id` → posisi nempel di department mana.
 *
 * Semua write operation dijalankan dalam transaksi + audit log per entity
 * supaya jejak perubahan struktur organisasi bisa ditelusuri.
 */
class OrgStructureController extends ApiController
{
    /**
     * GET /org-structure
     *
     * Mengembalikan tree gabungan departments → positions → employees.
     * Format friendly buat frontend tree component.
     *
     *   {
     *     "departments": [
     *       { id, name, code, parent_id, manager_employee_id, manager_name,
     *         employee_count, positions: [...], children: [...] }
     *     ],
     *     "stats": { total_departments, total_positions, total_employees }
     *   }
     */
    public function index()
    {
        $db = Database::connect();
        $companyId = $this->companyId();

        // Fetch departments scoped to tenant
        $deptBuilder = $db->table('departments')
            ->select('id, parent_id, code, name, description, manager_employee_id, is_active')
            ->where('deleted_at IS NULL');
        $this->tenant()->applyScope($deptBuilder);
        $departments = $deptBuilder->orderBy('name', 'ASC')->get()->getResultArray();

        // Fetch positions scoped to tenant. Order by tier (manager dulu)
        // lalu nama supaya rapi di tree.
        $posBuilder = $db->table('positions')
            ->select('id, department_id, job_grade_id, code, name, description, tier, is_active')
            ->where('deleted_at IS NULL');
        $this->tenant()->applyScope($posBuilder);
        // ORDER BY portable: pakai CASE WHEN supaya jalan di SQLite + MySQL.
        // (FIELD() adalah MySQL-only.) Manager dulu, lalu turun ke Staff.
        $tierOrder = "CASE tier "
            . "WHEN 'MANAGER' THEN 1 "
            . "WHEN 'ASST_MANAGER' THEN 2 "
            . "WHEN 'SUPERVISOR' THEN 3 "
            . "WHEN 'STAFF' THEN 4 "
            . "ELSE 5 END";
        $positions = $posBuilder
            ->orderBy($tierOrder, '', false)
            ->orderBy('name', 'ASC')
            ->get()
            ->getResultArray();

        // Manager names — single query
        $managerIds = array_filter(array_column($departments, 'manager_employee_id'));
        $managerMap = [];
        if (! empty($managerIds)) {
            $rows = $db->table('employees')
                ->select('id, full_name')
                ->whereIn('id', array_values(array_unique(array_map('intval', $managerIds))))
                ->get()
                ->getResultArray();
            foreach ($rows as $r) {
                $managerMap[(int) $r['id']] = $r['full_name'];
            }
        }

        // Employee count per department
        $countMap = [];
        if (! empty($departments)) {
            $deptIds = array_map('intval', array_column($departments, 'id'));
            $rows = $db->table('employees')
                ->select('department_id, COUNT(*) as cnt')
                ->whereIn('department_id', $deptIds)
                ->where('deleted_at IS NULL')
                ->groupBy('department_id')
                ->get()
                ->getResultArray();
            foreach ($rows as $r) {
                $countMap[(int) $r['department_id']] = (int) $r['cnt'];
            }
        }

        // Group positions by department_id
        $positionsByDept = [];
        $unassignedPositions = [];
        foreach ($positions as $p) {
            $did = $p['department_id'] !== null ? (int) $p['department_id'] : 0;
            if ($did === 0) {
                $unassignedPositions[] = $p;
            } else {
                $positionsByDept[$did][] = $p;
            }
        }

        // Open MPP per department — pengajuan kebutuhan SDM yang masih
        // aktif (DRAFT/SUBMITTED/APPROVED/HIRING). Ditampilkan sebagai
        // "slot kosong" supaya manager tahu ada posisi yang sedang dicari.
        // Skip table belum migrate (early-bird company tanpa MPP migration
        // — tetap aman karena array default kosong).
        $openMppByDept = [];
        $unassignedMpp = [];
        if ($db->tableExists('manpower_requests')) {
            $mppBuilder = $db->table('manpower_requests')
                ->select('id, department_id, reference_number, proposed_title, request_type, reason, headcount, hired_count, status, target_start_date, position_id')
                ->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'HIRING'])
                ->where('deleted_at IS NULL');
            $this->tenant()->applyScope($mppBuilder);
            $openMpps = $mppBuilder->orderBy('created_at', 'DESC')->get()->getResultArray();
            foreach ($openMpps as $m) {
                $did = $m['department_id'] !== null ? (int) $m['department_id'] : 0;
                if ($did === 0) {
                    $unassignedMpp[] = $m;
                } else {
                    $openMppByDept[$did][] = $m;
                }
            }
        }

        // Build tree (self-referencing parent_id)
        $byId = [];
        foreach ($departments as $d) {
            $id = (int) $d['id'];
            $byId[$id] = [
                'id'                  => $id,
                'parent_id'           => $d['parent_id'] !== null ? (int) $d['parent_id'] : null,
                'code'                => $d['code'],
                'name'                => $d['name'],
                'description'         => $d['description'],
                'manager_employee_id' => $d['manager_employee_id'] !== null ? (int) $d['manager_employee_id'] : null,
                'manager_name'        => $d['manager_employee_id']
                    ? ($managerMap[(int) $d['manager_employee_id']] ?? null)
                    : null,
                'is_active'           => (int) $d['is_active'],
                'employee_count'      => $countMap[$id] ?? 0,
                'positions'           => $positionsByDept[$id] ?? [],
                'open_mpps'           => $openMppByDept[$id] ?? [],
                'children'            => [],
            ];
        }

        $roots = [];
        foreach ($byId as $id => &$node) {
            $pid = $node['parent_id'];
            if ($pid !== null && isset($byId[$pid])) {
                $byId[$pid]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        // Stats
        $totalEmployees = 0;
        foreach ($countMap as $c) $totalEmployees += $c;

        // Total open MPP (lintas department) untuk dashboard summary.
        $totalOpenMpp = 0;
        foreach ($openMppByDept as $list) $totalOpenMpp += count($list);
        $totalOpenMpp += count($unassignedMpp);

        return $this->ok([
            'departments'          => $roots,
            'unassigned_positions' => $unassignedPositions,
            'unassigned_mpps'      => $unassignedMpp,
            'stats'                => [
                'total_departments' => count($departments),
                'total_positions'   => count($positions),
                'total_employees'   => $totalEmployees,
                'total_open_mpps'   => $totalOpenMpp,
            ],
            'tiers'                => [
                ['value' => 'MANAGER',      'label' => 'Manager'],
                ['value' => 'ASST_MANAGER', 'label' => 'Assistant Manager'],
                ['value' => 'SUPERVISOR',   'label' => 'Supervisor'],
                ['value' => 'STAFF',        'label' => 'Staff'],
            ],
        ]);
    }

    /**
     * GET /org-structure/department/(:num)
     *
     * Detail department untuk modal "view inside": informasi dept,
     * manager, daftar posisi + karyawan yang menempati, sub-dept
     * sekilas, dan slot MPP terbuka. Setiap karyawan dilengkapi
     * field minimal yang dibutuhkan UI: id, full_name, employee_number,
     * position_id/name, job_grade_name, manager_id, avatar.
     *
     * Endpoint ini sengaja query-heavy sekali (dipanggil saat user
     * buka modal) supaya frontend tidak perlu round-trip per posisi.
     */
    public function departmentDetail($id = null)
    {
        $deptId = (int) $id;
        if ($deptId <= 0) return $this->fail('department_id wajib diisi', 422);

        $db = Database::connect();
        $dept = $db->table('departments')
            ->where('id', $deptId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $dept) return $this->fail('Department tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $dept['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $companyId = (int) $dept['company_id'];

        // Manager nama
        $managerName = null;
        if (! empty($dept['manager_employee_id'])) {
            $row = $db->table('employees')->select('full_name')
                ->where('id', (int) $dept['manager_employee_id'])->get()->getRowArray();
            $managerName = $row['full_name'] ?? null;
        }

        // Parent name (untuk breadcrumb)
        $parentName = null;
        if (! empty($dept['parent_id'])) {
            $row = $db->table('departments')->select('name')
                ->where('id', (int) $dept['parent_id'])->get()->getRowArray();
            $parentName = $row['name'] ?? null;
        }

        // Sub-departments langsung (untuk navigasi cepat)
        $subDepts = $db->table('departments')
            ->select('id, code, name')
            ->where('parent_id', $deptId)
            ->where('deleted_at IS NULL')
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();

        // Sub-dept employee count untuk display
        if (! empty($subDepts)) {
            $subIds = array_map(fn($d) => (int) $d['id'], $subDepts);
            $countRows = $db->table('employees')
                ->select('department_id, COUNT(*) AS cnt')
                ->whereIn('department_id', $subIds)
                ->where('deleted_at IS NULL')
                ->groupBy('department_id')
                ->get()->getResultArray();
            $countMap = [];
            foreach ($countRows as $r) $countMap[(int) $r['department_id']] = (int) $r['cnt'];
            foreach ($subDepts as &$s) $s['employee_count'] = $countMap[(int) $s['id']] ?? 0;
            unset($s);
        }

        // Positions di department ini
        $positions = $db->table('positions')
            ->select('id, code, name, tier, job_grade_id')
            ->where('department_id', $deptId)
            ->where('deleted_at IS NULL')
            ->orderBy("CASE tier WHEN 'MANAGER' THEN 1 WHEN 'ASST_MANAGER' THEN 2 "
                . "WHEN 'SUPERVISOR' THEN 3 WHEN 'STAFF' THEN 4 ELSE 5 END", '', false)
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();

        // Employees di department ini, lengkap dengan position + grade name
        $employees = $db->table('employees e')
            ->select('e.id, e.full_name, e.employee_number, e.email, e.phone, e.gender,'
                . ' e.is_active, e.position_id, e.manager_id, e.job_grade_id, e.avatar_path,'
                . ' p.name AS position_name, p.tier AS position_tier,'
                . ' jg.name AS job_grade_name, jg.code AS job_grade_code,'
                . ' m.full_name AS manager_name')
            ->join('positions p',  'p.id = e.position_id',  'left')
            ->join('job_grades jg','jg.id = e.job_grade_id','left')
            ->join('employees m',  'm.id = e.manager_id',   'left')
            ->where('e.department_id', $deptId)
            ->where('e.deleted_at IS NULL')
            ->orderBy("CASE p.tier WHEN 'MANAGER' THEN 1 WHEN 'ASST_MANAGER' THEN 2 "
                . "WHEN 'SUPERVISOR' THEN 3 WHEN 'STAFF' THEN 4 ELSE 5 END", '', false)
            ->orderBy('e.full_name', 'ASC')
            ->get()->getResultArray();

        // Open MPP di department ini
        $openMpps = [];
        if ($db->tableExists('manpower_requests')) {
            $openMpps = $db->table('manpower_requests')
                ->select('id, reference_number, proposed_title, request_type, reason, '
                    . 'headcount, hired_count, status, target_start_date, position_id')
                ->where('department_id', $deptId)
                ->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'HIRING'])
                ->where('deleted_at IS NULL')
                ->orderBy('created_at', 'DESC')
                ->get()->getResultArray();
        }

        return $this->ok([
            'department' => [
                'id'                  => $deptId,
                'code'                => $dept['code'],
                'name'                => $dept['name'],
                'description'         => $dept['description'],
                'parent_id'           => $dept['parent_id'] !== null ? (int) $dept['parent_id'] : null,
                'parent_name'         => $parentName,
                'manager_employee_id' => $dept['manager_employee_id'] !== null ? (int) $dept['manager_employee_id'] : null,
                'manager_name'        => $managerName,
                'is_active'           => (int) $dept['is_active'],
                'employee_count'      => count($employees),
            ],
            'sub_departments' => $subDepts,
            'positions'       => $positions,
            'employees'       => $employees,
            'open_mpps'       => $openMpps,
        ]);
    }

    /**
     * POST /org-structure/move-employee
     *
     * Body:
     *   {
     *     "employee_id": 12,
     *     "new_department_id": 3 | null,
     *     "new_position_id": 18 | null  // optional
     *   }
     *
     * Pindahkan employee ke department lain (drag-and-drop di modal
     * detail department). Optional: sekaligus set position baru bila
     * UI menyertakan field-nya. Setelah perubahan, hitung ulang manager
     * di subtree supaya direct report konsisten.
     */
    public function moveEmployee()
    {
        $data = $this->getJsonInput();
        $empId = (int) ($data['employee_id'] ?? 0);
        $newDeptId = isset($data['new_department_id']) && $data['new_department_id'] !== null && $data['new_department_id'] !== ''
            ? (int) $data['new_department_id']
            : null;
        $newPosId = isset($data['new_position_id']) && $data['new_position_id'] !== null && $data['new_position_id'] !== ''
            ? (int) $data['new_position_id']
            : null;

        if ($empId <= 0) return $this->fail('employee_id wajib diisi', 422, 'VALIDATION_ERROR');

        $db = Database::connect();
        $emp = $db->table('employees')->where('id', $empId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $emp['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        // Validasi department tujuan
        if ($newDeptId !== null) {
            $dept = $db->table('departments')->where('id', $newDeptId)->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $dept) return $this->fail('Department tujuan tidak ditemukan', 404);
            if ((int) $dept['company_id'] !== (int) $emp['company_id']) {
                return $this->fail('Department tujuan harus dalam perusahaan yang sama', 422, 'CROSS_TENANT');
            }
        }

        // Validasi posisi (kalau dipilih, harus exist + scope sama)
        if ($newPosId !== null) {
            $pos = $db->table('positions')->where('id', $newPosId)->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $pos) return $this->fail('Posisi tidak ditemukan', 404);
            if ((int) $pos['company_id'] !== (int) $emp['company_id']) {
                return $this->fail('Posisi harus dalam perusahaan yang sama', 422, 'CROSS_TENANT');
            }
            // Saran ringan: posisi harusnya nempel di department yang sama
            // dengan tujuan; tapi kita tidak hard-block — bolehkan kalau
            // posisi tsb cross-dept (mis. shared role), dengan catatan di
            // audit log.
        }

        $oldDeptId = $emp['department_id'] !== null ? (int) $emp['department_id'] : null;
        $oldPosId  = $emp['position_id']   !== null ? (int) $emp['position_id']   : null;

        if ($oldDeptId === $newDeptId && $oldPosId === ($newPosId ?? $oldPosId)) {
            return $this->ok(['unchanged' => true], 'Tidak ada perubahan');
        }

        $update = [
            'department_id' => $newDeptId,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        if ($newPosId !== null) $update['position_id'] = $newPosId;

        $db->table('employees')->where('id', $empId)->update($update);

        Services::audit()
            ->forModule('MASTER')
            ->logUpdate(
                'employee',
                $empId,
                ['department_id' => $oldDeptId, 'position_id' => $oldPosId],
                ['department_id' => $newDeptId, 'position_id' => $newPosId ?? $oldPosId],
                "Pindahkan {$emp['full_name']} antar department/posisi"
            );

        // Manager_id otomatis disesuaikan via subtree recompute. Pakai
        // department lama sebagai anchor — recompute akan menyentuh dept
        // lama (untuk update mantan rekan yang manager-nya berubah).
        $report = (new ReportingChainService())->recomputeForCompany((int) $emp['company_id']);

        return $this->ok([
            'employee_id'    => $empId,
            'department_id'  => $newDeptId,
            'position_id'    => $newPosId ?? $oldPosId,
            'reports_synced' => $report,
        ], 'Karyawan berhasil dipindahkan, direct report disesuaikan');
    }

    /**
     * POST /org-structure/move-mpp
     *
     * Body:
     *   { "manpower_request_id": 5, "new_department_id": 2 | null }
     *
     * Drag-and-drop MPP slot ke department lain. Hanya berlaku untuk MPP
     * yang masih DRAFT/SUBMITTED/APPROVED (sebelum ditarik ke job_postings).
     */
    public function moveMpp()
    {
        $data = $this->getJsonInput();
        $mppId = (int) ($data['manpower_request_id'] ?? 0);
        $newDeptId = isset($data['new_department_id']) && $data['new_department_id'] !== null && $data['new_department_id'] !== ''
            ? (int) $data['new_department_id']
            : null;
        if ($mppId <= 0) {
            return $this->fail('manpower_request_id wajib diisi', 422, 'VALIDATION_ERROR');
        }

        // Cek tenant scope MPP via Service (untuk konsistensi).
        $db = Database::connect();
        $mpp = $db->table('manpower_requests')->where('id', $mppId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $mpp) return $this->fail('MPP tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $mpp['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        try {
            $result = Services::manpower()->moveToDepartment($mppId, $newDeptId, $this->userId());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        if (! ($result['unchanged'] ?? false)) {
            Services::audit()->forModule('MASTER')->logUpdate(
                'manpower_request', $mppId,
                ['department_id' => $result['old']],
                ['department_id' => $result['new']],
                "Pindahkan MPP '{$mpp['proposed_title']}' antar department"
            );
        }

        return $this->ok([
            'manpower_request_id' => $mppId,
            'department_id'       => $result['new'] ?? $newDeptId,
            'unchanged'           => $result['unchanged'] ?? false,
        ], 'MPP slot berhasil dipindahkan');
    }

    /**
     * POST /org-structure/recompute-reports
     *
     * Recompute manager_id (direct report) untuk seluruh karyawan di
     * perusahaan. Dipanggil otomatis setelah perubahan struktur, tapi
     * juga disediakan sebagai endpoint manual untuk debugging /
     * maintenance.
     */
    public function recomputeReports()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $svc = new ReportingChainService();
        $result = $svc->recomputeForCompany($companyId);

        Services::audit()
            ->forModule('MASTER')
            ->log(
                'RECOMPUTE',
                'reporting_chain',
                $companyId,
                null,
                $result,
                "Hitung ulang direct report: {$result['updated']} berubah, {$result['unchanged']} tetap"
            );

        return $this->ok($result, 'Direct report dihitung ulang');
    }

    /**
     * POST /org-structure/move-department
     *
     * Body:
     *   { "department_id": 5, "new_parent_id": 2 | null }
     *
     * Memindahkan department ke parent baru. NULL = jadi root.
     * Mencegah:
     *  - Self-parent (parent = self).
     *  - Cycle (parent yang sebenarnya descendant).
     *  - Cross-tenant move.
     */
    public function moveDepartment()
    {
        $data = $this->getJsonInput();
        $deptId = (int) ($data['department_id'] ?? 0);
        $newParentId = isset($data['new_parent_id']) && $data['new_parent_id'] !== null && $data['new_parent_id'] !== ''
            ? (int) $data['new_parent_id']
            : null;

        if ($deptId <= 0) {
            return $this->fail('department_id wajib diisi', 422, 'VALIDATION_ERROR');
        }
        if ($newParentId !== null && $newParentId === $deptId) {
            return $this->fail('Department tidak bisa menjadi parent dari dirinya sendiri', 422, 'INVALID_PARENT');
        }

        $db = Database::connect();
        $dept = $db->table('departments')->where('id', $deptId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $dept) return $this->fail('Department tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $dept['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        if ($newParentId !== null) {
            $parent = $db->table('departments')->where('id', $newParentId)->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $parent) return $this->fail('Parent department tidak ditemukan', 404);
            if ((int) $parent['company_id'] !== (int) $dept['company_id']) {
                return $this->fail('Parent harus dalam perusahaan yang sama', 422, 'CROSS_TENANT');
            }
            // Cek cycle: pastikan newParentId BUKAN descendant dari deptId.
            if ($this->isDescendant($db, $deptId, $newParentId, (int) $dept['company_id'])) {
                return $this->fail('Tidak bisa memindahkan ke descendant sendiri (akan membentuk siklus)', 422, 'CYCLE_DETECTED');
            }
        }

        $oldParentId = $dept['parent_id'] !== null ? (int) $dept['parent_id'] : null;
        if ($oldParentId === $newParentId) {
            return $this->ok(['unchanged' => true], 'Tidak ada perubahan');
        }

        $db->table('departments')->where('id', $deptId)->update([
            'parent_id'  => $newParentId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Services::audit()
            ->forModule('MASTER')
            ->logUpdate(
                'department',
                $deptId,
                ['parent_id' => $oldParentId],
                ['parent_id' => $newParentId],
                "Memindahkan department '{$dept['name']}' ke parent baru"
            );

        // Setelah parent berubah, posisi MANAGER di department ini dan
        // di descendant-nya bisa berubah atasannya. Subtree-only recompute
        // sudah cukup karena employee di luar subtree tidak terpengaruh.
        $report = (new ReportingChainService())->recomputeForDepartmentSubtree(
            (int) $dept['company_id'], $deptId
        );

        return $this->ok([
            'department_id'  => $deptId,
            'parent_id'      => $newParentId,
            'reports_synced' => $report,
        ], 'Department berhasil dipindahkan, direct report disesuaikan');
    }

    /**
     * POST /org-structure/move-position
     *
     * Body:
     *   { "position_id": 12, "new_department_id": 3 | null }
     *
     * Pindahkan posisi ke department lain. NULL = unassigned.
     */
    public function movePosition()
    {
        $data = $this->getJsonInput();
        $posId = (int) ($data['position_id'] ?? 0);
        $newDeptId = isset($data['new_department_id']) && $data['new_department_id'] !== null && $data['new_department_id'] !== ''
            ? (int) $data['new_department_id']
            : null;

        if ($posId <= 0) {
            return $this->fail('position_id wajib diisi', 422, 'VALIDATION_ERROR');
        }

        $db = Database::connect();
        $pos = $db->table('positions')->where('id', $posId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $pos) return $this->fail('Posisi tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $pos['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        if ($newDeptId !== null) {
            $dept = $db->table('departments')->where('id', $newDeptId)->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $dept) return $this->fail('Department tujuan tidak ditemukan', 404);
            if ((int) $dept['company_id'] !== (int) $pos['company_id']) {
                return $this->fail('Department tujuan harus dalam perusahaan yang sama', 422, 'CROSS_TENANT');
            }
        }

        $oldDeptId = $pos['department_id'] !== null ? (int) $pos['department_id'] : null;
        if ($oldDeptId === $newDeptId) {
            return $this->ok(['unchanged' => true], 'Tidak ada perubahan');
        }

        $db->table('positions')->where('id', $posId)->update([
            'department_id' => $newDeptId,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        Services::audit()
            ->forModule('MASTER')
            ->logUpdate(
                'position',
                $posId,
                ['department_id' => $oldDeptId],
                ['department_id' => $newDeptId],
                "Memindahkan posisi '{$pos['name']}' ke department baru"
            );

        // Karena posisi dipindah, semua employee yang menempatinya kini
        // ada di context department berbeda → atasan mereka & rekan
        // se-dept lama mungkin berubah. Cara aman: full company recompute.
        $report = (new ReportingChainService())->recomputeForCompany((int) $pos['company_id']);

        return $this->ok([
            'position_id'    => $posId,
            'department_id'  => $newDeptId,
            'reports_synced' => $report,
        ], 'Posisi berhasil dipindahkan, direct report disesuaikan');
    }

    /**
     * POST /org-structure/set-manager
     *
     * Body:
     *   { "department_id": 3, "manager_employee_id": 42 | null }
     *
     * Set/clear manager department.
     */
    public function setManager()
    {
        $data = $this->getJsonInput();
        $deptId = (int) ($data['department_id'] ?? 0);
        $managerId = isset($data['manager_employee_id']) && $data['manager_employee_id'] !== null && $data['manager_employee_id'] !== ''
            ? (int) $data['manager_employee_id']
            : null;

        if ($deptId <= 0) {
            return $this->fail('department_id wajib diisi', 422, 'VALIDATION_ERROR');
        }

        $db = Database::connect();
        $dept = $db->table('departments')->where('id', $deptId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $dept) return $this->fail('Department tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $dept['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        if ($managerId !== null) {
            $emp = $db->table('employees')->where('id', $managerId)->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
            if ((int) $emp['company_id'] !== (int) $dept['company_id']) {
                return $this->fail('Karyawan harus dalam perusahaan yang sama', 422, 'CROSS_TENANT');
            }
        }

        $oldManager = $dept['manager_employee_id'] !== null ? (int) $dept['manager_employee_id'] : null;
        $db->table('departments')->where('id', $deptId)->update([
            'manager_employee_id' => $managerId,
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        Services::audit()
            ->forModule('MASTER')
            ->logUpdate(
                'department',
                $deptId,
                ['manager_employee_id' => $oldManager],
                ['manager_employee_id' => $managerId],
                "Mengubah manager department '{$dept['name']}'"
            );

        // Set/clear manager dept biasanya juga mempengaruhi subtree
        // (anak-anak dept). Pakai subtree recompute supaya ringan tapi
        // tetap konsisten.
        $report = (new ReportingChainService())->recomputeForDepartmentSubtree(
            (int) $dept['company_id'], $deptId
        );

        return $this->ok([
            'department_id'       => $deptId,
            'manager_employee_id' => $managerId,
            'reports_synced'      => $report,
        ], 'Manager berhasil diperbarui, direct report disesuaikan');
    }

    /**
     * POST /org-structure/bulk-reorder
     *
     * Body:
     *   { "moves": [
     *       { "department_id": 5, "new_parent_id": 2 },
     *       { "department_id": 7, "new_parent_id": null }
     *     ] }
     *
     * Apply banyak pemindahan sekaligus dalam satu transaksi. Cocok dipanggil
     * setelah user menata ulang banyak node di frontend lalu menekan Save.
     * Validasi cycle dilakukan setelah semua perubahan diterapkan secara
     * in-memory supaya konsisten dengan state akhir, bukan urutan apply.
     */
    public function bulkReorder()
    {
        $data = $this->getJsonInput();
        $moves = $data['moves'] ?? [];
        if (! is_array($moves) || empty($moves)) {
            return $this->fail('Tidak ada perubahan untuk diterapkan', 422, 'NO_MOVES');
        }

        $db = Database::connect();
        $companyId = $this->companyId();

        // Build current parent map (tenant-scoped)
        $rowsBuilder = $db->table('departments')->select('id, parent_id, company_id, name')->where('deleted_at IS NULL');
        $this->tenant()->applyScope($rowsBuilder);
        $rows = $rowsBuilder->get()->getResultArray();

        $current = [];
        $nameMap = [];
        foreach ($rows as $r) {
            $current[(int) $r['id']] = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
            $nameMap[(int) $r['id']] = $r['name'];
        }

        // Sebelum apply, periksa juga department/parent yang mungkin ada di
        // company lain (lintas-tenant) atau sudah di-soft-delete. Pesan lebih
        // informatif untuk user: bedakan "tidak ada sama sekali", "di company
        // lain", "soft-deleted". Frontend pakai info ini untuk auto-recover
        // (mis. invalidate cache dan reload tree).
        //
        // Catatan penting: pakai array_key_exists() — BUKAN isset() — karena
        // entry root-department punya value NULL (parent_id = NULL), dan
        // isset() salah mengembalikan false untuk nilai null. Akibatnya
        // sebelum fix ini, semua department root selalu dianggap "missing"
        // dan endpoint balik 409 STALE_TREE_STATE walau datanya sehat.
        $missingIds = [];
        foreach ($moves as $m) {
            $did = (int) ($m['department_id'] ?? 0);
            if ($did <= 0) continue;
            if (! array_key_exists($did, $current)) $missingIds[] = $did;
            $newParent = isset($m['new_parent_id']) && $m['new_parent_id'] !== null && $m['new_parent_id'] !== ''
                ? (int) $m['new_parent_id']
                : null;
            if ($newParent !== null && ! array_key_exists($newParent, $current)) {
                $missingIds[] = $newParent;
            }
        }
        $missingIds = array_values(array_unique($missingIds));

        $diagnostics = [];
        if (! empty($missingIds)) {
            // Cek di seluruh tabel (tanpa scope tenant) untuk diagnosa
            $diagRows = $db->table('departments')
                ->select('id, name, company_id, deleted_at')
                ->whereIn('id', $missingIds)
                ->get()->getResultArray();
            $foundIds = [];
            foreach ($diagRows as $r) {
                $rid = (int) $r['id'];
                $foundIds[] = $rid;
                if ($r['deleted_at'] !== null) {
                    $diagnostics[$rid] = ['reason' => 'DELETED', 'name' => $r['name']];
                } elseif ((int) $r['company_id'] !== $companyId && ! $this->tenant()->isSuperAdmin()) {
                    $diagnostics[$rid] = ['reason' => 'CROSS_TENANT', 'name' => $r['name']];
                } else {
                    // Mestinya tidak terjadi — fallback
                    $diagnostics[$rid] = ['reason' => 'OUT_OF_SCOPE', 'name' => $r['name']];
                }
            }
            foreach ($missingIds as $mid) {
                if (! in_array($mid, $foundIds, true)) {
                    $diagnostics[$mid] = ['reason' => 'NOT_FOUND', 'name' => null];
                }
            }

            // Bangun pesan ringkas untuk user awam
            $deletedNames   = [];
            $crossNames     = [];
            $missingDispIds = [];
            foreach ($diagnostics as $mid => $d) {
                if ($d['reason'] === 'DELETED')      $deletedNames[] = $d['name'] ?? "#{$mid}";
                elseif ($d['reason'] === 'CROSS_TENANT') $crossNames[] = $d['name'] ?? "#{$mid}";
                else                                     $missingDispIds[] = "#{$mid}";
            }
            $msgParts = [];
            if ($deletedNames)   $msgParts[] = "sudah dihapus: " . implode(', ', $deletedNames);
            if ($crossNames)     $msgParts[] = "ada di perusahaan lain: " . implode(', ', $crossNames);
            if ($missingDispIds) $msgParts[] = "tidak ditemukan: " . implode(', ', $missingDispIds);
            $msg = "Beberapa department dalam perubahan tidak valid (" . implode('; ', $msgParts)
                . "). Tree mungkin sudah berubah di sesi/tab lain — silakan muat ulang halaman.";

            return $this->fail($msg, 409, 'STALE_TREE_STATE', [
                'missing_department_ids' => array_values($missingIds),
                'diagnostics'            => $diagnostics,
            ]);
        }

        // Apply moves to in-memory map first, validate, lalu persist.
        $applied = [];
        foreach ($moves as $m) {
            $did = (int) ($m['department_id'] ?? 0);
            $newParent = isset($m['new_parent_id']) && $m['new_parent_id'] !== null && $m['new_parent_id'] !== ''
                ? (int) $m['new_parent_id']
                : null;

            if ($did <= 0) continue; // sudah disaring di atas
            if ($newParent !== null && $newParent === $did) {
                return $this->fail("Department '{$nameMap[$did]}' tidak bisa jadi parent sendiri", 422, 'INVALID_PARENT');
            }
            $applied[$did] = ['old' => $current[$did], 'new' => $newParent];
            $current[$did] = $newParent; // update in-memory state
        }

        // Validate no cycles in final state
        foreach ($applied as $did => $_) {
            if ($this->hasCycleInMap($current, $did)) {
                return $this->fail("Perubahan menyebabkan siklus di sekitar '{$nameMap[$did]}'", 422, 'CYCLE_DETECTED');
            }
        }

        // Persist in transaction
        $db->transBegin();
        try {
            foreach ($applied as $did => $diff) {
                if ($diff['old'] === $diff['new']) continue;
                $db->table('departments')->where('id', $did)->update([
                    'parent_id'  => $diff['new'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                Services::audit()
                    ->forModule('MASTER')
                    ->logUpdate(
                        'department',
                        $did,
                        ['parent_id' => $diff['old']],
                        ['parent_id' => $diff['new']],
                        "Reorder org structure: '{$nameMap[$did]}'"
                    );
            }
            if ($db->transStatus() === false) {
                $db->transRollback();
                return $this->fail('Gagal menyimpan perubahan', 500);
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->fail('Gagal menyimpan perubahan: ' . $e->getMessage(), 500);
        }

        // Bulk reorder berpotensi mengubah banyak subtree sekaligus.
        // Lebih simpel & aman: full company recompute sekali di akhir.
        $report = (new ReportingChainService())->recomputeForCompany($companyId);

        return $this->ok([
            'applied_count'  => count(array_filter($applied, fn($d) => $d['old'] !== $d['new'])),
            'reports_synced' => $report,
        ], 'Perubahan struktur berhasil disimpan, direct report disesuaikan');
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Cek apakah `$candidate` adalah descendant dari `$ancestor` di tabel
     * departments dalam satu company. Dipakai untuk anti-cycle pada moveDepartment.
     */
    private function isDescendant($db, int $ancestor, int $candidate, int $companyId): bool
    {
        $visited = [];
        $stack = [$candidate];
        while (! empty($stack)) {
            $cur = array_pop($stack);
            if (in_array($cur, $visited, true)) continue;
            $visited[] = $cur;
            if ($cur === $ancestor) return true;
            $row = $db->table('departments')
                ->select('parent_id')
                ->where('id', $cur)
                ->where('company_id', $companyId)
                ->where('deleted_at IS NULL')
                ->get()
                ->getRowArray();
            if ($row && $row['parent_id'] !== null) {
                $stack[] = (int) $row['parent_id'];
            }
        }
        return false;
    }

    /**
     * Cek cycle dimulai dari $startId pada parent map [id => parent_id|null].
     * Return true bila ada cycle.
     */
    private function hasCycleInMap(array $parentMap, int $startId): bool
    {
        $seen = [];
        $cur = $startId;
        $hops = 0;
        while ($cur !== null) {
            if (isset($seen[$cur])) return true;
            $seen[$cur] = true;
            $cur = $parentMap[$cur] ?? null;
            // safety bound
            if (++$hops > 1000) return true;
        }
        return false;
    }
}