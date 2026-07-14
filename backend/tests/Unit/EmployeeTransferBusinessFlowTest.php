<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EmployeeTransferBusinessFlowTest extends TestCase
{
    public function testEffectiveTransferChangesOrganizationBeforePayrollResolvesAllocation(): void
    {
        $transfer = file_get_contents(APPPATH . 'Libraries/Employee/EmployeeTransferService.php');
        $payroll = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');

        self::assertIsString($transfer);
        self::assertIsString($payroll);
        self::assertStringContainsString('Mutasi belum mencapai tanggal efektif', $transfer);
        foreach (['branch_id', 'department_id', 'position_id', 'work_location_id'] as $field) {
            self::assertStringContainsString("\$update['{$field}']", $transfer);
        }
        self::assertStringContainsString('recomputeEmployee($empId)', $transfer);
        self::assertStringContainsString('resolveForEmployee((int) $emp[\'id\'])', $payroll);
    }
}
