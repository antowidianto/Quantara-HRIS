<?php

namespace Tests\Unit;

use App\Libraries\Pph21TerCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 20+ — Test untuk Pph21TerCalculator::iterateGrossUp dengan
 * yearly progressive tax model (untuk THR / bonus tahunan).
 *
 * Karena `calculateYearlyProgressive` bergantung ke DB (ptkp_rates +
 * pph21_brackets), test ini langsung pakai `iterateGrossUp` dengan
 * mock taxFunc yang implement Pasal 17 UU PPh Indonesia 2025:
 *   - 0–60jt:    5%
 *   - 60–250jt:  15%
 *   - 250–500jt: 25%
 *   - 500jt–5M:  30%
 *   - >5M:       35%
 *
 * Plus PTKP TK0 (54jt/tahun) + biaya jabatan 5% (max 6jt) supaya
 * realistis untuk skenario THR.
 */
final class Pph21YearlyGrossUpTest extends TestCase
{
    /**
     * Mock yearly progressive sesuai Pasal 17 + biaya jabatan + PTKP TK0.
     */
    private function yearlyTaxFunc(): callable
    {
        return function (float $grossYearly): float {
            // Biaya jabatan 5% max 6jt
            $biayaJabatan = min(0.05 * $grossYearly, 6_000_000);
            // PTKP TK0 = 54jt
            $ptkp = 54_000_000;
            $netIncome = $grossYearly - $biayaJabatan;
            $pkp = max(0, $netIncome - $ptkp);
            $pkp = floor($pkp / 1000) * 1000;

            // Progressive brackets
            $brackets = [
                [0,           60_000_000,    0.05],
                [60_000_000,  250_000_000,   0.15],
                [250_000_000, 500_000_000,   0.25],
                [500_000_000, 5_000_000_000, 0.30],
                [5_000_000_000, INF,         0.35],
            ];

            $tax = 0.0;
            foreach ($brackets as [$min, $max, $rate]) {
                if ($pkp <= $min) break;
                $applicable = min($pkp, $max) - $min;
                if ($applicable > 0) $tax += $applicable * $rate;
            }

            return round($tax);
        };
    }

    public function testThrGrossUpInLow5PercentBracket(): void
    {
        // Karyawan PKP rendah (gross yearly 100jt → PKP ≈ 41jt → bracket 5%)
        // HR mau bayar THR net 5jt
        $taxFunc = $this->yearlyTaxFunc();

        $result = Pph21TerCalculator::iterateGrossUp(
            targetNet: 5_000_000,
            taxFunc: $taxFunc,
            baseGross: 100_000_000,
        );

        $this->assertTrue($result['converged']);
        // Effective marginal rate: 5% × (1 - 0.05 biaya jabatan) = 4.75%
        // Gross-up ≈ 5jt / 0.9525 ≈ 5_249_343
        $this->assertEqualsWithDelta(5_249_343, $result['gross_up_amount'], 5_000);
        // Tax added: gross_up × 4.75% ≈ 249k
        $this->assertGreaterThan(240_000, $result['tax_added']);
        $this->assertLessThan(260_000, $result['tax_added']);
    }

    public function testThrGrossUpInMid15PercentBracket(): void
    {
        // Karyawan gaji menengah (gross yearly 200jt → PKP ≈ 140jt → bracket 15%)
        // HR mau bayar THR net 15jt
        $taxFunc = $this->yearlyTaxFunc();

        $result = Pph21TerCalculator::iterateGrossUp(
            targetNet: 15_000_000,
            taxFunc: $taxFunc,
            baseGross: 200_000_000,
        );

        $this->assertTrue($result['converged']);
        // Effective marginal rate: 15% × 0.95 = 14.25%
        // Gross-up ≈ 15jt / 0.8575 ≈ 17_492_711
        // Verifikasi mathematical correctness instead of exact value
        $tax = $taxFunc($result['gross_up_amount'] + 200_000_000);
        $baseTax = $taxFunc(200_000_000);
        $netActual = $result['gross_up_amount'] - ($tax - $baseTax);
        $this->assertEqualsWithDelta(15_000_000, $netActual, 1.0);
    }

