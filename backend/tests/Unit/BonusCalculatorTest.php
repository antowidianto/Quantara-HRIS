<?php

namespace Tests\Unit;

use App\Libraries\Payroll\BonusCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit test untuk BonusCalculator (Sprint 16+).
 *
 * Pure-function — tidak butuh DB, fokus ke:
 *   - 6 formula type (FIXED_AMOUNT, PCT_OF_BASIC, PCT_OF_GROSS, TENURE_BASED,
 *     KPI_SCORE, FORMULA_EXPR)
 *   - cap_amount enforcement
 *   - Eligibility check (department/branch/grade/position whitelist,
 *     min_tenure_months, exclude_resigned, employment_status)
 *   - tenureMonths boundary (resign mid-period, join after reference, dst)
 *   - FORMULA_EXPR security: tidak boleh execute fungsi PHP atau ekspresi
 *     berbahaya (defensive parser)
 */
class BonusCalculatorTest extends TestCase
{
    private const REF_DATE = '2026-12-31';

    /* ──────────────────────────────────────────────────────────────────
     * Formula types
     * ──────────────────────────────────────────────────────────────── */

    public function testFixedAmount(): void
    {
        $scheme = ['formula_type' => 'FIXED_AMOUNT', 'formula_value' => 5_000_000];
        $emp = ['basic_salary' => 10_000_000, 'join_date' => '2025-01-01'];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        $this->assertSame(5_000_000.0, $r['amount']);
        $this->assertFalse($r['capped']);
    }

    public function testPctOfBasic(): void
    {
        $scheme = ['formula_type' => 'PCT_OF_BASIC', 'formula_value' => 50];
        $emp = ['basic_salary' => 12_000_000, 'join_date' => '2024-01-01'];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        $this->assertSame(6_000_000.0, $r['amount']);
    }

    public function testPctOfGrossIncludesAllowance(): void
    {
        $scheme = ['formula_type' => 'PCT_OF_GROSS', 'formula_value' => 25];
        $emp = ['basic_salary' => 8_000_000, 'fixed_allowance' => 2_000_000, 'join_date' => '2025-01-01'];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        $this->assertSame(2_500_000.0, $r['amount']); // 25% × 10jt
    }

    public function testTenureBased(): void
    {
        // 100k per tahun masa kerja, tenure ~2 tahun
        $scheme = ['formula_type' => 'TENURE_BASED', 'formula_value' => 100_000];
        $emp = ['basic_salary' => 10_000_000, 'join_date' => '2024-12-31'];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        // Tenure ~24 bulan ÷ 12 = 2 tahun → 200k
        $this->assertGreaterThanOrEqual(180_000.0, $r['amount']);
        $this->assertLessThanOrEqual(220_000.0, $r['amount']);
    }

    public function testKpiScoreMultiplier(): void
    {
        $scheme = ['formula_type' => 'KPI_SCORE', 'formula_value' => 50]; // 50% basic × kpi
        $emp = [
            'basic_salary' => 10_000_000,
            'join_date'    => '2024-01-01',
            'kpi_score'    => 1.5, // 150% performance
        ];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        // 10jt × 50% × 1.5 = 7.5jt
        $this->assertSame(7_500_000.0, $r['amount']);
    }

    public function testKpiScoreClampedToMaxTwo(): void
    {
        $scheme = ['formula_type' => 'KPI_SCORE', 'formula_value' => 50];
        $emp = [
            'basic_salary' => 10_000_000,
            'join_date'    => '2024-01-01',
            'kpi_score'    => 5.0, // out of range, should clamp ke 2.0
        ];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        $this->assertSame(10_000_000.0, $r['amount']); // 10jt × 50% × 2.0
    }

    public function testFormulaExprBasicMath(): void
    {
        $scheme = [
            'formula_type' => 'FORMULA_EXPR',
            'formula_expr' => 'basic * 0.5 + tenure_months * 100000',
        ];
        $emp = ['basic_salary' => 10_000_000, 'join_date' => '2024-01-01']; // ~24 bulan
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        // basic*0.5 = 5jt; tenure_months × 100k ~ 2.4jt; total ~7.4jt
        $this->assertGreaterThan(6_000_000, $r['amount']);
        $this->assertLessThan(9_000_000, $r['amount']);
    }

