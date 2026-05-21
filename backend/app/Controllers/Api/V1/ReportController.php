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

    /**
     * Heatmap absensi per departemen × hari (Sprint 5-6 item T).
     *
     * Output untuk render heatmap di Dashboard HR: matrix departemen × tanggal,
     * value = persentase late+absent (0-100). Kolom day-by-day untuk satu bulan.
     *
     * Query param:
     *   ?month=YYYY-MM   (default: bulan sekarang)
     */
    public function attendanceHeatmap()
    {
        $db = \Config\Database::connect();
        $month = (string) ($this->request->getGet('month') ?? date('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $this->fail('month harus format YYYY-MM', 400);
        }
        $start = $month . '-01';
        $end   = date('Y-m-t', strtotime($start));

        // Aggregasi per (department, date): hitung total + (late + absent)
        $rows = $db->table('attendances a')
            ->select('e.department_id, d.name AS department_name, a.work_date,
                COUNT(DISTINCT a.employee_id) AS total,
                SUM(CASE WHEN a.status IN ("LATE","ABSENT") THEN 1 ELSE 0 END) AS issues,
                SUM(CASE WHEN a.status = "LATE" THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN a.status = "ABSENT" THEN 1 ELSE 0 END) AS absent_count', false)
            ->join('employees e', 'e.id = a.employee_id')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('a.company_id', $this->companyId())
            ->where('a.work_date >=', $start)
            ->where('a.work_date <=', $end)
            ->groupBy('e.department_id, d.name, a.work_date')
            ->get()->getResultArray();

        // Transform ke struktur cocok untuk heatmap:
        // {
        //   month, days: [{date, dow, is_weekend}],
        //   departments: [{id, name, cells: [{date, total, issues, score, late, absent}]}]
        // }
        $days = [];
        $cur = strtotime($start);
        $endTs = strtotime($end);
        while ($cur <= $endTs) {
            $dow = (int) date('N', $cur); // 1 = Mon, 7 = Sun
            $days[] = [
                'date' => date('Y-m-d', $cur),
                'dow' => $dow,
                'day' => (int) date('j', $cur),
                'is_weekend' => $dow >= 6,
            ];
            $cur = strtotime('+1 day', $cur);
        }

        $byDept = [];
        foreach ($rows as $r) {
            $deptKey = (int) ($r['department_id'] ?? 0);
            $deptName = (string) ($r['department_name'] ?? 'Tanpa Departemen');
            if (! isset($byDept[$deptKey])) {
                $byDept[$deptKey] = [
                    'id' => $deptKey, 'name' => $deptName,
                    'cells' => [],
                    'total_attendance' => 0, 'total_issues' => 0,
                ];
            }
            $total = (int) $r['total'];
            $issues = (int) $r['issues'];
            $byDept[$deptKey]['cells'][$r['work_date']] = [
                'date'   => $r['work_date'],
                'total'  => $total,
                'issues' => $issues,
                'late'   => (int) $r['late_count'],
                'absent' => (int) $r['absent_count'],
                // Score 0-100, lebih tinggi = lebih banyak masalah
                'score'  => $total > 0 ? round(($issues / $total) * 100, 1) : 0,
            ];
            $byDept[$deptKey]['total_attendance'] += $total;
            $byDept[$deptKey]['total_issues'] += $issues;
        }

        // Sort by avg issue rate descending — depart paling bermasalah duluan
        $departments = array_values($byDept);
        foreach ($departments as &$d) {
            $d['avg_issue_rate'] = $d['total_attendance'] > 0
                ? round(($d['total_issues'] / $d['total_attendance']) * 100, 1)
                : 0;
        }
        unset($d);
        usort($departments, fn ($a, $b) => $b['avg_issue_rate'] <=> $a['avg_issue_rate']);

        return $this->ok([
            'month'       => $month,
            'days'        => $days,
            'departments' => $departments,
        ]);
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