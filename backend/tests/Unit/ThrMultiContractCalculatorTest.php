<?php

namespace Tests\Unit;

use App\Libraries\ThrMultiContractCalculator;
use PHPUnit\Framework\TestCase;

/**
 * ThrMultiContractCalculatorTest — Sprint C+1 / C2.
 *
 * Verifikasi pure function compute() untuk berbagai skenario multi-contract.
 *
 * Holiday di-skenarioin di tengah tahun (2026-05-15 sebagai proxy Lebaran).
 * Calculator tidak peduli tanggal holiday spesifik, hanya year window.
 */
final class ThrMultiContractCalculatorTest extends TestCase
{
    private ThrMultiContractCalculator $calc;

    private const HOLIDAY = '2026-05-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new ThrMultiContractCalculator();
    }

    public function testNoContractReturnsNotEligible(): void
    {
        $r = $this->calc->compute([], self::HOLIDAY);
        $this->assertFalse($r['eligible']);
        $this->assertSame('no_contract', $r['reason']);
        $this->assertSame(0.0, $r['thr_amount']);
    }

    public function testFullYearTenureGetsFullThr(): void
    {
        // Kontrak start 2025-01-01, ongoing — saat holiday 2026-05-15:
        // tenure window = 2026-01-01 s/d 2026-05-15 ≈ 4.5 bulan
        // Tapi kalau di-merge kontinyu, segment terakhir tetap ≈ 4.5 bulan
        // (window dibatasi ke yearStart-yearEnd).
        // Karena single segment, eligible (≥ 1 bulan), proporsional 4.5/12 × salary.
        $contracts = [
            ['start_date' => '2025-01-01', 'end_date' => null, 'basic_salary' => 6_000_000],
        ];

        $r = $this->calc->compute($contracts, self::HOLIDAY);

        $this->assertTrue($r['eligible']);
        $this->assertEqualsWithDelta(4.5333, $r['tenure_months_in_year'], 0.05);
        $this->assertEqualsWithDelta(2_266_667, $r['thr_amount'], 50_000);
        $this->assertSame(6_000_000.0, $r['basis_salary']);
        $this->assertCount(1, $r['contract_segments']);
    }

    public function testKaryawanResignSebelumHolidayNotEligibleKalauTenureKurangSebulan(): void
    {
        // Kontrak hanya 2026-05-01 → 2026-05-10 (10 hari ≈ 0.33 bulan)
        $contracts = [
            ['start_date' => '2026-05-01', 'end_date' => '2026-05-10', 'basic_salary' => 5_000_000],
        ];

        $r = $this->calc->compute($contracts, self::HOLIDAY);

        $this->assertFalse($r['eligible']);
        $this->assertSame('tenure_below_minimum', $r['reason']);
        $this->assertLessThan(1.0, $r['tenure_months_in_year']);
    }

    public function testRenewalContinuousMergedAsOne(): void
    {
        // PKWT renewal: kontrak A end 2026-03-31, kontrak B start 2026-04-01.
        // Gap 1 hari = renewal kontinyu. Window holiday Mei: total 4.5 bulan.
        $contracts = [
            ['start_date' => '2026-01-01', 'end_date' => '2026-03-31', 'basic_salary' => 5_000_000],
            ['start_date' => '2026-04-01', 'end_date' => null,         'basic_salary' => 5_500_000], // gaji naik
        ];

        $r = $this->calc->compute($contracts, self::HOLIDAY);

        $this->assertTrue($r['eligible']);
        // Single merged segment dari Jan 1 sampai holiday
        $this->assertCount(1, $r['contract_segments']);
        $this->assertSame('2026-01-01', $r['contract_segments'][0]['start']);
        $this->assertSame('2026-05-15', $r['contract_segments'][0]['end']);
        // Basis salary pakai yang terbaru (5.5jt)
        $this->assertSame(5_500_000.0, $r['basis_salary']);
        $this->assertEqualsWithDelta(4.5, $r['tenure_months_in_year'], 0.1);
    }

    public function testResignRehireInSameYearOnlyUsesActiveSegment(): void
    {
        // Skenario kunci dari audit C2: resign Maret, rehire September.
        // Holiday Lebaran (Mei): hanya kontrak pertama yang aktif → 5 bulan.
        // Holiday Natal (Desember): hanya kontrak kedua yang aktif → ~4 bulan.
        $contracts = [
            ['start_date' => '2026-01-01', 'end_date' => '2026-03-31', 'basic_salary' => 5_000_000],
            ['start_date' => '2026-09-01', 'end_date' => null,         'basic_salary' => 7_000_000], // rehire dengan salary baru
        ];

        // THR Lebaran (Mei): hanya kontrak pertama yang punya overlap
        $rLebaran = $this->calc->compute($contracts, '2026-05-15');
        $this->assertTrue($rLebaran['eligible']);
        $this->assertCount(1, $rLebaran['contract_segments']);
        $this->assertSame('2026-01-01', $rLebaran['contract_segments'][0]['start']);
        $this->assertSame('2026-03-31', $rLebaran['contract_segments'][0]['end']);
        $this->assertSame(5_000_000.0, $rLebaran['basis_salary']);

        // THR Natal (Desember): kedua kontrak masuk ke segments,
        // tapi hanya segment terakhir (post-rehire) yang dipakai untuk tenure
        $rNatal = $this->calc->compute($contracts, '2026-12-25');
        $this->assertTrue($rNatal['eligible']);
        $this->assertCount(2, $rNatal['contract_segments']); // tidak ke-merge (gap > 1 hari)
        $this->assertSame(7_000_000.0, $rNatal['basis_salary']);
        // Tenure untuk THR adalah kontrak aktif terakhir (Sep 1 - Dec 25 ≈ 3.87 bulan)
        $this->assertEqualsWithDelta(3.87, $rNatal['tenure_months_in_year'], 0.1);
        // THR proporsional: ~3.87/12 × 7jt
        $this->assertEqualsWithDelta(2_256_667, $rNatal['thr_amount'], 100_000);
    }

    public function testContractFullyBeforeYearWindowSkipped(): void
    {
        // Kontrak full di 2025 (sebelum tahun holiday 2026)
        $contracts = [
            ['start_date' => '2024-01-01', 'end_date' => '2025-12-31', 'basic_salary' => 4_000_000],
        ];

        $r = $this->calc->compute($contracts, self::HOLIDAY);
        $this->assertFalse($r['eligible']);
        $this->assertSame('no_overlap_with_year', $r['reason']);
    }

    public function testContractFullyAfterHolidaySkipped(): void
    {
        // Kontrak start setelah holiday
        $contracts = [
            ['start_date' => '2026-06-01', 'end_date' => '2026-12-31', 'basic_salary' => 5_000_000],
        ];

        $r = $this->calc->compute($contracts, self::HOLIDAY);
        $this->assertFalse($r['eligible']);
        $this->assertSame('no_overlap_with_year', $r['reason']);
    }

    public function testTenureExactlyOneMonthEligible(): void
    {
        // Tepat 1 bulan: 2026-04-15 sampai 2026-05-15 = 31 hari ≈ 1.03 bulan
        $contracts = [
            ['start_date' => '2026-04-15', 'end_date' => null, 'basic_salary' => 6_000_000],
        ];

        $r = $this->calc->compute($contracts, self::HOLIDAY);
        $this->assertTrue($r['eligible']);
        $this->assertGreaterThanOrEqual(1.0, $r['tenure_months_in_year']);
        // Proporsional
        $this->assertGreaterThan(0, $r['thr_amount']);
        $this->assertLessThan(6_000_000.0, $r['thr_amount']);
    }

    public function testTwelveMonthsOrMoreCapsAtFullSalary(): void
    {
        // Kontrak start 2024 atau lebih awal → masih aktif di 2026
        // Window 2026-01-01 → 2026-05-15 = 4.5 bulan (cap di window),
        // tapi karena calculator dipakai untuk THR, year window memang dibatasi.
        // Untuk skenario "≥ 12 bulan" murni, holiday harus di akhir tahun.
        $contracts = [
            ['start_date' => '2023-01-01', 'end_date' => null, 'basic_salary' => 8_000_000],
        ];

        // Holiday Natal: window 11.83 bulan → cap di 12 bulan = 1× salary
        $r = $this->calc->compute($contracts, '2026-12-25');
        $this->assertTrue($r['eligible']);
        $this->assertGreaterThanOrEqual(11.5, $r['tenure_months_in_year']);
        // Proporsional, mendekati 1× karena ~11.83 bulan
        $this->assertGreaterThan(7_500_000, $r['thr_amount']);
        $this->assertLessThanOrEqual(8_000_000, $r['thr_amount']);
    }

    public function testGapExactlyOneDayIsRenewal(): void
    {
        // Gap = 1 hari → seharusnya merged (RENEWAL_GAP_DAYS = 1)
        $contracts = [
            ['start_date' => '2026-01-01', 'end_date' => '2026-02-28', 'basic_salary' => 5_000_000],
            ['start_date' => '2026-03-01', 'end_date' => null,         'basic_salary' => 5_000_000],
        ];

        $r = $this->calc->compute($contracts, self::HOLIDAY);
        $this->assertCount(1, $r['contract_segments'], 'Gap 1 hari harus di-merge');
    }

    public function testGapTwoDaysNotRenewal(): void
    {
        // Gap = 2 hari → resign-rehire, segment terpisah
        $contracts = [
            ['start_date' => '2026-01-01', 'end_date' => '2026-02-28', 'basic_salary' => 5_000_000],
            ['start_date' => '2026-03-03', 'end_date' => null,         'basic_salary' => 5_000_000],
        ];

        $r = $this->calc->compute($contracts, self::HOLIDAY);
        $this->assertCount(2, $r['contract_segments']);
    }

    public function testEmptyEndDateTreatedAsOngoing(): void
    {
        // Kasus end_date '' atau '0000-00-00' (legacy data)
        $contracts = [
            ['start_date' => '2026-02-01', 'end_date' => '0000-00-00', 'basic_salary' => 5_000_000],
        ];

        $r = $this->calc->compute($contracts, self::HOLIDAY);
        $this->assertTrue($r['eligible']);
    }
}