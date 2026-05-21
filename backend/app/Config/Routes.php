<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes = service('routes');

// ─── CORS preflight catch-all ─────────────────────────────────────
// Match any OPTIONS request to /api/* and answer immediately.
// The ApiCorsFilter (global before) sets the actual CORS headers; this
// route just ensures routing succeeds so the filter can run.
$routes->options('api/(:any)', static function () {
    return service('response')->setStatusCode(204);
});

// API v1 routes
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function ($routes) {

    // ─── Public (no auth) ───────────────────────────────────────────
    $routes->group('auth', static function ($routes) {
        $routes->post('login', 'AuthController::login', ['filter' => 'throttle:login']);
        $routes->post('refresh', 'AuthController::refresh');
        $routes->post('forgot-password', 'AuthController::forgotPassword', ['filter' => 'throttle:login']);
        $routes->post('reset-password', 'AuthController::resetPassword', ['filter' => 'throttle:login']);
        $routes->post('verify-2fa', 'AuthController::verifyTwoFactor', ['filter' => 'throttle:login']);
    });

    // Health check
    $routes->get('health', 'HealthController::index');

    // ─── Authenticated routes ────────────────────────────────────────
    $routes->group('', ['filter' => 'jwt'], static function ($routes) {

        // Auth (authenticated)
        $routes->post('auth/logout', 'AuthController::logout');
        $routes->get('auth/me', 'AuthController::me');
        $routes->post('auth/change-password', 'AuthController::changePassword');
        $routes->post('auth/2fa/enable', 'AuthController::enableTwoFactor');
        $routes->post('auth/2fa/disable', 'AuthController::disableTwoFactor');

        // Master: Companies / Business Units
        $routes->resource('companies', ['controller' => 'CompanyController']);
        $routes->resource('branches', ['controller' => 'BranchController']);
        $routes->resource('departments', ['controller' => 'DepartmentController']);
        $routes->resource('positions', ['controller' => 'PositionController']);
        $routes->resource('job-grades', ['controller' => 'JobGradeController']);

        // Employees
        $routes->resource('employees', ['controller' => 'EmployeeController']);
        $routes->get('employees/(:num)/contracts', 'EmployeeController::contracts/$1');
        $routes->post('employees/(:num)/contracts', 'EmployeeController::addContract/$1');
        $routes->get('employees/(:num)/documents', 'EmployeeController::documents/$1');
        $routes->post('employees/(:num)/documents', 'EmployeeController::uploadDocument/$1');

        // Attendance
        $routes->get('attendance', 'AttendanceController::index');
        $routes->post('attendance/clock-in', 'AttendanceController::clockIn');
        $routes->post('attendance/clock-out', 'AttendanceController::clockOut');
        $routes->get('attendance/my', 'AttendanceController::my');
        $routes->get('attendance/calendar/(:num)', 'AttendanceController::calendar/$1');

        // Attendance change requests (pengajuan perubahan + approval)
        // Catatan: definisikan SEBELUM route catch-all `attendance/(:num)`
        // supaya path /attendance/change-requests tidak nyangkut sebagai id.
        $routes->get('attendance/change-requests', 'AttendanceChangeRequestController::index');
        $routes->post('attendance/change-requests', 'AttendanceChangeRequestController::create');
        $routes->get('attendance/change-requests/(:num)', 'AttendanceChangeRequestController::show/$1');
        $routes->post('attendance/change-requests/(:num)/approve', 'AttendanceChangeRequestController::approve/$1');
        $routes->post('attendance/change-requests/(:num)/reject', 'AttendanceChangeRequestController::reject/$1');
        $routes->post('attendance/change-requests/(:num)/cancel', 'AttendanceChangeRequestController::cancel/$1');

        $routes->get('attendance/(:num)', 'AttendanceController::show/$1');
        $routes->post('attendance/(:num)/correction', 'AttendanceController::correction/$1');

        // Shifts & Roster
        $routes->resource('shifts', ['controller' => 'ShiftController']);
        $routes->resource('shift-schedules', ['controller' => 'ShiftScheduleController']);

        // Shift assignments — pemetaan shift ke scope
        $routes->get('shift-assignments',                'ShiftAssignmentController::index');
        $routes->get('shift-assignments/preview',        'ShiftAssignmentController::preview');
        $routes->get('shift-assignments/(:num)',         'ShiftAssignmentController::show/$1');
        $routes->post('shift-assignments',               'ShiftAssignmentController::create');
        $routes->put('shift-assignments/(:num)',         'ShiftAssignmentController::update/$1');
        $routes->delete('shift-assignments/(:num)',      'ShiftAssignmentController::delete/$1');

        // Shift patterns — pola siklus
        $routes->get('shift-patterns',                   'ShiftPatternController::index');
        $routes->get('shift-patterns/(:num)',            'ShiftPatternController::show/$1');
        $routes->post('shift-patterns',                  'ShiftPatternController::create');
        $routes->put('shift-patterns/(:num)',            'ShiftPatternController::update/$1');
        $routes->delete('shift-patterns/(:num)',         'ShiftPatternController::delete/$1');
        $routes->get('shift-pattern-assignments',        'ShiftPatternController::listAssignments');
        $routes->post('shift-pattern-assignments',       'ShiftPatternController::createAssignment');
        $routes->delete('shift-pattern-assignments/(:num)', 'ShiftPatternController::deleteAssignment/$1');

        // Roster — matrix view & generator
        $routes->get('roster',                           'RosterController::index');
        $routes->post('roster/generate',                 'RosterController::generate');
        $routes->post('roster/bulk-update',              'RosterController::bulkUpdate');
        $routes->post('roster/clear',                    'RosterController::clear');

        // Leaves
        // Endpoint khusus harus didefinisikan SEBELUM resource supaya
        // path /leave-types/for-me tidak nyangkut sebagai id resource.
        $routes->get('leave-types/for-me', 'LeaveController::eligibleTypes');
        $routes->resource('leave-types', ['controller' => 'LeaveTypeController']);
        // Sama: team-calendar harus di atas resource supaya tidak ke-trap
        // sebagai /leaves/(:num).
        $routes->get('leaves/team-calendar', 'LeaveController::teamCalendar');
        $routes->resource('leaves', ['controller' => 'LeaveController']);
        $routes->post('leaves/(:num)/approve', 'LeaveController::approve/$1');
        $routes->post('leaves/(:num)/reject', 'LeaveController::reject/$1');
        $routes->get('leave-balances/(:num)', 'LeaveController::balance/$1');

        // Overtime
        $routes->resource('overtimes', ['controller' => 'OvertimeController']);
        $routes->post('overtimes/(:num)/approve', 'OvertimeController::approve/$1');
        $routes->post('overtimes/(:num)/reject', 'OvertimeController::reject/$1');

        // Payroll
        $routes->resource('payroll-components', ['controller' => 'PayrollComponentController']);
        $routes->resource('payroll-periods', ['controller' => 'PayrollPeriodController']);
        $routes->post('payroll-periods/(:num)/calculate', 'PayrollPeriodController::calculate/$1');
        $routes->post('payroll-periods/(:num)/finalize', 'PayrollPeriodController::finalize/$1');
        // Payroll Output (export untuk bank, e-SPT, BPJS)
        $routes->get('payroll-periods/(:num)/export-summary', 'PayrollPeriodController::exportSummary/$1');
        $routes->get('payroll-periods/(:num)/bank-export',    'PayrollPeriodController::bankExport/$1');
        $routes->get('payroll-periods/(:num)/pph21-export',   'PayrollPeriodController::pph21Export/$1');
        $routes->get('payroll-periods/(:num)/bpjs-export',    'PayrollPeriodController::bpjsExport/$1');
        $routes->get('payslips', 'PayslipController::index');
        $routes->get('payslips/my', 'PayslipController::my');
        $routes->get('payslips/(:num)', 'PayslipController::show/$1');
        $routes->get('payslips/(:num)/pdf', 'PayslipController::pdf/$1');
        $routes->get('payslips/(:num)/bukti-potong', 'PayslipController::buktiPotong/$1');
        $routes->post('payroll/thr', 'PayrollController::generateThr');

        // Reimbursement & Loans
        $routes->resource('reimbursements', ['controller' => 'ReimbursementController']);
        $routes->resource('loans', ['controller' => 'LoanController']);

        // Notifications
        $routes->get('notifications', 'NotificationController::index');
        $routes->get('notifications/unread-count', 'NotificationController::unreadCount');
        $routes->post('notifications/(:num)/read', 'NotificationController::markRead/$1');
        $routes->post('notifications/read-all', 'NotificationController::markAllRead');

        // Roles & Permissions (admin)
        $routes->resource('roles', ['controller' => 'RoleController']);
        $routes->resource('permissions', ['controller' => 'PermissionController']);
        $routes->resource('users', ['controller' => 'UserController']);

        // Reports
        $routes->get('reports/headcount', 'ReportController::headcount');
        $routes->get('reports/attendance-summary', 'ReportController::attendanceSummary');
        $routes->get('reports/payroll-summary', 'ReportController::payrollSummary');
        $routes->get('reports/leave-summary', 'ReportController::leaveSummary');

        // Audit log (admin only)
        $routes->get('audit-logs', 'AuditLogController::index');
        $routes->get('audit-logs/stats', 'AuditLogController::stats');
        $routes->get('audit-logs/export', 'AuditLogController::export');
        $routes->get('audit-logs/(:num)', 'AuditLogController::show/$1');

        // Settings (per company)
        $routes->get('settings', 'SettingController::index');
        $routes->put('settings', 'SettingController::update');

        // Allowance mapping (per cabang & grade)
        $routes->get('allowance-types',          'AllowanceController::listTypes');
        $routes->post('allowance-types',         'AllowanceController::createType');
        $routes->put('allowance-types/(:num)',   'AllowanceController::updateType/$1');
        $routes->delete('allowance-types/(:num)','AllowanceController::deleteType/$1');

        $routes->get('allowance-rates',          'AllowanceController::listRates');
        $routes->post('allowance-rates',         'AllowanceController::createRate');
        $routes->put('allowance-rates/(:num)',   'AllowanceController::updateRate/$1');
        $routes->delete('allowance-rates/(:num)','AllowanceController::deleteRate/$1');

        $routes->get('employee-allowances/(:num)',                    'AllowanceController::listForEmployee/$1');
        $routes->post('employee-allowances/(:num)/recompute',         'AllowanceController::recomputeEmployee/$1');
        $routes->put('employee-allowances/(:num)',                    'AllowanceController::overrideAssignment/$1');
        $routes->post('allowances/recompute-company',                 'AllowanceController::recomputeCompany');

        // ─── Work Locations (lokasi kerja granular) ─────────────────
        $routes->resource('work-locations', ['controller' => 'WorkLocationController']);
        $routes->get('employees/(:num)/work-locations',  'WorkLocationController::employeeHistory/$1');
        $routes->post('employees/(:num)/work-locations', 'WorkLocationController::assignEmployee/$1');

        // ─── Anniversary / Birthday Widget ─────────────────────────
        $routes->get('anniversary/upcoming', 'AnniversaryController::upcoming');
        $routes->get('anniversary/today',    'AnniversaryController::today');

        // ─── Training & Sertifikasi ────────────────────────────────
        $routes->get('trainings-stats',                          'TrainingController::stats');
        $routes->get('certificates/expiring',                    'TrainingController::expiringCertificates');
        $routes->get('certificates',                             'TrainingController::listCertificates');
        $routes->post('certificates',                            'TrainingController::createCertificate');
        $routes->get('certificates/(:num)',                      'TrainingController::showCertificate/$1');
        $routes->put('certificates/(:num)',                      'TrainingController::updateCertificate/$1');
        $routes->delete('certificates/(:num)',                   'TrainingController::deleteCertificate/$1');
        $routes->get('training-budgets',                         'TrainingController::listBudgets');
        $routes->post('training-budgets',                        'TrainingController::createBudget');
        $routes->put('training-budgets/(:num)',                  'TrainingController::updateBudget/$1');
        $routes->post('training-registrations/(:num)/approve',   'TrainingController::approveRegistration/$1');
        $routes->post('training-registrations/(:num)/reject',    'TrainingController::rejectRegistration/$1');
        $routes->post('training-registrations/(:num)/complete',  'TrainingController::completeRegistration/$1');
        $routes->post('training-registrations/(:num)/cancel',    'TrainingController::cancelRegistration/$1');
        $routes->get('trainings',                                'TrainingController::index');
        $routes->post('trainings',                               'TrainingController::create');
        $routes->get('trainings/(:num)',                         'TrainingController::show/$1');
        $routes->put('trainings/(:num)',                         'TrainingController::update/$1');
        $routes->delete('trainings/(:num)',                      'TrainingController::delete/$1');
        $routes->post('trainings/(:num)/publish',                'TrainingController::publish/$1');
        $routes->get('trainings/(:num)/registrations',           'TrainingController::listRegistrations/$1');
        $routes->post('trainings/(:num)/register',               'TrainingController::register/$1');

        // ─── Performance Management (OKR / KPI / Review) ───────────
        // My (specific) — di atas resource biar tidak ke-trap
        $routes->get('performance/my',                         'PerformanceController::my');
        // Cycles
        $routes->get('performance-cycles',                     'PerformanceController::listCycles');
        $routes->post('performance-cycles',                    'PerformanceController::createCycle');
        $routes->get('performance-cycles/(:num)',              'PerformanceController::showCycle/$1');
        $routes->put('performance-cycles/(:num)',              'PerformanceController::updateCycle/$1');
        $routes->delete('performance-cycles/(:num)',           'PerformanceController::deleteCycle/$1');
        $routes->post('performance-cycles/(:num)/status',      'PerformanceController::changeCycleStatus/$1');
        // Goals — endpoint khusus di atas resource
        $routes->get('goals/weights/(:num)/(:num)',            'PerformanceController::validateWeights/$1/$2');
        $routes->post('goals/(:num)/approve',                  'PerformanceController::approveGoal/$1');
        $routes->post('goals/(:num)/check-in',                 'PerformanceController::checkInGoal/$1');
        $routes->get('goals',                                  'PerformanceController::listGoals');
        $routes->post('goals',                                 'PerformanceController::createGoal');
        $routes->get('goals/(:num)',                           'PerformanceController::showGoal/$1');
        $routes->put('goals/(:num)',                           'PerformanceController::updateGoal/$1');
        $routes->delete('goals/(:num)',                        'PerformanceController::deleteGoal/$1');
        // Reviews
        $routes->post('performance-reviews/(:num)/submit',     'PerformanceController::submitReview/$1');
        $routes->post('performance-reviews/(:num)/acknowledge','PerformanceController::acknowledgeReview/$1');
        $routes->post('performance-reviews/(:num)/finalize',   'PerformanceController::finalizeReview/$1');
        $routes->get('performance-reviews',                    'PerformanceController::listReviews');
        $routes->post('performance-reviews',                   'PerformanceController::createReview');
        $routes->get('performance-reviews/(:num)',             'PerformanceController::showReview/$1');
        $routes->put('performance-reviews/(:num)',             'PerformanceController::updateReview/$1');

        // ─── Anti-Cheat Attendance (HR Review) ─────────────────────
        $routes->get('attendance-anomaly',                    'AnomalyController::index');
        $routes->get('attendance-anomaly/stats',              'AnomalyController::stats');
        $routes->post('attendance-anomaly/(:num)/review',     'AnomalyController::review/$1');
        $routes->get('attendance-devices',                    'AnomalyController::devices');
        $routes->post('attendance-devices/(:num)/trust',      'AnomalyController::trustDevice/$1');
        $routes->post('attendance-devices/(:num)/block',      'AnomalyController::blockDevice/$1');

        // ─── HR Helpdesk / Tickets ─────────────────────────────────
        $routes->get('helpdesk/stats',                       'HelpdeskController::stats');
        $routes->get('helpdesk/tickets',                     'HelpdeskController::index');
        $routes->post('helpdesk/tickets',                    'HelpdeskController::create');
        $routes->get('helpdesk/tickets/(:num)',              'HelpdeskController::show/$1');
        $routes->put('helpdesk/tickets/(:num)',              'HelpdeskController::update/$1');
        $routes->delete('helpdesk/tickets/(:num)',           'HelpdeskController::delete/$1');
        $routes->post('helpdesk/tickets/(:num)/assign',      'HelpdeskController::assign/$1');
        $routes->post('helpdesk/tickets/(:num)/status',      'HelpdeskController::changeStatus/$1');
        $routes->post('helpdesk/tickets/(:num)/close',       'HelpdeskController::close/$1');
        $routes->post('helpdesk/tickets/(:num)/reopen',      'HelpdeskController::reopen/$1');
        $routes->post('helpdesk/tickets/(:num)/comments',    'HelpdeskController::addComment/$1');

        // ─── Recruitment / ATS ─────────────────────────────────────
        $routes->get('recruitment/pipeline-stats',  'RecruitmentController::pipelineStats');
        $routes->get('job-postings',                'RecruitmentController::indexJobs');
        $routes->post('job-postings',               'RecruitmentController::createJob');
        $routes->get('job-postings/(:num)',         'RecruitmentController::showJob/$1');
        $routes->put('job-postings/(:num)',         'RecruitmentController::updateJob/$1');
        $routes->delete('job-postings/(:num)',      'RecruitmentController::deleteJob/$1');
        $routes->post('job-postings/(:num)/open',   'RecruitmentController::openJob/$1');
        $routes->post('job-postings/(:num)/close',  'RecruitmentController::closeJob/$1');
        $routes->get('candidates',                          'RecruitmentController::indexCandidates');
        $routes->post('candidates',                         'RecruitmentController::createCandidate');
        $routes->get('candidates/(:num)',                   'RecruitmentController::showCandidate/$1');
        $routes->put('candidates/(:num)',                   'RecruitmentController::updateCandidate/$1');
        $routes->delete('candidates/(:num)',                'RecruitmentController::deleteCandidate/$1');
        $routes->post('candidates/(:num)/move-stage',       'RecruitmentController::moveStage/$1');
        $routes->post('candidates/(:num)/reject',           'RecruitmentController::rejectCandidate/$1');
        $routes->post('candidates/(:num)/hire',             'RecruitmentController::hireCandidate/$1');

        // ─── Business Trip / SPPD ──────────────────────────────────
        $routes->put('business-trip-expenses/(:num)',    'BusinessTripController::updateExpense/$1');
        $routes->delete('business-trip-expenses/(:num)', 'BusinessTripController::deleteExpense/$1');
        $routes->get('business-trips',                       'BusinessTripController::index');
        $routes->post('business-trips',                      'BusinessTripController::create');
        $routes->get('business-trips/(:num)',                'BusinessTripController::show/$1');
        $routes->put('business-trips/(:num)',                'BusinessTripController::update/$1');
        $routes->delete('business-trips/(:num)',             'BusinessTripController::delete/$1');
        $routes->post('business-trips/(:num)/submit',        'BusinessTripController::submit/$1');
        $routes->post('business-trips/(:num)/approve',       'BusinessTripController::approve/$1');
        $routes->post('business-trips/(:num)/reject',        'BusinessTripController::reject/$1');
        $routes->post('business-trips/(:num)/start',         'BusinessTripController::start/$1');
        $routes->post('business-trips/(:num)/complete',      'BusinessTripController::complete/$1');
        $routes->post('business-trips/(:num)/settle',        'BusinessTripController::settle/$1');
        $routes->post('business-trips/(:num)/cancel',        'BusinessTripController::cancel/$1');
        $routes->get('business-trips/(:num)/sppd',           'BusinessTripController::sppd/$1');
        $routes->get('business-trips/(:num)/expenses',       'BusinessTripController::listExpenses/$1');
        $routes->post('business-trips/(:num)/expenses',      'BusinessTripController::addExpense/$1');

        // ─── Asset / Inventory ─────────────────────────────────────
        $routes->get('asset-assignments',                'AssetController::listAssignments');
        $routes->get('asset-assignments/employee/(:num)','AssetController::forEmployee/$1');
        $routes->get('asset-assignments/(:num)/bast',    'AssetController::bast/$1');
        $routes->get('assets',                           'AssetController::index');
        $routes->post('assets',                          'AssetController::create');
        $routes->get('assets/(:num)',                    'AssetController::show/$1');
        $routes->put('assets/(:num)',                    'AssetController::update/$1');
        $routes->delete('assets/(:num)',                 'AssetController::delete/$1');
        $routes->post('assets/(:num)/assign',            'AssetController::assign/$1');
        $routes->post('assets/(:num)/return',            'AssetController::returnAsset/$1');

        // ─── Onboarding & Offboarding Tasks ────────────────────────
        // Templates
        $routes->get('task-templates',                'TaskTemplateController::index');
        $routes->get('task-templates/(:num)',         'TaskTemplateController::show/$1');
        $routes->post('task-templates',               'TaskTemplateController::create');
        $routes->put('task-templates/(:num)',         'TaskTemplateController::update/$1');
        $routes->delete('task-templates/(:num)',      'TaskTemplateController::delete/$1');
        // Employee tasks (instance)
        $routes->get('employee-tasks/board',          'EmployeeTaskController::board');
        $routes->get('employee-tasks/employee/(:num)','EmployeeTaskController::forEmployee/$1');
        $routes->post('employee-tasks/generate',      'EmployeeTaskController::generate');
        $routes->get('employee-tasks',                'EmployeeTaskController::index');
        $routes->post('employee-tasks',               'EmployeeTaskController::create');
        $routes->get('employee-tasks/(:num)',         'EmployeeTaskController::show/$1');
        $routes->put('employee-tasks/(:num)',         'EmployeeTaskController::update/$1');
        $routes->delete('employee-tasks/(:num)',      'EmployeeTaskController::delete/$1');
        $routes->post('employee-tasks/(:num)/complete','EmployeeTaskController::complete/$1');

        // ─── Severance / Pesangon (PHK) ────────────────────────────
        // Endpoint khusus didefinisikan SEBELUM CRUD
        $routes->get('severance/reasons',                'SeveranceController::reasons');
        $routes->post('severance/calculate',             'SeveranceController::preview');
        $routes->get('severance',                        'SeveranceController::index');
        $routes->post('severance',                       'SeveranceController::create');
        $routes->get('severance/(:num)',                 'SeveranceController::show/$1');
        $routes->put('severance/(:num)',                 'SeveranceController::update/$1');
        $routes->delete('severance/(:num)',              'SeveranceController::delete/$1');
        $routes->post('severance/(:num)/finalize',       'SeveranceController::finalize/$1');
        $routes->post('severance/(:num)/mark-paid',      'SeveranceController::markPaid/$1');
        $routes->post('severance/(:num)/cancel',         'SeveranceController::cancel/$1');
        $routes->get('severance/(:num)/breakdown',       'SeveranceController::breakdown/$1');

        // ─── Disciplinary Records (Surat Peringatan) ───────────────
        // Endpoint khusus didefinisikan SEBELUM CRUD agar path seperti
        // /disciplinary-records/categories tidak ke-trap sebagai id.
        $routes->get('disciplinary-records/categories',                  'DisciplinaryController::listCategories');
        $routes->post('disciplinary-records/categories',                 'DisciplinaryController::createCategory');
        $routes->put('disciplinary-records/categories/(:num)',           'DisciplinaryController::updateCategory/$1');
        $routes->delete('disciplinary-records/categories/(:num)',        'DisciplinaryController::deleteCategory/$1');
        $routes->get('disciplinary-records/employee-summary/(:num)',     'DisciplinaryController::employeeSummary/$1');
        $routes->get('disciplinary-records',                             'DisciplinaryController::index');
        $routes->post('disciplinary-records',                            'DisciplinaryController::create');
        $routes->get('disciplinary-records/(:num)',                      'DisciplinaryController::show/$1');
        $routes->put('disciplinary-records/(:num)',                      'DisciplinaryController::update/$1');
        $routes->delete('disciplinary-records/(:num)',                   'DisciplinaryController::delete/$1');
        $routes->post('disciplinary-records/(:num)/revoke',              'DisciplinaryController::revoke/$1');
        $routes->post('disciplinary-records/(:num)/acknowledge',         'DisciplinaryController::acknowledge/$1');
        $routes->get('disciplinary-records/(:num)/letter',               'DisciplinaryController::letter/$1');

        // ─── Approval Delegations ──────────────────────────────────
        $routes->get('approval-delegations',           'ApprovalDelegationController::index');
        $routes->get('approval-delegations/(:num)',    'ApprovalDelegationController::show/$1');
        $routes->post('approval-delegations',          'ApprovalDelegationController::create');
        $routes->put('approval-delegations/(:num)',    'ApprovalDelegationController::update/$1');
        $routes->delete('approval-delegations/(:num)', 'ApprovalDelegationController::delete/$1');

        // ─── Approval Chains ───────────────────────────────────────
        $routes->get('approval-chains',            'ApprovalChainController::index');
        $routes->get('approval-chains/(:num)',     'ApprovalChainController::show/$1');
        $routes->post('approval-chains',           'ApprovalChainController::create');
        $routes->put('approval-chains/(:num)',     'ApprovalChainController::update/$1');
        $routes->delete('approval-chains/(:num)',  'ApprovalChainController::delete/$1');
        // Assignment rules — chain mana berlaku untuk dept/branch/grade tertentu
        $routes->get('approval-assignments',       'ApprovalChainController::listAssignments');
        $routes->post('approval-assignments',      'ApprovalChainController::createAssignment');
        $routes->delete('approval-assignments/(:num)', 'ApprovalChainController::deleteAssignment/$1');
        // Inbox + decision
        $routes->get('approval-requests/inbox',         'ApprovalChainController::inbox');
        $routes->get('approval-requests/(:num)',        'ApprovalChainController::showRequest/$1');
        $routes->post('approval-requests/(:num)/approve','ApprovalChainController::approveRequest/$1');
        $routes->post('approval-requests/(:num)/reject', 'ApprovalChainController::rejectRequest/$1');

        // ─── Company Regulations (klausul peraturan) ───────────────
        $routes->get('regulations',                'CompanyRegulationController::index');
        $routes->get('regulations/preview',        'CompanyRegulationController::preview');
        $routes->get('regulations/(:num)',         'CompanyRegulationController::show/$1');
        $routes->post('regulations',               'CompanyRegulationController::create');
        $routes->put('regulations/(:num)',         'CompanyRegulationController::update/$1');
        $routes->delete('regulations/(:num)',      'CompanyRegulationController::delete/$1');

        // ─── Contract Templates ────────────────────────────────────
        $routes->get('contract-templates',                       'ContractTemplateController::index');
        $routes->get('contract-templates/variables',             'ContractTemplateController::variables');
        $routes->get('contract-templates/(:num)',                'ContractTemplateController::show/$1');
        $routes->post('contract-templates',                      'ContractTemplateController::create');
        $routes->put('contract-templates/(:num)',                'ContractTemplateController::update/$1');
        $routes->delete('contract-templates/(:num)',             'ContractTemplateController::delete/$1');
        $routes->post('contract-templates/(:num)/preview',       'ContractTemplateController::preview/$1');
        $routes->post('contract-templates/(:num)/generate',      'ContractTemplateController::generate/$1');

        // ─── Generated Contracts ───────────────────────────────────
        $routes->get('generated-contracts',                  'GeneratedContractController::index');
        $routes->get('generated-contracts/(:num)',           'GeneratedContractController::show/$1');
        $routes->get('generated-contracts/(:num)/html',      'GeneratedContractController::html/$1');
        $routes->put('generated-contracts/(:num)/status',    'GeneratedContractController::updateStatus/$1');
        $routes->delete('generated-contracts/(:num)',        'GeneratedContractController::delete/$1');
    });
});

// Fallback 404 — MUST send & return string body, bukan Response object,
// supaya tidak memicu "Object cannot be converted to string" pada beberapa
// kondisi (mis. preflight OPTIONS yang nyasar ke 404).
$routes->set404Override(static function () {
    $resp = service('response')
        ->setStatusCode(404)
        ->setJSON(['status' => 'error', 'message' => 'Endpoint not found']);
    $resp->send();
    exit;
});
