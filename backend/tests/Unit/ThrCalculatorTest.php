<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\PayrollController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ThrCalculatorTest — verifikasi method static helper untuk THR
 * di `PayrollController` (Sprint 4 fix).
 *
 * THR (PMK 6/2016 & Permenaker 6/2016):
 *   - Masa kerja ≥ 12 bulan : 1 bulan upah penuh
 *   - Masa kerja 1–11 bulan : proporsional = (bulan_kerja / 12) × upah
 *   - Masa kerja < 1 bulan  : tidak berhak
 *   - Karyawan resign mid-year tetap berhak proporsional sampai resign_date
 *
 * Run: vendor/bin/phpunit --filter ThrCalculatorTest
 */
class ThrCalculatorTest extends TestCase
{
    // ─── computeTenureMonths ────────────────────────────────────────

    /**
     * @return array<string, array{0:string, 1:?string, 2:string, 3:int}>
     */
    public static function tenureProvider(): array
    {
        // [joinDate, resignDate, payDate, expectedTenureMonths]
        // computeTenureMonths return total kumulatif (tidak di-cap di 12).
        // Caller (computeThrAmount) yang putuskan tenure ≥ 12 → 1 bulan penuh.
        // Angka aproksimasi dengan 1 bulan = 30 hari.
        return [
            // 2020-01-01 → 2026-04-15 = 2296 hari / 30 ≈ 76 bulan
            'join lebih dari setahun'        => ['2020-01-01', null, '2026-04-15', 76],
            // 2026-01-01 → 2026-04-15 = 104 hari / 30 = 3 bulan
            'join awal tahun ini'            => ['2026-01-01', null, '2026-04-15', 3],
            // 2026-03-15 → 2026-04-15 = 31 hari / 30 = 1 bulan
            'join Maret, bayar April'        => ['2026-03-15', null, '2026-04-15', 1],
            // 2026-04-01 → 2026-04-15 = 14 hari / 30 = 0 bulan (tidak berhak)
            'join < 1 bulan tidak berhak'    => ['2026-04-01', null, '2026-04-15', 0],
            // join setelah pay_date → 0
            'join setelah pay_date = 0'      => ['2026-05-01', null, '2026-04-15', 0],
            // 2026-01-01 → resign 2026-03-15 = 73 hari / 30 = 2 bulan
            'resign mid-year proporsional'   => ['2026-01-01', '2026-03-15', '2026-12-15', 2],
            // resign sebelum periode (default Jan 1 2026) → 0
            'resign sebelum periode = 0'     => ['2024-01-01', '2025-12-31', '2026-04-15', 0],
            // 2020-01-01 → min(pay 2026-04-15, resign 2026-06-01) = pay → 76 bulan
            'resign setelah pay tetap penuh' => ['2020-01-01', '2026-06-01', '2026-04-15', 76],
            // 2025-01-01 → 2026-04-15 = 469 hari / 30 = 15 bulan (≥ 12 = full THR)
            'resign tepat di pay_date'       => ['2025-01-01', '2026-04-15', '2026-04-15', 15],
        ];
    }

    #[DataProvider('tenureProvider')]
    public function testComputeTenureMonths(
        string $joinDate, ?string $resignDate, string $payDate, int $expected
    ): void {
        $actual = PayrollController::computeTenureMonths($joinDate, $resignDate, $payDate);
        $this->assertSame($expected, $actual,
            "join={$joinDate}, resign=" . ($resignDate ?? 'null') . ", pay={$payDate}");
    }

    public function testInvalidDatesReturnZero(): void
    {
        $this->assertSame(0, PayrollController::computeTenureMonths('not-a-date', null, '2026-04-15'));
        $this->assertSame(0, PayrollController::computeTenureMonths('2026-01-01', null, 'invalid'));
    }

    public function testTenureMonthsExceedsTwelveForLongTimeEmployee(): void
    {
        // Karyawan join 5 tahun lalu — return total kumulatif (≥ 12).
        // Caller computeThrAmount yang akan cap ke 1 bulan upah penuh.
        $r = PayrollController::computeTenureMonths('2020-01-01', null, '2026-12-15');
        $this->assertGreaterThanOrEqual(12, $r);
    }

    public function testCustomPeriodStartGatesEligibilityOnly(): void
    {
        // periodStart hanya dipakai untuk gate eligibility resign, bukan
        // untuk cap tenure. Karyawan resign di 2025 dengan period start
        // 2026-04-01 → tidak berhak (return 0).
        $r = PayrollController::computeTenureMonths(
            '2024-01-01', '2025-12-31', '2026-12-15', '2026-04-01'
        );
        $this->assertSame(0, $r);
    }

    // ─── computeThrAmount ───────────────────────────────────────────

    public function testFullThrForFullYear(): void
    {
        $this->assertSame(5_000_000.0, PayrollController::computeThrAmount(5_000_000, 12));
    }

    public function testProportionalThrForSixMonths(): void
    {
        // 6/12 × 5jt = 2.5jt
        $this->assertSame(2_500_000.0, PayrollController::computeThrAmount(5_000_000, 6));
    }

    public function testProportionalThrForOneMonth(): void
    {
        // 1/12 × 5jt ≈ 416.667
        $this->assertEqualsWithDelta(416_667, PayrollController::computeThrAmount(5_000_000, 1), 1);
    }

    public function testZeroTenureReturnsZero(): void
    {
        $this->assertSame(0.0, PayrollController::computeThrAmount(5_000_000, 0));
    }

    public function testZeroSalaryReturnsZero(): void
    {
        $this->assertSame(0.0, PayrollController::computeThrAmount(0, 12));
    }

    public function testNegativeTenureReturnsZero(): void
    {
        $this->assertSame(0.0, PayrollController::computeThrAmount(5_000_000, -1));
    }

    public function testCapTenureAtTwelve(): void
    {
        // tenureMonths > 12 → tetap 1 bulan upah (handled di caller)
        // method ini langsung return full kalau ≥ 12
        $this->assertSame(5_000_000.0, PayrollController::computeThrAmount(5_000_000, 24));
    }

    public function testRoundingProducesIntegerAmount(): void
    {
        // 7/12 × 4.567.890 = 2.664.602.5 → rounded ke 2.664.603
        $r = PayrollController::computeThrAmount(4_567_890, 7);
        $this->assertSame((float) (int) $r, $r, 'Hasil THR harus integer rupiah');
    }
}