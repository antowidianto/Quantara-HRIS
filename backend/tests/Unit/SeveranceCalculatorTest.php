<?php

namespace Tests\Unit;

use App\Libraries\SeveranceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SeveranceCalculatorTest — verifikasi perhitungan pesangon, UPMK, UPH
 * sesuai UU 6/2023 (Cipta Kerja) & PP 35/2021.
 *
 * Lihat tabel multiplier di SeveranceCalculator::REASONS dan tabel masa
 * kerja di SeveranceCalculator::pesangonMonths() / upmkMonths().
 *
 * Run: vendor/bin/phpunit --filter SeveranceCalculatorTest
 */
class SeveranceCalculatorTest extends TestCase
{
    private SeveranceCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new SeveranceCalculator();
    }

    // ─── pesangonMonths tabel ───────────────────────────────────────

    /**
     * @return array<string, array{0: float, 1: int}>
     */
    public static function pesangonMonthsProvider(): array
    {
        return [
            '0.5 tahun (< 1)'  => [0.5, 1],
            '1 tahun pas'      => [1.0, 2],
            '1.99 tahun'       => [1.99, 2],
            '3 tahun pas'      => [3.0, 4],
            '4.5 tahun'        => [4.5, 5],
            '7.99 tahun'       => [7.99, 8],
            '8 tahun pas'      => [8.0, 9],
            '20 tahun (cap)'   => [20.0, 9],
        ];
    }

    #[DataProvider('pesangonMonthsProvider')]
    public function testPesangonMonths(float $years, int $expected): void
    {
        $this->assertSame($expected, $this->calc->pesangonMonths($years));
    }

    // ─── upmkMonths tabel ───────────────────────────────────────────

    /**
     * @return array<string, array{0: float, 1: int}>
     */
    public static function upmkMonthsProvider(): array
    {
        return [
            '< 3 tahun'        => [2.0, 0],
            '3 tahun pas'      => [3.0, 2],
            '5.99 tahun'       => [5.99, 2],
            '6 tahun pas'      => [6.0, 3],
            '12 tahun pas'     => [12.0, 5],
            '24 tahun pas'     => [24.0, 10],
            '30 tahun (cap)'   => [30.0, 10],
        ];
    }

    #[DataProvider('upmkMonthsProvider')]
    public function testUpmkMonths(float $years, int $expected): void
    {
        $this->assertSame($expected, $this->calc->upmkMonths($years));
    }

    // ─── computeServiceYears ────────────────────────────────────────

    public function testComputeServiceYearsBasic(): void
    {
        $years = $this->calc->computeServiceYears('2020-01-01', '2025-01-01');
        // 5 tahun = 5 × 365.25 = 1826.25 hari → ~5.0
        $this->assertEqualsWithDelta(5.0, $years, 0.02);
    }

    public function testComputeServiceYearsZeroForInvalidRange(): void
    {
        $this->assertSame(0.0, $this->calc->computeServiceYears('2025-01-01', '2020-01-01'));
        $this->assertSame(0.0, $this->calc->computeServiceYears('2020-01-01', '2020-01-01'));
    }

    // ─── Multiplier per alasan PHK ──────────────────────────────────

    public function testEfficiencyLossMultiplierHalf(): void
    {
        // Alasan: EFFICIENCY_LOSS, multiplier pesangon 0.5×, UPMK 1.0×
        // Contoh: gaji 10jt, masa kerja 5 tahun
        // pesangon: 6 bulan × 10jt × 0.5 = 30jt
        // UPMK: 2 bulan × 10jt × 1.0 = 20jt
        // Total (tanpa UPH cuti, dst): 50jt
        $r = $this->calc->calculate([
            'last_monthly_salary' => 10_000_000,
            'service_years'       => 5.0,
            'termination_reason'  => 'EFFICIENCY_LOSS',
            'unused_leave_days'   => 0,
        ]);

        $this->assertSame(30_000_000.0, $r['pesangon_amount']);
        $this->assertSame(20_000_000.0, $r['upmk_amount']);
        $this->assertSame(50_000_000.0, $r['total_amount']);
    }

    public function testPensionRetireMultiplier175(): void
    {
        // PENSION_RETIRE: 1.75× pesangon, 1.0× UPMK
        // Gaji 10jt, masa kerja 8 tahun
        // pesangon: 9 bulan × 10jt × 1.75 = 157.5jt
        // UPMK: 3 bulan × 10jt × 1.0 = 30jt
        $r = $this->calc->calculate([
            'last_monthly_salary' => 10_000_000,
            'service_years'       => 8.0,
            'termination_reason'  => 'PENSION_RETIRE',
            'unused_leave_days'   => 0,
        ]);

        $this->assertSame(157_500_000.0, $r['pesangon_amount']);
        $this->assertSame(30_000_000.0, $r['upmk_amount']);
        $this->assertSame(187_500_000.0, $r['total_amount']);
    }

    public function testDeathMultiplier2x(): void
    {
        // DEATH: 2.0× pesangon, 1.0× UPMK
        // Gaji 5jt, masa kerja 10 tahun
        // pesangon: 9 × 5jt × 2.0 = 90jt
        // UPMK: 4 × 5jt × 1.0 = 20jt
        $r = $this->calc->calculate([
            'last_monthly_salary' => 5_000_000,
            'service_years'       => 10.0,
            'termination_reason'  => 'DEATH',
            'unused_leave_days'   => 0,
        ]);

        $this->assertSame(90_000_000.0, $r['pesangon_amount']);
        $this->assertSame(20_000_000.0, $r['upmk_amount']);
    }

    public function testResignReturnsZeroPesangonOnlyUphAndSeparation(): void
    {
        // RESIGN: pesangon 0, UPMK 0, hanya UPH + uang pisah
        $r = $this->calc->calculate([
            'last_monthly_salary' => 5_000_000,
            'service_years'       => 5.0,
            'termination_reason'  => 'RESIGN',
            'unused_leave_days'   => 6,
            'separation_pay'      => 1_000_000,
            'work_days_per_month' => 22,
        ]);

        $this->assertSame(0.0, $r['pesangon_amount']);
        $this->assertSame(0.0, $r['upmk_amount']);
        // UPH cuti: 6 hari × (5jt/22) ≈ 1_363_636
        $this->assertEqualsWithDelta(1_363_636, $r['uph_unused_leave_amount'], 1);
        $this->assertSame(1_000_000.0, $r['separation_pay']);
    }

    public function testViolationGrossNoSeverance(): void
    {
        $r = $this->calc->calculate([
            'last_monthly_salary' => 8_000_000,
            'service_years'       => 6.0,
            'termination_reason'  => 'VIOLATION_GROSS',
            'unused_leave_days'   => 0,
        ]);

        $this->assertSame(0.0, $r['pesangon_amount']);
        $this->assertSame(0.0, $r['upmk_amount']);
        $this->assertSame(0.0, $r['uph_total']);
        $this->assertSame(0.0, $r['total_amount']);
    }

    public function testForceMajeureMultiplier075(): void
    {
        // FORCE_MAJEURE: 0.75× pesangon, 1.0× UPMK
        // Gaji 10jt, masa kerja 4 tahun
        // pesangon: 5 × 10jt × 0.75 = 37.5jt
        $r = $this->calc->calculate([
            'last_monthly_salary' => 10_000_000,
            'service_years'       => 4.0,
            'termination_reason'  => 'FORCE_MAJEURE',
            'unused_leave_days'   => 0,
        ]);
        $this->assertSame(37_500_000.0, $r['pesangon_amount']);
    }

    public function testEndOfPkwtProportionalCompensation(): void
    {
        // END_OF_PKWT: kompensasi 1 bulan upah / 12 bulan masa kerja
        // 18 bulan = 1.5 tahun → 1.5 bulan upah
        // Gaji 5jt × 1.5 = 7.5jt diparkir di pesangon_amount
        $r = $this->calc->calculate([
            'last_monthly_salary' => 5_000_000,
            'service_years'       => 1.5,
            'termination_reason'  => 'END_OF_PKWT',
            'unused_leave_days'   => 0,
        ]);

        $this->assertEqualsWithDelta(7_500_000, $r['pesangon_amount'], 0.01);
        $this->assertEqualsWithDelta(0, $r['upmk_amount'], 0.01);
        $this->assertStringContainsString('PKWT', $r['pesangon_note']);
    }

    public function testInvalidReasonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->calculate([
            'last_monthly_salary' => 5_000_000,
            'service_years'       => 5.0,
            'termination_reason'  => 'NOT_A_REAL_REASON',
        ]);
    }

    public function testUnusedLeavePayCalculation(): void
    {
        // 12 hari cuti × (10jt / 22 hari kerja) = 12 × 454_545.45 ≈ 5_454_545
        $r = $this->calc->calculate([
            'last_monthly_salary' => 10_000_000,
            'service_years'       => 5.0,
            'termination_reason'  => 'EFFICIENCY_LOSS',
            'unused_leave_days'   => 12,
            'work_days_per_month' => 22,
        ]);

        $this->assertEqualsWithDelta(5_454_545, $r['uph_unused_leave_amount'], 1);
    }

    public function testUphIncludesHomecomingAndOther(): void
    {
        $r = $this->calc->calculate([
            'last_monthly_salary' => 5_000_000,
            'service_years'       => 3.0,
            'termination_reason'  => 'EFFICIENCY_LOSS',
            'unused_leave_days'   => 0,
            'uph_homecoming_amount' => 2_000_000,
            'uph_other_amount'      => 500_000,
        ]);

        $this->assertSame(2_000_000.0, $r['uph_homecoming_amount']);
        $this->assertSame(500_000.0, $r['uph_other_amount']);
        $this->assertSame(2_500_000.0, $r['uph_total']);
    }

    public function testZeroSalaryReturnsZeroAmounts(): void
    {
        $r = $this->calc->calculate([
            'last_monthly_salary' => 0,
            'service_years'       => 5.0,
            'termination_reason'  => 'EFFICIENCY_LOSS',
            'unused_leave_days'   => 0,
        ]);
        $this->assertSame(0.0, $r['pesangon_amount']);
        $this->assertSame(0.0, $r['upmk_amount']);
    }

    public function testReasonOptionsContainsAll17Reasons(): void
    {
        $opts = SeveranceCalculator::reasonOptions();
        $this->assertCount(17, $opts);
        $values = array_column($opts, 'value');
        $this->assertContains('EFFICIENCY_LOSS', $values);
        $this->assertContains('PENSION_RETIRE', $values);
        $this->assertContains('DEATH', $values);
        $this->assertContains('RESIGN', $values);
        $this->assertContains('END_OF_PKWT', $values);
    }

    public function testTotalAmountIsConsistent(): void
    {
        $r = $this->calc->calculate([
            'last_monthly_salary' => 10_000_000,
            'service_years'       => 5.0,
            'termination_reason'  => 'EFFICIENCY_LOSS',
            'unused_leave_days'   => 6,
            'uph_homecoming_amount' => 1_500_000,
            'uph_other_amount'      => 500_000,
            'work_days_per_month'   => 22,
        ]);

        $expectedTotal = $r['pesangon_amount']
                       + $r['upmk_amount']
                       + $r['uph_total']
                       + $r['separation_pay'];
        $this->assertEqualsWithDelta($expectedTotal, $r['total_amount'], 0.01,
            'total_amount harus = pesangon + upmk + uph + separation');
    }
}