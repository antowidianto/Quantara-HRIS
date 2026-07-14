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
        self::assertNotFalse($service);
        self::assertStringContainsString('Original payroll must be finalized', $service);
        self::assertStringContainsString('Adjustment maker cannot approve it', $service);
        self::assertStringContainsString("status' => 'REVERSED'", $service);
        self::assertStringNotContainsString("table('payslips')->update", $service);
    }

    public function testPayrollCalculationConsumesOnlyApprovedOrPostedAdjustments(): void
    {
        $payroll = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');
        self::assertNotFalse($payroll);
        self::assertStringContainsString("whereIn('status', ['APPROVED', 'POSTED'])", $payroll);
        self::assertStringContainsString("'status' => 'POSTED'", $payroll);
        self::assertStringContainsString("'category' => 'ADJUSTMENT'", $payroll);
    }

    public function testAdjustmentRoutesHaveSeparateManageAndApprovePermissions(): void
    {
        $routes = RouteSource::all();
        self::assertStringContainsString('permission:payroll.adjustment.manage', $routes);
        self::assertStringContainsString('permission:payroll.adjustment.approve', $routes);
        self::assertStringContainsString("'payroll-adjustments/(:num)/reverse'", $routes);
    }
}
