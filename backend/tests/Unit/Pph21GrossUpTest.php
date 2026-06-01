<?php

namespace Tests\Unit;

use App\Libraries\Payroll\Pph21TerCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 18+ — Test untuk Pph21TerCalculator::iterateGrossUp.
 *
 * Algorithm fixed-point iteration untuk solve "karyawan dapat net X"
 * → cari gross G yang setelah dipotong pajak nett-nya = X.
 *
 * Kasus realistis:
 *   - Flat 5% TER → solusi closed-form: G = X / (1 - 0.05)
 *   - Bracket TER → iteration konvergen 2-3 step
 *   - Progressive smooth → konvergen 4-5 step
 *
 * Pure test — pakai callable mock, tidak butuh DB.
 */
final class Pph21GrossUpTest extends TestCase
{
    public function testZeroTargetReturnsZero(): void
    {
        $taxFunc = fn (float $g): float => $g * 0.05;

        $result = Pph21TerCalculator::iterateGrossUp(0, $taxFunc);

        $this->assertSame(0.0, $result['gross_up_amount']);
        $this->assertSame(0.0, $result['tax_added']);
        $this->assertSame(0, $result['iterations']);
        $this->assertTrue($result['converged']);
    }

    public function testNegativeTargetReturnsZero(): void
    {
        $taxFunc = fn (float $g): float => $g * 0.05;

        $result = Pph21TerCalculator::iterateGrossUp(-1000, $taxFunc);

        $this->assertSame(0.0, $result['gross_up_amount']);
        $this->assertTrue($result['converged']);
    }

    public function testFlatTaxRateConvergesToClosedForm(): void
    {
        // tax = 5% gross. Closed-form: gross = net / 0.95
        // For target net 9_500_000 → gross 10_000_000, tax 500_000
        $taxFunc = fn (float $g): float => $g * 0.05;

        $result = Pph21TerCalculator::iterateGrossUp(9_500_000, $taxFunc);

        $this->assertTrue($result['converged']);
        $this->assertEqualsWithDelta(10_000_000.0, $result['gross_up_amount'], 1.0);
        $this->assertEqualsWithDelta(500_000.0, $result['tax_added'], 1.0);
        $this->assertLessThanOrEqual(10, $result['iterations']);
    }

    public function testHigherFlatRateNeedsMoreGross(): void
    {
        // tax = 30% gross → gross = net / 0.7
        // For target 7_000_000 → gross 10_000_000, tax 3_000_000
        $taxFunc = fn (float $g): float => $g * 0.30;

        $result = Pph21TerCalculator::iterateGrossUp(7_000_000, $taxFunc);

        $this->assertTrue($result['converged']);
        $this->assertEqualsWithDelta(10_000_000.0, $result['gross_up_amount'], 1.0);
        $this->assertEqualsWithDelta(3_000_000.0, $result['tax_added'], 1.0);
    }

    public function testBracketTerRateLowBracket(): void
    {
        // Simulate TER bracket A: ≤ 5jt = 0%, > 5jt = 5%
        $taxFunc = function (float $g): float {
            if ($g <= 5_000_000) return 0.0;
            return $g * 0.05;
        };

        // Target net 4_000_000 (di-bawah bracket): tax = 0, gross = 4M
        $r1 = Pph21TerCalculator::iterateGrossUp(4_000_000, $taxFunc);
        $this->assertEqualsWithDelta(4_000_000.0, $r1['gross_up_amount'], 1.0);
        $this->assertSame(0.0, $r1['tax_added']);

        // Target net 9_500_000 → gross 10_000_000 (5% bracket)
        $r2 = Pph21TerCalculator::iterateGrossUp(9_500_000, $taxFunc);
        $this->assertTrue($r2['converged']);
        $this->assertEqualsWithDelta(10_000_000.0, $r2['gross_up_amount'], 100.0);
    }

    public function testWithBaseGrossOnly_BonusGrossUpAddsToTaxAtMargin(): void
    {
        // Karyawan sudah punya gaji dasar 10jt (kena tax 5%, jadi 500k).
        // Bonus gross-up untuk net additional 1jt:
        //   - Naik gross ke 11_052_631.58 → tax 552_631.58 → tax_added 52_631.58
        //   - Net additional = 1_052_631.58 - 52_631.58 = 1_000_000 ✓
        $taxFunc = fn (float $g): float => $g * 0.05;

        $result = Pph21TerCalculator::iterateGrossUp(
            targetNet: 1_000_000,
            taxFunc: $taxFunc,
            baseGross: 10_000_000,
        );

        $this->assertTrue($result['converged']);
        $this->assertEqualsWithDelta(1_052_631.58, $result['gross_up_amount'], 1.0);
        $this->assertEqualsWithDelta(52_631.58, $result['tax_added'], 1.0);
    }

