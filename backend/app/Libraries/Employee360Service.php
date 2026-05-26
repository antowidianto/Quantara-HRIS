<?php

namespace App\Libraries;

use Config\Database;

/**
 * Employee360Service — aggregator profile lengkap karyawan untuk
 * "Employee 360 view" (Sprint 4 / CC-4).
 *
 * Sebelumnya HR harus klik di banyak page untuk lihat: profile + status
 * cuti + payroll YTD + training + performance + asset assigned + SP aktif
 * + dokumen lengkap. Service ini aggregate semua di 1 endpoint
 * `GET /api/v1/employees/{id}/360`.
 *
 * Setiap section di-isolate dengan try-catch defensive supaya error di
 * 1 modul (mis. tabel migrasi belum jalan) tidak break seluruh response.
 *
 * Section yang dimuat:
 *   1. profile          — basic info: name, NIK, position, dept, supervisor, dll
 *   2. employment       — join_date, contract type, probation status, masa kerja
 *   3. compensation     — basic_salary, fixed_allowance, bank info (masked)
 *   4. leave_summary    — saldo cuti per type tahun ini + last 5 leaves
 *   5. attendance       — month-to-date stats: presence, late, OT hours
 *   6. payroll_ytd      — total pendapatan YTD + payslip count
 *   7. performance      — last review score, active goals, current cycle
 *   8. training         — completed last year + active enrollments
 *   9. assets           — asset assigned + asset history count
 *   10. disciplinary    — SP aktif + summary
 *   11. documents       — count contract + dokumen verified
 *   12. lifecycle       — onboarding tasks completion, offboarding (kalau resign)
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item CC-4.
 */
class Employee360Service
{
    /**
     * Build full 360 view untuk satu employee.
     *
     * @return array {
     *   employee_id, sections: { profile, employment, compensation, leave_summary,
     *     attendance, payroll_ytd, performance, training, assets, disciplinary,
     *     documents, lifecycle },
     *   generated_at,
     * }
     */
    public function build(int $employeeId): array
    {
        $db = Database::connect();
        $emp = $db->table('employees e')
            ->select('e.*, p.name AS position_name, p.tier AS position_tier, '
                . 'd.name AS department_name, b.name AS branch_name, '
                . 'c.name AS company_name, jg.name AS grade_name, '
                . 'wl.name AS work_location_name, '
                . 'sup.full_name AS supervisor_name, '
                . 'mgr.full_name AS manager_name')
            ->join('positions p',     'p.id = e.position_id', 'left')
            ->join('departments d',   'd.id = e.department_id', 'left')
            ->join('branches b',      'b.id = e.branch_id', 'left')
            ->join('companies c',     'c.id = e.company_id', 'left')
            ->join('job_grades jg',   'jg.id = e.job_grade_id', 'left')
            ->join('work_locations wl', 'wl.id = e.work_location_id', 'left')
            ->join('employees sup',   'sup.id = e.supervisor_id', 'left')
            ->join('employees mgr',   'mgr.id = e.manager_id', 'left')
            ->where('e.id', $employeeId)
            ->where('e.deleted_at IS NULL')
            ->get()->getRowArray();

        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }

