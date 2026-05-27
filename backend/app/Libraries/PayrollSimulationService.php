<?php

namespace App\Libraries;

use Config\Services;

/**
 * PayrollSimulationService (Sprint 13 / H2.7)
 *
 * What-if payroll calculator — HR / kandidat input gaji + tunjangan +
 * konteks PTKP/BPJS, output rincian gross → BPJS → PPh21 → THP take-home.
 *
 * Use cases:
 *   1. Negosiasi gaji baru karyawan (HR mau cek THP target X juta)
 *   2. Offer letter generator: tampilkan estimasi THP ke kandidat
 *   3. Simulasi kenaikan gaji tahunan (compare current vs proposed)
 *
 * Pure in-memory — tidak persist ke `payslips`. Re-use service real:
 *   - BpjsCalculator::calculate() untuk komponen BPJS
 *   - Pph21TerCalculator::calculateMonthlyTer() untuk PPh21 bulanan TER
 *   - Pph21TerCalculator::calculateYearlyProgressive() untuk Desember /
 *     skenario gross_up.
 *
 * Output structure:
 *   [
 *     'gross_total'     => float,
 *     'taxable_gross'   => float,
 *     'bpjs' => [
 *       'kes_employee', 'kes_company',
 *       'jht_employee', 'jht_company',
 *       'jp_employee',  'jp_company',
 *       'jkk_company',  'jkm_company', 'jkp_company',
 *       'employee_total', 'company_total',
 *     ],
 *     'pph21' => ['monthly', 'category', 'rate', 'method'],
 *     'deductions_total' => float,
 *     'net_takehome'    => float,
 *     'company_cost'    => float,  (gross_total + bpjs.company_total)
 *     'inputs'          => array (input asli untuk audit/render),
 *   ]
 */
class PayrollSimulationService
{
    /**
     * Simulate untuk satu karyawan/kandidat.
     *
     * @param array $inputs {
     *   company_id:          int  (required, untuk lookup BPJS rates)
     *   basic_salary:        float  (required, > 0)
     *   allowances:          array<string,float>  (mis. ['transport'=>500000])
     *   taxable_extras:      array<string,float>  (earnings taxable selain basic+allowances)
     *   non_taxable_extras:  array<string,float>  (mis. tunjangan medis non-pajak)
     *   ptkp_status:         string  (TK0..K3, default TK0)
     *   pph21_method:        string  (TER|PROGRESSIVE, default TER)
     *   employment_status:   string  (PKWT/PKWTT/MAGANG/dll, mempengaruhi JKP)
     *   include_bpjs_kesehatan: bool (default true)
     *   include_bpjs_tk:     bool (default true) — JHT+JP+JKK+JKM+JKP
     *   year:                int  (default current year, untuk TER bracket)
     * }
     * @return array<string,mixed>
     */
    public function simulate(array $inputs): array
    {
        $companyId   = (int) ($inputs['company_id'] ?? 0);
        $basic       = (float) ($inputs['basic_salary'] ?? 0);
        $allowances  = $inputs['allowances'] ?? [];
        $taxableEx   = $inputs['taxable_extras'] ?? [];
        $nonTaxEx    = $inputs['non_taxable_extras'] ?? [];
        $ptkpStatus  = strtoupper((string) ($inputs['ptkp_status'] ?? 'TK0'));
        $pphMethod   = strtoupper((string) ($inputs['pph21_method'] ?? 'TER'));
        $empStatus   = $inputs['employment_status'] ?? null;
        $incKes      = (bool) ($inputs['include_bpjs_kesehatan'] ?? true);
        $incTk       = (bool) ($inputs['include_bpjs_tk'] ?? true);
        $year        = isset($inputs['year']) ? (int) $inputs['year'] : (int) date('Y');

        if ($basic <= 0) {
            throw new \InvalidArgumentException('basic_salary harus > 0');
        }
        if (! in_array($pphMethod, ['TER', 'PROGRESSIVE'], true)) {
            throw new \InvalidArgumentException('pph21_method harus TER atau PROGRESSIVE');
        }

        $allowanceTotal = array_sum(array_map('floatval', $allowances));
        $taxableExTotal = array_sum(array_map('floatval', $taxableEx));
        $nonTaxExTotal  = array_sum(array_map('floatval', $nonTaxEx));

        $grossTotal   = $basic + $allowanceTotal + $taxableExTotal + $nonTaxExTotal;
        $taxableGross = $basic + $allowanceTotal + $taxableExTotal; // exclude non-taxable

        // ─── BPJS ────────────────────────────────────────────────────
        $bpjsCalc = Services::bpjs();
        // BPJS calculator pakai base = basic + allowance untuk kalkulasi.
        // Untuk simulasi yang lebih akurat, gunakan taxable gross sbg base.
        $bpjsBase = $taxableGross;
        $bpjsRaw = $bpjsCalc->calculate($companyId, $basic, $bpjsBase - $basic, $empStatus);

        // Filter komponen yang tidak diaktifkan
        if (! $incKes) {
            $bpjsRaw['kes_employee'] = 0.0;
            $bpjsRaw['kes_company']  = 0.0;
        }
        if (! $incTk) {
            foreach (['jht_employee', 'jht_company', 'jp_employee', 'jp_company',
                'jkk_company', 'jkm_company', 'jkp_company'] as $k) {
                $bpjsRaw[$k] = 0.0;
            }
        }
        $bpjsEmpTotal = (float) $bpjsRaw['kes_employee']
            + (float) $bpjsRaw['jht_employee']
            + (float) $bpjsRaw['jp_employee'];
        $bpjsCoTotal  = (float) $bpjsRaw['kes_company']
            + (float) $bpjsRaw['jht_company']
            + (float) $bpjsRaw['jp_company']
            + (float) $bpjsRaw['jkk_company']
            + (float) $bpjsRaw['jkm_company']
            + (float) $bpjsRaw['jkp_company'];
        $bpjsRaw['employee_total'] = round($bpjsEmpTotal, 2);
        $bpjsRaw['company_total']  = round($bpjsCoTotal, 2);

        // ─── PPh21 ───────────────────────────────────────────────────
        $pphCalc = Services::pph21();
        $pphMonthly = 0.0;
        $pphRate    = null;
        $pphCategory= null;
        $pphYearly  = null;

        if ($pphMethod === 'TER') {
            // TER pakai gross taxable (BPJS employee tidak dikurang di TER per
            // PMK 168/2023 — TER = tarif efektif × gross bruto bulanan)
            $res = $pphCalc->calculateMonthlyTer($taxableGross, $ptkpStatus, $year);
            $pphMonthly  = (float) $res['tax'];
            $pphRate     = (float) $res['rate'];
            $pphCategory = (string) $res['category'];
        } else {
            // PROGRESSIVE — annualize dulu lalu /12 untuk indikasi monthly
            $bpjsYearly = ($bpjsRaw['jht_employee'] + $bpjsRaw['jp_employee']) * 12;
            $res = $pphCalc->calculateYearlyProgressive($taxableGross * 12, $ptkpStatus, $year, $bpjsYearly);
            $pphYearly  = $res;
            $pphMonthly = round((float) $res['tax'] / 12);
        }

        $deductionsTotal = $bpjsEmpTotal + $pphMonthly;
        $netTakehome     = $grossTotal - $deductionsTotal;
        $companyCost     = $grossTotal + $bpjsCoTotal;

        return [
            'gross_total'      => round($grossTotal, 2),
            'taxable_gross'    => round($taxableGross, 2),
            'non_taxable'      => round($nonTaxExTotal, 2),
            'bpjs'             => $bpjsRaw,
            'pph21'            => [
                'monthly'  => round($pphMonthly, 2),
                'method'   => $pphMethod,
                'rate'     => $pphRate,
                'category' => $pphCategory,
                'yearly'   => $pphYearly, // null kalau TER
            ],
            'deductions_total' => round($deductionsTotal, 2),
            'net_takehome'     => round($netTakehome, 2),
            'company_cost'     => round($companyCost, 2),
            'inputs'           => $inputs,
        ];
    }