    public function testPushesIntoHigherBracketIncreasesIterations(): void
    {
        // Bracket: ≤ 6.2jt = 0%, 6.2-9.2jt = 0.5%, 9.2-10.7jt = 1.5%
        // Skenario: base gross 9jt, tambah net 2jt → gross-up push
        // melewati bracket 9.2jt
        $taxFunc = function (float $g): float {
            if ($g <= 6_200_000) return 0.0;
            if ($g <= 9_200_000) return $g * 0.005;
            if ($g <= 10_700_000) return $g * 0.015;
            return $g * 0.025;
        };

        $result = Pph21TerCalculator::iterateGrossUp(
            targetNet: 2_000_000,
            taxFunc: $taxFunc,
            baseGross: 9_000_000,
        );

        $this->assertTrue($result['converged']);
        // Bonus gross harus > 2jt karena ditambah pajak naik bracket
        $this->assertGreaterThan(2_000_000, $result['gross_up_amount']);
        // Verifikasi: total pajak setelah grossup - pajak base = tax_added
        $this->assertGreaterThan(0, $result['tax_added']);
        // Net additional = grossup - tax_added → harus ≈ targetNet
        $netActual = $result['gross_up_amount'] - $result['tax_added'];
        $this->assertEqualsWithDelta(2_000_000.0, $netActual, 1.0);
    }

    public function testProgressiveTaxConvergesWithinIterationCap(): void
    {
        // Smooth progressive tax (yearly bracket simulation simplified)
        // 0-50M: 5%, 50-250M: 15%, 250M+: 25%
        $taxFunc = function (float $g): float {
            if ($g <= 50_000_000) return $g * 0.05;
            if ($g <= 250_000_000) return 50_000_000 * 0.05 + ($g - 50_000_000) * 0.15;
            return 50_000_000 * 0.05 + 200_000_000 * 0.15 + ($g - 250_000_000) * 0.25;
        };

        // Target 100jt net → harus push ke bracket tinggi
        $result = Pph21TerCalculator::iterateGrossUp(
            targetNet: 100_000_000,
            taxFunc: $taxFunc,
            baseGross: 0,
        );

        $this->assertTrue($result['converged']);
        $this->assertLessThanOrEqual(10, $result['iterations']);
        // Verifikasi mathematical correctness: grossUp - tax(grossUp) ≈ target
        $tax = $taxFunc($result['gross_up_amount']);
        $netActual = $result['gross_up_amount'] - $tax;
        $this->assertEqualsWithDelta(100_000_000.0, $netActual, 1.0);
    }

    public function testNonConvergentTaxFunctionReturnsLastGuess(): void
    {
        // Pathological tax function: tax > gross (impossible scenario)
        // Iteration tidak akan converge karena setiap step tax_added besar
        $taxFunc = fn (float $g): float => $g * 1.5;  // tax 150% — tidak masuk akal

        $result = Pph21TerCalculator::iterateGrossUp(
            targetNet: 1_000_000,
            taxFunc: $taxFunc,
            maxIter: 5,
        );

        // Tidak converge tapi tetap return result terakhir
        $this->assertFalse($result['converged']);
        $this->assertSame(5, $result['iterations']);
    }

    public function testEpsilonControlsPrecision(): void
    {
        // Tax 5% → closed form: gross = net / 0.95 ≈ 10526315.79
        // Dengan epsilon longgar 1000, lebih cepat converge
        $taxFunc = fn (float $g): float => $g * 0.05;

        $tight = Pph21TerCalculator::iterateGrossUp(
            10_000_000, $taxFunc, 0, 20, epsilon: 0.01
        );
        $loose = Pph21TerCalculator::iterateGrossUp(
            10_000_000, $taxFunc, 0, 20, epsilon: 1000.0
        );

        $this->assertTrue($tight['converged']);
        $this->assertTrue($loose['converged']);
        $this->assertLessThanOrEqual($tight['iterations'], $loose['iterations']);
    }
}