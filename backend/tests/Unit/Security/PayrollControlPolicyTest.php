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
        self::assertStringContainsString('Payroll has already been submitted for approval', $service);
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

    public function testPeriodCalculationIsAtomic(): void
    {
        $service = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');
        self::assertNotFalse($service);

        $begin = strpos($service, '$db->transBegin();', strpos($service, 'function calculatePeriod'));
        $clear = strpos($service, '$repository->clearCalculatedPayslips', $begin);
        $complete = strpos($service, '$repository->completeCalculation', $clear);
        $commit = strpos($service, '$db->transCommit()', $complete);
        $rollback = strpos($service, '$db->transRollback();', $commit);

        self::assertIsInt($begin);
        self::assertIsInt($clear);
        self::assertIsInt($complete);
        self::assertIsInt($commit);
        self::assertIsInt($rollback);
        self::assertLessThan($clear, $begin);
        self::assertLessThan($complete, $clear);
        self::assertLessThan($commit, $complete);
        self::assertLessThan($rollback, $commit);
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

    public function testPayrollSubmissionStartsApprovalChainWithEmailNotification(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/PayrollPeriodController.php');
        $workflow = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollApprovalWorkflowService.php');
        $engine = file_get_contents(APPPATH . 'Libraries/Approval/ApprovalEngine.php');
        $notification = file_get_contents(APPPATH . 'Libraries/Notification/NotificationService.php');
        self::assertNotFalse($controller);
        self::assertNotFalse($workflow);
        self::assertNotFalse($engine);
        self::assertNotFalse($notification);
        self::assertStringContainsString('PayrollApprovalWorkflowService', $controller);
        self::assertStringContainsString("'module' => 'PAYROLL'", $workflow);
        self::assertStringContainsString("'entity_table' => 'payroll_periods'", $workflow);
        self::assertStringContainsString("'approval.request'", $engine);
        self::assertStringContainsString("if (\$slug === 'email' && \$forceEmail)", $notification);
        self::assertStringContainsString("\$forceEmail ? ['in_app', 'email'] : ['in_app']", $notification);
    }

    public function testPayrollApprovalDetailListsEligibleCheckersAndDeliveryStatus(): void
    {
        $routes = $this->payrollRoutes();
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/PayrollPeriodController.php');
        self::assertNotFalse($controller);
        self::assertStringContainsString('payroll-periods/(:num)/approval-detail', $routes);
        self::assertStringContainsString('permission:payroll.view', $routes);
        self::assertStringContainsString('function approvalDetail', $controller);
        self::assertStringContainsString("->where('module', 'PAYROLL')", $controller);
        self::assertStringContainsString("'workflow_snapshot'", $controller);
        self::assertStringContainsString("'notified_in_app'", $controller);
        self::assertStringContainsString("'email_queued'", $controller);
        self::assertStringContainsString("'approved_at'", $controller);
        self::assertStringContainsString("'payroll_summary'", $controller);
        self::assertStringContainsString("'payroll_rows'", $controller);
        self::assertStringContainsString("'can_approve'", $controller);
    }

    public function testPayrollApprovalRequiresDoubleValidationAndCannotUseGenericInbox(): void
    {
        $payrollController = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/PayrollPeriodController.php');
        $approvalController = file_get_contents(APPPATH . 'Controllers/Api/V1/Approval/ApprovalChainController.php');
        self::assertNotFalse($payrollController);
        self::assertNotFalse($approvalController);
        self::assertStringContainsString("'review_confirmed'", $payrollController);
        self::assertStringContainsString("'confirmation_code'", $payrollController);
        self::assertStringContainsString('PAYROLL_REVIEW_CONFIRMATION_REQUIRED', $payrollController);
        self::assertStringContainsString('PAYROLL_CONFIRMATION_CODE_MISMATCH', $payrollController);
        self::assertStringContainsString('hash_equals', $payrollController);
        self::assertStringContainsString('PAYROLL_TABLE_APPROVAL_REQUIRED', $approvalController);
        self::assertStringContainsString('isPayrollApprovalRequest', $approvalController);
        self::assertStringContainsString('PAYROLL_MAKER_ONLY', $payrollController);
        self::assertStringContainsString("'can_submit'", $payrollController);
    }

    public function testDefaultPayrollChainHasTwoSequentialRoleSteps(): void
    {
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-09-01-000050_AddPayrollApprovalChain.php');
        self::assertNotFalse($migration);
        self::assertStringContainsString("'assistant_manager_payroll'", $migration);
        self::assertStringContainsString("'manager_payroll'", $migration);
        self::assertStringContainsString("[1, 'Approval Assistant Manager Payroll'", $migration);
        self::assertStringContainsString("[2, 'Approval Final Manager Payroll'", $migration);
        self::assertStringContainsString("'fallback_strategy' => 'BLOCK'", $migration);
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