    public function testThrGrossUpHighEarner30PercentBracket(): void
    {
        // Karyawan high earner (gross yearly 1 milyar → PKP > 500jt → bracket 30%)
        // HR mau bayar THR net 50jt
        $taxFunc = $this->yearlyTaxFunc();

        $result = Pph21TerCalculator::iterateGrossUp(
            targetNet: 50_000_000,
            taxFunc: $taxFunc,
            baseGross: 1_000_000_000,
        );

        $this->assertTrue($result['converged']);
        // Verifikasi mathematical correctness — gross-up - tax_added ≈ targetNet
        $tax = $taxFunc($result['gross_up_amount'] + 1_000_000_000);
        $baseTax = $taxFunc(1_000_000_000);
        $netActual = $result['gross_up_amount'] - ($tax - $baseTax);
        $this->assertEqualsWithDelta(50_000_000, $netActual, 1.0);
        // Gross-up harus signifikan lebih tinggi dari net (employer tanggung tax)
        $this->assertGreaterThan(50_000_000 * 1.3, $result['gross_up_amount']);
    }

    public function testThrCrossingBracketMid15ToHigh25(): void
    {
        // Skenario tricky: karyawan dengan gross 240jt (PKP ≈ 180jt → 15% bracket)
        // dapat THR besar yang push PKP melewati 250jt (kena bracket 25%)
        $taxFunc = $this->yearlyTaxFunc();

        $result = Pph21TerCalculator::iterateGrossUp(
            targetNet: 100_000_000,
            taxFunc: $taxFunc,
            baseGross: 240_000_000,
        );

        $this->assertTrue($result['converged']);
        // Bonus push effective bracket → marginal mix 15% & 25%
        // Verifikasi correctness: net actual ≈ targetNet
        $tax = $taxFunc($result['gross_up_amount'] + 240_000_000);
        $baseTax = $taxFunc(240_000_000);
        $netActual = $result['gross_up_amount'] - ($tax - $baseTax);
        $this->assertEqualsWithDelta(100_000_000, $netActual, 1.0);
    }

    public function testZeroGrossYearlyBaseStillWorks(): void
    {
        // Karyawan baru tanpa gross sebelumnya, dapat bonus net 50jt
        // PKP setelah PTKP & biaya jabatan ≈ 50jt → bracket 5%
        $taxFunc = $this->yearlyTaxFunc();

        $result = Pph21TerCalculator::iterateGrossUp(
            targetNet: 50_000_000,
            taxFunc: $taxFunc,
            baseGross: 0,
        );

        $this->assertTrue($result['converged']);
        // Net 50jt + tax ≈ rendah karena PTKP cover sebagian besar
        $tax = $taxFunc($result['gross_up_amount']);
        $netActual = $result['gross_up_amount'] - $tax;
        $this->assertEqualsWithDelta(50_000_000, $netActual, 1.0);
    }

    public function testYearlyProgressiveConvergesQuicklyForRealisticInput(): void
    {
        // Verifikasi konvergensi cepat (≤ 10 iterations) untuk skenario
        // realistis Indonesia. Skip kasus tepat di bracket boundary
        // (5M = batas 30%/35%) karena Newton bisa oscillate di
        // discontinuity — caller bisa tambah `maxIter` atau pakai
        // `epsilon` lebih longgar (mis. Rp 1000) untuk kasus extreme.
        $taxFunc = $this->yearlyTaxFunc();

        $cases = [
            [50_000_000, 100_000_000],   // Low bracket 5%
            [25_000_000, 240_000_000],   // Mid bracket 15%
            [100_000_000, 600_000_000],  // High bracket 30%
            [200_000_000, 1_500_000_000],// High bracket 30%, well within
        ];

        foreach ($cases as [$net, $base]) {
            $result = Pph21TerCalculator::iterateGrossUp(
                targetNet: $net,
                taxFunc: $taxFunc,
                baseGross: $base,
            );
            $this->assertTrue($result['converged'],
                "Failed converge for net={$net} base={$base}");
            $this->assertLessThanOrEqual(10, $result['iterations'],
                "Too many iterations for net={$net} base={$base}: {$result['iterations']}");
            // Verifikasi correctness regardless
            $tax = $taxFunc($result['gross_up_amount'] + $base);
            $baseTax = $taxFunc($base);
            $netActual = $result['gross_up_amount'] - ($tax - $baseTax);
            $this->assertEqualsWithDelta($net, $netActual, 1.0,
                "Net actual mismatch for net={$net} base={$base}");
        }
    }
}