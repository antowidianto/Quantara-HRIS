<?php
/**
 * Shared module map for refactor_modules.php / _fixup.php.
 *
 * Returns: [ctrlClassToModule, modelClassToModule, libClassToModule]
 */

declare(strict_types=1);

$controllerMap = [
    'Auth'         => ['AuthController', 'CandidateAuthController'],
    'Identity'     => ['UserController', 'RoleController', 'PermissionController', 'MenuController'],
    'Master'       => ['CompanyController', 'BranchController', 'DepartmentController', 'PositionController', 'JobGradeController', 'JobTitleController', 'CostCenterController', 'WorkLocationController', 'OrgStructureController', 'OrganizationRoleController'],
    'Employee'     => ['EmployeeController', 'EmployeeTransferController', 'Employee360Controller', 'ProbationController', 'AnniversaryController', 'JicController'],
    'Attendance'   => ['AttendanceController', 'AttendanceChangeRequestController', 'AnomalyController', 'ShiftController', 'ShiftAssignmentController', 'ShiftPatternController', 'ShiftScheduleController', 'RosterController', 'WfhRequestController', 'BusinessTripController', 'OvertimeController'],
    'Leave'        => ['LeaveController', 'LeaveTypeController'],
    'Payroll'      => ['PayrollController', 'PayrollComponentController', 'PayrollPeriodController', 'PayslipController', 'AllowanceController', 'BonusController', 'ReimbursementController', 'LoanController'],
    'Performance'  => ['PerformanceController', 'IdpController'],
    'Recruitment'  => ['RecruitmentController', 'RecruitmentPipelineController', 'CareerController', 'CareerPublicController', 'OnlineTestController', 'OnlineTestCategoryController', 'CandidateOnlineTestController', 'OfferController'],
    'Lifecycle'    => ['OffboardingController', 'ResignationController', 'TerminationController', 'SeveranceController', 'ExitInterviewController', 'EmployeeTaskController', 'TaskTemplateController', 'GeneratedContractController', 'ContractTemplateController', 'DisciplinaryController'],
    'Manpower'     => ['ManpowerController'],
    'Training'     => ['TrainingController'],
    'Asset'        => ['AssetController'],
    'Approval'     => ['ApprovalChainController', 'ApprovalDelegationController'],
    'Notification' => ['NotificationController', 'NotificationPreferenceController', 'WebPushController', 'DeviceController'],
    'Webhook'      => ['WebhookController', 'InboundWebhookController'],
    'Compliance'   => ['ComplianceReportController', 'PrivacyController', 'AuditLogController', 'CspReportController'],
    'Helpdesk'     => ['HelpdeskController'],
    'System'       => ['SettingController', 'EmailSettingsController', 'CompanyRegulationController', 'ImportController', 'FileUploadController', 'FileServeController', 'HealthController', 'MobileVersionController', 'OpenApiController', 'ReportController', 'Horizon2Controller', 'Horizon3Controller', 'Horizon4Controller'],
];

$modelMap = [
    'Identity'   => ['UserModel', 'MenuModel', 'DeviceTokenModel'],
    'Master'     => ['CompanyModel', 'BranchModel', 'DepartmentModel', 'PositionModel', 'JobGradeModel'],
    'Employee'   => ['EmployeeModel'],
    'Leave'      => ['LeaveRequestModel'],
    'Attendance' => ['OvertimeRequestModel'],
    'Payroll'    => ['PayslipModel'],
    'System'     => ['IdempotencyKeyModel'],
];

$libraryMap = [
    'Auth'         => ['CandidateAuthService', 'JwtService', 'PasswordPolicy', 'TwoFactorBackupCodes'],
    'Master'       => ['CostCenterService', 'OrganizationStructureService'],
    'Employee'     => ['Employee360Service', 'EmployeeTransferService', 'JicService', 'ProbationService'],
    'Attendance'   => ['AntiCheatService', 'BusinessTripService', 'OvertimeCalculator', 'ShiftResolver', 'ShiftTimeHelper', 'WfhRequestService'],
    'Leave'        => ['HalfDayLeaveCalculator', 'LeaveBalanceService', 'LongSickLeaveCalculator', 'TenureLeaveQuotaCalculator'],
    'Payroll'      => ['AllowanceService', 'BonusCalculator', 'BonusService', 'BpjsCalculator', 'JkpPayslipIntegration', 'PayrollExporter', 'PayrollService', 'PayrollSettings', 'PayrollSimulationService', 'Pph21TerCalculator', 'Pph21YearEndCorrection', 'ThrMultiContractCalculator'],
    'Performance'  => ['CompetencyService', 'IdpService', 'PerformanceCascadingService', 'PerformanceService', 'PulseSurveyService', 'SuccessionService'],
    'Recruitment'  => ['CandidateScoringService', 'CareerService', 'OfferService', 'OnboardingPlanService', 'OnboardingService', 'OnlineTestService', 'RecruitmentService', 'ReferralProgramService', 'TalentPoolService'],
    'Lifecycle'    => ['ComplianceReportService', 'ContractRenderer', 'DisciplinaryService', 'ExitInterviewService', 'OffboardingService', 'PaklaringService', 'SeveranceCalculator', 'TerminationService'],
    'Manpower'     => ['ManpowerService', 'WorkforceSimulationService'],
    'Training'     => ['TrainingService'],
    'Asset'        => ['AssetService'],
    'Approval'     => ['ApprovalConditionEvaluator', 'ApprovalDelegationResolver', 'ApprovalEngine', 'ReportingChainService'],
    'Notification' => ['NotificationService', 'PushLocalizer', 'PushNotifier', 'QuietHours', 'WhatsAppService'],
    'Webhook'      => ['InboundWebhookHandler', 'WebhookDispatcher', 'WebhookSigner'],
    'Compliance'   => ['AuditLogger', 'DataPrivacyService', 'PrivacyScope', 'TurnoverAnalyticsService'],
    'Analytics'    => ['AiAssistantService', 'CompensationIntelligenceService'],
    'System'       => ['CacheService', 'CircuitBreaker', 'CircuitOpenException', 'EmailQueueBackoff', 'EmailTemplateRenderer', 'Encryption', 'ExceptionLogger', 'FieldsHelper', 'FileSignatureValidator', 'IndonesianValidator', 'JobQueue', 'LookupCache', 'OcrService', 'TenantContext', 'Telemetry'],
];

$flatten = static function (array $map): array {
    $out = [];
    foreach ($map as $module => $classes) {
        foreach ($classes as $c) {
            $out[$c] = $module;
        }
    }
    return $out;
};

return [
    $flatten($controllerMap),
    $flatten($modelMap),
    $flatten($libraryMap),
];