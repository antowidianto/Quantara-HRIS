<?php

namespace Tests\Unit;

use App\Libraries\LeaveBalanceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LeaveBalanceServiceTest — verifikasi `computeProratedQuota()` (pure
 * function, tidak butuh DB).
 *
 * Method ini hitung quota cuti proporsional saat karyawan join mid-year.
 * Aturan:
 *  - Kalau `is_prorated = 0` → return default_days penuh
 *  - Kalau join sebelum/awal tahun → full quota
 *  - Kalau join setelah akhir tahun → 0
 *  - Kalau join mid-year → (12 - bulan_join + 1) / 12 × default_days
 *  - Kalau hari join > 15 → bulan itu tidak dihitung
 *
 * Run: vendor/bin/phpunit --filter LeaveBalanceServiceTest
 */
class LeaveBalanceServiceTest extends TestCase
{
    private LeaveBalanceService $svc;

    protected function setUp(): void
    {
        $this->svc = new LeaveBalanceService();
    }

    private function leaveType(int $defaultDays = 12, bool $prorated = true): array
    {
        return [
            'default_days' => $defaultDays,
            'is_prorated'  => $prorated ? 1 : 0,
        ];
    }

    private function employee(string $joinDate): array
    {
        return ['join_date' => $joinDate];
    }

    // ─── Not prorated ────────────────────────────────────────────────

    public function testNotProratedReturnsFullQuota(): void
    {
        $r = $this->svc->computeProratedQuota(
            $this->employee('2026-07-15'),  // mid-year
            $this->leaveType(12, prorated: false),
            2026
        );

        $this->assertSame(12.0, $r['quota'], 'is_prorated=0 → full quota');
        $this->assertSame(1.0, $r['prorate_factor']);
        $this->assertSame(12, $r['months_worked']);
        $this->assertFalse($r['is_prorated']);
    }

    // ─── Empty join_date ────────────────────────────────────────────

    public function testEmptyJoinDateReturnsFullQuota(): void
    {
        $r = $this->svc->computeProratedQuota(
            ['join_date' => ''],
            $this->leaveType(12),
            2026
        );

        $this->assertSame(12.0, $r['quota']);
        $this->assertSame(1.0, $r['prorate_factor']);
    }

    // ─── Join sebelum tahun ─────────────────────────────────────────

    public function testJoinBeforeYearReturnsFullQuota(): void
    {
        $r = $this->svc->computeProratedQuota(
            $this->employee('2020-03-01'),
            $this->leaveType(12),
            2026
        );

        $this->assertSame(12.0, $r['quota']);
        $this->assertSame(1.0, $r['prorate_factor']);
        $this->assertSame(12, $r['months_worked']);
    }

    public function testJoinExactlyJanuary1ReturnsFullQuota(): void
    {
        $r = $this->svc->computeProratedQuota(
            $this->employee('2026-01-01'),
            $this->leaveType(12),
            2026
        );

        $this->assertSame(12.0, $r['quota']);
        $this->assertSame(12, $r['months_worked']);
    }

    // ─── Join setelah tahun ─────────────────────────────────────────

    public function testJoinAfterYearReturnsZero(): void
    {
        $r = $this->svc->computeProratedQuota(
            $this->employee('2027-01-01'),
            $this->leaveType(12),
            2026
        );

        $this->assertSame(0.0, $r['quota']);
        $this->assertSame(0.0, $r['prorate_factor']);
        $this->assertSame(0, $r['months_worked']);
    }

    // ─── Mid-year prorate (data driven) ─────────────────────────────

    /**
     * @return array<string, array{0: string,1: int, 2: int, 3: float, 4: int}>
     */
    public static function midYearProvider(): array
    {
        // [joinDate, year, defaultDays, expectedQuota, expectedMonthsWorked]
        // Formula: monthsWorked = 12 - month + 1; kalau day > 15 maka -1
        return [
            // Mei 1 2026 → 12-5+1 = 8 bulan kerja → 8/12 × 12 = 8 hari
            'mei 1, 12 hari'      => ['2026-05-01', 2026, 12, 8.0, 8],
            // Mei 16 2026 → 16 > 15 → 8-1 = 7 bulan → 7/12 × 12 = 7 hari
            'mei 16, 12 hari'     => ['2026-05-16', 2026, 12, 7.0, 7],
            // Juni 1 2026 → 12-6+1 = 7 bulan → 7/12 × 12 = 7 hari
            'juni 1, 12 hari'     => ['2026-06-01', 2026, 12, 7.0, 7],
            // Juli 15 2026 → 15 ≤ 15 → 6 bulan → 6/12 × 12 = 6 hari
            'juli 15, 12 hari'    => ['2026-07-15', 2026, 12, 6.0, 6],
            // Juli 16 2026 → 16 > 15 → 5 bulan → 5/12 × 12 = 5 hari
            'juli 16, 12 hari'    => ['2026-07-16', 2026, 12, 5.0, 5],
            // Desember 1 2026 → 12-12+1 = 1 bulan → 1/12 × 12 = 1 hari
            'des 1, 12 hari'      => ['2026-12-01', 2026, 12, 1.0, 1],
            // Desember 31 2026 → 31 > 15 → 0 bulan → 0
            'des 31, 12 hari'     => ['2026-12-31', 2026, 12, 0.0, 0],
            // Default 18 hari, mid June: 7/12 × 18 = 10.5
            'juni 1, 18 hari'     => ['2026-06-01', 2026, 18, 10.5, 7],
            // Default 6 hari, mid March: 10/12 × 6 = 5
            'maret 1, 6 hari'     => ['2026-03-01', 2026, 6, 5.0, 10],
        ];
    }

    #[DataProvider('midYearProvider')]
    public function testMidYearProrate(
        string $joinDate, int $year, int $defaultDays,
        float $expectedQuota, int $expectedMonthsWorked
    ): void {
        $r = $this->svc->computeProratedQuota(
            $this->employee($joinDate),
            $this->leaveType($defaultDays),
            $year
        );

        $this->assertSame($expectedMonthsWorked, $r['months_worked'],
            "monthsWorked salah untuk join {$joinDate}");
        $this->assertEqualsWithDelta($expectedQuota, $r['quota'], 0.05,
            "quota salah untuk join {$joinDate}, default {$defaultDays} hari");
        $this->assertTrue($r['is_prorated']);
    }

    public function testFactorIsRounded(): void
    {
        // 7/12 = 0.58333... → rounded ke 0.5833 (4 desimal)
        $r = $this->svc->computeProratedQuota(
            $this->employee('2026-06-01'),
            $this->leaveType(12),
            2026
        );

        $this->assertSame(0.5833, $r['prorate_factor']);
    }

    public function testQuotaRoundedTo1Decimal(): void
    {
        // 7/12 × 18 = 10.5 → tidak ada rounding issue
        // 7/12 × 13 = 7.5833... → rounded ke 7.6
        $r = $this->svc->computeProratedQuota(
            $this->employee('2026-06-01'),
            $this->leaveType(13),
            2026
        );

        $this->assertSame(7.6, $r['quota']);
    }
}