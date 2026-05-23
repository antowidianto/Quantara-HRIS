<?php

namespace Tests\Unit;

use App\Libraries\BpjsCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * BpjsCalculatorTest — verifikasi perhitungan iuran BPJS pakai
 * pure method `compute()` (tidak butuh DB).
 *
 * Default rates (lihat `BpjsCalculator::defaultRates()`):
 *  - Kesehatan: 1% emp + 4% com, plafon 12 jt
 *  - JHT: 2% emp + 3.7% com, no cap
 *  - JP:  1% emp + 2% com, plafon 10.547.400
 *  - JKK: 0.24% com only (default risiko rendah)
 *  - JKM: 0.3% com only
 *  - JKP: 0.46% com only, plafon 5 jt (Sprint 4)
 *
 * Run: vendor/bin/phpunit --filter BpjsCalculatorTest
 */
class BpjsCalculatorTest extends TestCase
{
    private BpjsCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new BpjsCalculator();
    }

    // ─── Default rates table ────────────────────────────────────────

    public function testDefaultRatesContainsAllRequiredKeys(): void
    {
        $rates = BpjsCalculator::defaultRates();
        $this->assertArrayHasKey('kes_employee_rate', $rates);
        $this->assertArrayHasKey('jht_company_rate', $rates);
        $this->assertArrayHasKey('jp_max_salary', $rates);
        $this->assertArrayHasKey('jkk_company_rate', $rates);
        $this->assertArrayHasKey('jkm_company_rate', $rates);
        $this->assertArrayHasKey('jkp_company_rate', $rates);  // Sprint 4
        $this->assertArrayHasKey('jkp_max_salary', $rates);    // Sprint 4
    }

    public function testJkpRateIsZeroPoint46Percent(): void
    {
        $rates = BpjsCalculator::defaultRates();
        $this->assertSame(0.0046, (float) $rates['jkp_company_rate']);
        $this->assertSame(5000000.0, (float) $rates['jkp_max_salary']);
    }

    // ─── Standard scenario UMR Jakarta ──────────────────────────────

    public function testUmpJakartaSalaryAllComponents(): void
    {
        // UMP DKI Jakarta 2026 ~ 5.396.760, di bawah semua plafon
        $r = $this->calc->compute(5_396_760);

        // Kesehatan: 5.396.760 × 1% / 4%
        $this->assertEqualsWithDelta(53968,  $r['kes_employee'], 1);
        $this->assertEqualsWithDelta(215870, $r['kes_company'], 1);

        // JHT: full salary × 2% / 3.7%
        $this->assertEqualsWithDelta(107935, $r['jht_employee'], 1);
        $this->assertEqualsWithDelta(199680, $r['jht_company'], 1);

        // JP: 5.396.760 < plafon 10.547.400 → full salary × 1% / 2%
        $this->assertEqualsWithDelta(53968,  $r['jp_employee'], 1);
        $this->assertEqualsWithDelta(107935, $r['jp_company'], 1);

        // JKK: 0.24%; JKM: 0.3%
        $this->assertEqualsWithDelta(12952,  $r['jkk_company'], 1);
        $this->assertEqualsWithDelta(16190,  $r['jkm_company'], 1);

        // JKP: salary > plafon 5jt → cap di 5jt × 0.46% = 23.000
        $this->assertEqualsWithDelta(23000,  $r['jkp_company'], 1);
    }

    // ─── Plafon Kesehatan (12 jt) ───────────────────────────────────

    public function testKesehatanCapsAtTwelveMillion(): void
    {
        // Salary 20 jt — kes harus di-cap di 12 jt
        $r = $this->calc->compute(20_000_000);
        $this->assertEqualsWithDelta(120_000, $r['kes_employee'], 1);   // 12jt × 1%
        $this->assertEqualsWithDelta(480_000, $r['kes_company'], 1);    // 12jt × 4%
    }

    public function testKesehatanBelowCap(): void
    {
        $r = $this->calc->compute(8_000_000);
        $this->assertEqualsWithDelta(80_000,  $r['kes_employee'], 1);
        $this->assertEqualsWithDelta(320_000, $r['kes_company'], 1);
    }

    // ─── Plafon JP (10.547.400) ─────────────────────────────────────

    public function testJpCapsAtPmkPlafond(): void
    {
        // Salary 15 jt — JP di-cap
        $r = $this->calc->compute(15_000_000);
        $this->assertEqualsWithDelta(105_474, $r['jp_employee'], 1);   // 10.547.400 × 1%
        $this->assertEqualsWithDelta(210_948, $r['jp_company'], 1);    // 10.547.400 × 2%
    }

    public function testJpExactlyAtPlafond(): void
    {
        $r = $this->calc->compute(10_547_400);
        $this->assertEqualsWithDelta(105_474, $r['jp_employee'], 1);
        $this->assertEqualsWithDelta(210_948, $r['jp_company'], 1);
    }

    // ─── JHT no cap ─────────────────────────────────────────────────

    public function testJhtHasNoCap(): void
    {
        // Salary 30 jt — JHT tetap full salary
        $r = $this->calc->compute(30_000_000);
        $this->assertEqualsWithDelta(600_000,   $r['jht_employee'], 1);  // 30jt × 2%
        $this->assertEqualsWithDelta(1_110_000, $r['jht_company'], 1);   // 30jt × 3.7%
    }

    // ─── JKP Sprint 4 ──────────────────────────────────────────────

    public function testJkpBelowPlafond(): void
    {
        // Salary 3 jt < plafon 5 jt → 3jt × 0.46% = 13.800
        $r = $this->calc->compute(3_000_000);
        $this->assertEqualsWithDelta(13_800, $r['jkp_company'], 1);
    }

    public function testJkpAtPlafond(): void
    {
        // Salary 5 jt = plafon → 5jt × 0.46% = 23.000
        $r = $this->calc->compute(5_000_000);
        $this->assertEqualsWithDelta(23_000, $r['jkp_company'], 1);
    }

    public function testJkpCapsAtFiveMillion(): void
    {
        // Salary 8 jt > plafon 5 jt → di-cap = 23.000
        $r = $this->calc->compute(8_000_000);
        $this->assertEqualsWithDelta(23_000, $r['jkp_company'], 1);
    }

    /**
     * @return array<string, array{0: string, 1: float}>
     */
    public static function jkpEmploymentStatusProvider(): array
    {
        // [employmentStatus, expectedJkpAmount]
        return [
            'PKWTT eligible'         => ['PKWTT',       23_000.0],
            'PKWT eligible'          => ['PKWT',        23_000.0],
            'pkwtt lowercase'        => ['pkwtt',       23_000.0],
            'MAGANG not eligible'    => ['MAGANG',      0.0],
            'OUTSOURCING not elig.'  => ['OUTSOURCING', 0.0],
            'HARIAN not eligible'    => ['HARIAN',      0.0],
            'null = include'         => [null,          23_000.0],
        ];
    }

    #[DataProvider('jkpEmploymentStatusProvider')]
    public function testJkpEligibilityByEmploymentStatus(?string $status, float $expected): void
    {
        $r = $this->calc->compute(5_000_000, [], $status);
        $this->assertEqualsWithDelta($expected, $r['jkp_company'], 1,
            "JKP untuk status '{$status}' harus = {$expected}");
    }

    // ─── Custom rates override ──────────────────────────────────────

    public function testCustomRatesOverride(): void
    {
        // Perusahaan dengan tingkat risiko tinggi: JKK 1.74%
        $r = $this->calc->compute(10_000_000, ['jkk_company_rate' => 0.0174]);
        $this->assertEqualsWithDelta(174_000, $r['jkk_company'], 1);
    }

    public function testPartialOverrideMergesWithDefaults(): void
    {
        // Hanya override JKK; default lainnya harus tetap berlaku
        $r = $this->calc->compute(5_000_000, ['jkk_company_rate' => 0.005]);
        $this->assertEqualsWithDelta(50_000, $r['kes_employee'], 1);   // default 1%
        $this->assertEqualsWithDelta(25_000, $r['jkk_company'], 1);    // override 0.5%
    }

    // ─── Totals ─────────────────────────────────────────────────────

    public function testEmployeeTotalSumsKesAndJhtAndJp(): void
    {
        $r = $this->calc->compute(5_000_000);
        $expectedTotal = $r['kes_employee'] + $r['jht_employee'] + $r['jp_employee'];
        $this->assertEqualsWithDelta($expectedTotal, $r['employee_total'], 1);
    }

    public function testCompanyTotalIncludesJkp(): void
    {
        // Sprint 4 — JKP harus included di company_total
        $r = $this->calc->compute(5_000_000, [], 'PKWTT');
        $expected = $r['kes_company'] + $r['jht_company'] + $r['jp_company']
                  + $r['jkk_company'] + $r['jkm_company'] + $r['jkp_company'];
        $this->assertEqualsWithDelta($expected, $r['company_total'], 1);
    }

    public function testCompanyTotalExcludesJkpForMagang(): void
    {
        // MAGANG: JKP = 0, jadi total tidak include JKP
        $r = $this->calc->compute(5_000_000, [], 'MAGANG');
        $this->assertSame(0.0, $r['jkp_company']);
    }

    // ─── Edge cases ─────────────────────────────────────────────────

    public function testZeroSalaryReturnsZeros(): void
    {
        $r = $this->calc->compute(0);
        $this->assertSame(0.0, $r['kes_employee']);
        $this->assertSame(0.0, $r['jkp_company']);
        $this->assertSame(0.0, $r['employee_total']);
        $this->assertSame(0.0, $r['company_total']);
    }

    public function testRoundingProducesIntegerAmounts(): void
    {
        $r = $this->calc->compute(5_396_760);
        foreach ($r as $key => $amount) {
            $this->assertSame((float) (int) $amount, $amount,
                "Component '{$key}' harus integer-rounded, dapat {$amount}");
        }
    }
}