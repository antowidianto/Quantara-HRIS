<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\RouteSource;

final class PayrollAdjustmentPolicyTest extends TestCase
{
    public function testHistoricalPayrollUsesAppendOnlyAdjustmentWorkflow(): void
    {
        $service = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollAdjustmentService.php');
        $approval = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollAdjustmentApprovalWorkflowService.php');
        self::assertNotFalse($service);
        self::assertNotFalse($approval);
        self::assertStringContainsString('Original payroll must be finalized', $service);
        self::assertStringNotContainsString('public function approve(', $service);
        self::assertStringContainsString("'module' => 'PAYROLL_ADJUSTMENT'", $approval);
        self::assertStringContainsString("'status' => 'PENDING_APPROVAL'", $approval);
        self::assertStringContainsString("'status' => 'DRAFT'", $service);
        self::assertStringNotContainsString("table('payslips')->update", $service);
    }

    public function testPayrollCalculationConsumesOnlyApprovedOrPostedAdjustments(): void
    {
        $repository = file_get_contents(APPPATH . 'Repositories/Payroll/PayrollRepository.php');
        $calculation = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollEmployeeCalculationService.php');
        self::assertNotFalse($repository);
        self::assertNotFalse($calculation);
        self::assertStringContainsString("whereIn('status', ['APPROVED', 'POSTED'])", $repository);
        self::assertStringContainsString("'status' => 'POSTED'", $repository);
        self::assertStringContainsString("'status' => 'REVERSED'", $repository);
        self::assertStringContainsString("'category' => 'ADJUSTMENT'", $calculation);
    }

    public function testAdjustmentRoutesSubmitIntoGenericApproval(): void
    {
        $routes = RouteSource::all();
        self::assertStringContainsString('permission:payroll.adjustment.manage', $routes);
        self::assertStringContainsString("'payroll-adjustments/(:num)/reverse'", $routes);
        self::assertStringContainsString("'payroll-adjustments/(:num)/submit'", $routes);
        self::assertStringContainsString("'payroll-adjustments/(:num)/cancel'", $routes);
        self::assertStringNotContainsString("'payroll-adjustments/(:num)/approve'", $routes);
    }
}
