<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

final class CriticalTransactionTenantPolicyTest extends TestCase
{
    /** @dataProvider criticalControllerProvider */
    public function testCriticalTransactionControllersResolveAndApplyActiveCompany(
        string $relativePath,
        array $tables,
    ): void {
        $source = file_get_contents(APPPATH . $relativePath);
        self::assertNotFalse($source);
        self::assertStringContainsString('requireCompanyId()', $source);

        foreach ($tables as $table) {
            self::assertMatchesRegularExpression(
                "/table\\('{$table}'\\)[\\s\\S]{0,500}where\\('company_id'/",
                $source,
                "{$relativePath} must scope {$table} by company_id",
            );
        }
    }

    public static function criticalControllerProvider(): array
    {
        return [
            'attendance' => [
                'Controllers/Api/V1/Attendance/AttendanceController.php',
                ['attendances', 'employees', 'branches', 'shifts'],
            ],
            'overtime' => [
                'Controllers/Api/V1/Attendance/OvertimeController.php',
                ['overtimes', 'employees', 'leave_balances'],
            ],
            'leave' => [
                'Controllers/Api/V1/Leave/LeaveController.php',
                ['leaves', 'leave_types', 'leave_balances', 'attendances'],
            ],
            'reimbursement' => [
                'Controllers/Api/V1/Payroll/ReimbursementController.php',
                ['reimbursements', 'employees', 'employee_benefit_balances'],
            ],
            'loan' => [
                'Controllers/Api/V1/Payroll/LoanController.php',
                ['loans', 'loan_installments', 'employees'],
            ],
        ];
    }

    public function testPayrollOrchestratorRequiresActiveCompanyAndScopesAllInputs(): void
    {
        $source = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');
        self::assertNotFalse($source);
        self::assertStringContainsString('Active company context is required for payroll operations', $source);
        foreach (['payroll_periods', 'payslips', 'attendances', 'overtimes', 'reimbursements', 'loans'] as $table) {
            self::assertMatchesRegularExpression(
                "/table\\('{$table}'\\)[\\s\\S]{0,500}where\\([^\\n]*company_id/",
                $source,
                "PayrollService must tenant-scope {$table}",
            );
        }

        $settlementSource = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollPaymentSettlementService.php');
        self::assertNotFalse($settlementSource);
        foreach (['payroll_periods', 'payslips', 'reimbursements', 'loans', 'loan_installments', 'employee_contracts'] as $table) {
            self::assertMatchesRegularExpression(
                "/table\\('{$table}'\\)[\\s\\S]{0,500}where\\([^\\n]*company_id/",
                $settlementSource,
                "PayrollPaymentSettlementService must tenant-scope {$table}",
            );
        }
    }
}
