<?php

declare(strict_types=1);

namespace App\Libraries\Payroll;

use App\Libraries\Attendance\OvertimeCalculator;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class PayrollEmployeeCalculationService
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $connection = null)
    {
        $this->db = $connection ?? Database::connect();
    }

public function calculate(
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
        array $adjustments = [],
        array $oneTimeComponents = [],
    ): array {
        $db = $this->db;
        $companyId = (int) $period['company_id'];

        // ─── Build earnings ───────────────────────────────────────────
        $earnings = [];
        $deductions = [];

        $monthlyBasicSalary = (float) $emp['basic_salary'];
        $basicSalary = PayrollService::prorateBasicSalary(
            $monthlyBasicSalary,
            (string) $emp['join_date'],
            isset($emp['resign_date']) ? (string) $emp['resign_date'] : null,
            (string) $period['start_date'],
            (string) $period['end_date'],
        );
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
            ->where('epc.company_id', $companyId)
            ->where('c.company_id', $companyId)
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
            Services::allowance()->recomputeEmployee((int) $emp['id'], $companyId);
        } catch (\Throwable $e) {
            // Jangan gagalkan payroll hanya karena recompute error.
            log_message('warning', 'AllowanceService recompute failed: ' . $e->getMessage());
        }
        $allowanceEarnings = Services::allowance()->earningsForPayroll((int) $emp['id'], $companyId);
        foreach ($allowanceEarnings as $ae) {
            $earnings[] = $ae;
        }

        // ─── Attendance summary ───────────────────────────────────────
        $attRows = $db->table('attendances')
            ->where('company_id', $companyId)
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

        $unpaidLeaves = $db->table('leaves l')
            ->select('l.start_date, l.end_date, l.days, l.is_half_day')
            ->join('leave_types lt', 'lt.id = l.leave_type_id')
            ->where('l.company_id', $companyId)
            ->where('lt.company_id', $companyId)
            ->where('l.employee_id', $emp['id'])
            ->where('l.status', 'APPROVED')
            ->where('lt.is_paid', 0)
            ->where('l.start_date <=', $period['end_date'])
            ->where('l.end_date >=', $period['start_date'])
            ->get()->getResultArray();
        $unpaidLeaveDays = PayrollService::unpaidLeaveDaysInPeriod(
            $unpaidLeaves,
            (string) $period['start_date'],
            (string) $period['end_date'],
        );

        // ─── Overtime ────────────────────────────────────────────────
        $otRows = $db->table('overtimes')
            ->where('company_id', $companyId)
            ->where('employee_id', $emp['id'])
            ->whereIn('status', ['APPROVED', 'REALIZED'])
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
                $monthlyBasicSalary + $fixedAllowance,
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
            ->where('company_id', $companyId)
            ->where('employee_id', $emp['id'])
            ->where('status', 'APPROVED')
            ->where('expense_date <=', $period['end_date'])
            ->where('paid_in_payroll_period_id IS NULL')
            ->get()
            ->getResultArray();

        $reimbTotal = PayrollService::approvedReimbursementTotal($reimbs);
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

        // Approved corrections are posted into this later period. The
        // original finalized payslip remains untouched and auditable.
        foreach ($adjustments as $adjustment) {
            $entry = [
                'code' => 'ADJ-' . (int) $adjustment['id'],
                'name' => 'Koreksi Payroll #' . (int) $adjustment['original_period_id'],
                'category' => 'ADJUSTMENT',
                'amount' => (float) $adjustment['amount'],
                'is_taxable' => (int) $adjustment['is_taxable'],
            ];
            if ($adjustment['adjustment_type'] === 'EARNING') {
                $earnings[] = $entry;
            } else {
                $entry['is_taxable'] = 0;
                $deductions[] = $entry;
            }
        }

        foreach ($oneTimeComponents as $component) {
            $entry = [
                'code' => (string) $component['code'],
                'name' => (string) $component['name'],
                'category' => (string) $component['category'],
                'amount' => (float) $component['amount'],
                'is_taxable' => (int) $component['is_taxable'],
            ];
            if ($component['component_type'] === 'EARNING') {
                $earnings[] = $entry;
            } else {
                $entry['is_taxable'] = 0;
                $deductions[] = $entry;
            }
        }

        // ─── Absent deduction (proportional) ────────────────────────
        // Sprint 5-6 — workingDaysPerMonth dari setting per-company,
        // default 22. Bisa di-disable lewat setting `payroll.absent_deduction_enabled=0`.
        $absentDeduction = 0;
        if ($absentDeductionEnabled && $absent > 0 && $workDays > 0) {
            $perDay = $monthlyBasicSalary / max(1, $workingDaysPerMonth);
            $absentDeduction = round($perDay * $absent);
            $deductions[] = ['code' => 'ABSENT', 'name' => 'Potongan Absen', 'category' => 'OTHER', 'amount' => $absentDeduction, 'is_taxable' => 0];
        }
        $unpaidLeaveDeduction = 0.0;
        if ($absentDeductionEnabled && $unpaidLeaveDays > 0) {
            $unpaidLeaveDeduction = PayrollService::unpaidLeaveDeduction(
                $monthlyBasicSalary,
                $workingDaysPerMonth,
                $unpaidLeaveDays,
            );
            $deductions[] = [
                'code' => 'UNPAID_LEAVE',
                'name' => 'Potongan Cuti Tidak Dibayar',
                'category' => 'OTHER',
                'amount' => $unpaidLeaveDeduction,
                'is_taxable' => 0,
            ];
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
        $bpjs = $bpjsCalc->calculate($companyId, $monthlyBasicSalary, $fixedAllowance);
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
                ->where('ps.company_id', $companyId)
                ->where('pp.company_id', $companyId)
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
            ->where('company_id', $companyId)
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
            'absent_deduction'   => $absentDeduction + $unpaidLeaveDeduction,
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

        if ($db->tableExists('payroll_one_time_components')) {
            $resignationSources = $db->table('payroll_one_time_components')
                ->select('source_id')->where('company_id', $companyId)
                ->where('payroll_period_id', (int) $period['id'])
                ->where('employee_id', (int) $emp['id'])
                ->where('source_type', 'RESIGNATION')->groupBy('source_id')
                ->get()->getResultArray();
            foreach ($resignationSources as $source) {
                $db->table('resignation_requests')->where('id', (int) $source['source_id'])
                    ->where('company_id', $companyId)->update([
                        'final_settlement_payslip_id' => $payslipId,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }
        }

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
            $row = [
                'payslip_id' => $payslipId,
                'code'       => $d['code'],
                'name'       => $d['name'],
                'type'       => 'DEDUCTION',
                'category'   => $d['category'],
                'amount'     => $d['amount'],
                'is_taxable' => 0,
                'sort_order' => $sort++,
            ];
            if ($payslipDetailsHasNatura) {
                $row['is_natura'] = 0;
            }
            $details[] = $row;
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
}
