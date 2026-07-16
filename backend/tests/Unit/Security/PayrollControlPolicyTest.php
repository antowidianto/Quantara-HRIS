<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

final class PayrollControlPolicyTest extends TestCase
{
    private function payrollRoutes(): string
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes/PayrollRoutes.php');
        self::assertNotFalse($routes);
        return $routes;
    }

    public function testFourEyesTransitionsAndSnapshotAreRequired(): void
    {
        $service = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollControlService.php');
        $snapshotService = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollSnapshotService.php');
        self::assertNotFalse($service);
        self::assertNotFalse($snapshotService);
        self::assertStringContainsString('Payroll preparer cannot approve', $service);
        self::assertStringContainsString('Payroll preparer cannot finalize', $service);
        self::assertStringContainsString("status' => 'APPROVED", $service);
        self::assertStringContainsString('snapshot_hash', $service);
        self::assertStringContainsString('hash_equals', $snapshotService);
        self::assertStringContainsString('requiredApprovals', $service);
        self::assertStringContainsString('BREAK_GLASS', $service);
        self::assertStringContainsString('Break-glass reason must be at least 20 characters', $service);
    }

    public function testApprovedPayrollCannotBeRecalculated(): void
    {
        $service = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');
        self::assertNotFalse($service);
        self::assertStringContainsString(
            'Approved or finalized payroll cannot be recalculated',
            $service
        );
        self::assertStringContainsString("in_array(\$period['status'], ['DRAFT', 'CALCULATED'], true)", $service);
    }

    public function testSubmitApproveAndFinalizeRoutesHaveExplicitPolicies(): void
    {
        $routes = $this->payrollRoutes();
        foreach (['submit', 'approve', 'finalize', 'break-glass-finalize'] as $action) {
            self::assertMatchesRegularExpression(
                "/payroll-periods\/\(:num\)\/{$action}.{0,240}permission:payroll\..{0,100}idempotency:required/s",
                $routes
            );
        }
    }

    public function testBreakGlassUsesDedicatedPermissionAndCriticalAudit(): void
    {
        $routes = $this->payrollRoutes();
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/PayrollPeriodController.php');
        self::assertNotFalse($controller);
        self::assertStringContainsString('permission:payroll.break_glass', $routes);
        self::assertStringContainsString('payroll_period.break_glass_finalize', $controller);
        self::assertStringContainsString("severity: 'CRITICAL'", $controller);
        self::assertStringContainsString('notifyPayrollBreakGlass', $controller);
    }

    public function testRegeneratedPdfUsesFinalizedSnapshot(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/PayslipController.php');
        $snapshotService = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollSnapshotService.php');
        self::assertNotFalse($controller);
        self::assertNotFalse($snapshotService);
        self::assertStringContainsString('PAYROLL_SNAPSHOT_INVALID', $controller);
        self::assertStringContainsString('PAYROLL_SNAPSHOT_MISSING', $controller);
        self::assertStringContainsString('PayrollSnapshotService::LOCKED_STATUSES', $controller);
        self::assertStringContainsString("'FINALIZED'", $snapshotService);
        self::assertStringContainsString('hash_equals', $snapshotService);
        self::assertStringContainsString('$ps = $snapshotPayslip', $controller);
    }

    public function testEveryFinancialTransitionVerifiesTheSnapshot(): void
    {
        foreach ([
            APPPATH . 'Libraries/Payroll/PayrollFinalizationService.php',
            APPPATH . 'Controllers/Api/V1/Payroll/DisbursementController.php',
            APPPATH . 'Libraries/Payroll/PayrollPaymentSettlementService.php',
        ] as $path) {
            $source = file_get_contents($path);
            self::assertNotFalse($source);
            self::assertStringContainsString('PayrollSnapshotService', $source, $path);
            self::assertStringContainsString('assertValid', $source, $path);
        }
    }

    public function testLegacySnapshotBackfillDoesNotOverwritePartialEvidence(): void
    {
        $service = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollSnapshotService.php');
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-09-01-000031_BackfillLegacyPayrollSnapshots.php');
        self::assertNotFalse($service);
        self::assertNotFalse($migration);
        self::assertStringContainsString('if ($json !== \'\' || $hash !== \'\')', $service);
        self::assertStringContainsString('backfillMissing', $migration);
        self::assertStringContainsString('must not be erased on rollback', $migration);
    }
}
