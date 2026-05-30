<?php

namespace Tests\Unit;

use App\Libraries\LongSickLeaveCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LongSickLeaveCalculatorTest — Sprint C / C3.
 *
 * UU 13/2003 Pasal 153 + PP 35/2021 — pemotongan upah progresif untuk
 * sakit berkepanjangan terverifikasi dokter:
 *   Bulan 1-4   = 100%
 *   Bulan 5-8   =  75%
 *   Bulan 9-12  =  50%
 *   Bulan 13+   =  25% (eligible PHK Pasal 154A)
 */
class LongSickLeaveCalculatorTest extends TestCase
{
    public static function percentageProvider(): array
    {
        return [
            'belum sakit'           => [0,    100],
            'bulan 1'               => [1,    100],
            'bulan 4 batas atas P1' => [4,    100],
            'bulan 5 awal P2'       => [5,     75],
            'bulan 8 batas atas P2' => [8,     75],
            'bulan 9 awal P3'       => [9,     50],
            'bulan 12 batas atas P3'=> [12,    50],
            'bulan 13 awal P4'      => [13,    25],
            'bulan 24 P4'           => [24,    25],
            'bulan 100 P4'          => [100,   25],
        ];
    }

    #[DataProvider('percentageProvider')]
    public function testPercentageForMonth(int $month, int $expectedPct): void
    {
        $this->assertSame($expectedPct, LongSickLeaveCalculator::percentageForMonth($month));
    }

    public static function phaseLabelProvider(): array
    {
        return [
            'normal'    => [0, 'NORMAL'],
            'phase 1'   => [3, 'PHASE_1_FULL'],
            'phase 2'   => [6, 'PHASE_2_75'],
            'phase 3'   => [10, 'PHASE_3_50'],
            'phase 4'   => [15, 'PHASE_4_25_OR_PHK'],
        ];
    }

    #[DataProvider('phaseLabelProvider')]
    public function testPhaseLabel(int $month, string $expectedLabel): void
    {
        $this->assertSame($expectedLabel, LongSickLeaveCalculator::phaseLabel($month));
    }

    public function testComputeFullPaymentForRecentSickStart(): void
    {
        $result = LongSickLeaveCalculator::compute('2026-05-01', '2026-05-15');

        $this->assertSame(1, $result['months_continuous']);
        $this->assertSame(100, $result['percentage']);
        $this->assertSame('PHASE_1_FULL', $result['phase']);
        $this->assertFalse($result['eligible_phk']);
    }

    public function testComputeReducedPaymentAfter6Months(): void
    {
        // 6 bulan continuous sick → bulan ke-6 = phase 2 (75%)
        $result = LongSickLeaveCalculator::compute('2026-01-01', '2026-06-30');

        $this->assertSame(6, $result['months_continuous']);
        $this->assertSame(75, $result['percentage']);
        $this->assertSame('PHASE_2_75', $result['phase']);
        $this->assertFalse($result['eligible_phk']);
    }

    public function testComputeEligiblePhkAfter12Months(): void
    {
        $result = LongSickLeaveCalculator::compute('2025-01-01', '2026-01-15');

        $this->assertGreaterThanOrEqual(12, $result['months_continuous']);
        $this->assertSame(25, $result['percentage']);
        $this->assertTrue($result['eligible_phk']);
        $this->assertStringContainsString('PHK', $result['description']);
    }

    public function testComputeWithInvalidDateReturnsSafeDefault(): void
    {
        $result = LongSickLeaveCalculator::compute('not-a-date', '2026-05-01');

        $this->assertSame(0, $result['months_continuous']);
        $this->assertSame(100, $result['percentage']);
        $this->assertSame('NORMAL', $result['phase']);
        $this->assertFalse($result['eligible_phk']);
    }

    public function testComputeWhenPayrollPeriodBeforeSickStart(): void
    {
        // Payroll Mei untuk karyawan yang baru sakit Juni → tidak terkena
        $result = LongSickLeaveCalculator::compute('2026-06-01', '2026-05-31');

        $this->assertSame(0, $result['months_continuous']);
        $this->assertSame(100, $result['percentage']);
    }

    public function testPaymentAmountWithFullPay(): void
    {
        $this->assertSame(5_000_000.0, LongSickLeaveCalculator::paymentAmount(5_000_000.0, 1));
        $this->assertSame(5_000_000.0, LongSickLeaveCalculator::paymentAmount(5_000_000.0, 4));
    }

    public function testPaymentAmountWithReducedPay(): void
    {
        // Phase 2: 75% × 4_000_000 = 3_000_000
        $this->assertSame(3_000_000.0, LongSickLeaveCalculator::paymentAmount(4_000_000.0, 5));

        // Phase 3: 50% × 4_000_000 = 2_000_000
        $this->assertSame(2_000_000.0, LongSickLeaveCalculator::paymentAmount(4_000_000.0, 9));

        // Phase 4: 25% × 4_000_000 = 1_000_000
        $this->assertSame(1_000_000.0, LongSickLeaveCalculator::paymentAmount(4_000_000.0, 15));
    }

    public function testDeductionAmountIsCorrect(): void
    {
        // 4M × (100% - 75%) = 1M deduction at month 5
        $this->assertSame(1_000_000.0, LongSickLeaveCalculator::deductionAmount(4_000_000.0, 5));
        // 4M × (100% - 50%) = 2M deduction at month 9
        $this->assertSame(2_000_000.0, LongSickLeaveCalculator::deductionAmount(4_000_000.0, 9));
        // 4M × (100% - 25%) = 3M deduction at month 13+
        $this->assertSame(3_000_000.0, LongSickLeaveCalculator::deductionAmount(4_000_000.0, 13));
    }

    public function testZeroSalaryReturnsZeroPayment(): void
    {
        $this->assertSame(0.0, LongSickLeaveCalculator::paymentAmount(0.0, 5));
    }

    public function testDescriptionContainsRelevantInfo(): void
    {
        $result = LongSickLeaveCalculator::compute('2025-01-01', '2026-05-01');
        $this->assertStringContainsString('upah', $result['description']);
        $this->assertStringContainsString('PHK', $result['description']);
    }
}