<?php

namespace App\Libraries\Payroll;

use App\Libraries\Attendance\OvertimeCalculator;
use App\Libraries\Employee\EmployeeEffectiveHistoryService;
use App\Repositories\Payroll\PayrollRepository;
use Config\Services;

/**
 * PayrollService - Orchestrates payroll calculation per period.
 *
 * Sprint 17+ — auto-merge bonus run yang APPROVED/PAID dengan
 * `payroll_period_id` matching ke payslip karyawan tersebut. Bonus
 * total appear sebagai earning baru kategori `BONUS` (tax_treatment
 * NORMAL → taxable, NON_TAXABLE → exclude dari grossTaxable, GROSS_UP
 * & NET defer ke Sprint 18+).
 */
class PayrollService
{
    /**
     * Calendar-day proration for employees joining or leaving inside a pay period.
     * The contractual monthly salary remains the basis for overtime and BPJS;
     * only the BASIC cash earning is prorated.
     */
    public static function prorateBasicSalary(
        float $monthlySalary,
        string $joinDate,
        ?string $resignDate,
        string $periodStart,
        string $periodEnd,
    ): float {
        $start = new \DateTimeImmutable($periodStart);
        $end = new \DateTimeImmutable($periodEnd);
        if ($end < $start || $monthlySalary <= 0) {
            return 0.0;
        }

        $eligibleStart = max($start, new \DateTimeImmutable($joinDate));
        $eligibleEnd = $resignDate ? min($end, new \DateTimeImmutable($resignDate)) : $end;
        if ($eligibleEnd < $eligibleStart) {
            return 0.0;
        }

        $periodDays = (int) $start->diff($end)->days + 1;
        $eligibleDays = (int) $eligibleStart->diff($eligibleEnd)->days + 1;

        return round($monthlySalary * $eligibleDays / $periodDays, 2);
    }

    /**
     * Sum approved unpaid-leave days attributable to a payroll period.
     * Stored leave days already account for half days, weekends and holidays;
     * overlapping rows are proportionally clipped at period boundaries.
     */
    public static function unpaidLeaveDaysInPeriod(array $leaves, string $periodStart, string $periodEnd): float
    {
        $periodFrom = new \DateTimeImmutable($periodStart);
        $periodTo = new \DateTimeImmutable($periodEnd);
        if ($periodTo < $periodFrom) {
            return 0.0;
        }

        $days = 0.0;
        foreach ($leaves as $leave) {
            $leaveFrom = new \DateTimeImmutable((string) $leave['start_date']);
            $leaveTo = new \DateTimeImmutable((string) $leave['end_date']);
            $overlapFrom = max($periodFrom, $leaveFrom);
            $overlapTo = min($periodTo, $leaveTo);
            if ($overlapTo < $overlapFrom) {
                continue;
            }

            $storedDays = max(0.0, (float) ($leave['days'] ?? 0));
            $leaveCalendarDays = (int) $leaveFrom->diff($leaveTo)->days + 1;
            $overlapCalendarDays = (int) $overlapFrom->diff($overlapTo)->days + 1;
            $days += $leaveCalendarDays === $overlapCalendarDays
                ? $storedDays
                : $storedDays * $overlapCalendarDays / max(1, $leaveCalendarDays);
        }

        return round($days, 2);
    }

    public static function unpaidLeaveDeduction(
        float $monthlySalary,
        int $workingDaysPerMonth,
        float $unpaidLeaveDays,
    ): float {
        if ($monthlySalary <= 0 || $unpaidLeaveDays <= 0) {
            return 0.0;
        }

        return round($monthlySalary / max(1, $workingDaysPerMonth) * $unpaidLeaveDays, 2);
    }

    public static function approvedReimbursementTotal(array $reimbursements): float
    {
        $total = 0.0;
        foreach ($reimbursements as $reimbursement) {
            $total += max(0.0, (float) ($reimbursement['amount'] ?? 0));
        }

        return round($total, 2);
    }

