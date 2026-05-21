<?php

namespace App\Libraries;

use Config\Services;

/**
 * PayrollService - Orchestrates payroll calculation per period.
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

        foreach ($employees as $emp) {
            $result = $this->calculateEmployee($emp, $period, $bpjsCalc, $pphCalc, $otCalc);
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
        ];
    }

    private function calculateEmployee(
        array $emp,
        array $period,
        BpjsCalculator $bpjsCalc,
        Pph21TerCalculator $pphCalc,
        OvertimeCalculator $otCalc
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

        // Recurring components
        $recurring = $db->table('employee_payroll_components epc')
            ->select('epc.amount, c.code, c.name, c.type, c.category, c.is_taxable')
            ->join('payroll_components c', 'c.id = epc.component_id')
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
                $earnings[] = ['code' => $r['code'], 'name' => $r['name'], 'category' => $r['category'], 'amount' => (float) $r['amount'], 'is_taxable' => (int) $r['is_taxable']];
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
            $amount = $otCalc->calculate($basicSalary + $fixedAllowance, (float) $ot['hours'], $ot['day_type']);
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

        // ─── Absent deduction (proportional) ────────────────────────
        $absentDeduction = 0;
        if ($absent > 0 && $workDays > 0) {
            $perDay = $basicSalary / 22; // standard 22 working days
            $absentDeduction = round($perDay * $absent);
            $deductions[] = ['code' => 'ABSENT', 'name' => 'Potongan Absen', 'category' => 'OTHER', 'amount' => $absentDeduction, 'is_taxable' => 0];
        }

        // ─── Compute gross taxable / non-taxable ─────────────────────
        $grossTaxable = 0;
        $grossEarning = 0;
        foreach ($earnings as $e) {
            $grossEarning += $e['amount'];
            if ($e['is_taxable']) $grossTaxable += $e['amount'];
        }

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
        $totalDeduction = 0;
        foreach ($deductions as $d) $totalDeduction += $d['amount'];
        $takeHome = $grossEarning - $totalDeduction;

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

        $db->table('payslips')->insert($payslipData);
        $payslipId = $db->insertID();

        // Insert details
        $sort = 0;
        $details = [];
        foreach ($earnings as $e) {
            $details[] = [
                'payslip_id' => $payslipId,
                'code'       => $e['code'],
                'name'       => $e['name'],
                'type'       => 'EARNING',
                'category'   => $e['category'],
                'amount'     => $e['amount'],
                'is_taxable' => $e['is_taxable'],
                'sort_order' => $sort++,
            ];
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
                $remainingDeduction -= $apply;
            }
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