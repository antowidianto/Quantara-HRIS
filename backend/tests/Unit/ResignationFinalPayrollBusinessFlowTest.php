<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Lifecycle\OffboardingService;
use App\Libraries\Lifecycle\ResignationThrEligibility;
use PHPUnit\Framework\TestCase;

final class ResignationFinalPayrollBusinessFlowTest extends TestCase
{
    public function testSeveranceFinalTaxUsesStatutoryProgressiveBrackets(): void
    {
        self::assertSame(0.0, OffboardingService::severanceFinalTax(50_000_000));
        self::assertSame(2_500_000.0, OffboardingService::severanceFinalTax(100_000_000));
        self::assertSame(62_500_000.0, OffboardingService::severanceFinalTax(500_000_000));
        self::assertSame(87_500_000.0, OffboardingService::severanceFinalTax(600_000_000));
    }

    public function testFinalSettlementIsTenantScopedIdempotentAndPayrollConsumed(): void
    {
        $offboarding = file_get_contents(APPPATH . 'Libraries/Lifecycle/OffboardingService.php');
        $payroll = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');
        $deactivation = file_get_contents(APPPATH . 'Commands/DeactivateResigned.php');
        self::assertNotFalse($offboarding);
        self::assertNotFalse($payroll);
        self::assertNotFalse($deactivation);

        self::assertStringContainsString('Active company context is required for offboarding operations', $offboarding);
        self::assertStringContainsString("->where('period_year', \$year)", $offboarding);
        self::assertStringContainsString("->where('period_month', \$month)", $offboarding);
        self::assertStringContainsString("->where('source_type', 'RESIGNATION')", $offboarding);
        self::assertStringContainsString("table('payroll_one_time_components')", $payroll);
        self::assertStringContainsString("table('resignation_requests')", $payroll);
        self::assertStringContainsString("->where('company_id', (int) \$r['company_id'])", $deactivation);
    }

    public function testInactiveEmployeeResigningInsidePeriodRemainsInFinalPayrollSelection(): void
    {
        $payroll = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');
        self::assertNotFalse($payroll);
        self::assertMatchesRegularExpression(
            '/where\\(\'is_active\', 1\\)[\\s\\S]{0,240}where\\(\'resign_date >=\', \\$period\\[\'start_date\'\\]\\)[\\s\\S]{0,160}where\\(\'resign_date <=\', \\$period\\[\'end_date\'\\]\\)/',
            $payroll,
        );
    }

    public function testPkwttResigningWithinThirtyDaysRetainsMatchingReligiousThr(): void
    {
        $result = ResignationThrEligibility::evaluate([
            'employment_status' => 'PKWTT', 'religion' => 'ISLAM',
            'join_date' => '2024-01-01', 'basic_salary' => 10_000_000, 'fixed_allowance' => 1_000_000,
        ], '2026-03-10', ['date' => '2026-03-20', 'name' => 'Hari Raya Idul Fitri']);

        self::assertTrue($result['eligible']);
        self::assertSame(11_000_000.0, $result['amount']);
        self::assertSame('2026-03-20', $result['holiday_date']);
    }

    public function testPkwtWrongReligionAndOutsideWindowAreNotEligible(): void
    {
        $base = ['religion' => 'ISLAM', 'join_date' => '2024-01-01', 'basic_salary' => 10_000_000];
        self::assertFalse(ResignationThrEligibility::evaluate($base + ['employment_status' => 'PKWT'], '2026-03-10', ['date' => '2026-03-20', 'name' => 'Idul Fitri'])['eligible']);
        self::assertFalse(ResignationThrEligibility::evaluate($base + ['employment_status' => 'PKWTT'], '2026-02-01', ['date' => '2026-03-20', 'name' => 'Idul Fitri'])['eligible']);
        self::assertFalse(ResignationThrEligibility::evaluate($base + ['employment_status' => 'PKWTT'], '2026-03-10', ['date' => '2026-03-20', 'name' => 'Natal'])['eligible']);
    }

    public function testEligibleThrIsPostedAsTraceableOneTimeFinalPayrollComponent(): void
    {
        $source = file_get_contents(APPPATH . 'Libraries/Lifecycle/OffboardingService.php');
        self::assertIsString($source);
        self::assertStringContainsString('ResignationThrEligibility', $source);
        self::assertStringContainsString("'THR_RESIGNATION'", $source);
        self::assertStringContainsString("'EARNING', 'THR', \$thrAmount, true", $source);
        self::assertStringContainsString("'thr_resignation'", $source);
    }
}
