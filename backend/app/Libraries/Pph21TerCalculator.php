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