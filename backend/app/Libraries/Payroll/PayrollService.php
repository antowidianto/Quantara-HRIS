<?php

namespace App\Libraries\Payroll;

use Config\Services;

use App\Libraries\Attendance\OvertimeCalculator;
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
     * Calculate payroll for an entire period.
     *
     * @return array summary
     */
    public function calculatePeriod(int $payrollPeriodId): array
    {
        $db = \Config\Database::connect();
        $period = $db->table('payroll_periods')->where('id', $payrollPeriodId)->get()->getRowArray();
        if (! $period) {
            throw new \InvalidArgumentException('Payroll period not found');
        }

        // ─── Pending-approval pre-check ──────────────────────────────
        // Scan transaksi yang memengaruhi perhitungan payroll (dari
        // payroll components & approval-flow lainnya) yang statusnya
        // masih menggantung. Hanya warn + log (tidak block calculate)
        // supaya HR tetap bisa lanjut hitung; mereka punya audit trail
        // kalau ternyata ada pengajuan yang ke-skip dan harus di-recalc
        // setelah approval.
        $pending = $this->pendingApprovalsForPeriod($payrollPeriodId, $period);
        $warnings = [];
        if (! empty($pending['items'])) {
            $warnings = $this->buildPendingWarnings($pending);
            $this->logPendingApprovals($payrollPeriodId, $period, $pending, $warnings);
        }

        // Clear existing payslips for THIS period (re-calculation flow).
        $existing = $db->table('payslips')->where('payroll_period_id', $payrollPeriodId)->get()->getResultArray();
        foreach ($existing as $p) {
            $db->table('payslip_details')->where('payslip_id', $p['id'])->delete();
        }
        $db->table('payslips')->where('payroll_period_id', $payrollPeriodId)->delete();

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
        $slipPrefix = 'PS-' . $period['code'] . '-';
        $orphan = $db->table('payslips')
            ->where('company_id', (int) $period['company_id'])
            ->like('slip_number', $slipPrefix, 'after')
            ->get()->getResultArray();
        foreach ($orphan as $p) {
            $db->table('payslip_details')->where('payslip_id', $p['id'])->delete();
            $db->table('payslips')->where('id', $p['id'])->delete();
        }

        $employees = $db->table('employees')
            ->where('company_id', $period['company_id'])
            ->where('is_active', 1)
            ->where('join_date <=', $period['end_date'])
            ->groupStart()
                ->where('resign_date IS NULL')
                ->orWhere('resign_date >=', $period['start_date'])
            ->groupEnd()
            ->get()
            ->getResultArray();

        $totalGross = 0;
        $totalDeduction = 0;
        $totalNet = 0;
        $count = 0;

        $bpjsCalc = Services::bpjs();
        $pphCalc  = Services::pph21();
        $otCalc   = new OvertimeCalculator();

        // Sprint 5-6 — magic numbers ke setting per-company
        $cfg = Services::payrollSettings();
        $workingDaysPerMonth = $cfg->workingDaysPerMonth((int) $period['company_id']);
        $overtimeHoursPerMonth = $cfg->overtimeHoursPerMonth((int) $period['company_id']);
        $absentDeductionEnabled = $cfg->absentDeductionEnabled((int) $period['company_id']);

        // Sprint 17+ — Lookup bonus yang sudah di-link ke period ini
        // (status APPROVED/PAID). Map: employee_id => {amount, breakdown}.
        // Hanya queried sekali per period untuk efisiensi.
        try {
            $bonusByEmp = (new BonusService($db))->bonusesForPayrollPeriod($payrollPeriodId);
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

        foreach ($employees as $emp) {
            $result = $this->calculateEmployee(
                $emp, $period, $bpjsCalc, $pphCalc, $otCalc,
                $workingDaysPerMonth, $overtimeHoursPerMonth, $absentDeductionEnabled,
                $bonusByEmp[(int) $emp['id']] ?? null,
                $pkwtByEmp[(int) $emp['id']] ?? null,
            );
            $totalGross     += $result['gross_earning'];
            $totalDeduction += $result['total_deduction'];
            $totalNet       += $result['take_home_pay'];
            $count++;
        }

        $db->table('payroll_periods')
            ->where('id', $payrollPeriodId)
            ->update([
                'status'          => 'CALCULATED',
                'total_gross'     => $totalGross,
                'total_deduction' => $totalDeduction,
                'total_net'       => $totalNet,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

        return [
            'period_id'        => $payrollPeriodId,
            'employee_count'   => $count,
            'total_gross'      => $totalGross,
            'total_deduction'  => $totalDeduction,
            'total_net'        => $totalNet,
            'warnings'         => $warnings,
            'pending_approvals'=> $pending,
        ];
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
     * @return array{
     *   total_count:int,
     *   items:array<string, list<array<string,mixed>>>,
     *   counts:array<string, int>
     * }
     */
    public function pendingApprovalsForPeriod(int $payrollPeriodId, ?array $period = null): array
    {
        $db = \Config\Database::connect();
        if ($period === null) {
            $period = $db->table('payroll_periods')->where('id', $payrollPeriodId)->get()->getRowArray();
        }
        if (! $period) {
            return ['total_count' => 0, 'items' => [], 'counts' => []];
        }

        $companyId = (int) $period['company_id'];
        $startDate = (string) $period['start_date'];
        $endDate   = (string) $period['end_date'];

        $items = [];

        // 1. Overtime PENDING (dalam window period)
        $overtimePending = $db->table('overtimes o')
            ->select('o.id, o.employee_id, o.work_date, o.hours, o.day_type, o.created_at,'
                . ' e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = o.employee_id', 'left')
            ->where('o.company_id', $companyId)
            ->where('o.status', 'PENDING')
            ->where('o.work_date >=', $startDate)
            ->where('o.work_date <=', $endDate)
            ->orderBy('o.work_date', 'ASC')
            ->limit(100)
            ->get()->getResultArray();
        if (! empty($overtimePending)) {
            $items['overtime'] = $overtimePending;
        }

        // 2. Attendance change request PENDING (dalam window period)
        if ($db->tableExists('attendance_change_requests')) {
            $acrPending = $db->table('attendance_change_requests acr')
                ->select('acr.id, acr.employee_id, acr.work_date, acr.created_at,'
                    . ' e.full_name AS employee_name, e.employee_number')
                ->join('employees e', 'e.id = acr.employee_id', 'left')
                ->where('acr.company_id', $companyId)
                ->where('acr.status', 'PENDING')
                ->where('acr.work_date >=', $startDate)
                ->where('acr.work_date <=', $endDate)
                ->orderBy('acr.work_date', 'ASC')
                ->limit(100)
                ->get()->getResultArray();
            if (! empty($acrPending)) {
                $items['attendance_change'] = $acrPending;
            }
        }

        // 3. Reimbursement PENDING dengan expense_date <= end_date
        $reimbPending = $db->table('reimbursements r')
            ->select('r.id, r.employee_id, r.request_number, r.category, r.expense_date,'
                . ' r.amount, r.created_at,'
                . ' e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = r.employee_id', 'left')
            ->where('r.company_id', $companyId)
            ->where('r.status', 'PENDING')
            ->where('r.expense_date <=', $endDate)
            ->orderBy('r.expense_date', 'ASC')
            ->limit(100)
            ->get()->getResultArray();
        if (! empty($reimbPending)) {
            $items['reimbursement'] = $reimbPending;
        }

        // 4. Loan/Kasbon PENDING
        $loanPending = $db->table('loans l')
            ->select('l.id, l.employee_id, l.loan_number, l.principal,'
                . ' l.installment_amount, l.installments, l.created_at,'
                . ' e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = l.employee_id', 'left')
            ->where('l.company_id', $companyId)
            ->where('l.status', 'PENDING')
            ->orderBy('l.created_at', 'ASC')
            ->limit(100)
            ->get()->getResultArray();
        if (! empty($loanPending)) {
            $items['loan'] = $loanPending;
        }

        // 5. Bonus run yang ditujukan ke period ini tapi belum APPROVED
        if ($db->tableExists('bonus_runs')) {
            $bonusPending = $db->table('bonus_runs br')
                ->select('br.id, br.name, br.status, br.total_amount,'
                    . ' br.recipient_count, br.pay_date, br.bonus_scheme_id,'
                    . ' bs.code AS scheme_code, bs.name AS scheme_name')
                ->join('bonus_schemes bs', 'bs.id = br.bonus_scheme_id', 'left')
                ->where('br.company_id', $companyId)
                ->where('br.payroll_period_id', $payrollPeriodId)
                ->whereIn('br.status', ['DRAFT', 'CALCULATED'])
                ->where('br.deleted_at IS NULL')
                ->orderBy('br.id', 'ASC')
                ->limit(100)
                ->get()->getResultArray();
            if (! empty($bonusPending)) {
                $items['bonus_run'] = $bonusPending;
            }
        }

        $counts = [];
        $total = 0;
        foreach ($items as $key => $rows) {
            $counts[$key] = count($rows);
            $total += count($rows);
        }

        return [
            'total_count' => $total,
            'items'       => $items,
            'counts'      => $counts,
        ];
    }

    /**
     * Bentuk pesan warning Bahasa Indonesia ringkas per kategori.
     *
     * @return list<array{type:string,count:int,message:string}>
     */
    private function buildPendingWarnings(array $pending): array
    {
        $labels = [
            'overtime'          => 'pengajuan lembur',
            'attendance_change' => 'koreksi absensi',
            'reimbursement'     => 'pengajuan reimbursement',
            'loan'              => 'pengajuan kasbon',
            'bonus_run'         => 'bonus run',
        ];
        $warnings = [];
        foreach ($pending['counts'] as $type => $count) {
            if ($count <= 0) continue;
            $label = $labels[$type] ?? $type;
            $warnings[] = [
                'type'    => $type,
                'count'   => $count,
                'message' => sprintf(
                    'Ada %d %s yang masih menunggu approval — perhitungan periode ini akan mengabaikan transaksi tersebut sampai di-approve & re-calculate.',
                    $count,
                    $label,
                ),
            ];
        }
        return $warnings;
    }

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
                        'start_date'  => $period['start_date'] ?? null,
                        'end_date'    => $period['end_date'] ?? null,
                        'counts'      => $pending['counts'],
                        'warnings'    => $warnings,
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
    private function calculateEmployee(
        array $emp,
        array $period,
        BpjsCalculator $bpjsCalc,
        Pph21TerCalculator $pphCalc,
        OvertimeCalculator $otCalc,
        int $workingDaysPerMonth = 22,
        int $overtimeHoursPerMonth = 173,
        bool $absentDeductionEnabled = true,
        ?array $bonusInfo = null,
        ?array $pkwtInfo = null,
    ): array {
        $db = \Config\Database::connect();
        $companyId = (int) $period['company_id'];

        // ─── Build earnings ───────────────────────────────────────────
        $earnings = [];
        $deductions = [];

        $basicSalary = (float) $emp['basic_salary'];
        if ($basicSalary > 0) {
            $earnings[] = ['code' => 'BASIC', 'name' => 'Gaji Pokok', 'category' => 'BASIC', 'amount' => $basicSalary, 'is_taxable' => 1];
        }

        // Recurring components.
        //
        // H2.10 — Pajak Natura (PMK 66/2023): LEFT JOIN allowance_types
        // untuk men-detect kalau payroll_component ini sebenarnya
        // representasi natura/kenikmatan. Detection: payroll_components
        // dirujuk dari allowance_types.payroll_component_id.
        // Bila `is_taxable_natura=1` → item ditandai is_natura supaya
        // diperlakukan: (a) tetap menambah Gross Taxable PPh21 di atas
        // ambang batas tahunan, (b) tidak menambah cash Take Home Pay.
        $hasNaturaCols = $db->fieldExists('is_taxable_natura', 'allowance_types');

        $recBuilder = $db->table('employee_payroll_components epc')
            ->select('epc.amount, c.code, c.name, c.type, c.category, c.is_taxable'
                . ($hasNaturaCols
                    ? ', at.is_taxable_natura AS is_natura, at.natura_tax_threshold AS natura_threshold_yearly'
                    : ''))
            ->join('payroll_components c', 'c.id = epc.component_id');
        if ($hasNaturaCols) {
            $recBuilder->join('allowance_types at', 'at.payroll_component_id = c.id', 'left');
        }
        $recurring = $recBuilder
            ->where('epc.employee_id', $emp['id'])
            ->where('c.is_active', 1)
            ->where('epc.effective_from <=', $period['end_date'])
            ->groupStart()
                ->where('epc.effective_to IS NULL')
                ->orWhere('epc.effective_to >=', $period['start_date'])
            ->groupEnd()
            ->get()
            ->getResultArray();

        foreach ($recurring as $r) {
            if ($r['type'] === 'EARNING') {
                $entry = [
                    'code'       => $r['code'],
                    'name'       => $r['name'],
                    'category'   => $r['category'],
                    'amount'     => (float) $r['amount'],
                    'is_taxable' => (int) $r['is_taxable'],
                ];
                if ((int) ($r['is_natura'] ?? 0) === 1) {
                    // Apply ambang batas natura via pure helper
                    // (NaturaTaxCalculator). Logic taxability per
                    // PMK 66/2023 di-extract supaya bisa unit tested.
                    $entry = NaturaTaxCalculator::buildEntry(
                        $entry,
                        (float) ($r['natura_threshold_yearly'] ?? 0),
                    );
                } else {
                    $entry['is_natura'] = 0;
                }
                $earnings[] = $entry;
            } else {
                $deductions[] = ['code' => $r['code'], 'name' => $r['name'], 'category' => $r['category'], 'amount' => (float) $r['amount'], 'is_taxable' => (int) $r['is_taxable']];
            }
        }

        // ─── Distance-based allowances (per cabang & grade) ──────────
        // Pastikan snapshot terbaru — recompute defensif untuk karyawan
        // ini sebelum membaca assignment-nya.
        try {
            Services::allowance()->recomputeEmployee((int) $emp['id']);
        } catch (\Throwable $e) {
            // Jangan gagalkan payroll hanya karena recompute error.
            log_message('warning', 'AllowanceService recompute failed: ' . $e->getMessage());
        }
        $allowanceEarnings = Services::allowance()->earningsForPayroll((int) $emp['id']);
        foreach ($allowanceEarnings as $ae) {
            $earnings[] = $ae;
        }

        // ─── Attendance summary ───────────────────────────────────────
        $attRows = $db->table('attendances')
            ->where('employee_id', $emp['id'])
            ->where('work_date >=', $period['start_date'])
            ->where('work_date <=', $period['end_date'])
            ->get()
            ->getResultArray();

        $workDays = max(1, (int) (((strtotime($period['end_date']) - strtotime($period['start_date'])) / 86400) + 1));
        $present = 0;
        $absent  = 0;
        $lateMins = 0;
        foreach ($attRows as $a) {
            if (in_array($a['status'], ['PRESENT', 'LATE', 'HALF_DAY', 'LEAVE', 'HOLIDAY'], true)) {
                $present++;
            } elseif ($a['status'] === 'ABSENT') {
                $absent++;
            }
            $lateMins += (int) $a['late_minutes'];
        }

        // ─── Overtime ────────────────────────────────────────────────
        $otRows = $db->table('overtimes')
            ->where('employee_id', $emp['id'])
            ->where('status', 'APPROVED')
            ->where('work_date >=', $period['start_date'])
            ->where('work_date <=', $period['end_date'])
            ->where('paid_in_payroll_period_id IS NULL')
            ->get()
            ->getResultArray();

        $totalOtAmount = 0;
        $totalOtHours = 0;
        $fixedAllowance = 0;
        foreach ($earnings as $e) {
            if ($e['category'] === 'ALLOWANCE') $fixedAllowance += $e['amount'];
        }
        foreach ($otRows as $ot) {
            // Sprint 5-6 — pakai overtimeHoursPerMonth dari setting (default 173)
            $amount = $otCalc->calculate(
                $basicSalary + $fixedAllowance,
                (float) $ot['hours'],
                $ot['day_type'],
                5,
                $overtimeHoursPerMonth,
            );
            $totalOtAmount += $amount;
            $totalOtHours  += (float) $ot['hours'];
        }
        if ($totalOtAmount > 0) {
            $earnings[] = ['code' => 'OT', 'name' => 'Lembur', 'category' => 'OVERTIME', 'amount' => $totalOtAmount, 'is_taxable' => 1];
        }

        // ─── Reimbursements (approved, unpaid) ──────────────────────
        $reimbs = $db->table('reimbursements')
            ->where('employee_id', $emp['id'])
            ->where('status', 'APPROVED')
            ->where('paid_in_payroll_period_id IS NULL')
            ->get()
            ->getResultArray();

        $reimbTotal = 0;
        foreach ($reimbs as $r) {
            $reimbTotal += (float) $r['amount'];
        }
        if ($reimbTotal > 0) {
            $earnings[] = ['code' => 'REIMB', 'name' => 'Reimbursement', 'category' => 'REIMBURSEMENT', 'amount' => $reimbTotal, 'is_taxable' => 0];
        }

        // ─── Bonus auto-merge (Sprint 17+/18+) ──────────────────────
        // Bonus run yang sudah APPROVED/PAID dengan payroll_period_id
        // = period ini → masuk sebagai earning kategori BONUS via
        // helper pure function BonusCalculator::bonusEarningEntries()
        // (tax-treatment grouping di sana, mudah unit-tested).
        //
        // Sprint 18+ — Untuk bonus dengan tax_treatment GROSS_UP atau NET,
        // pre-process dulu via applyGrossUp() supaya nominal di breakdown
        // jadi effective gross. taxFunc-nya pakai TER monthly dengan
        // base = penghasilan taxable saat ini (basic + allowance +
        // overtime — reimbursement skip karena non-taxable).
        $bonusTotal = 0.0;
        $bonusInfoForEntries = $bonusInfo;
        if (is_array($bonusInfo) && ! empty($bonusInfo['breakdown'])) {
            $hasGrossUpOrNet = false;
            foreach ($bonusInfo['breakdown'] as $b) {
                $tt = strtoupper((string) ($b['tax_treatment'] ?? 'NORMAL'));
                if ($tt === 'GROSS_UP' || $tt === 'NET') {
                    $hasGrossUpOrNet = true;
                    break;
                }
            }
            if ($hasGrossUpOrNet) {
                // Hitung base taxable dari earnings yang sudah ada
                $baseTaxable = 0.0;
                foreach ($earnings as $e) {
                    if ($e['is_taxable']) $baseTaxable += (float) $e['amount'];
                }
                $taxFunc = fn (float $g): float => (float) $pphCalc->calculateMonthlyTer(
                    $g, $emp['ptkp_status'] ?: 'TK0', (int) $period['period_year']
                )['tax'];
                $bonusInfoForEntries = BonusCalculator::applyGrossUp($bonusInfo, $taxFunc, $baseTaxable);
            }
        }
        $bonusEntries = BonusCalculator::bonusEarningEntries($bonusInfoForEntries);
        foreach ($bonusEntries as $entry) {
            $earnings[] = $entry;
            $bonusTotal += (float) $entry['amount'];
        }

        // ─── Kompensasi PKWT (H1.12 — PP 35/2021) ───────────────────
        // Bila kontrak PKWT karyawan jatuh tempo di periode ini,
        // sisipkan earning "Kompensasi PKWT" sebagai bagian dari Gross.
        // Status `pkwt_compensation_paid=1` di-flag saat finalize().
        $pkwtCompensationAmount = 0.0;
        $pkwtContractId = null;
        if (is_array($pkwtInfo) && (float) ($pkwtInfo['amount'] ?? 0) > 0) {
            $earnings[] = [
                'code'       => $pkwtInfo['code'] ?? 'PKWT_COMP',
                'name'       => $pkwtInfo['name'] ?? 'Kompensasi PKWT',
                'category'   => $pkwtInfo['category'] ?? 'BONUS',
                'amount'     => (float) $pkwtInfo['amount'],
                'is_taxable' => (int) ($pkwtInfo['is_taxable'] ?? 1),
            ];
            $pkwtCompensationAmount = (float) $pkwtInfo['amount'];
            $pkwtContractId = (int) ($pkwtInfo['contract_id'] ?? 0);
        }

        // ─── Absent deduction (proportional) ────────────────────────
        // Sprint 5-6 — workingDaysPerMonth dari setting per-company,
        // default 22. Bisa di-disable lewat setting `payroll.absent_deduction_enabled=0`.
        $absentDeduction = 0;
        if ($absentDeductionEnabled && $absent > 0 && $workDays > 0) {
            $perDay = $basicSalary / max(1, $workingDaysPerMonth);
            $absentDeduction = round($perDay * $absent);
            $deductions[] = ['code' => 'ABSENT', 'name' => 'Potongan Absen', 'category' => 'OTHER', 'amount' => $absentDeduction, 'is_taxable' => 0];
        }

        // ─── Compute gross / cash / taxable split via pure helper ───
        //
        // H2.10 — `NaturaTaxCalculator::aggregate` mengembalikan tuple
        // (grossEarning, cashEarning, grossTaxable, naturaTotal) yang
        // sesuai PMK 66/2023:
        //   - `grossEarning` (basis SPT 1721) = SEMUA earning incl natura
        //   - `cashEarning` (basis THP)       = earning kecuali natura
        //   - `grossTaxable` (basis PPh21)    = pakai natura_tax_base
        //                                       (sudah di-apply threshold)
        $agg = NaturaTaxCalculator::aggregate($earnings);
        $grossEarning = $agg['gross_earning'];
        $cashEarning  = $agg['cash_earning'];
        $grossTaxable = $agg['gross_taxable'];
        $naturaTotal  = $agg['natura_total'];

        // ─── BPJS ────────────────────────────────────────────────────
        $bpjs = $bpjsCalc->calculate($companyId, $basicSalary, $fixedAllowance);
        $deductions[] = ['code' => 'BPJS_KES_EMP', 'name' => 'BPJS Kesehatan', 'category' => 'BPJS_KES', 'amount' => $bpjs['kes_employee'], 'is_taxable' => 0];
        $deductions[] = ['code' => 'BPJS_JHT_EMP', 'name' => 'BPJS JHT', 'category' => 'BPJS_TK', 'amount' => $bpjs['jht_employee'], 'is_taxable' => 0];
        $deductions[] = ['code' => 'BPJS_JP_EMP', 'name' => 'BPJS JP', 'category' => 'BPJS_TK', 'amount' => $bpjs['jp_employee'], 'is_taxable' => 0];

        // ─── PPh21 ───────────────────────────────────────────────────
        $ptkp = $emp['ptkp_status'] ?: 'TK0';
        $isDecember = (int) ($period['period_month'] ?? 0) === 12 || (int) ($period['is_december_correction'] ?? 0) === 1;

        $pph = 0;
        $terRate = 0;
        $terCategory = $pphCalc->categoryFromPtkp($ptkp);

        if ($isDecember) {
            // Sum already-paid TER for this employee in this fiscal year
            $paidTer = (float) $db->table('payslips ps')
                ->selectSum('ps.pph21_amount')
                ->join('payroll_periods pp', 'pp.id = ps.payroll_period_id')
                ->where('ps.employee_id', $emp['id'])
                ->where('pp.period_year', $period['period_year'])
                ->where('pp.period_month <', 12)
                ->get()
                ->getRowArray()['pph21_amount'] ?? 0;

            $bpjsYearly = ($bpjs['jht_employee'] + $bpjs['jp_employee']) * 12;
            $grossYearly = $grossTaxable * 12;
            $dec = $pphCalc->calculateDecemberCorrection($grossYearly, $ptkp, $paidTer, (int) $period['period_year'], $bpjsYearly);
            $pph = $dec['december_tax'];
        } else {
            $ter = $pphCalc->calculateMonthlyTer($grossTaxable, $ptkp, (int) $period['period_year']);
            $pph = $ter['tax'];
            $terRate = $ter['rate'];
        }

        if ($pph > 0) {
            $deductions[] = ['code' => 'PPH21', 'name' => 'PPh 21', 'category' => 'PPH21', 'amount' => $pph, 'is_taxable' => 0];
        }

        // ─── Loan installments ───────────────────────────────────────
        $loans = $db->table('loans')
            ->where('employee_id', $emp['id'])
            ->where('status', 'RUNNING')
            ->where('remaining >', 0)
            ->get()
            ->getResultArray();

        $loanDeduction = 0;
        foreach ($loans as $loan) {
            $instalment = min((float) $loan['installment_amount'], (float) $loan['remaining']);
            $loanDeduction += $instalment;
        }
        if ($loanDeduction > 0) {
            $deductions[] = ['code' => 'LOAN', 'name' => 'Potongan Kasbon', 'category' => 'LOAN', 'amount' => $loanDeduction, 'is_taxable' => 0];
        }

        // ─── Totals ─────────────────────────────────────────────────
        // H2.10 — Take Home Pay cash = cashEarning - totalDeduction
        // (natura tidak ikut dibayar tunai). grossEarning tetap di
        // payslip untuk dasar pelaporan SPT 1721.
        $totalDeduction = 0;
        foreach ($deductions as $d) $totalDeduction += $d['amount'];
        $takeHome = $cashEarning - $totalDeduction;

        // ─── Persist payslip ────────────────────────────────────────
        $slipNumber = 'PS-' . $period['code'] . '-' . str_pad((string) $emp['id'], 5, '0', STR_PAD_LEFT);

        // Defensive guard: jika masih ada baris lain yang nyantol pakai
        // slip_number ini (mis. seed data lama), hapus dulu supaya
        // INSERT tidak gagal karena UNIQUE constraint. Aman karena
        // payslip difinalisasi tidak akan ke titik ini (period harus
        // CALCULATED untuk re-calculate).
        $stale = $db->table('payslips')->where('slip_number', $slipNumber)->get()->getRowArray();
        if ($stale) {
            $db->table('payslip_details')->where('payslip_id', $stale['id'])->delete();
            $db->table('payslips')->where('id', $stale['id'])->delete();
        }
        $payslipData = [
            'company_id'         => $companyId,
            'payroll_period_id'  => $period['id'],
            'employee_id'        => $emp['id'],
            'slip_number'        => $slipNumber,
            'employee_number'    => $emp['employee_number'],
            'employee_name'      => $emp['full_name'],
            'ptkp_status'        => $ptkp,
            'work_days'          => $workDays,
            'present_days'       => $present,
            'absent_days'        => $absent,
            'late_minutes'       => $lateMins,
            'overtime_hours'     => $totalOtHours,
            'basic_salary'       => $basicSalary,
            'gross_earning'      => $grossEarning,
            'overtime_amount'    => $totalOtAmount,
            'reimbursement_amount' => $reimbTotal,
            'bonus_amount'       => $bonusTotal,
            'bpjs_kes_employee'  => $bpjs['kes_employee'],
            'bpjs_kes_company'   => $bpjs['kes_company'],
            'bpjs_jht_employee'  => $bpjs['jht_employee'],
            'bpjs_jht_company'   => $bpjs['jht_company'],
            'bpjs_jp_employee'   => $bpjs['jp_employee'],
            'bpjs_jp_company'    => $bpjs['jp_company'],
            'bpjs_jkk_company'   => $bpjs['jkk_company'],
            'bpjs_jkm_company'   => $bpjs['jkm_company'],
            'pph21_amount'       => $pph,
            'pph21_method'       => 'TER',
            'ter_rate'           => $terRate,
            'ter_category'       => $terCategory,
            'loan_deduction'     => $loanDeduction,
            'absent_deduction'   => $absentDeduction,
            'total_earning'      => $grossEarning,
            'total_deduction'    => $totalDeduction,
            'take_home_pay'      => $takeHome,
            'breakdown'          => json_encode(['earnings' => $earnings, 'deductions' => $deductions], JSON_UNESCAPED_UNICODE),
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        // Sprint 6 — Cost Center: tag payslip ke cost center karyawan supaya
        // total biaya gaji bisa di-aggregate per cost center untuk laporan
        // budget vs actual finance. Resolve via chain employee → dept →
        // branch → company root. Best-effort: kalau resolver gagal atau
        // schema belum migrate, payslip tetap ter-insert tanpa cost_center.
        $payslipsCols = $db->getFieldNames('payslips');
        if (in_array('cost_center_id', $payslipsCols, true)) {
            try {
                $payslipData['cost_center_id'] = Services::costCenter()
                    ->resolveForEmployee((int) $emp['id']);
            } catch (\Throwable $e) {
                log_message('warning', 'Payslip cost_center resolve failed: ' . $e->getMessage());
            }
        }

        $db->table('payslips')->insert($payslipData);
        $payslipId = $db->insertID();

        // Insert details
        $sort = 0;
        $details = [];
        $payslipDetailsHasNatura = $db->fieldExists('is_natura', 'payslip_details');
        foreach ($earnings as $e) {
            $row = [
                'payslip_id' => $payslipId,
                'code'       => $e['code'],
                'name'       => $e['name'],
                'type'       => 'EARNING',
                'category'   => $e['category'],
                'amount'     => $e['amount'],
                'is_taxable' => $e['is_taxable'],
                'sort_order' => $sort++,
            ];
            if ($payslipDetailsHasNatura) {
                $row['is_natura'] = (int) ($e['is_natura'] ?? 0);
            }
            $details[] = $row;
        }
        foreach ($deductions as $d) {
            $details[] = [
                'payslip_id' => $payslipId,
                'code'       => $d['code'],
                'name'       => $d['name'],
                'type'       => 'DEDUCTION',
                'category'   => $d['category'],
                'amount'     => $d['amount'],
                'is_taxable' => 0,
                'sort_order' => $sort++,
            ];
        }
        if (! empty($details)) {
            $db->table('payslip_details')->insertBatch($details);
        }

        return [
            'gross_earning'   => $grossEarning,
            'total_deduction' => $totalDeduction,
            'take_home_pay'   => $takeHome,
        ];
    }

    /**
     * Finalize payroll: mark period as PAID, mark overtimes & reimbursements as paid,
     * decrement loan balances, send notifications.
     */
    public function finalize(int $payrollPeriodId): void
    {
        $db = \Config\Database::connect();
        $period = $db->table('payroll_periods')->where('id', $payrollPeriodId)->get()->getRowArray();
        if (! $period || $period['status'] !== 'CALCULATED') {
            throw new \RuntimeException('Period must be in CALCULATED status to finalize');
        }

        $db->transStart();

        // Mark overtime & reimbursement paid
        $db->table('overtimes')
            ->where('company_id', $period['company_id'])
            ->where('status', 'APPROVED')
            ->where('work_date >=', $period['start_date'])
            ->where('work_date <=', $period['end_date'])
            ->where('paid_in_payroll_period_id IS NULL')
            ->update(['paid_in_payroll_period_id' => $payrollPeriodId]);

        $db->table('reimbursements')
            ->where('company_id', $period['company_id'])
            ->where('status', 'APPROVED')
            ->where('paid_in_payroll_period_id IS NULL')
            ->update(['paid_in_payroll_period_id' => $payrollPeriodId, 'status' => 'PAID']);

        // Decrement loans
        $payslips = $db->table('payslips')->where('payroll_period_id', $payrollPeriodId)->get()->getResultArray();
        foreach ($payslips as $ps) {
            if ((float) $ps['loan_deduction'] <= 0) continue;
            $loans = $db->table('loans')
                ->where('employee_id', $ps['employee_id'])
                ->where('status', 'RUNNING')
                ->where('remaining >', 0)
                ->get()
                ->getResultArray();
            $remainingDeduction = (float) $ps['loan_deduction'];
            foreach ($loans as $loan) {
                if ($remainingDeduction <= 0) break;
                $apply = min($remainingDeduction, (float) $loan['installment_amount'], (float) $loan['remaining']);
                $newRemaining = (float) $loan['remaining'] - $apply;
                $newPaidInst  = (int) $loan['paid_installments'] + 1;
                $status = $newRemaining <= 0 ? 'COMPLETED' : 'RUNNING';
                $db->table('loans')->where('id', $loan['id'])->update([
                    'remaining'         => $newRemaining,
                    'paid_installments' => $newPaidInst,
                    'status'            => $status,
                    'updated_at'        => date('Y-m-d H:i:s'),
                ]);

                // Mark the oldest unpaid installment as PAID
                $unpaidInst = $db->table('loan_installments')
                    ->where('loan_id', $loan['id'])
                    ->where('status', 'UNPAID')
                    ->orderBy('installment_number', 'ASC')
                    ->limit(1)
                    ->get()
                    ->getRowArray();
                    
                if ($unpaidInst) {
                    $db->table('loan_installments')->where('id', $unpaidInst['id'])->update([
                        'status' => 'PAID',
                        'paid_at' => date('Y-m-d H:i:s'),
                        'payroll_period_id' => $payrollPeriodId,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                $remainingDeduction -= $apply;
            }
        }

        // H1.12 — Tandai kompensasi PKWT yang dibayarkan di period ini.
        // PkwtCompensationService.markPaid() idempotent: hanya kontrak
        // yang masih `pkwt_compensation_paid=0` & end_date dalam range
        // periode ini akan di-flag.
        try {
            $pkwtSvc = new \App\Libraries\Lifecycle\PkwtCompensationService();
            if ($db->fieldExists('pkwt_compensation_paid', 'employee_contracts')) {
                $expiringPkwt = $db->table('employee_contracts')
                    ->select('id, employee_id, end_date')
                    ->where('contract_type', 'PKWT')
                    ->where('end_date >=', $period['start_date'])
                    ->where('end_date <=', $period['end_date'])
                    ->groupStart()
                        ->where('pkwt_compensation_paid', 0)
                        ->orWhere('pkwt_compensation_paid IS NULL')
                    ->groupEnd()
                    ->get()->getResultArray();
                foreach ($expiringPkwt as $row) {
                    $calc = $pkwtSvc->compensationForContract((int) $row['id']);
                    if ($calc['amount'] > 0) {
                        $pkwtSvc->markPaid((int) $row['id'], $payrollPeriodId, (float) $calc['amount']);
                    }
                }
            }
        } catch (\Throwable $e) {
            log_message('warning', 'PkwtCompensationService.markPaid failed: ' . $e->getMessage());
        }

        $db->table('payroll_periods')->where('id', $payrollPeriodId)->update([
            'status'        => 'PAID',
            'finalized_at'  => date('Y-m-d H:i:s'),
            'finalized_by'  => Services::tenant()->getUserId() ?: null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        // Notify employees their payslip is ready
        $notifier = Services::notification();
        foreach ($payslips as $ps) {
            $emp = $db->table('employees')->where('id', $ps['employee_id'])->get()->getRowArray();
            if ($emp && $emp['user_id']) {
                $notifier->send(
                    (int) $emp['user_id'],
                    (int) $period['company_id'],
                    'payslip.generated',
                    'Slip gaji tersedia',
                    "Slip gaji periode {$period['name']} sudah dapat diakses.",
                    ['payslip_id' => $ps['id']],
                    "/payslips/{$ps['id']}",
                    true
                );
            }
        }
    }
}