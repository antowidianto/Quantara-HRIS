<?php

namespace Tests\Unit;

use App\Libraries\TenureLeaveQuotaCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TenureLeaveQuotaCalculatorTest — Sprint C / C4 + C5.
 *
 * Test cuti besar (long-service leave UU 13/2003 Pasal 79 ayat 2(d)):
 *   - Eligible setelah PKWTT 6 tahun terus-menerus (1 bulan kalender)
 *   - Berulang setiap kelipatan 6 tahun
 *
 * Test bonus quota berbasis tenure:
 *   - 5 tahun → +2 hari, 10 tahun → +5 hari, dst.
 */
class TenureLeaveQuotaCalculatorTest extends TestCase
{
    public static function tenureProvider(): array
    {
        return [
            'fresh hire'           => ['2026-01-01', '2026-05-15', 0],
            '1 year'               => ['2025-05-01', '2026-05-15', 1],
            'almost 5 years'       => ['2021-06-01', '2026-05-15', 4],
            'exactly 5 years'      => ['2021-05-01', '2026-05-15', 5],
            '6 years'              => ['2020-05-01', '2026-05-15', 6],
            '10 years'             => ['2016-05-01', '2026-05-15', 10],
            'future hire'          => ['2030-01-01', '2026-05-15', 0],
        ];
    }

    #[DataProvider('tenureProvider')]
    public function testTenureYears(string $join, string $ref, int $expected): void
    {
        $this->assertSame($expected, TenureLeaveQuotaCalculator::tenureYears($join, $ref));
    }

    public static function bonusDaysProvider(): array
    {
        return [
            'tenure 0'    => [0,   0],
            'tenure 1'    => [1,   0],
            'tenure 4'    => [4,   0],
            'tenure 5'    => [5,   2],   // First milestone
            'tenure 9'    => [9,   2],
            'tenure 10'   => [10,  5],
            'tenure 14'   => [14,  5],
            'tenure 15'   => [15,  7],
            'tenure 19'   => [19,  7],
            'tenure 20'   => [20, 10],
            'tenure 25'   => [25, 13],
            'tenure 30'   => [30, 16],
            'tenure 50'   => [50, 16], // capped di 30+
        ];
    }

    #[DataProvider('bonusDaysProvider')]
    public function testBonusDaysForTenure(int $tenure, int $expected): void
    {
        $this->assertSame($expected, TenureLeaveQuotaCalculator::bonusDaysForTenure($tenure));
    }

    public function testBonusDaysWithCustomSchedule(): void
    {
        // Company X: bonus mulai dari tenure 3 tahun
        $custom = [[3, 1], [7, 4], [10, 8]];

        $this->assertSame(0, TenureLeaveQuotaCalculator::bonusDaysForTenure(2, $custom));
        $this->assertSame(1, TenureLeaveQuotaCalculator::bonusDaysForTenure(3, $custom));
        $this->assertSame(1, TenureLeaveQuotaCalculator::bonusDaysForTenure(6, $custom));
        $this->assertSame(4, TenureLeaveQuotaCalculator::bonusDaysForTenure(7, $custom));
        $this->assertSame(8, TenureLeaveQuotaCalculator::bonusDaysForTenure(10, $custom));
        $this->assertSame(8, TenureLeaveQuotaCalculator::bonusDaysForTenure(20, $custom));
    }

    public function testLongServiceNotEligibleBefore6Years(): void
    {
        $r = TenureLeaveQuotaCalculator::longServiceLeaveEligibility('2021-05-01', '2026-05-15');

        $this->assertFalse($r['eligible']);
        $this->assertSame(5, $r['tenure_years']);
        $this->assertSame(6, $r['next_cycle_year']);
        $this->assertSame(0, $r['days_granted']);
    }

    public function testLongServiceEligibleAfter6Years(): void
    {
        $r = TenureLeaveQuotaCalculator::longServiceLeaveEligibility('2020-05-01', '2026-05-15');

        $this->assertTrue($r['eligible']);
        $this->assertSame(6, $r['tenure_years']);
        $this->assertSame(30, $r['days_granted']);
        $this->assertSame(12, $r['next_cycle_year']);
    }

    public function testLongServiceNextCycleAt12Years(): void
    {
        // Karyawan 13 tahun → cycle 2 (sudah lewat 12), cycle 3 di tahun 18
        $r = TenureLeaveQuotaCalculator::longServiceLeaveEligibility('2013-05-01', '2026-05-15');

        $this->assertTrue($r['eligible']);
        $this->assertSame(13, $r['tenure_years']);
        $this->assertSame(18, $r['next_cycle_year']);
    }

    public function testTotalAnnualQuotaWithoutBonus(): void
    {
        $r = TenureLeaveQuotaCalculator::totalAnnualQuota(3); // Tenure 3 tahun

        $this->assertSame(12, $r['basic']);
        $this->assertSame(0, $r['bonus']);
        $this->assertSame(12, $r['total']);
    }

    public function testTotalAnnualQuotaWithBonus(): void
    {
        $r = TenureLeaveQuotaCalculator::totalAnnualQuota(15);

        $this->assertSame(12, $r['basic']);
        $this->assertSame(7, $r['bonus']);
        $this->assertSame(19, $r['total']);
    }

    public function testTotalAnnualQuotaWithCustomBasic(): void
    {
        // Mis. company kasih basic 14 hari (bukan 12 default UU)
        $r = TenureLeaveQuotaCalculator::totalAnnualQuota(10, 14);

        $this->assertSame(14, $r['basic']);
        $this->assertSame(5, $r['bonus']);
        $this->assertSame(19, $r['total']);
    }

    public function testInvalidJoinDateReturnsZeroTenure(): void
    {
        $this->assertSame(0, TenureLeaveQuotaCalculator::tenureYears('not-a-date', '2026-05-15'));
    }
}