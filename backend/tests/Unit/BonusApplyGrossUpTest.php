<?php

namespace Tests\Unit;

use App\Libraries\Payroll\BonusCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 18+ — Test untuk BonusCalculator::applyGrossUp.
 *
 * Helper ini di-pakai PayrollService sebelum bonusEarningEntries() saat
 * breakdown punya tax_treatment GROSS_UP atau NET. Mock taxFunc supaya
 * pure-test tanpa DB.
 */
final class BonusApplyGrossUpTest extends TestCase
{
    public function testNullBonusInfoReturnsZero(): void
    {
        $taxFunc = fn (float $g): float => 0.0;

        $result = BonusCalculator::applyGrossUp(null, $taxFunc);

        $this->assertSame(0.0, $result['amount']);
        $this->assertSame([], $result['breakdown']);
        $this->assertSame(0.0, $result['gross_up_total']);
    }

    public function testEmptyBreakdownPassthrough(): void
    {
        $taxFunc = fn (float $g): float => 0.0;
        $info = ['amount' => 5_000_000, 'breakdown' => []];

        $result = BonusCalculator::applyGrossUp($info, $taxFunc);

        $this->assertSame(5_000_000.0, $result['amount']);
        $this->assertSame(0.0, $result['gross_up_total']);
    }

    public function testNormalEntriesPassthroughUnchanged(): void
    {
        $taxFunc = fn (float $g): float => $g * 0.05;
        $info = [
            'amount' => 3_000_000,
            'breakdown' => [
                ['tax_treatment' => 'NORMAL', 'amount' => 3_000_000],
            ],
        ];

        $result = BonusCalculator::applyGrossUp($info, $taxFunc);

        $this->assertSame(3_000_000.0, $result['amount']);
        $this->assertSame(0.0, $result['gross_up_total']);
        $this->assertSame(3_000_000.0, (float) $result['breakdown'][0]['amount']);
    }

    public function testNonTaxableEntriesPassthroughUnchanged(): void
    {
        $taxFunc = fn (float $g): float => $g * 0.05;
        $info = [
            'amount' => 1_000_000,
            'breakdown' => [
                ['tax_treatment' => 'NON_TAXABLE', 'amount' => 1_000_000],
            ],
        ];

        $result = BonusCalculator::applyGrossUp($info, $taxFunc);

        $this->assertSame(1_000_000.0, $result['amount']);
        $this->assertSame(0.0, $result['gross_up_total']);
    }

    public function testNetEntryGetsGrossedUp(): void
    {
        // tax 5% flat. Target net 9_500_000 → gross 10_000_000
        $taxFunc = fn (float $g): float => $g * 0.05;
        $info = [
            'amount' => 9_500_000,
            'breakdown' => [
                ['tax_treatment' => 'NET', 'amount' => 9_500_000],
            ],
        ];

        $result = BonusCalculator::applyGrossUp($info, $taxFunc);

        $this->assertEqualsWithDelta(10_000_000.0, $result['amount'], 1.0);
        $this->assertEqualsWithDelta(500_000.0, $result['gross_up_total'], 1.0);
        $this->assertEqualsWithDelta(10_000_000.0, (float) $result['breakdown'][0]['amount'], 1.0);
        $this->assertEqualsWithDelta(9_500_000.0, (float) $result['breakdown'][0]['original_amount'], 1.0);
        $this->assertEqualsWithDelta(500_000.0, (float) $result['breakdown'][0]['tax_added'], 1.0);
        $this->assertTrue($result['breakdown'][0]['converged']);
    }

    public function testGrossUpEntryGetsGrossedUp(): void
    {
        // tax 30% flat. Target 7_000_000 → gross 10_000_000
        $taxFunc = fn (float $g): float => $g * 0.30;
        $info = [
            'amount' => 7_000_000,
            'breakdown' => [
                ['tax_treatment' => 'GROSS_UP', 'amount' => 7_000_000, 'run_name' => 'Bonus Net Q4'],
            ],
        ];

        $result = BonusCalculator::applyGrossUp($info, $taxFunc);

        $this->assertEqualsWithDelta(10_000_000.0, $result['amount'], 1.0);
        $this->assertEqualsWithDelta(3_000_000.0, $result['gross_up_total'], 1.0);
        $this->assertSame('Bonus Net Q4', $result['breakdown'][0]['run_name']);
    }

    public function testMixedNormalAndNetGetsPartialGrossUp(): void
    {
        // tax 5% flat. Mixed: NORMAL 5jt (passthrough) + NET 1jt (gross up)
        $taxFunc = fn (float $g): float => $g * 0.05;
        $info = [
            'amount' => 6_000_000,
            'breakdown' => [
                ['tax_treatment' => 'NORMAL', 'amount' => 5_000_000],
                ['tax_treatment' => 'NET',    'amount' => 1_000_000],
            ],
        ];

        $result = BonusCalculator::applyGrossUp($info, $taxFunc);

        // NORMAL stays 5jt, NET 1jt → grossup 1_052_631.58
        // Total ≈ 6_052_631.58
        $this->assertEqualsWithDelta(6_052_631.58, $result['amount'], 1.0);
        $this->assertEqualsWithDelta(52_631.58, $result['gross_up_total'], 1.0);
        $this->assertCount(2, $result['breakdown']);
        $this->assertSame(5_000_000, $result['breakdown'][0]['amount']);
        $this->assertEqualsWithDelta(1_052_631.58, (float) $result['breakdown'][1]['amount'], 1.0);
    }

    public function testBaseGrossInfluencesGrossUpAtMargin(): void
    {
        // tax 0% sampai 5jt, 5% di atas. Karyawan dengan base 10jt sudah
        // di bracket 5%. Bonus NET 1jt → marginal 5%
        $taxFunc = function (float $g): float {
            if ($g <= 5_000_000) return 0.0;
            return ($g - 5_000_000) * 0.05;
        };
        $info = [
            'amount' => 1_000_000,
            'breakdown' => [
                ['tax_treatment' => 'NET', 'amount' => 1_000_000],
            ],
        ];

        // Base 10jt: marginal 5% pada bonus → gross-up factor 1/0.95
        $r1 = BonusCalculator::applyGrossUp($info, $taxFunc, baseGross: 10_000_000);
        $this->assertEqualsWithDelta(1_052_631.58, $r1['amount'], 1.0);

        // Base 0: bonus 1jt masuk bracket 0% → gross = net = 1jt
        $r2 = BonusCalculator::applyGrossUp($info, $taxFunc, baseGross: 0);
        $this->assertEqualsWithDelta(1_000_000.0, $r2['amount'], 1.0);
        $this->assertEqualsWithDelta(0.0, $r2['gross_up_total'], 1.0);
    }

    public function testZeroAmountEntryNotGrossedUp(): void
    {
        $taxFunc = fn (float $g): float => $g * 0.05;
        $info = [
            'amount' => 0,
            'breakdown' => [
                ['tax_treatment' => 'NET', 'amount' => 0],
            ],
        ];

        $result = BonusCalculator::applyGrossUp($info, $taxFunc);

        $this->assertSame(0.0, $result['amount']);
        $this->assertSame(0.0, $result['gross_up_total']);
    }
}