    /**
     * Bandingkan dua skenario (current vs proposed). Berguna untuk
     * use case kenaikan gaji / promosi.
     *
     * @return array{current: array, proposed: array, delta: array}
     */
    public function compare(array $currentInputs, array $proposedInputs): array
    {
        $current  = $this->simulate($currentInputs);
        $proposed = $this->simulate($proposedInputs);
        return [
            'current'  => $current,
            'proposed' => $proposed,
            'delta'    => [
                'gross_total'    => round($proposed['gross_total'] - $current['gross_total'], 2),
                'pph21_monthly'  => round($proposed['pph21']['monthly'] - $current['pph21']['monthly'], 2),
                'bpjs_employee'  => round($proposed['bpjs']['employee_total'] - $current['bpjs']['employee_total'], 2),
                'bpjs_company'   => round($proposed['bpjs']['company_total'] - $current['bpjs']['company_total'], 2),
                'net_takehome'   => round($proposed['net_takehome'] - $current['net_takehome'], 2),
                'company_cost'   => round($proposed['company_cost'] - $current['company_cost'], 2),
            ],
        ];
    }

    /**
     * Reverse calculation: dari target THP, estimasi basic_salary yang
     * diperlukan via bisection. Output dengan struktur sama seperti
     * simulate() supaya UI bisa langsung tampil.
     *
     * @param float $targetNet  Target net take-home / bulan (Rp)
     * @param array $baseInputs Template input (tanpa basic_salary)
     * @param float $tolerance  Akurasi rupiah (default Rp 1.000)
     * @param int   $maxIter    Max iterasi (default 30)
     */
    public function reverseFromNet(
        float $targetNet,
        array $baseInputs,
        float $tolerance = 1000,
        int $maxIter = 30,
    ): array {
        if ($targetNet <= 0) {
            throw new \InvalidArgumentException('targetNet harus > 0');
        }
        // Bracket inisial
        $low  = $targetNet;        // basic minimum kira-kira = net (kalau pajak 0)
        $high = $targetNet * 2.5;  // upper bound generous

        $best = null;
        for ($i = 0; $i < $maxIter; $i++) {
            $mid = ($low + $high) / 2;
            $inputs = $baseInputs;
            $inputs['basic_salary'] = $mid;
            $sim = $this->simulate($inputs);
            $diff = $sim['net_takehome'] - $targetNet;
            $best = $sim;
            if (abs($diff) <= $tolerance) break;
            if ($diff > 0) $high = $mid;
            else $low = $mid;
        }
        return $best ?? [];
    }
}