        return [
            'employee_id'   => $employeeId,
            'generated_at'  => date('Y-m-d H:i:s'),
            'sections' => [
                'profile'       => $this->buildProfile($emp),
                'employment'    => $this->buildEmployment($db, $emp),
                'compensation'  => $this->buildCompensation($emp),
                'leave_summary' => $this->buildLeaveSummary($db, $employeeId),
                'attendance'    => $this->buildAttendance($db, $employeeId),
                'payroll_ytd'   => $this->buildPayrollYtd($db, $employeeId),
                'performance'   => $this->buildPerformance($db, $employeeId),
                'training'      => $this->buildTraining($db, $employeeId),
                'assets'        => $this->buildAssets($db, $employeeId),
                'disciplinary'  => $this->buildDisciplinary($db, $employeeId),
                'documents'     => $this->buildDocuments($db, $employeeId),
                'lifecycle'     => $this->buildLifecycle($db, $employeeId),
            ],
        ];
    }

    private function buildProfile(array $emp): array
    {
        return [
            'id'              => (int) $emp['id'],
            'employee_number' => $emp['employee_number'] ?? null,
            'full_name'       => $emp['full_name'] ?? null,
            'nickname'        => $emp['nickname'] ?? null,
            'email'           => $emp['email'] ?? null,
            'phone'           => $emp['phone'] ?? null,
            'gender'          => $emp['gender'] ?? null,
            'date_of_birth'   => $emp['date_of_birth'] ?? null,
            'marital_status'  => $emp['marital_status'] ?? null,
            'religion'        => $emp['religion'] ?? null,
            'avatar_path'     => $emp['avatar_path'] ?? null,
            'is_active'       => (int) ($emp['is_active'] ?? 0),
        ];
    }

    private function buildEmployment(\CodeIgniter\Database\BaseConnection $db, array $emp): array
    {
        $joinDate = $emp['join_date'] ?? null;
        $today    = date('Y-m-d');
        $tenureMonths = 0;
        if ($joinDate) {
            $start = strtotime($joinDate);
            $end   = strtotime($today);
            if ($start && $end > $start) {
                $tenureMonths = (int) round(($end - $start) / 86400 / 30);
            }
        }

        // Active contract
        $activeContract = null;
        try {
            $activeContract = $db->table('employee_contracts')
                ->where('employee_id', (int) $emp['id'])
                ->whereIn('status', ['ACTIVE', 'SIGNED'])
                ->where('deleted_at IS NULL')
                ->orderBy('start_date', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
        } catch (\Throwable $e) {}

        return [
            'company_name'      => $emp['company_name'] ?? null,
            'branch_name'       => $emp['branch_name'] ?? null,
            'department_name'   => $emp['department_name'] ?? null,
            'position_name'     => $emp['position_name'] ?? null,
            'position_tier'     => $emp['position_tier'] ?? null,
            'grade_name'        => $emp['grade_name'] ?? null,
            'work_location_name'=> $emp['work_location_name'] ?? null,
            'supervisor_name'   => $emp['supervisor_name'] ?? null,
            'manager_name'      => $emp['manager_name'] ?? null,
            'employment_status' => $emp['employment_status'] ?? null,
            'employment_type'   => $emp['employment_type'] ?? null,
            'join_date'         => $joinDate,
            'tenure_months'     => $tenureMonths,
            'tenure_label'      => $this->humanizeTenure($tenureMonths),
            'probation_end_date'=> $emp['probation_end_date'] ?? null,
            'probation_status'  => $emp['probation_status'] ?? null,
            'resign_date'       => $emp['resign_date'] ?? null,
            'resign_reason'     => $emp['resign_reason'] ?? null,
            'active_contract'   => $activeContract ? [
                'id'             => (int) $activeContract['id'],
                'contract_type'  => $activeContract['contract_type'] ?? null,
                'start_date'     => $activeContract['start_date'] ?? null,
                'end_date'       => $activeContract['end_date'] ?? null,
                'status'         => $activeContract['status'] ?? null,
            ] : null,
        ];
    }

    private function buildCompensation(array $emp): array
    {
        // Bank account info di-mask supaya tidak fully expose lewat 360 endpoint.
        // HR yang butuh full account harus akses payroll detail (ada audit log
        // tersendiri).
        $bank = $emp['bank_account'] ?? null;
        $maskedBank = $bank
            ? str_repeat('*', max(0, strlen($bank) - 4)) . substr((string) $bank, -4)
            : null;

        // NPWP juga di-mask
        $npwp = $emp['npwp'] ?? null;
        $maskedNpwp = $npwp ? substr((string) $npwp, 0, 2) . '.***.***.*' : null;

        return [
            'basic_salary'      => isset($emp['basic_salary']) ? (float) $emp['basic_salary'] : null,
            'fixed_allowance'   => isset($emp['fixed_allowance']) ? (float) $emp['fixed_allowance'] : null,
            'bank_name'         => $emp['bank_name'] ?? null,
            'bank_account_masked' => $maskedBank,
            'npwp_masked'       => $maskedNpwp,
            'ptkp_status'       => $emp['ptkp_status'] ?? null,
            'bpjs_kes_number'   => $emp['bpjs_kesehatan_number'] ?? null,
            'bpjs_tk_number'    => $emp['bpjs_ketenagakerjaan_number'] ?? null,
        ];
    }

    private function buildLeaveSummary(\CodeIgniter\Database\BaseConnection $db, int $employeeId): array
    {
        $year = (int) date('Y');
        $balances = [];
        try {
            $balances = $db->table('leave_balances lb')
                ->select('lb.id, lb.entitled, lb.taken, lb.remaining, '
                    . 'lt.name AS leave_type, lt.code, lt.category')
                ->join('leave_types lt', 'lt.id = lb.leave_type_id')
                ->where('lb.employee_id', $employeeId)
                ->where('lb.period_year', $year)
                ->orderBy('lt.category', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {}

        $recent = [];
        try {
            $recent = $db->table('leaves l')
                ->select('l.id, l.start_date, l.end_date, l.duration_days, '
                    . 'l.status, l.reason, lt.name AS leave_type')
                ->join('leave_types lt', 'lt.id = l.leave_type_id', 'left')
                ->where('l.employee_id', $employeeId)
                ->where('l.deleted_at IS NULL')
                ->orderBy('l.start_date', 'DESC')
                ->limit(5)
                ->get()->getResultArray();
        } catch (\Throwable $e) {}

        return [
            'period_year'   => $year,
            'balances'      => $balances,
            'recent_leaves' => $recent,
        ];
    }

    private function buildAttendance(\CodeIgniter\Database\BaseConnection $db, int $employeeId): array
    {
        $monthStart = date('Y-m-01');
        $monthEnd   = date('Y-m-t');
        $stats = [
            'period_start'   => $monthStart,
            'period_end'     => $monthEnd,
            'present_count'  => 0,
            'late_count'     => 0,
            'absent_count'   => 0,
            'overtime_hours' => 0.0,
        ];
        try {
            $rows = $db->table('attendance')
                ->select('status, late_minutes, total_overtime_minutes')
                ->where('employee_id', $employeeId)
                ->where('attendance_date >=', $monthStart)
                ->where('attendance_date <=', $monthEnd)
                ->where('deleted_at IS NULL')
                ->get()->getResultArray();
            foreach ($rows as $r) {
                if ($r['status'] === 'PRESENT') {
                    $stats['present_count']++;
                    if ((int) ($r['late_minutes'] ?? 0) > 0) $stats['late_count']++;
                } elseif ($r['status'] === 'ABSENT') {
                    $stats['absent_count']++;
                }
                $stats['overtime_hours'] += ((int) ($r['total_overtime_minutes'] ?? 0)) / 60;
            }
            $stats['overtime_hours'] = round($stats['overtime_hours'], 2);
        } catch (\Throwable $e) {}
        return $stats;
    }

    private function buildPayrollYtd(\CodeIgniter\Database\BaseConnection $db, int $employeeId): array
    {
        $year = (int) date('Y');
        $stats = [
            'period_year'   => $year,
            'gross_total'   => 0.0,
            'net_total'     => 0.0,
            'pph21_total'   => 0.0,
            'bpjs_total'    => 0.0,
            'payslip_count' => 0,
        ];
        try {
            if (! $db->tableExists('payslips')) return $stats;
            $rows = $db->table('payslips')
                ->select('gross_total, net_total, pph21_amount, bpjs_total')
                ->where('employee_id', $employeeId)
                ->where('YEAR(period_start)', $year)
                ->where('deleted_at IS NULL')
                ->get()->getResultArray();
            foreach ($rows as $r) {
                $stats['gross_total'] += (float) ($r['gross_total'] ?? 0);
                $stats['net_total']   += (float) ($r['net_total'] ?? 0);
                $stats['pph21_total'] += (float) ($r['pph21_amount'] ?? 0);
                $stats['bpjs_total']  += (float) ($r['bpjs_total'] ?? 0);
            }
            $stats['payslip_count'] = count($rows);
        } catch (\Throwable $e) {}
        return $stats;
    }

    private function buildPerformance(\CodeIgniter\Database\BaseConnection $db, int $employeeId): array
    {
        $info = [
            'last_review'    => null,
            'active_goals'   => 0,
            'current_cycle'  => null,
        ];
        try {
            $info['last_review'] = $db->table('performance_reviews pr')
                ->select('pr.id, pr.cycle_id, pr.overall_score, pr.recommendation, '
                    . 'pr.status, pr.finalized_at, pc.name AS cycle_name')
                ->join('performance_cycles pc', 'pc.id = pr.cycle_id', 'left')
                ->where('pr.employee_id', $employeeId)
                ->where('pr.deleted_at IS NULL')
                ->orderBy('pr.id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
        } catch (\Throwable $e) {}

        try {
            $info['active_goals'] = $db->table('performance_goals')
                ->where('employee_id', $employeeId)
                ->whereIn('status', ['ACTIVE', 'IN_PROGRESS', 'APPROVED'])
                ->where('deleted_at IS NULL')
                ->countAllResults();
        } catch (\Throwable $e) {}

        try {
            $info['current_cycle'] = $db->table('performance_cycles')
                ->where('status', 'ACTIVE')
                ->where('deleted_at IS NULL')
                ->orderBy('start_date', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
        } catch (\Throwable $e) {}

        return $info;
    }

    private function buildTraining(\CodeIgniter\Database\BaseConnection $db, int $employeeId): array
    {
        $info = [
            'completed_last_year'   => 0,
            'active_enrollments'    => 0,
            'recent_completions'    => [],
        ];
        try {
            $oneYearAgo = date('Y-m-d', strtotime('-1 year'));
            $info['completed_last_year'] = $db->table('training_registrations')
                ->where('employee_id', $employeeId)
                ->where('status', 'COMPLETED')
                ->where('completed_at >=', $oneYearAgo)
                ->where('deleted_at IS NULL')
                ->countAllResults();

            $info['active_enrollments'] = $db->table('training_registrations')
                ->where('employee_id', $employeeId)
                ->whereIn('status', ['REGISTERED', 'APPROVED', 'IN_PROGRESS'])
                ->where('deleted_at IS NULL')
                ->countAllResults();

            $info['recent_completions'] = $db->table('training_registrations tr')
                ->select('tr.id, tr.completed_at, tr.score, t.title AS training_title')
                ->join('trainings t', 't.id = tr.training_id', 'left')
                ->where('tr.employee_id', $employeeId)
                ->where('tr.status', 'COMPLETED')
                ->where('tr.deleted_at IS NULL')
                ->orderBy('tr.completed_at', 'DESC')
                ->limit(5)
                ->get()->getResultArray();
        } catch (\Throwable $e) {}

        return $info;
    }

    private function buildAssets(\CodeIgniter\Database\BaseConnection $db, int $employeeId): array
    {
        $info = ['assigned' => [], 'history_count' => 0];
        try {
            $info['assigned'] = $db->table('assets')
                ->select('id, asset_number, name, category, condition, assigned_at')
                ->where('current_employee_id', $employeeId)
                ->where('status', 'ASSIGNED')
                ->where('deleted_at IS NULL')
                ->orderBy('assigned_at', 'DESC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {}

        try {
            if ($db->tableExists('asset_assignments')) {
                $info['history_count'] = $db->table('asset_assignments')
                    ->where('employee_id', $employeeId)
                    ->where('deleted_at IS NULL')
                    ->countAllResults();
            }
        } catch (\Throwable $e) {}

        return $info;
    }

    private function buildDisciplinary(\CodeIgniter\Database\BaseConnection $db, int $employeeId): array
    {
        $info = ['active_count' => 0, 'history_count' => 0, 'highest_active' => null, 'recent' => []];
        try {
            $info['active_count'] = $db->table('disciplinary_records')
                ->where('employee_id', $employeeId)
                ->where('status', 'ACTIVE')
                ->where('deleted_at IS NULL')
                ->countAllResults();

            $info['history_count'] = $db->table('disciplinary_records')
                ->where('employee_id', $employeeId)
                ->where('deleted_at IS NULL')
                ->countAllResults();

            // Highest active SP level
            $levelOrder = ['VERBAL' => 1, 'SP1' => 2, 'SP2' => 3, 'SP3' => 4, 'SUSPEND' => 5, 'TERMINATION' => 6];
            $actives = $db->table('disciplinary_records')
                ->select('level')
                ->where('employee_id', $employeeId)
                ->where('status', 'ACTIVE')
                ->where('deleted_at IS NULL')
                ->get()->getResultArray();
            $highest = null;
            $highestVal = 0;
            foreach ($actives as $a) {
                $v = $levelOrder[$a['level']] ?? 0;
                if ($v > $highestVal) { $highest = $a['level']; $highestVal = $v; }
            }
            $info['highest_active'] = $highest;

            $info['recent'] = $db->table('disciplinary_records')
                ->select('id, level, reference_number, subject, effective_date, expires_at, status')
                ->where('employee_id', $employeeId)
                ->where('deleted_at IS NULL')
                ->orderBy('effective_date', 'DESC')
                ->limit(5)
                ->get()->getResultArray();
        } catch (\Throwable $e) {}

        return $info;
    }

    private function buildDocuments(\CodeIgniter\Database\BaseConnection $db, int $employeeId): array
    {
        $info = [
            'contract_count' => 0,
            'document_count' => 0,
            'recent_contracts' => [],
        ];
        try {
            $info['contract_count'] = $db->table('employee_contracts')
                ->where('employee_id', $employeeId)
                ->where('deleted_at IS NULL')
                ->countAllResults();

            if ($db->tableExists('employee_documents')) {
                $info['document_count'] = $db->table('employee_documents')
                    ->where('employee_id', $employeeId)
                    ->where('deleted_at IS NULL')
                    ->countAllResults();
            }

            $info['recent_contracts'] = $db->table('employee_contracts')
                ->select('id, contract_type, start_date, end_date, status')
                ->where('employee_id', $employeeId)
                ->where('deleted_at IS NULL')
                ->orderBy('start_date', 'DESC')
                ->limit(3)
                ->get()->getResultArray();
        } catch (\Throwable $e) {}

        return $info;
    }

    private function buildLifecycle(\CodeIgniter\Database\BaseConnection $db, int $employeeId): array
    {
        $info = [
            'onboarding_progress' => null,
            'offboarding_progress'=> null,
            'active_resignation'  => null,
            'active_termination'  => null,
        ];

        try {
            // Onboarding progress
            $onboard = $db->table('employee_tasks')
                ->select('COUNT(*) AS total, '
                    . 'SUM(CASE WHEN status = "COMPLETED" THEN 1 ELSE 0 END) AS completed, '
                    . 'SUM(CASE WHEN status IN ("OVERDUE") OR (status = "PENDING" AND due_date < CURDATE()) THEN 1 ELSE 0 END) AS overdue')
                ->where('employee_id', $employeeId)
                ->where('type', 'ONBOARD')
                ->where('deleted_at IS NULL')
                ->get()->getRowArray();
            if ($onboard && (int) $onboard['total'] > 0) {
                $info['onboarding_progress'] = [
                    'total'     => (int) $onboard['total'],
                    'completed' => (int) $onboard['completed'],
                    'overdue'   => (int) $onboard['overdue'],
                    'percent'   => (int) round(((int) $onboard['completed'] / max(1, (int) $onboard['total'])) * 100),
                ];
            }

            // Offboarding progress
            $offboard = $db->table('employee_tasks')
                ->select('COUNT(*) AS total, '
                    . 'SUM(CASE WHEN status = "COMPLETED" THEN 1 ELSE 0 END) AS completed, '
                    . 'SUM(CASE WHEN status IN ("OVERDUE") OR (status = "PENDING" AND due_date < CURDATE()) THEN 1 ELSE 0 END) AS overdue')
                ->where('employee_id', $employeeId)
                ->where('type', 'OFFBOARD')
                ->where('deleted_at IS NULL')
                ->get()->getRowArray();
            if ($offboard && (int) $offboard['total'] > 0) {
                $info['offboarding_progress'] = [
                    'total'     => (int) $offboard['total'],
                    'completed' => (int) $offboard['completed'],
                    'overdue'   => (int) $offboard['overdue'],
                    'percent'   => (int) round(((int) $offboard['completed'] / max(1, (int) $offboard['total'])) * 100),
                ];
            }
        } catch (\Throwable $e) {}

        // Active resignation
        try {
            $info['active_resignation'] = $db->table('resignation_requests')
                ->select('id, status, last_working_date, effective_date, reason_category')
                ->where('employee_id', $employeeId)
                ->whereIn('status', ['PENDING_SUPERVISOR', 'PENDING_HR', 'APPROVED'])
                ->where('deleted_at IS NULL')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()->getRowArray() ?: null;
        } catch (\Throwable $e) {}

        // Active termination
        try {
            if ($db->tableExists('termination_requests')) {
                $info['active_termination'] = $db->table('termination_requests')
                    ->select('id, status, reference_number, termination_type, reason_code, '
                        . 'planned_termination_date, effective_date')
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', [
                        'DRAFT', 'PENDING_BIPARTITE', 'PENDING_HR_DIRECTOR',
                        'PENDING_BOD', 'APPROVED', 'EXECUTED',
                    ])
                    ->where('deleted_at IS NULL')
                    ->orderBy('id', 'DESC')
                    ->limit(1)
                    ->get()->getRowArray() ?: null;
            }
        } catch (\Throwable $e) {}

        return $info;
    }

    public function humanizeTenure(int $months): string
    {
        if ($months <= 0) return 'kurang dari 1 bulan';
        $years = intdiv($months, 12);
        $remM = $months % 12;
        $parts = [];
        if ($years > 0) $parts[] = $years . ' tahun';
        if ($remM > 0)  $parts[] = $remM . ' bulan';
        return implode(' ', $parts);
    }
}