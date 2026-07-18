<?php

namespace Tests\Unit;

use App\Libraries\Approval\ApprovalModuleCatalog;
use PHPUnit\Framework\TestCase;

final class ApprovalGovernancePolicyTest extends TestCase
{
    public function testCatalogContainsAllEngineModules(): void
    {
        $this->assertSame(
            ['LEAVE', 'OVERTIME', 'ATTENDANCE_CHANGE', 'REIMBURSEMENT', 'LOAN', 'BUSINESS_TRIP', 'BONUS', 'PAYROLL_ADJUSTMENT', 'COMPENSATION_REVIEW', 'TRAINING_REGISTRATION', 'TIMESHEET', 'CANDIDATE_OFFER', 'GENERATED_CONTRACT', 'ASSET_TRANSACTION', 'HIRE_CONVERSION', 'PERFORMANCE_GOAL', 'IDP_ACTIVATION', 'EMPLOYEE_SENSITIVE_CHANGE', 'MASTER_RATE_CHANGE', 'PRIVILEGED_ACTION', 'GENERIC'],
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
        $this->assertStringContainsString('roleIdsForSlugs', $source);
        $this->assertStringContainsString("'hr_director'", $source);
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

    public function testOvertimePlanUsesStableIdAndSupportsMultiStatusListing(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Attendance/OvertimeController.php');
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2024-01-01-000004_CreateLeaveOvertimeTables.php');

        $this->assertIsString($source);
        $this->assertIsString($migration);
        $this->assertStringContainsString("explode(',', \$status)", $source);
        $this->assertStringContainsString("->whereIn('o.status', \$statuses)", $source);
        $this->assertStringContainsString("\$responseBody['data']['id']", $source);
        $this->assertStringNotContainsString('$newId = (int) $db->insertID();', $source);
        $this->assertStringContainsString("'status'        => 'PLANNED'", $source);
        $this->assertMatchesRegularExpression(
            "/'start_time'\\s*=> \\['type' => 'VARCHAR', 'constraint' => 19\\]/",
            $migration
        );
        $this->assertMatchesRegularExpression(
            "/'end_time'\\s*=> \\['type' => 'VARCHAR', 'constraint' => 19\\]/",
            $migration
        );
    }

    public function testOvertimeDetailIncludesTenantScopedApprovalTimeline(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Attendance/OvertimeController.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("\$db->table('approval_requests ar')", $source);
        $this->assertStringContainsString("->where('ar.company_id', \$companyId)", $source);
        $this->assertStringContainsString("->where('ar.module', 'OVERTIME')", $source);
        $this->assertStringContainsString("->where('ar.entity_table', 'overtimes')", $source);
        $this->assertStringContainsString("->where('ar.entity_id', (int) \$id)", $source);
        $this->assertStringContainsString("\$db->table('approval_decisions ad')", $source);
        $this->assertStringContainsString("->where('ad.company_id', \$companyId)", $source);
        $this->assertStringContainsString("\$approval['workflow_snapshot']", $source);
        $this->assertStringContainsString("\$snapshot['steps']", $source);
        $this->assertStringContainsString("'condition_matched'", $source);
        $this->assertStringContainsString("'expected_approver_user_ids'", $source);
        $this->assertStringContainsString("'WAITING_APPROVAL'", $source);
        $this->assertStringContainsString("\$approval['timeline'] = \$timeline;", $source);
        $this->assertStringContainsString("\$row['approval'] = \$approval;", $source);
    }

    public function testOvertimePublicEndpointsUseApprovalEngine(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Attendance/OvertimeController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("public function approve", $source);
        $this->assertMatchesRegularExpression("/approveEntity\\(\\s*'OVERTIME'/", $source);
        $this->assertStringContainsString("public function reject", $source);
        $this->assertMatchesRegularExpression("/rejectEntity\\(\\s*'OVERTIME'/", $source);
    }

    public function testAttendanceChangeDetailIncludesTenantScopedApprovalTimeline(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Attendance/AttendanceChangeRequestController.php');
        $timeline = file_get_contents(APPPATH . 'Libraries/Approval/ApprovalTimelineService.php');

        $this->assertIsString($controller);
        $this->assertIsString($timeline);
        $this->assertStringContainsString("->where('cr.company_id', \$companyId)", $controller);
        $this->assertStringContainsString('new ApprovalTimelineService()', $controller);
        $this->assertStringContainsString("'ATTENDANCE_CHANGE'", $controller);
        $this->assertStringContainsString("'attendance_change_requests'", $controller);
        $this->assertStringContainsString("\$snapshot['steps']", $timeline);
        $this->assertStringContainsString("'expected_approver_user_ids'", $timeline);
        $this->assertStringContainsString("'WAITING_APPROVAL'", $timeline);
        $this->assertStringContainsString("\$approval['timeline'] = \$timeline;", $timeline);
    }

    public function testAttendanceCalendarRoutesRequestHistoryByRequestType(): void
    {
        $source = file_get_contents(dirname(ROOTPATH) . '/frontend/src/views/Attendance/MyAttendance.vue');

        $this->assertIsString($source);
        $this->assertStringContainsString('const requestHistoryRoute = computed(', $source);
        $this->assertStringContainsString('selectedDay.value?.change_request', $source);
        $this->assertStringContainsString("return '/attendance/my-changes'", $source);
        $this->assertStringContainsString("return '/leaves?scope=mine'", $source);
        $this->assertStringContainsString(':to="requestHistoryRoute"', $source);
        $this->assertStringNotContainsString('<RouterLink to="/leaves"', $source);
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
        $this->assertStringContainsString('submitRetire(', $workflow);
        $this->assertStringContainsString('asset_payload_hash', $workflow);
        $this->assertStringContainsString('submitAssign(', $controller);
        $this->assertStringContainsString('submitReturn(', $controller);
        $this->assertStringContainsString('function retire(', $controller);
        $this->assertStringNotContainsString('$update[\'status\'] = $data[\'status\']', $controller);
        $this->assertStringContainsString("return 'ASSET_TRANSACTION';", $adapter);
    }

    public function testHireConversionUsesEncryptedPayloadAndFinalDomainAdapter(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Recruitment/RecruitmentController.php');
        $workflow = file_get_contents(APPPATH . 'Libraries/Recruitment/HireApprovalWorkflowService.php');
        $adapter = file_get_contents(APPPATH . 'Libraries/Approval/Adapters/HireConversionApprovalDomainAdapter.php');

        $this->assertStringContainsString("'module' => 'HIRE_CONVERSION'", $workflow);
        $this->assertStringContainsString('hire_payload_encrypted', $workflow);
        $this->assertStringContainsString('hire_snapshot_hash', $workflow);
        $this->assertStringContainsString('HIRE_OFFER_MISMATCH', $workflow);
        $this->assertStringContainsString('HireApprovalWorkflowService())', $controller);
        $this->assertStringNotContainsString('Services::recruitment()->convertToEmployee(', $controller);
        $this->assertStringContainsString("return 'HIRE_CONVERSION';", $adapter);
    }

    public function testPerformanceGoalUsesOwnerSubmitAndFinalDomainAdapter(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Performance/PerformanceController.php');
        $workflow = file_get_contents(APPPATH . 'Libraries/Performance/PerformanceGoalApprovalService.php');
        $adapter = file_get_contents(APPPATH . 'Libraries/Approval/Adapters/PerformanceGoalApprovalDomainAdapter.php');

        $this->assertStringContainsString('function submitGoal(', $controller);
        $this->assertStringContainsString('Direct approval dinonaktifkan', $controller);
        $this->assertStringContainsString("'module' => 'PERFORMANCE_GOAL'", $workflow);
        $this->assertStringContainsString('goal_snapshot_hash', $workflow);
        $this->assertStringContainsString('Total bobot goal dalam cycle harus 100%', $workflow);
        $this->assertStringContainsString("return 'PERFORMANCE_GOAL';", $adapter);
    }

    public function testIdpActivationIsTenantScopedAndUsesFinalDomainAdapter(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Performance/IdpController.php');
        $service = file_get_contents(APPPATH . 'Libraries/Performance/IdpService.php');
        $workflow = file_get_contents(APPPATH . 'Libraries/Performance/IdpApprovalWorkflowService.php');
        $adapter = file_get_contents(APPPATH . 'Libraries/Approval/Adapters/IdpActivationApprovalDomainAdapter.php');

        $this->assertStringContainsString('submitActivation(', $controller);
        $this->assertStringContainsString('Direct finalize dinonaktifkan', $service);
        $this->assertStringContainsString("'module' => 'IDP_ACTIVATION'", $workflow);
        $this->assertStringContainsString('idp_snapshot_hash', $workflow);
        $this->assertStringContainsString("return 'IDP_ACTIVATION';", $adapter);
    }
}
