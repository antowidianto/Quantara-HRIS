<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * PayrollController — for actions not tied to a single period (e.g. THR generation).
 *
 * THR (Tunjangan Hari Raya) sesuai PMK 6/2016 & Permenaker 6/2016:
 *   - Masa kerja ≥ 12 bulan : 1 bulan upah penuh
 *   - Masa kerja 1–11 bulan : proporsional = (bulan_kerja / 12) × upah
 *   - Masa kerja < 1 bulan  : tidak berhak THR
 *
 * Sprint 4 fix:
 *   - `tenureMonths` dihitung sampai `pay_date` (bukan time()) supaya
 *     re-generate THR tidak mengubah hasil sebelumnya.
 *   - Karyawan resign mid-year DI-include kalau resign_date setelah
 *     awal periode THR (Jan 1) — tenure dihitung dari join_date sampai
 *     min(resign_date, pay_date). Sebelumnya: pakai `is_active=1` saja
 *     yang otomatis exclude resign meski masih berhak THR.
 *
 * Lihat docs/IMPROVEMENT_CHECKLIST.md item 4 (Payroll Engine).
 */
class PayrollController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    /**
     * Hitung total masa kerja (bulan) sampai tanggal pay_date THR.
     * Public + static supaya bisa di-unit-test.
     *
     * Aturan eligibility (PMK 6/2016 & Permenaker 6/2016):
     *  - Karyawan yang resign sebelum awal periode THR (default: 1 Jan
     *    tahun pay_date) tidak berhak → return 0.
     *  - Karyawan yang masih aktif atau resign DI dalam periode THR
     *    berhak proporsional. Tenure dihitung dari `join_date` sampai
     *    `min(pay_date, resign_date)` — total kumulatif, tidak di-cap.
     *
     * Kaller (`computeThrAmount`) yang putuskan: tenure ≥ 12 → 1 bulan
     * upah penuh; tenure 1..11 → proporsional; tenure < 1 → 0.
     *
     * @param string      $joinDate    YYYY-MM-DD
     * @param string|null $resignDate  YYYY-MM-DD; null = masih aktif
     * @param string      $payDate     YYYY-MM-DD tanggal bayar THR
     * @param string|null $periodStart YYYY-MM-DD; cutoff eligibility resign
     *                                 (default: 1 Jan tahun pay_date)
     * @return int Total masa kerja dalam bulan (0..N), 0 = tidak berhak
     */
    public static function computeTenureMonths(
        string $joinDate,
        ?string $resignDate,
        string $payDate,
        ?string $periodStart = null,
    ): int {
        $payTs = strtotime($payDate);
        if ($payTs === false) return 0;

        $startTs = $periodStart
            ? strtotime($periodStart)
            : strtotime(date('Y-01-01', $payTs));
        $joinTs = strtotime($joinDate);
        if ($joinTs === false) return 0;

        // Karyawan join setelah pay_date → tidak berhak
        if ($joinTs > $payTs) return 0;

        // Eligibility: resign sebelum awal periode THR → tidak berhak
        $effectiveEnd = $payTs;
        if ($resignDate) {
            $resignTs = strtotime($resignDate);
            if ($resignTs !== false) {
                if ($resignTs < $startTs) return 0;
                $effectiveEnd = min($payTs, $resignTs);
            }
        }
        if ($effectiveEnd < $joinTs) return 0;

        // Total masa kerja kumulatif (1 bulan = 30 hari approximation)
        $months = (int) floor(($effectiveEnd - $joinTs) / 86400 / 30);
        return max(0, $months);
    }

    /**
     * Hitung nominal THR proporsional.
     * - tenure ≥ 12 bulan → 1 bulan penuh
     * - tenure 1..11    → tenure/12 × upah
     * - tenure < 1      → 0
     */
    public static function computeThrAmount(float $monthlySalary, int $tenureMonths): float
    {
        if ($tenureMonths < 1 || $monthlySalary <= 0) return 0.0;
        if ($tenureMonths >= 12) return round($monthlySalary);
        return round($monthlySalary * ($tenureMonths / 12));
    }

    /**
     * Generate THR period for the company.
     */
    public function generateThr()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'pay_date' => 'required|valid_date',
            'name'     => 'required|max_length[100]',
        ]);
        if (! $valid) return null;

        $companyId = $this->companyId();
        $payTs = strtotime($data['pay_date']);

        // Sprint 22+ — opsi tax treatment THR:
        //   - GROSS (default, backward-compatible behavior lama)
        //   - NET   (employer absorb pajak via yearly progressive gross-up)
        $taxTreatment = strtoupper((string) ($data['tax_treatment'] ?? 'GROSS'));
        if (! in_array($taxTreatment, ['GROSS', 'NET'], true)) {
            return $this->fail('tax_treatment harus GROSS atau NET', 422);
        }
        $year  = (int) date('Y', $payTs);
        $code  = 'THR-' . $year;
        $startDate = date('Y-01-01', $payTs);
        $endDate   = $data['pay_date'];

        $db = \Config\Database::connect();
        $existing = $db->table('payroll_periods')->where('company_id', $companyId)->where('code', $code)->countAllResults();
        if ($existing > 0) {
            return $this->fail('THR period for this year already exists', 409);
        }

        $now = date('Y-m-d H:i:s');
        $db->table('payroll_periods')->insert([
            'company_id'   => $companyId,
            'code'         => $code,
            'name'         => $data['name'],
            'type'         => 'THR',
            'period_year'  => $year,
            'start_date'   => $startDate,
            'end_date'     => $endDate,
            'pay_date'     => $data['pay_date'],
            'cutoff_attendance_date' => $endDate,
            'status'       => 'DRAFT',
            'created_by'   => $this->userId(),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $periodId = (int) $db->insertID();

        // Sprint 4 — include karyawan yang resign mid-year selama
        // resign_date ≥ start_date THR; mereka tetap berhak THR proporsional.
        $employees = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->where('join_date <=', $endDate)
            ->groupStart()
                ->where('resign_date IS NULL')
                ->orWhere('resign_date >=', $startDate)
            ->groupEnd()
            ->get()->getResultArray();

        $totalGross = 0;
        $totalNet = 0;
        $totalDeduction = 0;
        $countIncluded = 0;
        foreach ($employees as $emp) {
            $tenureMonths = self::computeTenureMonths(
                (string) $emp['join_date'],
                ! empty($emp['resign_date']) ? (string) $emp['resign_date'] : null,
                $data['pay_date'],
                $startDate,
            );
            if ($tenureMonths < 1) continue;

            $monthly = (float) $emp['basic_salary'];
            $thrNetTarget = self::computeThrAmount($monthly, $tenureMonths);
            if ($thrNetTarget <= 0) continue;

            $ptkp = (string) ($emp['ptkp_status'] ?: 'TK0');
            $baseGrossYearly = $monthly * 12.0;

            $thrGross = $thrNetTarget;
            $thrPph = 0.0;
            $thrGrossUp = 0.0;
            if ($taxTreatment === 'NET') {
                $grossUp = Services::pph21()->grossUpYearlyFromNet(
                    $thrNetTarget,
                    $baseGrossYearly,
                    $ptkp,
                    $year,
                    0.0, // BPJS yearly THR scope belum dipipe (defer)
                );
                $thrGross = (float) $grossUp['gross_up_amount'];
                $thrPph = (float) $grossUp['tax_added'];
                $thrGrossUp = max(0.0, $thrGross - $thrNetTarget);
            }

            // Backward-compatible: mode GROSS mengikuti behavior lama (tidak
            // potong pajak terpisah pada slip THR). Mode NET set take_home ke
            // target net (employer absorb tax).
            $takeHome = $taxTreatment === 'NET'
                ? $thrNetTarget
                : $thrGross;

            $slipNumber = 'THR-' . $year . '-' . str_pad((string) $emp['id'], 5, '0', STR_PAD_LEFT);
            $db->table('payslips')->insert([
                'company_id'        => $companyId,
                'payroll_period_id' => $periodId,
                'employee_id'       => $emp['id'],
                'slip_number'       => $slipNumber,
                'employee_number'   => $emp['employee_number'],
                'employee_name'     => $emp['full_name'],
                'ptkp_status'       => $ptkp,
                'thr_amount'        => $thrGross,
                'gross_earning'     => $thrGross,
                'total_earning'     => $thrGross,
                'pph21_amount'      => $thrPph,
                'total_deduction'   => $thrPph,
                'take_home_pay'     => $takeHome,
                'pph21_method'      => 'TER',
                'breakdown'         => json_encode([
                    'tenure_months' => $tenureMonths,
                    'monthly_base'  => $monthly,
                    'thr_net_target'=> $thrNetTarget,
                    'thr_gross'     => $thrGross,
                    'thr_pph'       => $thrPph,
                    'thr_gross_up'  => $thrGrossUp,
                    'tax_treatment' => $taxTreatment,
                    'resign_date'   => $emp['resign_date'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $totalGross += $thrGross;
            $totalDeduction += $thrPph;
            $totalNet += $takeHome;
            $countIncluded++;
        }

        $db->table('payroll_periods')->where('id', $periodId)->update([
            'status'          => 'CALCULATED',
            'total_gross'     => $totalGross,
            'total_deduction' => $totalDeduction,
            'total_net'       => $totalNet,
        ]);

        Services::audit()->forModule('PAYROLL')->log(
            action: 'payroll.thr_generate',
            entity: 'payroll_period',
            entityId: $periodId,
            description: "Generate THR {$year}: {$countIncluded} karyawan, gross Rp "
                . number_format($totalGross, 0, ',', '.')
                . ", net Rp " . number_format($totalNet, 0, ',', '.')
                . " ({$taxTreatment})",
            severity: 'NOTICE',
        );

        // Sprint 15+ — webhook outbound `thr.generated`
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::THR_GENERATED,
            (int) $companyId,
            [
                'payroll_period_id' => $periodId,
                'name'              => $data['name'],
                'period_year'       => $year,
                'pay_date'          => $data['pay_date'],
                'employee_count'    => $countIncluded,
                'total_gross'       => $totalGross,
                'total_deduction'   => $totalDeduction,
                'total_net'         => $totalNet,
                'tax_treatment'     => $taxTreatment,
                'generated_by'      => $this->userId(),
                'generated_at'      => date('c'),
            ],
        );

        return $this->ok([
            'period_id'       => $periodId,
            'total_gross'     => $totalGross,
            'total_deduction' => $totalDeduction,
            'total_net'       => $totalNet,
            'tax_treatment'   => $taxTreatment,
            'count'           => $countIncluded,
        ], 'THR generated', 201);
    }

    /**
     * GET /thr/projection?year=YYYY&pay_date=YYYY-MM-DD (Sprint 9 / H1.8).
     *
     * Projeksi total biaya THR sebelum di-generate. Tidak menulis ke DB —
     * hanya hitung tenure_months × monthly_salary tiap karyawan aktif (atau
     * resign mid-year) lalu agregasi per department / cost center.
     *
     * Query params:
     *   - year (required): tahun THR (default: tahun berjalan)
     *   - pay_date (optional): tanggal bayar simulasi, default H-7 sebelum
     *     Idul Fitri tahun ybs. Karena tabel hari libur belum tentu meng-cover
     *     Idul Fitri secara akurat, default sederhana = 1 April tahun ybs.
     *
     * Output:
     *   {
     *     year, pay_date, count, total_gross,
     *     by_department: [{id, name, count, total}],
     *     by_cost_center: [{id, name, total}],
     *     top_employees: [{employee_number, full_name, tenure_months, amount}],
     *   }
     */
    public function thrProjection()
    {
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);

        $year = (int) ($this->request->getGet('year') ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            return $this->fail('Tahun tidak valid', 422);
        }
        $payDate = (string) ($this->request->getGet('pay_date') ?? "{$year}-04-01");
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
            return $this->fail('Format pay_date: YYYY-MM-DD', 422);
        }
        $startDate = "{$year}-01-01";

        $db = \Config\Database::connect();
        $employees = $db->table('employees e')
            ->select('e.id, e.employee_number, e.full_name, e.basic_salary,'
                . ' e.join_date, e.resign_date, e.department_id, e.cost_center_id,'
                . ' d.name AS department_name, cc.name AS cost_center_name')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('cost_centers cc', 'cc.id = e.cost_center_id', 'left')
            ->where('e.company_id', $companyId)
            ->where('e.deleted_at IS NULL')
            ->where('e.join_date <=', $payDate)
            ->groupStart()
                ->where('e.resign_date IS NULL')
                ->orWhere('e.resign_date >=', $startDate)
            ->groupEnd()
            ->get()->getResultArray();

        $byDept = [];
        $byCc = [];
        $rowsForTop = [];
        $total = 0.0;
        $count = 0;

        foreach ($employees as $emp) {
            $tenure = self::computeTenureMonths(
                (string) $emp['join_date'],
                ! empty($emp['resign_date']) ? (string) $emp['resign_date'] : null,
                $payDate,
                $startDate
            );
            if ($tenure < 1) continue;
            $monthly = (float) ($emp['basic_salary'] ?? 0);
            $amount = self::computeThrAmount($monthly, $tenure);
            if ($amount <= 0) continue;

            $count++;
            $total += $amount;
            $rowsForTop[] = [
                'employee_id'     => (int) $emp['id'],
                'employee_number' => $emp['employee_number'],
                'full_name'       => $emp['full_name'],
                'tenure_months'   => $tenure,
                'amount'          => $amount,
            ];

            $deptKey = (int) ($emp['department_id'] ?? 0);
            if (! isset($byDept[$deptKey])) {
                $byDept[$deptKey] = [
                    'id'    => $deptKey ?: null,
                    'name'  => $emp['department_name'] ?? '— Tanpa Department —',
                    'count' => 0,
                    'total' => 0.0,
                ];
            }
            $byDept[$deptKey]['count']++;
            $byDept[$deptKey]['total'] += $amount;

            $ccKey = (int) ($emp['cost_center_id'] ?? 0);
            if (! isset($byCc[$ccKey])) {
                $byCc[$ccKey] = [
                    'id'    => $ccKey ?: null,
                    'name'  => $emp['cost_center_name'] ?? '— Tanpa Cost Center —',
                    'count' => 0,
                    'total' => 0.0,
                ];
            }
            $byCc[$ccKey]['count']++;
            $byCc[$ccKey]['total'] += $amount;
        }

        // Sort top karyawan by amount DESC, ambil top 10
        usort($rowsForTop, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $top = array_slice($rowsForTop, 0, 10);

        // Convert assoc array → indexed for JSON output
        $byDept = array_values($byDept);
        $byCc = array_values($byCc);
        usort($byDept, fn ($a, $b) => $b['total'] <=> $a['total']);
        usort($byCc, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $this->ok([
            'year'           => $year,
            'pay_date'       => $payDate,
            'period_start'   => $startDate,
            'count'          => $count,
            'total_gross'    => round($total, 2),
            'by_department'  => $byDept,
            'by_cost_center' => $byCc,
            'top_employees'  => $top,
        ]);
    }
}
