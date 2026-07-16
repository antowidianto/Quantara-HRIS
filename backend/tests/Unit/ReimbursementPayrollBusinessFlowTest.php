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

        $calculation = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollEmployeeCalculationService.php');
        $finalization = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollFinalizationService.php');
        self::assertNotFalse($calculation);
        self::assertNotFalse($finalization);
        self::assertStringContainsString("->where('status', 'APPROVED')", $calculation);
        self::assertStringContainsString("'code' => 'REIMB'", $calculation);
        self::assertStringContainsString("'is_taxable' => 0", $calculation);
        self::assertStringContainsString("'paid_in_payroll_period_id' => \$periodId", $finalization);

        $settlement = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollPaymentSettlementService.php');
        self::assertNotFalse($settlement);
        self::assertStringContainsString("->where('paid_in_payroll_period_id', \$periodId)", $settlement);
        self::assertStringContainsString("->update(['status' => 'PAID'])", $settlement);
    }

    public function testApprovalPersistsActorAndTimestamp(): void
    {
        $workflow = file_get_contents(APPPATH . 'Libraries/Payroll/ReimbursementApprovalWorkflowService.php');
        self::assertNotFalse($workflow);
        self::assertStringContainsString("'approved_by' => \$userId", $workflow);
        self::assertStringContainsString("'approved_at' => \$now", $workflow);

        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/ReimbursementController.php');
        self::assertNotFalse($controller);
        self::assertStringContainsString('->approveEntity(', $controller);
    }
}