    public function testFormulaExprWithGrouping(): void
    {
        $scheme = [
            'formula_type' => 'FORMULA_EXPR',
            'formula_expr' => '(basic + 1000000) * 0.1',
        ];
        $emp = ['basic_salary' => 9_000_000, 'join_date' => '2024-01-01'];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        $this->assertSame(1_000_000.0, $r['amount']); // (9jt + 1jt) × 0.1
    }

    public function testFormulaExprRejectsArbitraryCode(): void
    {
        // Tidak boleh execute fungsi PHP atau ekspresi berbahaya
        $scheme = [
            'formula_type' => 'FORMULA_EXPR',
            'formula_expr' => 'eval("var_dump(1)") + basic',
        ];
        $emp = ['basic_salary' => 10_000_000, 'join_date' => '2024-01-01'];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        // Parser harus reject dan return 0 (defensive)
        $this->assertSame(0.0, $r['amount']);
    }

    public function testFormulaExprRejectsBadSyntax(): void
    {
        $scheme = [
            'formula_type' => 'FORMULA_EXPR',
            'formula_expr' => 'basic +++ ((',
        ];
        $emp = ['basic_salary' => 10_000_000, 'join_date' => '2024-01-01'];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        $this->assertSame(0.0, $r['amount']);
    }

    public function testNegativeAmountClampedToZero(): void
    {
        $scheme = [
            'formula_type' => 'FORMULA_EXPR',
            'formula_expr' => 'basic - 100000000', // negative
        ];
        $emp = ['basic_salary' => 5_000_000, 'join_date' => '2024-01-01'];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        $this->assertSame(0.0, $r['amount']);
    }

    /* ──────────────────────────────────────────────────────────────────
     * Cap enforcement
     * ──────────────────────────────────────────────────────────────── */

    public function testCapAmountEnforced(): void
    {
        $scheme = [
            'formula_type'  => 'PCT_OF_BASIC',
            'formula_value' => 200,         // 200% basic
            'cap_amount'    => 5_000_000,   // tapi di-cap 5jt
        ];
        $emp = ['basic_salary' => 10_000_000, 'join_date' => '2024-01-01'];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        $this->assertSame(5_000_000.0, $r['amount']);
        $this->assertTrue($r['capped']);
    }

    public function testNoCapWhenAmountBelowLimit(): void
    {
        $scheme = [
            'formula_type'  => 'FIXED_AMOUNT',
            'formula_value' => 1_000_000,
            'cap_amount'    => 5_000_000,
        ];
        $emp = ['basic_salary' => 10_000_000, 'join_date' => '2024-01-01'];
        $r = BonusCalculator::compute($scheme, $emp, self::REF_DATE);
        $this->assertSame(1_000_000.0, $r['amount']);
        $this->assertFalse($r['capped']);
    }

    /* ──────────────────────────────────────────────────────────────────
     * Tenure
     * ──────────────────────────────────────────────────────────────── */

    public function testTenureFullYear(): void
    {
        $tenure = BonusCalculator::tenureMonths('2025-01-01', null, '2026-01-01');
        // ~12 bulan (1 bulan = 30 hari approx)
        $this->assertGreaterThanOrEqual(11, $tenure);
        $this->assertLessThanOrEqual(13, $tenure);
    }

    public function testTenureJoinAfterReferenceReturnsZero(): void
    {
        $tenure = BonusCalculator::tenureMonths('2027-01-01', null, '2026-12-31');
        $this->assertSame(0, $tenure);
    }

    public function testTenureResignMidPeriodCappedAtResign(): void
    {
        $tenure = BonusCalculator::tenureMonths(
            '2026-01-01',
            '2026-06-30',
            '2026-12-31',
        );
        // Tenure dihitung sampai resign_date, bukan reference
        $this->assertGreaterThanOrEqual(5, $tenure);
        $this->assertLessThanOrEqual(7, $tenure);
    }

