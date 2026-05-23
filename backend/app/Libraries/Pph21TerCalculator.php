<?php

namespace App\Libraries;

/**
 * PPh21 Calculator using TER (Tarif Efektif Rata-rata) per PMK 168/2023.
 *
 * Cara penggunaan TER:
 * - Bulan Januari–November: PPh21 = TER * Penghasilan Bruto Bulanan
 * - Bulan Desember: PPh21 dihitung dengan tarif progresif setahun
 *   dikurangi PPh21 yang sudah dipotong Jan-Nov
 *
 * Kategori TER ditentukan oleh status PTKP:
 *  - A: TK/0, TK/1, K/0
 *  - B: TK/2, TK/3, K/1, K/2
 *  - C: K/3
 */
class Pph21TerCalculator
{
    /**
     * Determine TER category from PTKP status code.
     */
    public function categoryFromPtkp(string $ptkp): string
    {
        $ptkp = strtoupper($ptkp);
        if (in_array($ptkp, ['TK0', 'TK1', 'K0'], true)) return 'A';
        if (in_array($ptkp, ['TK2', 'TK3', 'K1', 'K2'], true)) return 'B';
        if ($ptkp === 'K3') return 'C';
        // HB (suami-istri digabung) — fall back to A as conservative default
        return 'A';
    }

