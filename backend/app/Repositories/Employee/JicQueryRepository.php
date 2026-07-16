<?php

declare(strict_types=1);

namespace App\Repositories\Employee;

use CodeIgniter\Database\BaseConnection;

/** Tenant-aware read model for the JIC list/inbox. */
final class JicQueryRepository
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function paginate(
        int $companyId,
        int $userId,
        bool $isSuperAdmin,
        bool $canViewAll,
        bool $canApprove,
        bool $canManage,
        array $filters,
    ): array {
        if (! $isSuperAdmin && $companyId <= 0) {
            throw new \DomainException('Active company context is required');
        }

        $builder = $this->db->table('job_information_changes jic')
            ->select(
                'jic.*, e.full_name AS employee_name, e.employee_number, '
                . 'd_old.name AS old_dept_name_join, d_new.name AS new_dept_name, '
                . 'p_new.name AS new_position_name, g_new.name AS new_grade_name'
            )
            ->join('employees e', 'e.id = jic.employee_id AND e.company_id = jic.company_id', 'left')
            ->join('departments d_old', 'd_old.id = jic.old_department_id AND d_old.company_id = jic.company_id', 'left')
            ->join('departments d_new', 'd_new.id = jic.new_department_id AND d_new.company_id = jic.company_id', 'left')
            ->join('positions p_new', 'p_new.id = jic.new_position_id AND p_new.company_id = jic.company_id', 'left')
            ->join('job_grades g_new', 'g_new.id = jic.new_job_grade_id AND g_new.company_id = jic.company_id', 'left')
            ->where('jic.deleted_at IS NULL');

        if ($companyId > 0) {
            $builder->where('jic.company_id', $companyId);
        }

        $as = (string) ($filters['as'] ?? '');
        if (! $canViewAll || $as === 'mine') {
            $builder->where('e.user_id', $userId);
        } elseif ($as === 'inbox') {
            $employeeBuilder = $this->db->table('employees')
                ->select('id, department_id')->where('user_id', $userId)
                ->where('deleted_at IS NULL');
            if ($companyId > 0) $employeeBuilder->where('company_id', $companyId);
            $myEmployee = $employeeBuilder->get()->getRowArray();
            $myEmployeeId = (int) ($myEmployee['id'] ?? 0);

            $builder->groupStart()
                ->groupStart()->where('jic.status', 'PENDING_SUPERVISOR')
                    ->groupStart()->where('e.supervisor_id', $myEmployeeId)->orWhere('e.manager_id', $myEmployeeId)->groupEnd()
                ->groupEnd()
                ->orGroupStart()->where('jic.status', 'PENDING_DEPT_HEAD')
                    ->groupStart()->where('d_old.manager_employee_id', $myEmployeeId)->orWhere('d_new.manager_employee_id', $myEmployeeId)->groupEnd()
                ->groupEnd()
                ->orGroupStart()->where('jic.status', 'PENDING_HR')->where($canApprove || $canManage ? '1=1' : '1=0', null, false)->groupEnd()
                ->orGroupStart()->where('jic.status', 'PENDING_BOD')->where($canManage || $isSuperAdmin ? '1=1' : '1=0', null, false)->groupEnd()
            ->groupEnd();
        }

        foreach ([
            'status' => 'jic.status',
            'change_type' => 'jic.change_type',
            'effective_from' => 'jic.effective_date >=',
            'effective_to' => 'jic.effective_date <=',
        ] as $key => $column) {
            if (($filters[$key] ?? '') !== '') $builder->where($column, $filters[$key]);
        }
        if ($canViewAll && (int) ($filters['employee_id'] ?? 0) > 0) {
            $builder->where('jic.employee_id', (int) $filters['employee_id']);
        }

        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = (clone $builder)->countAllResults(false);
        $rows = $builder->orderBy('jic.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function findDetail(int $id, int $companyId, bool $isSuperAdmin): ?array
    {
        if (! $isSuperAdmin && $companyId <= 0) {
            throw new \DomainException('Active company context is required');
        }

        $builder = $this->db->table('job_information_changes jic')
            ->select(
                'jic.*, e.full_name AS employee_name, e.employee_number, '
                . 'e.user_id AS employee_user_id, e.supervisor_id, e.manager_id, e.department_id AS emp_dept_id, '
                . 'p_old.name AS old_position_name_join, p_new.name AS new_position_name, p_new.tier AS new_position_tier, '
                . 'd_old.name AS old_dept_name_join, d_new.name AS new_dept_name, '
                . 'b_old.name AS old_branch_name, b_new.name AS new_branch_name, '
                . 'g_old.name AS old_grade_name_join, g_new.name AS new_grade_name, '
                . 'sup_old.full_name AS old_supervisor_name, sup_new.full_name AS new_supervisor_name, '
                . 'mgr_old.full_name AS old_manager_name, mgr_new.full_name AS new_manager_name'
            )
            ->join('employees e', 'e.id = jic.employee_id AND e.company_id = jic.company_id', 'left')
            ->join('positions p_old', 'p_old.id = jic.old_position_id AND p_old.company_id = jic.company_id', 'left')
            ->join('positions p_new', 'p_new.id = jic.new_position_id AND p_new.company_id = jic.company_id', 'left')
            ->join('departments d_old', 'd_old.id = jic.old_department_id AND d_old.company_id = jic.company_id', 'left')
            ->join('departments d_new', 'd_new.id = jic.new_department_id AND d_new.company_id = jic.company_id', 'left')
            ->join('branches b_old', 'b_old.id = jic.old_branch_id AND b_old.company_id = jic.company_id', 'left')
            ->join('branches b_new', 'b_new.id = jic.new_branch_id AND b_new.company_id = jic.company_id', 'left')
            ->join('job_grades g_old', 'g_old.id = jic.old_job_grade_id AND g_old.company_id = jic.company_id', 'left')
            ->join('job_grades g_new', 'g_new.id = jic.new_job_grade_id AND g_new.company_id = jic.company_id', 'left')
            ->join('employees sup_old', 'sup_old.id = jic.old_supervisor_id AND sup_old.company_id = jic.company_id', 'left')
            ->join('employees sup_new', 'sup_new.id = jic.new_supervisor_id AND sup_new.company_id = jic.company_id', 'left')
            ->join('employees mgr_old', 'mgr_old.id = jic.old_manager_id AND mgr_old.company_id = jic.company_id', 'left')
            ->join('employees mgr_new', 'mgr_new.id = jic.new_manager_id AND mgr_new.company_id = jic.company_id', 'left')
            ->where('jic.id', $id)->where('jic.deleted_at IS NULL');
        if (! $isSuperAdmin) $builder->where('jic.company_id', $companyId);

        return $builder->get()->getRowArray() ?: null;
    }

    public function isSupervisor(int $companyId, int $callerUserId, int $employeeId): bool
    {
        if ($companyId <= 0) return false;
        $employee = $this->db->table('employees')->select('supervisor_id, manager_id')
            ->where('id', $employeeId)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        $caller = $this->db->table('employees')->select('id')
            ->where('user_id', $callerUserId)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $employee || ! $caller) return false;
        $callerId = (int) $caller['id'];
        return $callerId === (int) ($employee['supervisor_id'] ?? 0)
            || $callerId === (int) ($employee['manager_id'] ?? 0);
    }

    public function isDepartmentHead(int $companyId, int $callerUserId, array $jic): bool
    {
        if ($companyId <= 0) return false;
        $caller = $this->db->table('employees')->select('id')
            ->where('user_id', $callerUserId)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $caller) return false;
        $departments = array_values(array_filter([
            (int) ($jic['old_department_id'] ?? 0),
            (int) ($jic['new_department_id'] ?? 0),
        ]));
        if ($departments === []) return false;
        return $this->db->table('departments')->whereIn('id', $departments)
            ->where('company_id', $companyId)->where('manager_employee_id', (int) $caller['id'])
            ->countAllResults() > 0;
    }

    /** @return array<string,mixed>|null */
    public function employeeProfile(int $employeeId, int $companyId): ?array
    {
        $this->requireCompany($companyId);
        return $this->db->table('employees e')->select(
            'e.id, e.employee_number, e.full_name, e.first_name, e.last_name, e.gender, e.email, e.phone, '
            . 'e.avatar_path, e.user_id, e.employment_status, e.employment_type, e.is_active, e.join_date, '
            . 'e.probation_end_date, e.contract_end_date, e.resign_date, e.basic_salary, e.tax_method, '
            . 'e.ptkp_status, e.education_level, e.marital_status, b.name AS branch_name, '
            . 'd.name AS department_name, p.name AS position_name, p.tier AS position_tier, '
            . 'g.name AS grade_name, wl.name AS work_location_name, wl.type AS work_location_type'
        )->join('branches b', 'b.id = e.branch_id AND b.company_id = e.company_id', 'left')
            ->join('departments d', 'd.id = e.department_id AND d.company_id = e.company_id', 'left')
            ->join('positions p', 'p.id = e.position_id AND p.company_id = e.company_id', 'left')
            ->join('job_grades g', 'g.id = e.job_grade_id AND g.company_id = e.company_id', 'left')
            ->join('work_locations wl', 'wl.id = e.work_location_id AND wl.company_id = e.company_id', 'left')
            ->where('e.id', $employeeId)->where('e.company_id', $companyId)->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function employeeDetail(int $employeeId, int $companyId): ?array
    {
        if ($employeeId <= 0) return null;
        $this->requireCompany($companyId);
        $row = $this->db->table('employees e')->select(
            'e.id, e.employee_number, e.full_name, e.email, e.phone, e.user_id, '
            . 'p.name AS position_name, p.tier AS position_tier, d.name AS department_name'
        )->join('positions p', 'p.id = e.position_id AND p.company_id = e.company_id', 'left')
            ->join('departments d', 'd.id = e.department_id AND d.company_id = e.company_id', 'left')
            ->where('e.id', $employeeId)->where('e.company_id', $companyId)->get()->getRowArray();
        if (! $row) return null;
        return ['employee_id' => (int) $row['id'], 'employee_number' => $row['employee_number'], 'full_name' => $row['full_name'],
            'email' => $row['email'], 'phone' => $row['phone'], 'position' => $row['position_name'],
            'position_tier' => $row['position_tier'], 'department' => $row['department_name'],
            'user_id' => $row['user_id'] ? (int) $row['user_id'] : null];
    }

    /** @return array<string,mixed>|null */
    public function departmentHead(int $departmentId, int $companyId): ?array
    {
        if ($departmentId <= 0) return null;
        $this->requireCompany($companyId);
        $department = $this->db->table('departments')->select('manager_employee_id')->where('id', $departmentId)
            ->where('company_id', $companyId)->get()->getRowArray();
        return empty($department['manager_employee_id']) ? null : $this->employeeDetail((int) $department['manager_employee_id'], $companyId);
    }

    /** @return array<string,mixed>|null */
    public function workLocation(int $id, int $companyId): ?array
    {
        if ($id <= 0) return null;
        $this->requireCompany($companyId);
        return $this->db->table('work_locations')->select('id, code, name, type, address, city, province')
            ->where('id', $id)->where('company_id', $companyId)->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function actor(int $userId, int $companyId): ?array
    {
        if ($userId <= 0) return null;
        $this->requireCompany($companyId);
        $row = $this->db->table('users u')->select(
            'u.id, u.name, u.email, e.id AS employee_id, e.employee_number, '
            . 'e.full_name AS employee_name, p.name AS position_name'
        )->join('employees e', 'e.user_id = u.id AND e.company_id = u.company_id', 'left')
            ->join('positions p', 'p.id = e.position_id AND p.company_id = u.company_id', 'left')
            ->where('u.id', $userId)->where('u.company_id', $companyId)->get()->getRowArray();
        if (! $row) return null;
        return ['user_id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email'],
            'employee_id' => $row['employee_id'] ? (int) $row['employee_id'] : null,
            'employee_number' => $row['employee_number'], 'employee_name' => $row['employee_name'], 'position' => $row['position_name']];
    }

    /** @return array<string,mixed>|null */
    public function initiator(int $userId, int $companyId): ?array
    {
        if ($userId <= 0) return null;
        $this->requireCompany($companyId);
        return $this->db->table('users u')->select('u.id, u.name, u.email, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.user_id = u.id AND e.company_id = u.company_id', 'left')
            ->where('u.id', $userId)->where('u.company_id', $companyId)->get()->getRowArray() ?: null;
    }

    private function requireCompany(int $companyId): void
    {
        if ($companyId <= 0) throw new \DomainException('Active company context is required');
    }
}
