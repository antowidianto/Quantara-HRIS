<?php

namespace App\Controllers\Api\V1;

class ReportController extends ApiController
{
    public function headcount()
    {
        $db = \Config\Database::connect();
        $companyId = $this->companyId();

        $byDept = $db->table('employees e')
            ->select('d.id, d.name, COUNT(e.id) AS total')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('e.company_id', $companyId)
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL')
            ->groupBy('d.id, d.name')
            ->get()->getResultArray();

        $byStatus = $db->table('employees')
            ->select('employment_status, COUNT(*) AS total')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->where('is_active', 1)
            ->groupBy('employment_status')
            ->get()->getResultArray();

        $byGender = $db->table('employees')
            ->select('gender, COUNT(*) AS total')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->where('is_active', 1)
            ->groupBy('gender')
            ->get()->getResultArray();

        $totals = $db->table('employees')
            ->select('SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN is_active=0 THEN 1 ELSE 0 END) AS inactive, COUNT(*) AS total')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();

        return $this->ok([
            'totals'    => $totals,
            'by_department' => $byDept,
            'by_status' => $byStatus,
            'by_gender' => $byGender,
        ]);
    }

    public function attendanceSummary()
    {
        $db = \Config\Database::connect();
        $month = (string) ($this->request->getGet('month') ?? date('Y-m'));
        $start = $month . '-01';
        $end   = date('Y-m-t', strtotime($start));

        $rows = $db->table('attendances a')
            ->select('a.employee_id, e.full_name, e.employee_number,
                SUM(CASE WHEN a.status="PRESENT" THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN a.status="LATE" THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN a.status="ABSENT" THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN a.status="LEAVE" THEN 1 ELSE 0 END) AS leave_days,
                SUM(a.late_minutes) AS total_late_minutes,
                SUM(a.work_minutes) AS total_work_minutes', false)
            ->join('employees e', 'e.id = a.employee_id')
            ->where('a.company_id', $this->companyId())
            ->where('a.work_date >=', $start)
            ->where('a.work_date <=', $end)
            ->groupBy('a.employee_id, e.full_name, e.employee_number')
            ->get()->getResultArray();
        return $this->ok(['month' => $month, 'rows' => $rows]);
    }

    public function payrollSummary()
    {
        $db = \Config\Database::connect();
        $year = (int) ($this->request->getGet('year') ?? date('Y'));

        $rows = $db->table('payroll_periods pp')
            ->select('pp.id, pp.code, pp.name, pp.period_year, pp.period_month, pp.status, pp.total_gross, pp.total_deduction, pp.total_net, COUNT(ps.id) AS slip_count')
            ->join('payslips ps', 'ps.payroll_period_id = pp.id', 'left')
            ->where('pp.company_id', $this->companyId())
            ->where('pp.period_year', $year)
            ->groupBy('pp.id')
            ->orderBy('pp.start_date', 'DESC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function leaveSummary()
    {
        $db = \Config\Database::connect();
        $year = (int) ($this->request->getGet('year') ?? date('Y'));

        $rows = $db->table('leaves l')
            ->select('lt.code, lt.name, COUNT(l.id) AS total_requests,
                SUM(CASE WHEN l.status="APPROVED" THEN l.days ELSE 0 END) AS total_days')
            ->join('leave_types lt', 'lt.id = l.leave_type_id')
            ->where('l.company_id', $this->companyId())
            ->where('l.start_date >=', sprintf('%04d-01-01', $year))
            ->where('l.start_date <=', sprintf('%04d-12-31', $year))
            ->groupBy('lt.id, lt.code, lt.name')
            ->get()->getResultArray();
        return $this->ok($rows);
    }
}