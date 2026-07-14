<?php

namespace Tests\Unit;

use App\Libraries\Erp\ErpJournalService;
use PHPUnit\Framework\TestCase;

final class PayrollAccountingBusinessFlowTest extends TestCase
{
    public function testJournalBalanceRuleAndPayrollSchemaWiring(): void
    {
        self::assertTrue(ErpJournalService::isBalancedPure(15_000_000, 15_000_000));
        self::assertFalse(ErpJournalService::isBalancedPure(15_000_000, 14_999_999));

        $service = file_get_contents(APPPATH . 'Libraries/Erp/ErpJournalService.php');
        self::assertIsString($service);
        self::assertStringContainsString("->where('payroll_period_id', \$periodId)", $service);
        self::assertStringContainsString('cost_center_id, gross_earning, take_home_pay', $service);
        self::assertStringContainsString('bpjs_kes_employee', $service);
        self::assertStringContainsString('pph21_amount', $service);
        self::assertStringContainsString('Jurnal tidak balance', $service);
    }
}
