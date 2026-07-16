<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Payroll\PayrollVarianceService;
use PHPUnit\Framework\TestCase;
use Tests\Support\RouteSource;

final class PayrollVarianceBusinessFlowTest extends TestCase
{
    public function testMaterialPeriodAndEmployeeVarianceRequiresReview(): void
    {
        $analysis = PayrollVarianceService::compare(
            [
                ['employee_id' => 1, 'employee_name' => 'A', 'take_home_pay' => 12_000_000],
                ['employee_id' => 2, 'employee_name' => 'B', 'take_home_pay' => 5_000_000],
            ],
            [
                ['employee_id' => 1, 'employee_name' => 'A', 'take_home_pay' => 10_000_000],
                ['employee_id' => 2, 'employee_name' => 'B', 'take_home_pay' => 5_000_000],
            ],
            10,
        );

        self::assertTrue($analysis['requires_review']);
        self::assertSame(13.33, $analysis['total_variance_percent']);
        self::assertCount(1, $analysis['exceptions']);
        self::assertSame(20.0, $analysis['exceptions'][0]['variance_percent']);
        self::assertSame('MATERIAL_CHANGE', $analysis['exceptions'][0]['reason']);
    }

    public function testFirstPayrollHasExplicitMissingBaselineWithoutBlocking(): void
    {
        $analysis = PayrollVarianceService::compare([
            ['employee_id' => 1, 'employee_name' => 'A', 'take_home_pay' => 10_000_000],
        ], [], 10);

        self::assertTrue($analysis['baseline_missing']);
        self::assertFalse($analysis['requires_review']);
        self::assertSame([], $analysis['exceptions']);
    }

    public function testWorkflowRequiresReviewBeforeSubmitAndProtectsReviewRoute(): void
    {
        $control = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollControlService.php');
        $routes = RouteSource::all();
        self::assertNotFalse($control);
        self::assertStringContainsString('Material payroll variance must be reviewed before submission', $control);
        self::assertMatchesRegularExpression(
            '/review-variance.{0,220}permission:payroll\.calculate.{0,100}idempotency:required/s',
            $routes,
        );

        $disbursement = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/DisbursementController.php');
        self::assertNotFalse($disbursement);
        self::assertStringContainsString('Payroll period must be FINALIZED before disbursement', $disbursement);
        self::assertStringContainsString("['IN_PROGRESS', 'COMPLETED']", $disbursement);
        $finalization = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollFinalizationService.php');
        self::assertNotFalse($finalization);
        self::assertStringContainsString("'status' => 'FINALIZED'", $finalization);
        self::assertStringContainsString('PayrollPaymentSettlementService', $disbursement);
        $settlement = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollPaymentSettlementService.php');
        self::assertNotFalse($settlement);
        self::assertStringContainsString("'status' => 'PAID'", $settlement);
        self::assertStringContainsString("'disbursement_status' => 'COMPLETED'", $settlement);
    }
}