    /**
     * Calculate monthly PPh21 using TER (for Jan-Nov).
     *
     * @return array{tax: float, rate: float, category: string}
     */
    public function calculateMonthlyTer(float $grossMonthly, string $ptkp, ?int $year = null): array
    {
        $year = $year ?? (int) date('Y');
        $category = $this->categoryFromPtkp($ptkp);

        $db = \Config\Database::connect();
        $row = $db->table('ter_rates')
            ->where('category', $category)
            ->where('effective_year <=', $year)
            ->where('min_income <=', $grossMonthly)
            ->groupStart()
                ->where('max_income >', $grossMonthly)
                ->orWhere('max_income IS NULL')
            ->groupEnd()
            ->orderBy('effective_year', 'DESC')
            ->orderBy('min_income', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $rate = $row ? (float) $row['rate'] : 0.0;
        $tax  = round($grossMonthly * $rate);

        return [
            'tax'      => $tax,
            'rate'     => $rate,
            'category' => $category,
        ];
    }

    /**
     * Calculate yearly PPh21 using progressive brackets (for Desember reconciliation).
     *
     * @return array{tax: float, taxable_income: float, ptkp_amount: float}
     */
    public function calculateYearlyProgressive(float $grossYearly, string $ptkp, ?int $year = null, float $bpjsDeductionYearly = 0): array
    {
        $year = $year ?? (int) date('Y');
        $db = \Config\Database::connect();

        // Biaya jabatan: 5% gross max 6jt/tahun
        $biayaJabatan = min(0.05 * $grossYearly, 6000000);

        // Net income = gross - biaya jabatan - JHT employee - JP employee
        $netIncome = $grossYearly - $biayaJabatan - $bpjsDeductionYearly;

        // PTKP
        $ptkpRow = $db->table('ptkp_rates')
            ->where('code', strtoupper($ptkp))
            ->where('effective_year <=', $year)
            ->orderBy('effective_year', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $ptkpAmount = $ptkpRow ? (float) $ptkpRow['amount_yearly'] : 54000000;

        // PKP — pembulatan ke bawah ribuan
        $pkp = max(0, $netIncome - $ptkpAmount);
        $pkp = floor($pkp / 1000) * 1000;

        // Apply progressive brackets
        $brackets = $db->table('pph21_brackets')
            ->where('effective_year <=', $year)
            ->orderBy('effective_year', 'DESC')
            ->orderBy('min_income', 'ASC')
            ->get()
            ->getResultArray();

        $tax = 0.0;
        foreach ($brackets as $b) {
            $min = (float) $b['min_income'];
            $max = $b['max_income'] !== null ? (float) $b['max_income'] : INF;
            if ($pkp <= $min) break;
            $applicable = min($pkp, $max) - $min;
            if ($applicable > 0) {
                $tax += $applicable * (float) $b['rate'];
            }
        }

        return [
            'tax'            => round($tax),
            'taxable_income' => $pkp,
            'ptkp_amount'    => $ptkpAmount,
            'biaya_jabatan'  => $biayaJabatan,
            'net_income'     => $netIncome,
        ];
    }

    /**
     * Sprint 18+ — Iterative gross-up calculator (pure, testable).
     *
     * Solusi untuk skenario "karyawan dapat bonus net X bersih ke tangan":
     * cari effective gross G sehingga `G - tax(baseGross + G) + tax(baseGross) = X`.
     * Karena tax progressive (TER bracket-based), pakai fixed-point iteration.
     *
     * Konvergensi biasanya 2-3 iterasi untuk TER (bracket discrete) dan
     * 4-6 iterasi untuk progressive (smoother). Default maxIter 10 +
     * epsilon Rp1 cukup tight untuk semua skenario realistis.
     *
     * @param float    $targetNet     Net amount yang harus diterima karyawan
     * @param callable $taxFunc       (float $gross) => float $tax. Pure fungsi
     *                                 berdasar gross. Caller inject TER atau
     *                                 progressive sesuai konteks.
     * @param float    $baseGross     Gross dasar (penghasilan tanpa bonus
     *                                 gross-up ini). Iteration akan add
     *                                 effective_gross di atasnya.
     * @param int      $maxIter       Maximum iteration (default 10)
     * @param float    $epsilon       Toleransi konvergensi rupiah (default 1)
     *
     * @return array{gross_up_amount:float, tax_added:float, iterations:int, converged:bool}
     */
    public static function iterateGrossUp(
        float $targetNet,
        callable $taxFunc,
        float $baseGross = 0.0,
        int $maxIter = 10,
        float $epsilon = 1.0
    ): array {
        // Defensive — tidak ada gunanya iterate kalau target ≤ 0
        if ($targetNet <= 0) {
            return [
                'gross_up_amount' => 0.0,
                'tax_added'       => 0.0,
                'iterations'      => 0,
                'converged'       => true,
            ];
        }

        $baseTax = (float) $taxFunc($baseGross);

        // Implementation: Newton-Raphson dengan numerical differentiation.
        //
        // Solve f(G) = (G - taxAdded(G)) - targetNet = 0
        //   where taxAdded(G) = tax(baseGross + G) - tax(baseGross)
        //
        // f'(G) = 1 - tax'(baseGross + G) ≈ 1 - marginalRate
        //
        // Newton step: G_new = G - f(G) / f'(G)
        //
        // Untuk tarif TER (piecewise constant) Newton converge dalam 1-2
        // iterasi setelah berada di bracket yang benar. Untuk progressive
        // smooth juga 3-5 iterasi cukup. Maxiter default 10 amply.
        //
        // Compare ke fixed-point iteration sebelumnya: untuk 30% flat rate,
        // fixed-point butuh ~50 iterasi untuk konvergen ke Rp1; Newton
        // butuh 1 iterasi (linear function!).

        $grossUp = $targetNet; // initial guess (no tax yet)
        $taxAdded = 0.0;
        $iterations = 0;
        $converged = false;

        for ($i = 0; $i < $maxIter; $i++) {
            $iterations++;
            $totalTax = (float) $taxFunc($baseGross + $grossUp);
            $taxAdded = $totalTax - $baseTax;
            $netActual = $grossUp - $taxAdded;
            $error = $netActual - $targetNet;

            if (abs($error) < $epsilon) {
                $converged = true;
                break;
            }

            // Numerical derivative of tax(baseGross + G) wrt G:
            //   tax'(G) ≈ (tax(baseGross + G + h) - tax(baseGross + G)) / h
            // Pakai perturbation 0.01% gross atau minimum Rp1 untuk
            // stability di bracket boundary.
            $h = max(1.0, abs($grossUp) * 1e-4);
            $taxAhead = (float) $taxFunc($baseGross + $grossUp + $h);
            $marginalRate = ($taxAhead - $totalTax) / $h;
            $netDerivative = 1.0 - $marginalRate;

            // Singular case: marginal rate ≥ 1 (impossible tax) → bail out
            if (abs($netDerivative) < 1e-10) {
                break;
            }

            $grossUp = $grossUp - $error / $netDerivative;
            // Clamp ke 0 — defensive untuk pathological tax functions
            if ($grossUp < 0) $grossUp = 0.0;
        }

        return [
            'gross_up_amount' => round($grossUp, 2),
            'tax_added'       => round($taxAdded, 2),
            'iterations'      => $iterations,
            'converged'       => $converged,
        ];
    }

    /**
     * Wrapper untuk iterateGrossUp yang pakai TER monthly sebagai tax
     * function. Cocok untuk bonus regular (Jan-Nov) yang di-merge ke
     * payslip bulanan.
     *
     * @return array{gross_up_amount:float, tax_added:float, iterations:int, converged:bool}
     */
    public function grossUpMonthlyFromNet(
        float $targetNet,
        float $baseGrossMonthly,
        string $ptkp,
        ?int $year = null
    ): array {
        return self::iterateGrossUp(
            $targetNet,
            fn (float $g): float => (float) $this->calculateMonthlyTer($g, $ptkp, $year)['tax'],
            $baseGrossMonthly,
        );
    }

    /**
     * Sprint 20+ — Wrapper yearly progressive untuk gross-up.
     *
     * Berguna untuk THR / bonus tahunan yang dihitung dengan tarif
     * progresif (Pasal 17 UU PPh) saat Desember reconciliation, bukan
     * TER bulanan yang flat per bracket.
     *
     * Algoritma sama (Newton-Raphson via iterateGrossUp), tapi taxFunc
     * menjadi `calculateYearlyProgressive` yang sudah include biaya
     * jabatan + PTKP + progressive bracket.
     *
     * Contoh use case:
     *   - HR bayar THR net 25 juta — employer absorb pajak yearly
     *   - calculateYearlyProgressive yang dipanggil = (gross_yearly_excl_thr + grossup) → tax
     *   - solve gross supaya net = 25juta
     *
     * @param float $targetNet         Net amount yang harus diterima
     * @param float $baseGrossYearly   Gross yearly base (penghasilan tanpa THR/bonus ini)
     * @param string $ptkp             PTKP code (TK0/TK1/dst)
     * @param int|null $year           Tahun pajak (default current)
     * @param float $bpjsYearly        BPJS yearly (JHT+JP) deduction
     *
     * @return array{gross_up_amount:float, tax_added:float, iterations:int, converged:bool}
     */
    public function grossUpYearlyFromNet(
        float $targetNet,
        float $baseGrossYearly,
        string $ptkp,
        ?int $year = null,
        float $bpjsYearly = 0
    ): array {
        return self::iterateGrossUp(
            $targetNet,
            fn (float $g): float => (float) $this->calculateYearlyProgressive($g, $ptkp, $year, $bpjsYearly)['tax'],
            $baseGrossYearly,
        );
    }

    /**
     * Calculate December correction: yearly progressive minus already-paid TER.
     */
    public function calculateDecemberCorrection(
        float $grossYearly,
        string $ptkp,
        float $alreadyPaidTer,
        ?int $year = null,
        float $bpjsDeductionYearly = 0
    ): array {
        $yearly = $this->calculateYearlyProgressive($grossYearly, $ptkp, $year, $bpjsDeductionYearly);
        $december = max(0, $yearly['tax'] - $alreadyPaidTer);

        return [
            'december_tax'  => round($december),
            'yearly_tax'    => $yearly['tax'],
            'paid_ter'      => $alreadyPaidTer,
            'pkp'           => $yearly['taxable_income'],
            'ptkp_amount'   => $yearly['ptkp_amount'],
            'biaya_jabatan' => $yearly['biaya_jabatan'],
        ];
    }
}