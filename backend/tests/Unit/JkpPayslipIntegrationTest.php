<?php

namespace Tests\Unit;

use App\Libraries\Payroll\JkpPayslipIntegration;
use PHPUnit\Framework\TestCase;

/**
 * JkpPayslipIntegrationTest — Sprint C+1 / C7.
 *
 * Verifikasi pure-function integrasi JKP ke payslip.
 *
 * Skenario:
 *   - Compute breakdown sesuai PP 37/2021 + PP 35/2021 (cap 5 juta)
 *   - Apply ke payslip row tidak mutate input
 *   - SiPP row format konsisten dengan input context
 */
final class JkpPayslipIntegrationTest extends TestCase
{
    private JkpPayslipIntegration $jkp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jkp = new JkpPayslipIntegration();
    }

    public function testComputeBelowCap(): void
    {
        // Upah 4 juta — di bawah cap 5 juta, jadi wage_base = 4 juta
        $r = $this->jkp->compute(4_000_000);

        $this->assertSame(4_000_000.0, $r['wage_base']);
        // 0.46% × 4 juta = 18.400
        $this->assertSame(18_400.0, $r['total']);
        // 0.22% × 4 juta = 8.800
        $this->assertSame(8_800.0, $r['gov_subsidy']);
        // company net = 18400 - 8800 = 9600
        $this->assertSame(9_600.0, $r['company_net']);
        $this->assertSame(0.0, $r['employee']);
    }

    public function testComputeAtCap(): void
    {
        // Upah tepat cap 5 juta
        $r = $this->jkp->compute(5_000_000);
        $this->assertSame(5_000_000.0, $r['wage_base']);
        $this->assertSame(23_000.0, $r['total']);          // 0.46% × 5jt
        $this->assertSame(11_000.0, $r['gov_subsidy']);    // 0.22% × 5jt
        $this->assertSame(12_000.0, $r['company_net']);    // 0.24% × 5jt
    }

    public function testComputeAboveCapClampsWageBase(): void
    {
        // Upah 12 juta — di atas cap 5 juta, harus pakai wage_base = 5 juta
        $r = $this->jkp->compute(12_000_000);
        $this->assertSame(5_000_000.0, $r['wage_base'], 'wage_base wajib di-clamp ke cap');
        $this->assertSame(23_000.0, $r['total']);
        $this->assertSame(11_000.0, $r['gov_subsidy']);
        $this->assertSame(12_000.0, $r['company_net']);
    }

    public function testComputeZeroWageReturnsZeroes(): void
    {
        $r = $this->jkp->compute(0);
        $this->assertSame(0.0, $r['wage_base']);
        $this->assertSame(0.0, $r['total']);
        $this->assertSame(0.0, $r['gov_subsidy']);
        $this->assertSame(0.0, $r['company_net']);
    }

    public function testComputeNegativeWageTreatedAsZero(): void
    {
        $r = $this->jkp->compute(-500_000);
        $this->assertSame(0.0, $r['total']);
        $this->assertSame(0.0, $r['company_net']);
    }

    public function testApplyToPayslipDoesNotMutateInput(): void
    {
        $original = [
            'employee_id'    => 42,
            'gross_earning'  => 4_500_000,
            'breakdown'      => ['ump_check' => 'ok'],
        ];
        $copy = $original;

        $result = $this->jkp->applyToPayslip($original, 4_500_000);

        // Original tidak berubah (PHP array semantics by-value)
        $this->assertSame($copy, $original);

        // Result punya field JKP
        $this->assertArrayHasKey('bpjs_jkp_company', $result);
        $this->assertGreaterThan(0, $result['bpjs_jkp_company']);

        // Breakdown lama tetap, plus key 'jkp' baru
        $this->assertArrayHasKey('ump_check', $result['breakdown']);
        $this->assertArrayHasKey('jkp', $result['breakdown']);
        $this->assertSame(false, $result['breakdown']['jkp']['cap_applied']); // 4.5jt < 5jt
    }

    public function testApplyToPayslipWithJsonStringBreakdownGetsParsed(): void
    {
        $payslip = [
            'breakdown' => json_encode(['existing' => 'data']),
        ];

        $result = $this->jkp->applyToPayslip($payslip, 6_000_000);

        $this->assertIsArray($result['breakdown']);
        $this->assertSame('data', $result['breakdown']['existing']);
        $this->assertArrayHasKey('jkp', $result['breakdown']);
        $this->assertTrue($result['breakdown']['jkp']['cap_applied']); // 6jt > 5jt cap
    }

    public function testApplyToPayslipMissingBreakdownInitializesEmpty(): void
    {
        $payslip = ['employee_id' => 1];
        $result = $this->jkp->applyToPayslip($payslip, 3_000_000);

        $this->assertIsArray($result['breakdown']);
        $this->assertArrayHasKey('jkp', $result['breakdown']);
    }

    public function testBuildSippRowUsesEmployeeContext(): void
    {
        $row = $this->jkp->buildSippRow([
            'employee_id'  => 100,
            'bpjs_tk_no'   => '0000-1234-5678',
            'nik'          => '3275012345678901',
            'full_name'    => 'Budi Santoso',
            'monthly_wage' => 4_000_000,
        ]);

        $this->assertSame('0000-1234-5678', $row['bpjs_tk_no']);
        $this->assertSame('Budi Santoso', $row['name']);
        $this->assertSame(4_000_000.0, $row['wage_base']);
        $this->assertSame(18_400.0, $row['total_iuran']);
        $this->assertSame(8_800.0, $row['gov_subsidy']);
        $this->assertSame(9_600.0, $row['company_paid']);
    }

    public function testBuildSippRowToleratesMissingFields(): void
    {
        // Mis. kandidat tanpa BPJS TK number — harus tidak crash
        $row = $this->jkp->buildSippRow([
            'monthly_wage' => 0,
        ]);

        $this->assertSame('', $row['bpjs_tk_no']);
        $this->assertSame('', $row['nik']);
        $this->assertSame('', $row['name']);
        $this->assertSame(0.0, $row['total_iuran']);
    }

    public function testRateConstantsMatchPP37And35(): void
    {
        // Sanity check konstanta sesuai regulasi
        $this->assertSame(5_000_000.0, JkpPayslipIntegration::WAGE_CAP);
        $this->assertEqualsWithDelta(0.0046, JkpPayslipIntegration::RATE_TOTAL, 1e-10);
        $this->assertEqualsWithDelta(0.0022, JkpPayslipIntegration::RATE_GOV_SUBSIDY, 1e-10);
        $this->assertEqualsWithDelta(0.0024, JkpPayslipIntegration::RATE_COMPANY_NET, 1e-10);
    }
}