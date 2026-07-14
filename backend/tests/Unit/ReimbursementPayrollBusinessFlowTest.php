<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Payroll\PayrollService;
use PHPUnit\Framework\TestCase;

final class ReimbursementPayrollBusinessFlowTest extends TestCase
{
    public function testApprovedReimbursementsBecomeNonTaxablePayrollEarningAndAreMarkedPaid(): void
    {
        self::assertSame(1_750_000.0, PayrollService::approvedReimbursementTotal([
            ['amount' => 1_500_000],
            ['amount' => 250_000],
        ]));

        $payroll = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');
        self::assertNotFalse($payroll);
        self::assertStringContainsString("->where('status', 'APPROVED')", $payroll);
        self::assertStringContainsString("'code' => 'REIMB'", $payroll);
        self::assertStringContainsString("'is_taxable' => 0", $payroll);
        self::assertStringContainsString("'paid_in_payroll_period_id' => \$payrollPeriodId", $payroll);

        $settlement = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollPaymentSettlementService.php');
        self::assertNotFalse($settlement);
        self::assertStringContainsString("->where('paid_in_payroll_period_id', \$periodId)", $settlement);
        self::assertStringContainsString("->update(['status' => 'PAID'])", $settlement);
    }

    public function testApprovalPersistsActorAndTimestamp(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/ReimbursementController.php');
        self::assertNotFalse($controller);
        self::assertStringContainsString("'approved_by' => \$this->userId()", $controller);
        self::assertStringContainsString("'approved_at' => date('Y-m-d H:i:s')", $controller);
    }
}