    /**
     * Calculate payroll for an entire period.
     *
     * @return array summary
     */
    public function calculatePeriod(int $payrollPeriodId): array
    {
        $activeCompanyId = $this->activeCompanyId();
        $repository = new PayrollRepository();
        $db = $repository->connection();
        $period = $repository->findPeriod($payrollPeriodId, $activeCompanyId);
        if (!$period) {
            throw new \InvalidArgumentException('Payroll period not found');
        }
        if (!in_array($period['status'], ['DRAFT', 'CALCULATED'], true)) {
            throw new \RuntimeException('Approved or finalized payroll cannot be recalculated');
        }

        // ─── Pending-approval pre-check ──────────────────────────────
        // Scan transaksi yang memengaruhi perhitungan payroll (dari
        // payroll components & approval-flow lainnya) yang statusnya
        // masih menggantung. Hanya warn + log (tidak block calculate)
        // supaya HR tetap bisa lanjut hitung; mereka punya audit trail
        // kalau ternyata ada pengajuan yang ke-skip dan harus di-recalc
        // setelah approval.
        $pendingService = new PayrollPendingApprovalService($db);
        $pending = $pendingService->scan($payrollPeriodId, $activeCompanyId, $period);
        $warnings = [];
        if (!empty($pending['items'])) {
            $warnings = $pendingService->warnings($pending);
            $this->logPendingApprovals($payrollPeriodId, $period, $pending, $warnings);
        }

        // Calculate the entire period atomically. A failed employee calculation
        // must restore the previous payslips and period totals instead of
        // leaving a partially rebuilt payroll register behind.
        $db->transBegin();

        try {
            // Clear existing payslips for THIS period (re-calculation flow).
            // Defensive: also clear orphan payslips with the same slip_number
            // prefix (`PS-{period_code}-...`) yang dimiliki perusahaan ini.
            // Skenario yang menyebabkan UNIQUE constraint error sebelumnya:
            //   1. User bikin period dengan code "2024-01", calculate, lalu
            //      delete period itu (soft / hard).
            //   2. Bikin period baru dengan code yang sama lagi → calculate
            //      → insert nubrak payslip lama yang masih ada di DB karena
            //      delete cascade tidak terkonfigurasi di SQLite.
            //   3. INSERT gagal: "UNIQUE constraint failed: payslips.slip_number".
            // Cleanup berbasis slip_number prefix mencegah ini tanpa
            // mengubah format nomor slip.
            $repository->clearCalculatedPayslips($period, $activeCompanyId);
            $employees = $repository->employeesForPeriod($period, $activeCompanyId);

            $totalGross = 0;
            $totalDeduction = 0;
            $totalNet = 0;
            $count = 0;

            $bpjsCalc = Services::bpjs();
            $pphCalc = Services::pph21();
            $otCalc = new OvertimeCalculator();

            // Sprint 5-6 — magic numbers ke setting per-company
            $cfg = Services::payrollSettings();
            $workingDaysPerMonth = $cfg->workingDaysPerMonth((int) $period['company_id']);
            $overtimeHoursPerMonth = $cfg->overtimeHoursPerMonth((int) $period['company_id']);
            $absentDeductionEnabled = $cfg->absentDeductionEnabled((int) $period['company_id']);

            // Sprint 17+ — Lookup bonus yang sudah di-link ke period ini
            // (status APPROVED/PAID). Map: employee_id => {amount, breakdown}.
            // Hanya queried sekali per period untuk efisiensi.
            try {
                $bonusByEmp = (new BonusService($db))->bonusesForPayrollPeriod(
                    $payrollPeriodId,
                    (int) $period['company_id'],
                );
            } catch (\Throwable $e) {
                log_message('warning', 'BonusService lookup failed: ' . $e->getMessage());
                $bonusByEmp = [];
            }

            // H1.12 — Lookup kompensasi PKWT yang akan jatuh tempo di
            // periode ini (per PP 35/2021). Map: employee_id => earning entry.
            // Persist ke `pkwt_compensation_paid` di-handle saat finalize().
            try {
                $pkwtByEmp = (new \App\Libraries\Lifecycle\PkwtCompensationService())
                    ->earningEntriesForPayrollPeriod($payrollPeriodId);
            } catch (\Throwable $e) {
                log_message('warning', 'PkwtCompensationService lookup failed: ' . $e->getMessage());
                $pkwtByEmp = [];
            }

            $adjustmentsByEmp = $repository->adjustmentsByEmployee($payrollPeriodId, $activeCompanyId);
            $oneTimeByEmp = $repository->oneTimeComponentsByEmployee($payrollPeriodId, $activeCompanyId);

            $employeeCalculator = new PayrollEmployeeCalculationService($db);
            foreach ($employees as $emp) {
                if ($db->tableExists('employee_master_history')) {
                    $snapshot = (new EmployeeEffectiveHistoryService($db))->asOf(
                        (int) $period['company_id'],
                        (int) $emp['id'],
                        (string) $period['end_date'],
                    );
                    if ($snapshot !== null) {
                        foreach (['position_id', 'manager_id', 'supervisor_id', 'department_id', 'branch_id', 'cost_center_id', 'job_grade_id', 'work_location_id', 'basic_salary', 'fixed_allowance', 'employment_status', 'employment_type', 'contract_end_date'] as $field) {
                            $emp[$field] = $snapshot[$field] ?? null;
                        }
                    }
                }
                $result = $employeeCalculator->calculate(
                    $emp,
                    $period,
                    $bpjsCalc,
                    $pphCalc,
                    $otCalc,
                    $workingDaysPerMonth,
                    $overtimeHoursPerMonth,
                    $absentDeductionEnabled,
                    $bonusByEmp[(int) $emp['id']] ?? null,
                    $pkwtByEmp[(int) $emp['id']] ?? null,
                    $adjustmentsByEmp[(int) $emp['id']] ?? [],
                    $oneTimeByEmp[(int) $emp['id']] ?? [],
                );
                $totalGross += $result['gross_earning'];
                $totalDeduction += $result['total_deduction'];
                $totalNet += $result['take_home_pay'];
                $count++;
            }

            $repository->completeCalculation(
                $payrollPeriodId,
                $activeCompanyId,
                Services::tenant()->getUserId(),
                ['total_gross' => $totalGross, 'total_deduction' => $totalDeduction, 'total_net' => $totalNet],
            );

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Payroll calculation transaction failed');
            }
            if (!$db->transCommit()) {
                throw new \RuntimeException('Unable to commit payroll calculation');
            }

            return [
                'period_id' => $payrollPeriodId,
                'employee_count' => $count,
                'total_gross' => $totalGross,
                'total_deduction' => $totalDeduction,
                'total_net' => $totalNet,
                'warnings' => $warnings,
                'pending_approvals' => $pending,
            ];
        } catch (\Throwable $exception) {
            $db->transRollback();

            throw $exception;
        }
    }

    /**
     * Cek pengajuan transaksi yang memengaruhi payroll tapi masih menggantung
     * approval pada saat calculate. Hasilnya dipakai untuk:
     *   - Surface warning ke caller (controller / UI)
     *   - Log ke `log_message('warning', ...)` & `AuditLogger` severity WARNING
     *
     * Cakupan check (semua di-scope per company periode ini):
     *   1. Overtime PENDING dengan work_date dalam [start_date, end_date]
     *      → kalau di-approve setelah calculate, lembur akan ke-skip dari
     *        slip period ini sampai user re-calculate.
     *   2. Attendance Change Request PENDING dengan work_date dalam range
     *      → bisa mengubah status attendance (PRESENT/ABSENT) yang
     *        mempengaruhi absent_deduction di slip.
     *   3. Reimbursement PENDING dengan expense_date <= end_date
     *      → kalau di-approve setelah calculate, akan terbayar di
     *        period berikutnya saja.
     *   4. Loan/Kasbon PENDING (any) di company
     *      → cicilan baru mulai jalan setelah status RUNNING; kalau
     *        approval terlambat, instalment pertama akan delay 1 period.
     *   5. Bonus Run dengan payroll_period_id = period ini & status
     *      DRAFT/CALCULATED (belum APPROVED)
     *      → bonus tidak akan ter-merge ke payslip period ini.
     *
     * @param int        $payrollPeriodId
     * @param array|null $period Pre-loaded period row (optimization).
     *
     * @return array{
     *   total_count:int,
     *   items:array<string, list<array<string,mixed>>>,
     *   counts:array<string, int>
     * }
     */
    public function pendingApprovalsForPeriod(int $payrollPeriodId, ?array $period = null): array
    {
        $companyId = $period !== null
            ? (int) ($period['company_id'] ?? 0)
            : $this->activeCompanyId();
        if ($period === null) {
            $period = (new PayrollRepository())->findPeriod($payrollPeriodId, $companyId);
        }
        if (!$period) {
            return ['total_count' => 0, 'items' => [], 'counts' => []];
        }

        return (new PayrollPendingApprovalService())->scan(
            $payrollPeriodId,
            $companyId,
            $period,
        );
    }

    /**
     * Bentuk pesan warning Bahasa Indonesia ringkas per kategori.
     *
     * @return list<array{type:string,count:int,message:string}>
     */

    /**
     * Tulis log warning + audit trail untuk pending approvals.
     */
    private function logPendingApprovals(
        int $payrollPeriodId,
        array $period,
        array $pending,
        array $warnings
    ): void {
        $summary = [];
        foreach ($pending['counts'] as $type => $count) {
            $summary[] = "{$type}={$count}";
        }
        $summaryStr = implode(', ', $summary);

        log_message(
            'warning',
            sprintf(
                '[Payroll calculate] Period #%d (%s) memiliki transaksi pending approval: %s',
                $payrollPeriodId,
                $period['code'] ?? '-',
                $summaryStr,
            ),
        );

        try {
            Services::audit()
                ->forModule('PAYROLL')
                ->log(
                    action: 'payroll_period.pending_approvals_detected',
                    entity: 'payroll_period',
                    entityId: $payrollPeriodId,
                    description: sprintf(
                        'Calculate periode #%d (%s): terdapat %d transaksi pending approval (%s).',
                        $payrollPeriodId,
                        $period['code'] ?? '-',
                        (int) $pending['total_count'],
                        $summaryStr,
                    ),
                    severity: 'WARNING',
                    metadata: [
                        'period_code' => $period['code'] ?? null,
                        'period_name' => $period['name'] ?? null,
                        'start_date' => $period['start_date'] ?? null,
                        'end_date' => $period['end_date'] ?? null,
                        'counts' => $pending['counts'],
                        'warnings' => $warnings,
                    ],
                );
        } catch (\Throwable $e) {
            // Audit failure jangan menggagalkan calculate.
            log_message('error', 'AuditLogger.log pending approvals failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array{amount:float, breakdown:array}|null $bonusInfo
     *        Sprint 17+ — info bonus run yang sudah APPROVED/PAID untuk
     *        period ini. Kalau null/empty → tidak ada bonus untuk
     *        karyawan ini. Diteruskan oleh calculatePeriod() supaya
     *        DB query bonus hanya sekali (di luar loop employee).
     * @param array{code:string,name:string,category:string,amount:float,is_taxable:int,contract_id:int}|null $pkwtInfo
     *        H1.12 — entry kompensasi PKWT yang akan dibayarkan di
     *        period ini (jika kontrak PKWT karyawan jatuh tempo).
     */

    /** Freeze approved payroll for disbursement; bank confirmation settles payment side-effects. */
    public function finalize(int $payrollPeriodId): void
    {
        $companyId = $this->activeCompanyId();
        $result = (new PayrollFinalizationService())->finalize(
            $payrollPeriodId,
            $companyId,
            Services::tenant()->getUserId(),
        );
        $period = $result['period'];
        $notifier = Services::notification();
        foreach ($result['recipients'] as $recipient) {
            if (empty($recipient['user_id'])) {
                continue;
            }
            $notifier->send(
                (int) $recipient['user_id'],
                $companyId,
                'payslip.generated',
                'Slip gaji tersedia',
                "Slip gaji periode {$period['name']} sudah dapat diakses.",
                ['payslip_id' => $recipient['id']],
                "/payslips/{$recipient['id']}",
                true,
            );
        }
    }

    private function activeCompanyId(): int
    {
        $companyId = Services::tenant()->getCompanyId();
        if ($companyId <= 0) {
            throw new \RuntimeException('Active company context is required for payroll operations');
        }

        return $companyId;
    }
}
