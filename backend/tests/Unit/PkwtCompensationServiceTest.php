<?php

namespace Tests\Unit;

use App\Libraries\Lifecycle\PkwtCompensationService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk PkwtCompensationService (H1.12).
 *
 * Yang di-test: pure helper functions yang tidak butuh DB:
 *  - daysBetween() — kalkulasi durasi inklusif Y-m-d
 *  - accumulatedDaysPure() — sum durasi multi-kontrak
 *  - tenureMonthsPure() — kalkulasi bulan kalender
 *  - calculatePure() — formula PP 35/2021 Pasal 16
 *
 * Run: vendor/bin/phpunit --filter PkwtCompensationServiceTest
 */
class PkwtCompensationServiceTest extends TestCase
{
    // ─── daysBetween ─────────────────────────────────────────────────

    public function testDaysBetweenSameDayReturnsOne(): void
    {
        $this->assertSame(1, PkwtCompensationService::daysBetween('2025-01-01', '2025-01-01'));
    }

    public function testDaysBetweenInclusiveRange(): void
    {
        // 1 Jan – 31 Jan = 31 hari (inklusif)
        $this->assertSame(31, PkwtCompensationService::daysBetween('2025-01-01', '2025-01-31'));
    }

    public function testDaysBetweenFullYearLeapAware(): void
    {
        // 2024 = leap year (366 hari inklusif)
        $this->assertSame(366, PkwtCompensationService::daysBetween('2024-01-01', '2024-12-31'));
        // 2025 = bukan leap (365 hari)
        $this->assertSame(365, PkwtCompensationService::daysBetween('2025-01-01', '2025-12-31'));
    }

    public function testDaysBetweenInvalidReturnsZero(): void
    {
        $this->assertSame(0, PkwtCompensationService::daysBetween('', '2025-01-01'));
        $this->assertSame(0, PkwtCompensationService::daysBetween('2025-01-01', ''));
        // Reversed range
        $this->assertSame(0, PkwtCompensationService::daysBetween('2025-12-31', '2025-01-01'));
    }

    // ─── accumulatedDaysPure ─────────────────────────────────────────

    public function testAccumulatedDaysSumsMultipleContracts(): void
    {
        $contracts = [
            ['start_date' => '2024-01-01', 'end_date' => '2024-12-31'], // 366 (leap)
            ['start_date' => '2025-01-01', 'end_date' => '2025-12-31'], // 365
            ['start_date' => '2026-01-01', 'end_date' => '2026-06-30'], // 181
        ];
        $this->assertSame(366 + 365 + 181, PkwtCompensationService::accumulatedDaysPure($contracts));
    }

    public function testAccumulatedDaysIgnoresInvalidEntries(): void
    {
        $contracts = [
            ['start_date' => '2025-01-01', 'end_date' => '2025-12-31'],
            ['start_date' => '', 'end_date' => '2025-12-31'], // skip
            ['start_date' => '2026-01-01', 'end_date' => '2026-01-01'], // 1 day
        ];
        $this->assertSame(365 + 1, PkwtCompensationService::accumulatedDaysPure($contracts));
    }

    public function testAccumulatedDaysExceedsFiveYearThreshold(): void
    {
        // 5 kontrak setahun penuh + 1 hari → > 5 tahun (1825 hari)
        $contracts = [];
        for ($i = 0; $i < 5; $i++) {
            $contracts[] = [
                'start_date' => sprintf('%d-01-01', 2025 + $i),
                'end_date'   => sprintf('%d-12-31', 2025 + $i),
            ];
        }
        $total = PkwtCompensationService::accumulatedDaysPure($contracts);
        // 4 tahun reguler (365×4) + 1 leap year (366) = 1826
        $this->assertGreaterThan(PkwtCompensationService::MAX_PKWT_DAYS, $total);
    }

    // ─── tenureMonthsPure ────────────────────────────────────────────

    public function testTenureMonthsExactOneYear(): void
    {
        $this->assertSame(12, PkwtCompensationService::tenureMonthsPure('2025-01-15', '2026-01-15'));
    }

    public function testTenureMonthsAlmostOneYear(): void
    {
        // 1 hari kurang 1 tahun → 11 bulan (di-floor)
        $this->assertSame(11, PkwtCompensationService::tenureMonthsPure('2025-01-15', '2026-01-14'));
    }

    public function testTenureMonthsLessThanOneMonth(): void
    {
        $this->assertSame(0, PkwtCompensationService::tenureMonthsPure('2025-03-10', '2025-03-25'));
    }

    public function testTenureMonthsInvalidInputs(): void
    {
        $this->assertSame(0, PkwtCompensationService::tenureMonthsPure('', '2025-12-31'));
        $this->assertSame(0, PkwtCompensationService::tenureMonthsPure('2025-12-31', '2025-01-01'));
    }

    // ─── calculatePure (Formula PP 35/2021) ─────────────────────────

    public function testCalculatePureBelowOneMonthReturnsZero(): void
    {
        $this->assertSame(0.0, PkwtCompensationService::calculatePure(0, 5_000_000.0));
    }

    public function testCalculatePureExactly12Months(): void
    {
        // Masa kerja 12 bulan, upah 5jt → kompensasi = 1 × 5jt = 5jt
        $this->assertSame(5_000_000.0, PkwtCompensationService::calculatePure(12, 5_000_000.0));
    }

    public function testCalculatePureSixMonths(): void
    {
        // Masa kerja 6 bulan, upah 6jt → 6/12 × 6jt = 3jt
        $this->assertSame(3_000_000.0, PkwtCompensationService::calculatePure(6, 6_000_000.0));
    }

    public function testCalculatePureTwoYearContract(): void
    {
        // 24 bulan × 4jt → 2 × 4jt = 8jt
        $this->assertSame(8_000_000.0, PkwtCompensationService::calculatePure(24, 4_000_000.0));
    }

    public function testCalculatePureZeroSalaryReturnsZero(): void
    {
        $this->assertSame(0.0, PkwtCompensationService::calculatePure(12, 0.0));
        $this->assertSame(0.0, PkwtCompensationService::calculatePure(12, -100.0));
    }

    public function testCalculatePureRoundsToTwoDecimals(): void
    {
        // 5 bulan × 3.333.333,33 → 5/12 × 3333333.33 ≈ 1388888.89
        $result = PkwtCompensationService::calculatePure(5, 3_333_333.33);
        $this->assertEqualsWithDelta(1_388_888.89, $result, 0.01);
    }

    // ─── MAX_PKWT_DAYS Constant ─────────────────────────────────────

    public function testMaxPkwtDaysIsFiveYears(): void
    {
        // 5 tahun = 1825 hari (5 × 365), per regulasi PP 35/2021
        $this->assertSame(1825, PkwtCompensationService::MAX_PKWT_DAYS);
    }
}