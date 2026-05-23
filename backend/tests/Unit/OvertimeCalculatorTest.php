<?php

namespace Tests\Unit;

use App\Libraries\OvertimeCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OvertimeCalculatorTest — verifikasi perhitungan lembur sesuai
 * PP 35/2021 Pasal 31.
 *
 * Formula:
 *  hourly = monthlyBase / 173
 *
 * WEEKDAY:
 *  - jam ke-1     : 1.5 × hourly
 *  - jam ke-2..n  : 2.0 × hourly
 *
 * REST DAY (5 hari kerja/minggu):
 *  - jam 1..8     : 2.0 × hourly
 *  - jam 9        : 3.0 × hourly
 *  - jam 10..n    : 4.0 × hourly
 *
 * REST DAY (6 hari kerja/minggu):
 *  - jam 1..7     : 2.0 × hourly
 *  - jam 8        : 3.0 × hourly
 *  - jam 9..n     : 4.0 × hourly
 *
 * Run: vendor/bin/phpunit --filter OvertimeCalculatorTest
 */
class OvertimeCalculatorTest extends TestCase
{
    /** Toleransi pembulatan untuk floating point assertion. */
    private const EPSILON = 1.0;

    private OvertimeCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new OvertimeCalculator();
    }

    // ─── Edge cases ─────────────────────────────────────────────────

    public function testZeroHoursReturnsZero(): void
    {
        $this->assertSame(0.0, $this->calc->calculate(5_000_000, 0, 'WEEKDAY'));
        $this->assertSame(0.0, $this->calc->calculate(5_000_000, 0, 'WEEKEND'));
        $this->assertSame(0.0, $this->calc->calculate(5_000_000, 0, 'HOLIDAY'));
    }

    public function testNegativeHoursReturnsZero(): void
    {
        $this->assertSame(0.0, $this->calc->calculate(5_000_000, -1, 'WEEKDAY'));
    }

    // ─── WEEKDAY ────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: float, 1: float, 2: float}>
     */
    public static function weekdayProvider(): array
    {
        // Format: [monthlyBase, hours, expectedAmount]
        // hourly = base/173
        $base = 5_190_000;          // hourly = 30000
        return [
            // 1 jam: 1.5 × 30000 = 45000
            'weekday 1 jam'  => [$base, 1, 45_000],
            // 2 jam: (1.5 × 30000) + (1.0 × 2.0 × 30000) = 45000 + 60000 = 105000
            'weekday 2 jam'  => [$base, 2, 105_000],
            // 3 jam: 45000 + 2 × 60000 = 165000
            'weekday 3 jam'  => [$base, 3, 165_000],
            // 5 jam: 45000 + 4 × 60000 = 285000
            'weekday 5 jam'  => [$base, 5, 285_000],
            // Setengah jam saja: 0.5 × 1.5 × 30000 = 22500
            'weekday 0.5 jam' => [$base, 0.5, 22_500],
        ];
    }

    #[DataProvider('weekdayProvider')]
    public function testWeekdayCalculation(float $base, float $hours, float $expected): void
    {
        $actual = $this->calc->calculate($base, $hours, 'WEEKDAY');
        $this->assertEqualsWithDelta($expected, $actual, self::EPSILON,
            "Weekday {$hours} jam dari base {$base}");
    }

    // ─── REST DAY 5-day week ────────────────────────────────────────

    /**
     * @return array<string, array{0: float, 1: float, 2: float}>
     */
    public static function restDay5DayProvider(): array
    {
        $base = 5_190_000; // hourly = 30000
        return [
            // 1 jam: 2 × 30000 = 60000
            'restday 5d 1 jam'  => [$base, 1, 60_000],
            // 8 jam: 8 × 2 × 30000 = 480000
            'restday 5d 8 jam'  => [$base, 8, 480_000],
            // 9 jam: 480000 + (1 × 3 × 30000) = 480000 + 90000 = 570000
            'restday 5d 9 jam'  => [$base, 9, 570_000],
            // 10 jam: 570000 + (1 × 4 × 30000) = 570000 + 120000 = 690000
            'restday 5d 10 jam' => [$base, 10, 690_000],
            // 11 jam: 690000 + 120000 = 810000
            'restday 5d 11 jam' => [$base, 11, 810_000],
        ];
    }

    #[DataProvider('restDay5DayProvider')]
    public function testRestDay5DayCalculation(float $base, float $hours, float $expected): void
    {
        $actual = $this->calc->calculate($base, $hours, 'WEEKEND', 5);
        $this->assertEqualsWithDelta($expected, $actual, self::EPSILON,
            "Rest day 5-day week {$hours} jam dari base {$base}");
    }

    // ─── REST DAY 6-day week ────────────────────────────────────────

    /**
     * @return array<string, array{0: float, 1: float, 2: float}>
     */
    public static function restDay6DayProvider(): array
    {
        $base = 5_190_000; // hourly = 30000
        return [
            // 1 jam: 2 × 30000 = 60000
            'restday 6d 1 jam'  => [$base, 1, 60_000],
            // 7 jam: 7 × 2 × 30000 = 420000
            'restday 6d 7 jam'  => [$base, 7, 420_000],
            // 8 jam: 420000 + (1 × 3 × 30000) = 510000
            'restday 6d 8 jam'  => [$base, 8, 510_000],
            // 9 jam: 510000 + (1 × 4 × 30000) = 630000
            'restday 6d 9 jam'  => [$base, 9, 630_000],
            // 10 jam: 630000 + 120000 = 750000
            'restday 6d 10 jam' => [$base, 10, 750_000],
        ];
    }

    #[DataProvider('restDay6DayProvider')]
    public function testRestDay6DayCalculation(float $base, float $hours, float $expected): void
    {
        $actual = $this->calc->calculate($base, $hours, 'WEEKEND', 6);
        $this->assertEqualsWithDelta($expected, $actual, self::EPSILON,
            "Rest day 6-day week {$hours} jam dari base {$base}");
    }

    // ─── HOLIDAY same as weekend formula ────────────────────────────

    public function testHolidayUsesRestDayFormula(): void
    {
        $base = 5_190_000;
        // Holiday 9 jam (5-day) harus sama dengan weekend 9 jam (5-day)
        $weekend = $this->calc->calculate($base, 9, 'WEEKEND', 5);
        $holiday = $this->calc->calculate($base, 9, 'HOLIDAY', 5);
        $this->assertSame($weekend, $holiday);
    }

    // ─── Real-world salary scenarios ────────────────────────────────

    public function testRealWorldUmpJakartaScenario(): void
    {
        // UMP DKI Jakarta 2026 ~= 5.396.760 + tunjangan tetap 200rb
        // hourly = 5_596_760 / 173 ≈ 32_352
        $base = 5_596_760;
        $amount = $this->calc->calculate($base, 3, 'WEEKDAY');
        // Expected: 1×1.5×32352 + 2×2×32352 = 48528 + 129408 = 177936
        $this->assertEqualsWithDelta(177_936, $amount, 100,
            'UMP Jakarta + 200rb tunjangan, 3 jam weekday');
    }

    public function testRoundingIsApplied(): void
    {
        // Pastikan hasil di-round (tidak ada desimal residual)
        $amount = $this->calc->calculate(5_000_000, 1, 'WEEKDAY');
        $this->assertSame((float) (int) $amount, $amount,
            'Hasil overtime harus integer (sudah di-round)');
    }

    // ─── Sprint 5-6 — custom hoursPerMonth divisor ─────────────────

    public function testCustomHoursPerMonthDivisor(): void
    {
        // Perusahaan dengan working hours 160 (40 jam × 4 minggu),
        // bukan default 173. Hourly rate jadi lebih besar.
        $base = 4_800_000;
        // Default (173): hourly = 27_745, 1 jam weekday = 41_618
        // Custom (160): hourly = 30_000, 1 jam weekday = 45_000
        $defaultAmount = $this->calc->calculate($base, 1, 'WEEKDAY');
        $customAmount  = $this->calc->calculate($base, 1, 'WEEKDAY', 5, 160);

        $this->assertEqualsWithDelta(41_618, $defaultAmount, 1);
        $this->assertSame(45_000.0, $customAmount);
    }

    public function testInvalidHoursPerMonthFallsbackToDefault(): void
    {
        $base = 5_190_000;
        $expected = $this->calc->calculate($base, 1, 'WEEKDAY');
        // 0 atau negatif → fallback ke 173
        $this->assertSame($expected, $this->calc->calculate($base, 1, 'WEEKDAY', 5, 0));
        $this->assertSame($expected, $this->calc->calculate($base, 1, 'WEEKDAY', 5, -10));
    }
}
