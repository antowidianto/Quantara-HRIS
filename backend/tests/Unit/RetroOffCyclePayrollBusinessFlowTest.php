<?php

namespace Tests\Unit;

use App\Libraries\Payroll\RetroTriggerPolicy;
use PHPUnit\Framework\TestCase;

final class RetroOffCyclePayrollBusinessFlowTest extends TestCase
{
    public function testSupportedCorrectionTriggersAreExplicit(): void
    {
        self::assertSame('SALARY', RetroTriggerPolicy::normalize('salary'));
        self::assertSame('MANUAL', RetroTriggerPolicy::normalize(null));
        foreach (['ALLOWANCE', 'ATTENDANCE', 'TAX_STATUS', 'BPJS', 'TRANSFER', 'CONTRACT'] as $trigger) {
            self::assertContains($trigger, RetroTriggerPolicy::TRIGGERS);
        }
        $this->expectException(\InvalidArgumentException::class);
        RetroTriggerPolicy::normalize('UNKNOWN');
    }

    public function testOffCyclePeriodAndAppendOnlyPayslipWiringExist(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/PayrollPeriodController.php');
        $payroll = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');
        $adjustment = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollAdjustmentService.php');
        self::assertIsString($controller);
        self::assertIsString($payroll);
        self::assertIsString($adjustment);
        self::assertStringContainsString('OFF_CYCLE', $controller);
        self::assertStringContainsString("'code' => 'ADJ-'", $payroll);
        self::assertStringContainsString("'original_period_id'", $adjustment);
        self::assertStringNotContainsString("table('payslips')->where('id', \$original", $adjustment);
    }
}
