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
}
