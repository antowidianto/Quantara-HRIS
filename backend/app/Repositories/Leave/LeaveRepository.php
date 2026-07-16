<?php

declare(strict_types=1);

namespace App\Repositories\Leave;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class LeaveRepository
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }

    private function requireCompany(int $companyId): void
    {
        if ($companyId <= 0) throw new \DomainException('Active company context is required');
    }

    /** @return array<string,mixed>|null */
    public function employee(int $id, int $companyId): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('employees')->where('id', $id)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function leaveType(int $id, int $companyId): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('leave_types')->where('id', $id)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function leave(int $id, int $companyId): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('leaves')->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function activeTypes(int $companyId): array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('leave_types')->where('company_id', $companyId)
            ->where('is_active', 1)->where('deleted_at IS NULL')
            ->orderBy('category', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function balances(int $employeeId, int $companyId, int $year): array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('leave_balances')->where('company_id', $companyId)
            ->where('employee_id', $employeeId)->where('period_year', $year)->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function balance(int $employeeId, int $leaveTypeId, int $companyId, int $year): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('leave_balances')->where('company_id', $companyId)
            ->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)
            ->where('period_year', $year)->get()->getRowArray() ?: null;
    }

    /** @param list<string> $statuses @return array<string,mixed>|null */
    public function payrollPeriodForDate(int $companyId, string $date, array $statuses): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('payroll_periods')->where('company_id', $companyId)
            ->where('start_date <=', $date)->where('end_date >=', $date)->whereIn('status', $statuses)
            ->orderBy('start_date', 'DESC')->limit(1)->get()->getRowArray() ?: null;
    }

    /** @param list<string> $statuses @return array<string,mixed>|null */
    public function nextPayrollPeriod(int $companyId, string $date, array $statuses): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('payroll_periods')->where('company_id', $companyId)
            ->where('start_date >', $date)->whereIn('status', $statuses)
            ->orderBy('start_date', 'ASC')->limit(1)->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function lockedPayrollOverlap(int $companyId, string $start, string $end): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('payroll_periods')->where('company_id', $companyId)
            ->whereIn('status', ['FINALIZED', 'PAID', 'CLOSED'])
            ->where('start_date <=', $end)->where('end_date >=', $start)
            ->get()->getRowArray() ?: null;
    }

    public function hasLeaveOverlap(int $employeeId, int $companyId, string $start, string $end): bool
    {
        $this->requireCompany($companyId);
        return $this->db()->table('leaves')->where('company_id', $companyId)
            ->where('employee_id', $employeeId)->whereIn('status', ['PENDING', 'APPROVED'])
            ->where('start_date <=', $end)->where('end_date >=', $start)->countAllResults() > 0;
    }

    /** @return list<string> */
    public function holidayDates(int $companyId, string $start, string $end): array
    {
        $this->requireCompany($companyId);
        $rows = $this->db()->table('holidays')->select('date')
            ->groupStart()->where('company_id', $companyId)->orWhere('company_id IS NULL')->groupEnd()
            ->where('date >=', $start)->where('date <=', $end)->get()->getResultArray();
        return array_values(array_map('strval', array_column($rows, 'date')));
    }

    /** @return list<array<string,mixed>> */
    public function fallbackApprovers(int $companyId): array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('users')->where('company_id', $companyId)
            ->whereIn('role_id', [2, 3])->get()->getResultArray();
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginate(int $companyId, ?int $employeeId, ?string $status, int $page, int $perPage): array
    {
        $this->requireCompany($companyId);
        $builder = $this->db()->table('leaves l')
            ->select('l.*, e.full_name AS employee_name, e.employee_number, lt.code AS leave_code, lt.name AS leave_name')
            ->join('employees e', 'e.id = l.employee_id AND e.company_id = l.company_id')
            ->join('leave_types lt', 'lt.id = l.leave_type_id AND lt.company_id = l.company_id')
            ->where('l.company_id', $companyId);
        if ($employeeId !== null) $builder->where('l.employee_id', $employeeId);
        if ($status !== null && $status !== '') $builder->where('l.status', $status);
        $total = $builder->countAllResults(false);
        return [
            'rows' => $builder->orderBy('l.created_at', 'DESC')->limit($perPage, ($page - 1) * $perPage)
                ->get()->getResultArray(),
            'total' => $total,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function teamCalendar(
        int $companyId,
        string $start,
        string $end,
        ?int $branchId,
        ?int $departmentId,
    ): array {
        $this->requireCompany($companyId);
        $builder = $this->db()->table('leaves l')
            ->select('l.id, l.employee_id, l.start_date, l.end_date, l.days, l.status, l.is_half_day, l.half_day_part,'
                . ' lt.code AS leave_code, lt.name AS leave_name, lt.category,'
                . ' e.full_name AS employee_name, e.employee_number, e.branch_id, e.department_id,'
                . ' b.name AS branch_name, d.name AS department_name')
            ->join('leave_types lt', 'lt.id = l.leave_type_id AND lt.company_id = l.company_id')
            ->join('employees e', 'e.id = l.employee_id AND e.company_id = l.company_id')
            ->join('branches b', 'b.id = e.branch_id AND b.company_id = l.company_id', 'left')
            ->join('departments d', 'd.id = e.department_id AND d.company_id = l.company_id', 'left')
            ->where('l.company_id', $companyId)->whereIn('l.status', ['PENDING', 'APPROVED'])
            ->where('l.start_date <=', $end)->where('l.end_date >=', $start);
        if ($branchId !== null) $builder->where('e.branch_id', $branchId);
        if ($departmentId !== null) $builder->where('e.department_id', $departmentId);
        return $builder->orderBy('l.start_date', 'ASC')->get()->getResultArray();
    }
}