    public function testTenureNullJoinReturnsZero(): void
    {
        $this->assertSame(0, BonusCalculator::tenureMonths(null, null, '2026-12-31'));
    }

    /* ──────────────────────────────────────────────────────────────────
     * Eligibility
     * ──────────────────────────────────────────────────────────────── */

    public function testEligibilityNoFiltersAllowsAll(): void
    {
        $check = BonusCalculator::checkEligibility([], [
            'employment_status' => 'PKWTT',
            'department_id'     => 5,
            'join_date'         => '2024-01-01',
        ], self::REF_DATE);
        $this->assertTrue($check['eligible']);
    }

    public function testEligibilityDepartmentFilterMatch(): void
    {
        $check = BonusCalculator::checkEligibility(
            ['department_ids' => [3, 5, 7]],
            ['department_id' => 5, 'join_date' => '2024-01-01'],
            self::REF_DATE,
        );
        $this->assertTrue($check['eligible']);
    }

    public function testEligibilityDepartmentFilterMiss(): void
    {
        $check = BonusCalculator::checkEligibility(
            ['department_ids' => [3, 5]],
            ['department_id' => 99, 'join_date' => '2024-01-01'],
            self::REF_DATE,
        );
        $this->assertFalse($check['eligible']);
        $this->assertStringContainsString('departemen', $check['reason']);
    }

    public function testEligibilityMinTenureGate(): void
    {
        $check = BonusCalculator::checkEligibility(
            ['min_tenure_months' => 12],
            ['join_date' => '2026-06-01'], // < 12 bulan
            self::REF_DATE,
        );
        $this->assertFalse($check['eligible']);
        $this->assertStringContainsString('Tenure', $check['reason']);
    }

    public function testEligibilityExcludeResignedBeforeRef(): void
    {
        $check = BonusCalculator::checkEligibility(
            ['exclude_resigned' => true],
            ['join_date' => '2024-01-01', 'resign_date' => '2026-06-30'],
            self::REF_DATE,
        );
        $this->assertFalse($check['eligible']);
        $this->assertStringContainsString('resign', $check['reason']);
    }

    public function testEligibilityResignAfterRefStillEligible(): void
    {
        $check = BonusCalculator::checkEligibility(
            ['exclude_resigned' => true],
            ['join_date' => '2024-01-01', 'resign_date' => '2027-03-31'],
            self::REF_DATE,
        );
        $this->assertTrue($check['eligible']);
    }

    public function testEligibilityEmploymentStatusWhitelist(): void
    {
        $check = BonusCalculator::checkEligibility(
            ['employment_status' => ['PKWT', 'PKWTT']],
            ['employment_status' => 'MAGANG', 'join_date' => '2024-01-01'],
            self::REF_DATE,
        );
        $this->assertFalse($check['eligible']);
    }

    public function testEligibilityCombinedFilters(): void
    {
        $eligibility = [
            'department_ids'    => [10],
            'min_tenure_months' => 6,
            'employment_status' => ['PKWTT'],
            'exclude_resigned'  => true,
        ];
        // Semua match
        $emp = [
            'department_id'     => 10,
            'employment_status' => 'PKWTT',
            'join_date'         => '2024-01-01',
            'resign_date'       => null,
        ];
        $this->assertTrue(BonusCalculator::checkEligibility($eligibility, $emp, self::REF_DATE)['eligible']);

        // Tenure kurang
        $emp['join_date'] = '2026-09-01';
        $this->assertFalse(BonusCalculator::checkEligibility($eligibility, $emp, self::REF_DATE)['eligible']);
    }

    /* ──────────────────────────────────────────────────────────────────
     * Constants
     * ──────────────────────────────────────────────────────────────── */

    public function testFormulaTypesConstantContainsAllSupportedTypes(): void
    {
        $expected = [
            'FIXED_AMOUNT',
            'PCT_OF_BASIC',
            'PCT_OF_GROSS',
            'TENURE_BASED',
            'KPI_SCORE',
            'FORMULA_EXPR',
        ];
        $this->assertSame($expected, BonusCalculator::FORMULA_TYPES);
    }
}