<?php

namespace Tests\Unit;

use App\Libraries\Payroll\PayrollReconciliationService;
use PHPUnit\Framework\TestCase;

final class PayrollReconciliationBusinessFlowTest extends TestCase
{
    public function testCleanRegisterBalancesToBankFile(): void
    {
        $result = PayrollReconciliationService::analyzeRecords([
            ['employee_id' => 1, 'take_home_pay' => 8_000_000, 'bank_name' => 'BCA', 'bank_account' => '123', 'cost_center_id' => 10],
            ['employee_id' => 2, 'take_home_pay' => 7_000_000, 'bank_name' => 'BNI', 'bank_account' => '456', 'cost_center_id' => 20],
        ], 15_000_000);

        self::assertTrue($result['is_reconciled']);
        self::assertSame(15_000_000.0, $result['register_total']);
        self::assertSame($result['register_total'], $result['bank_file_total']);
        self::assertSame([], $result['exceptions']);
    }

    public function testMismatchDuplicateAndMissingAllocationEnterExceptionQueue(): void
    {
        $result = PayrollReconciliationService::analyzeRecords([
            ['employee_id' => 1, 'take_home_pay' => 8_000_000, 'bank_name' => 'BCA', 'bank_account' => '123-00', 'cost_center_id' => null],
            ['employee_id' => 2, 'take_home_pay' => 7_000_000, 'bank_name' => 'bca', 'bank_account' => '12300', 'cost_center_id' => 20],
            ['employee_id' => 3, 'take_home_pay' => 1_000_000, 'bank_name' => '', 'bank_account' => '', 'cost_center_id' => 30],
        ], 20_000_000);

        self::assertFalse($result['is_reconciled']);
        self::assertSame(4, $result['exception_count']);
        self::assertSame(
            ['REGISTER_TOTAL_MISMATCH', 'MISSING_COST_CENTER', 'MISSING_BANK_ACCOUNT', 'DUPLICATE_BANK_ACCOUNT'],
            array_column($result['exceptions'], 'code'),
        );
    }
}
