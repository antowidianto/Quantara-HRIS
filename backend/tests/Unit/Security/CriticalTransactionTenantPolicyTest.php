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
                ['overtimes', 'employees'],
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

    public function testOvertimeApprovalSideEffectsAreTenantScopedInWorkflow(): void
    {
        $source = file_get_contents(APPPATH . 'Libraries/Attendance/OvertimeApprovalWorkflowService.php');
        self::assertNotFalse($source);
        foreach (['overtimes', 'employees', 'leave_balances'] as $table) {
            self::assertMatchesRegularExpression(
                "/table\\('{$table}'\\)[\\s\\S]{0,500}where\\([^\\n]*company_id/",
                $source,
                "Overtime approval workflow must tenant-scope {$table}",
            );
        }
    }

    public function testLeaveControllerDelegatesToTenantScopedRepositoryAndWorkflow(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Leave/LeaveController.php');
        $repository = file_get_contents(APPPATH . 'Repositories/Leave/LeaveRepository.php');
        $workflow = file_get_contents(APPPATH . 'Libraries/Leave/LeaveWorkflowService.php');
        self::assertNotFalse($controller);
        self::assertNotFalse($repository);
        self::assertNotFalse($workflow);
        self::assertStringContainsString('requireCompanyId()', $controller);
        self::assertStringContainsString('new LeaveRepository()', $controller);
        self::assertStringContainsString('new LeaveWorkflowService()', $controller);
        self::assertStringNotContainsString('Database::connect()', $controller);

        foreach (['leaves', 'leave_types', 'leave_balances'] as $table) {
            self::assertMatchesRegularExpression(
                "/table\\('{$table}'\\)[\\s\\S]{0,500}where\\([^\\n]*company_id/",
                $repository . $workflow,
                "Leave repository/workflow must tenant-scope {$table}",
            );
        }
        self::assertMatchesRegularExpression(
            "/table\\('attendances'\\)[\\s\\S]{0,500}where\\([^\\n]*company_id/",
            $workflow,
        );
    }

    public function testPayrollOrchestratorRequiresActiveCompanyAndScopesAllInputs(): void
    {
        $source = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');
        $inputs = file_get_contents(APPPATH . 'Repositories/Payroll/PayrollRepository.php')
            . file_get_contents(APPPATH . 'Libraries/Payroll/PayrollEmployeeCalculationService.php');
        self::assertNotFalse($source);
        self::assertIsString($inputs);
        self::assertStringContainsString('Active company context is required for payroll operations', $source);
        foreach (['payroll_periods', 'payslips', 'attendances', 'overtimes', 'reimbursements', 'loans'] as $table) {
            self::assertMatchesRegularExpression(
                "/table\\('{$table}'\\)[\\s\\S]{0,500}where\\([^\\n]*company_id/",
                $inputs,
                "Payroll repository/calculation use case must tenant-scope {$table}",
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

    public function testContractRenderingFailsClosedAcrossTenantBoundaries(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Lifecycle/ContractTemplateController.php');
        self::assertNotFalse($controller);
        self::assertStringContainsString('->where(\'company_id\', $companyId)', $controller);
        self::assertStringContainsString('buildContext($empId, $contractId, $data[\'extras\'] ?? [], $companyId)', $controller);

        $renderer = file_get_contents(APPPATH . 'Libraries/Lifecycle/ContractRenderer.php');
        self::assertNotFalse($renderer);
        self::assertStringContainsString('?int $expectedCompanyId = null', $renderer);
        self::assertStringContainsString('$employeeBuilder->where(\'company_id\', $expectedCompanyId)', $renderer);
        self::assertStringContainsString('->where(\'employee_id\', $employeeId)', $renderer);
        foreach (['positions', 'departments', 'branches', 'work_locations', 'employees', 'job_grades', 'employee_contracts'] as $table) {
            self::assertMatchesRegularExpression(
                "/table\\('{$table}'\\)[\\s\\S]{0,250}where\\('company_id'/",
                $renderer,
                "ContractRenderer must tenant-scope {$table}",
            );
        }
    }
}
