<?php

namespace Tests\Unit;

use App\Libraries\Approval\ApprovalModuleCatalog;
use PHPUnit\Framework\TestCase;

final class ApprovalGovernancePolicyTest extends TestCase
{
    public function testCatalogContainsAllEngineModules(): void
    {
        $this->assertSame(
            ['LEAVE', 'OVERTIME', 'ATTENDANCE_CHANGE', 'REIMBURSEMENT', 'LOAN', 'BUSINESS_TRIP', 'BONUS', 'PAYROLL_ADJUSTMENT', 'COMPENSATION_REVIEW', 'TRAINING_REGISTRATION', 'TIMESHEET', 'CANDIDATE_OFFER', 'GENERATED_CONTRACT', 'ASSET_TRANSACTION', 'GENERIC'],
            ApprovalModuleCatalog::modules(),
        );
    }

    public function testCatalogRejectsUnknownModule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApprovalModuleCatalog::assertEntityTable('PAYROLL', 'payroll_periods');
    }

    public function testCatalogRejectsMismatchedEntityTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApprovalModuleCatalog::assertEntityTable('LEAVE', 'users');
    }

    public function testCatalogAcceptsCanonicalEntityTable(): void
    {
        ApprovalModuleCatalog::assertEntityTable('REIMBURSEMENT', 'reimbursements');
        $this->addToAssertionCount(1);
    }

    public function testGenericCannotStartWithoutRegisteredAdapter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApprovalModuleCatalog::assertEntityTable('GENERIC', 'users');
    }

    public function testEngineContainsFourEyesAndTenantIntegrityGuards(): void
    {
        $source = file_get_contents(APPPATH . 'Libraries/Approval/ApprovalEngine.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("Self-approval tidak diizinkan", $source);
        $this->assertStringContainsString("Employee tidak ditemukan di tenant aktif", $source);
        $this->assertStringContainsString("Entitas approval tidak ditemukan di tenant aktif", $source);
        $this->assertStringContainsString("->where('role_id', 2)", $source);
        $this->assertStringContainsString('decideWithinTransaction', $source);
        $this->assertStringContainsString('Step sudah diputuskan oleh approver lain', $source);
        $this->assertStringContainsString('domainAdapters', $source);
        $this->assertStringContainsString("->where('status', 'PENDING')->update", $source);
        $this->assertStringContainsString('startWithinTransaction', $source);
        $this->assertStringContainsString('cancelEntity', $source);
        $this->assertStringContainsString('outbox->record', $source);
        $this->assertStringContainsString("'outbox_event_id'", $source);
        $this->assertStringContainsString('buildWorkflowSnapshot', $source);
        $this->assertStringContainsString("'workflow_snapshot'", $source);
        $this->assertStringContainsString("'chain_version_hash'", $source);
        $this->assertStringContainsString("'expected_approver_user_ids'", $source);
    }

    public function testActiveChainsCannotBeMutatedUnderPendingRequests(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Approval/ApprovalChainController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('CHAIN_HAS_PENDING_REQUESTS', $source);
        $this->assertStringContainsString('hasPendingRequests', $source);
    }

    public function testOvertimeLegalLimitsAreHardValidation(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Attendance/OvertimeController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('OVERTIME_DAILY_LIMIT_EXCEEDED', $source);
        $this->assertStringContainsString('OVERTIME_WEEKLY_LIMIT_EXCEEDED', $source);
        $this->assertStringContainsString("\$dayType === 'WEEKDAY'", $source);
        $this->assertStringContainsString("->whereNotIn('status', ['REJECTED', 'CANCELLED'])", $source);
    }

    public function testLeaveEndpointCannotBypassApprovalEngine(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Leave/LeaveController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('->approveEntity(', $source);
        $this->assertStringContainsString('->rejectEntity(', $source);
        $this->assertStringNotContainsString('leaveWorkflow()->approve(', $source);
        $this->assertStringNotContainsString('leaveWorkflow()->reject(', $source);
    }

    public function testOvertimePublicEndpointsUseApprovalEngine(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Attendance/OvertimeController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("public function approve", $source);
        $this->assertStringContainsString("approveEntity(\n                'OVERTIME'", $source);
        $this->assertStringContainsString("public function reject", $source);
        $this->assertStringContainsString("rejectEntity(\n                'OVERTIME'", $source);
    }

    public function testAttendanceChangeUsesApprovalEngineAndStartsWorkflow(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Attendance/AttendanceChangeRequestController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("'module' => 'ATTENDANCE_CHANGE'", $source);
        $this->assertStringContainsString("'entity_table' => 'attendance_change_requests'", $source);
        $this->assertStringContainsString('->approveEntity(', $source);
        $this->assertStringContainsString('->rejectEntity(', $source);
        $this->assertStringNotContainsString('legacyApprove', $source);
        $this->assertStringNotContainsString('legacyReject', $source);
    }

    public function testReimbursementUsesApprovalEngineAndCancelsWorkflow(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/ReimbursementController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("'module' => 'REIMBURSEMENT'", $source);
        $this->assertStringContainsString("'entity_table' => 'reimbursements'", $source);
        $this->assertStringContainsString('->approveEntity(', $source);
        $this->assertStringContainsString('->rejectEntity(', $source);
        $this->assertStringContainsString('->cancelEntity(', $source);
        $this->assertStringNotContainsString("'used_amount' => \$balance['used_amount'] + \$row['amount']", $source);
    }

    public function testLoanUsesApprovalEngineAndCancelsWorkflow(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/LoanController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("'module' => 'LOAN'", $source);
        $this->assertStringContainsString("'entity_table' => 'loans'", $source);
        $this->assertStringContainsString('->approveEntity(', $source);
        $this->assertStringContainsString('->rejectEntity(', $source);
        $this->assertStringContainsString('->cancelEntity(', $source);
        $this->assertStringNotContainsString('->approveLoan(', $source);
        $this->assertStringNotContainsString('->rejectLoan(', $source);
    }

    public function testFinalOutcomePublicationUsesTransactionalOutbox(): void
    {
        foreach ([
            'Controllers/Api/V1/Leave/LeaveController.php',
            'Controllers/Api/V1/Attendance/OvertimeController.php',
            'Controllers/Api/V1/Attendance/AttendanceChangeRequestController.php',
        ] as $path) {
            $source = file_get_contents(APPPATH . $path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('WebhookEvent::LEAVE_APPROVED', $source);
            $this->assertStringNotContainsString('WebhookEvent::OVERTIME_APPROVED', $source);
            $this->assertStringNotContainsString('WebhookEvent::ATTENDANCE_CHANGE_APPROVED', $source);
        }
        $outbox = file_get_contents(APPPATH . 'Libraries/Approval/ApprovalOutcomeOutbox.php');
        $this->assertIsString($outbox);
        $this->assertStringContainsString('approval_outbox_events', $outbox);
        $this->assertStringContainsString('Services::webhook()->dispatch', $outbox);
        $this->assertStringContainsString('Services::notification()->send', $outbox);
    }

    public function testBusinessTripCannotFailOpenOrBypassApprovalEngine(): void
    {
        $service = file_get_contents(APPPATH . 'Libraries/Attendance/BusinessTripService.php');
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Attendance/BusinessTripController.php');
        $this->assertIsString($service);
        $this->assertIsString($controller);
        $this->assertStringContainsString("'module' => 'BUSINESS_TRIP'", $service);
        $this->assertStringContainsString("'entity_table' => 'business_trips'", $service);
        $this->assertStringNotContainsString('approval()->submit(', $service);
        $this->assertStringNotContainsString('without approval chain', $service);
        $this->assertStringContainsString('->approveEntity(', $controller);
        $this->assertStringContainsString('->rejectEntity(', $controller);
        $this->assertStringNotContainsString('businessTrip()->approve(', $controller);
        $this->assertStringNotContainsString('businessTrip()->reject(', $controller);
        $this->assertStringContainsString("'source_business_trip_id'", $service);
        $this->assertStringContainsString("'module' => 'REIMBURSEMENT'", $service);
        $this->assertStringContainsString("cancelEntity('BUSINESS_TRIP'", $service);
    }

    public function testBonusCannotFinalizeOutsideApprovalEngine(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/BonusController.php');
        $workflow = file_get_contents(APPPATH . 'Libraries/Payroll/BonusWorkflowService.php');
        $approval = file_get_contents(APPPATH . 'Libraries/Payroll/BonusApprovalWorkflowService.php');
        $routes = file_get_contents(APPPATH . 'Config/Routes/LifecycleRoutes.php');
        $this->assertIsString($controller);
        $this->assertIsString($workflow);
        $this->assertIsString($approval);
        $this->assertIsString($routes);
        $this->assertStringNotContainsString('function approveRun', $controller);
        $this->assertStringNotContainsString('function approveRun', $workflow);
        $this->assertStringNotContainsString("bonus-runs/(:num)/approve", $routes);
        $this->assertStringContainsString("'module' => 'BONUS'", $approval);
        $this->assertStringContainsString("'entity_table' => 'bonus_runs'", $approval);
        $this->assertStringContainsString("'status' => 'PENDING_APPROVAL'", $approval);
        $this->assertStringContainsString("cancelEntity('BONUS'", $workflow);
        $this->assertStringNotContainsString('WebhookEvent::BONUS_RUN_APPROVED', $controller);
    }

    public function testCompensationReviewCannotBypassExpectedApprovers(): void
    {
        $service = file_get_contents(APPPATH . 'Libraries/Compensation/CompensationReviewService.php');
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Compensation/CompensationReviewController.php');
        $routes = file_get_contents(APPPATH . 'Config/Routes/DevelopmentRoutes.php');
        $workflow = file_get_contents(APPPATH . 'Libraries/Compensation/CompensationReviewApprovalWorkflowService.php');
        $this->assertIsString($service);
        $this->assertIsString($controller);
        $this->assertIsString($routes);
        $this->assertIsString($workflow);
        $this->assertStringContainsString("'module' => 'COMPENSATION_REVIEW'", $service);
        $this->assertStringContainsString("'entity_table' => 'compensation_review_proposals'", $service);
        $this->assertStringContainsString('immutable', $service);
        $this->assertStringNotContainsString('public function approve(', $service);
        $this->assertStringNotContainsString('public function reject(', $service);
        $this->assertStringNotContainsString('function approve(', $controller);
        $this->assertStringNotContainsString('function reject(', $controller);
        $this->assertStringNotContainsString('compensation-proposals/(:num)/approve', $routes);
        $this->assertStringNotContainsString('compensation-proposals/(:num)/reject', $routes);
        $this->assertStringContainsString("where('status', 'SUBMITTED')->update", $workflow);
    }

    public function testTrainingRegistrationUsesGenericApprovalAndBudgetGuard(): void
    {
        $catalog = file_get_contents(APPPATH . 'Libraries/Approval/ApprovalModuleCatalog.php');
        $service = file_get_contents(APPPATH . 'Libraries/Training/TrainingService.php');
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Training/TrainingController.php');
        $adapter = file_get_contents(APPPATH . 'Libraries/Approval/Adapters/TrainingRegistrationApprovalDomainAdapter.php');

        $this->assertStringContainsString("'TRAINING_REGISTRATION'=> 'training_registrations'", $catalog);
        $this->assertStringContainsString("'module' => 'TRAINING_REGISTRATION'", $service);
        $this->assertStringContainsString("Services::approval()->approveEntity(", $controller);
        $this->assertStringContainsString("Services::approval()->rejectEntity(", $controller);
        $this->assertStringContainsString("Services::approval()->cancelEntity(", $service);
        $this->assertStringContainsString('reserveBudget(', $service);
        $this->assertStringContainsString("where('status', 'REGISTERED')->update", $service);
        $this->assertStringContainsString("return 'TRAINING_REGISTRATION';", $adapter);
    }

    public function testTimesheetCannotUsePermissionOnlyDirectApproval(): void
    {
        $service = file_get_contents(APPPATH . 'Libraries/Timesheet/TimesheetService.php');
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Timesheet/TimesheetController.php');
        $adapter = file_get_contents(APPPATH . 'Libraries/Approval/Adapters/TimesheetApprovalDomainAdapter.php');

        $this->assertStringContainsString("'module' => 'TIMESHEET'", $service);
        $this->assertStringContainsString("->where('company_id', \$companyId)", $service);
        $this->assertStringContainsString('assertEntryStillValid(', $service);
        $this->assertStringContainsString('Services::approval()->approveEntity(', $controller);
        $this->assertStringContainsString('Services::approval()->rejectEntity(', $controller);
        $this->assertStringContainsString("return 'TIMESHEET';", $adapter);
    }

    public function testCandidateOfferIsNotSentBeforeExpectedApproversDecide(): void
    {
        $offer = file_get_contents(APPPATH . 'Libraries/Recruitment/OfferService.php');
        $workflow = file_get_contents(APPPATH . 'Libraries/Recruitment/CandidateOfferApprovalWorkflowService.php');
        $adapter = file_get_contents(APPPATH . 'Libraries/Approval/Adapters/CandidateOfferApprovalDomainAdapter.php');
        $engine = file_get_contents(APPPATH . 'Libraries/Approval/ApprovalEngine.php');

        $this->assertStringContainsString("'module' => 'CANDIDATE_OFFER'", $workflow);
        $this->assertStringContainsString("'status' => 'PENDING_APPROVAL'", $workflow);
        $this->assertStringContainsString("->where('status', 'PENDING_APPROVAL')->update", $workflow);
        $this->assertStringContainsString('offer_snapshot_hash', $workflow);
        $this->assertStringContainsString('is_policy_exception', $workflow);
        $this->assertStringContainsString('CandidateOfferApprovalWorkflowService())->submit', $offer);
        $this->assertStringContainsString("return 'CANDIDATE_OFFER';", $adapter);
        $this->assertStringContainsString("\$type === 'HIRING_MANAGER'", $engine);
    }

    public function testGeneratedContractReleaseAndRevocationUseDomainAdapter(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Lifecycle/GeneratedContractController.php');
        $workflow = file_get_contents(APPPATH . 'Libraries/Lifecycle/GeneratedContractApprovalWorkflowService.php');
        $adapter = file_get_contents(APPPATH . 'Libraries/Approval/Adapters/GeneratedContractApprovalDomainAdapter.php');

        $this->assertStringContainsString("'module' => 'GENERATED_CONTRACT'", $workflow);
        $this->assertStringContainsString('document_snapshot_hash', $workflow);
        $this->assertStringContainsString("['SEND', 'REVOKE']", $workflow);
        $this->assertStringContainsString('GeneratedContractApprovalWorkflowService())', $controller);
        $this->assertStringNotContainsString('in_list[DRAFT,SENT,SIGNED,REVOKED]', $controller);
        $this->assertStringContainsString("return 'GENERATED_CONTRACT';", $adapter);
    }

    public function testAssetAssignmentAndReturnOnlyMutateAtFinalDecision(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Asset/AssetController.php');
        $workflow = file_get_contents(APPPATH . 'Libraries/Asset/AssetApprovalWorkflowService.php');
        $adapter = file_get_contents(APPPATH . 'Libraries/Approval/Adapters/AssetTransactionApprovalDomainAdapter.php');

        $this->assertStringContainsString("'module' => 'ASSET_TRANSACTION'", $workflow);
        $this->assertStringContainsString('asset_snapshot_hash', $workflow);
        $this->assertStringContainsString('is_high_value_loss', $workflow);
        $this->assertStringContainsString('submitAssign(', $controller);
        $this->assertStringContainsString('submitReturn(', $controller);
        $this->assertStringContainsString("return 'ASSET_TRANSACTION';", $adapter);
    }
}
