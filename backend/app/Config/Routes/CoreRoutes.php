<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class CoreRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // Notifications
        $routes->get('notifications', 'Notification\NotificationController::index', ['filter' => 'ownership:account']);
        $routes->get('notifications/unread-count', 'Notification\NotificationController::unreadCount', ['filter' => 'ownership:account']);
        $routes->post('notifications/(:num)/read', 'Notification\NotificationController::markRead/$1', ['filter' => 'ownership:account']);
        $routes->post('notifications/read-all', 'Notification\NotificationController::markAllRead', ['filter' => 'ownership:account']);

        // Sprint 7 â€” Web Push subscription endpoints
        $routes->get('notifications/push-public-key', 'Notification\WebPushController::publicKey', ['filter' => 'ownership:account']);
        $routes->post('notifications/push-subscribe', 'Notification\WebPushController::subscribe', ['filter' => 'ownership:account']);
        $routes->delete('notifications/push-subscribe', 'Notification\WebPushController::unsubscribe', ['filter' => 'ownership:account']);

        \Config\Routes\IntegrationRoutes::register($routes);


        // Notification preferences (per user, multi-channel)
        // Lihat docs/AUDIT.md item K. Quiet-hours endpoint ditambah di
        // sprint mobile M7 (DND window; lihat docs/SPRINT_MOBILE_M7_*).
        // Endpoint /quiet-hours didefinisikan duluan supaya tidak
        // ke-trap sebagai sub-resource dari index/update.
        $routes->get('notification-preferences/quiet-hours', 'Notification\NotificationPreferenceController::getQuietHours', ['filter' => 'ownership:account']);
        $routes->put('notification-preferences/quiet-hours', 'Notification\NotificationPreferenceController::updateQuietHours', ['filter' => 'ownership:account']);
        $routes->get('notification-preferences',  'Notification\NotificationPreferenceController::index', ['filter' => 'ownership:account']);
        $routes->put('notification-preferences',  'Notification\NotificationPreferenceController::update', ['filter' => 'ownership:account']);
        $routes->get('notification-broadcast/options', 'Notification\NotificationBroadcastController::options', ['filter' => 'permission:notifications.broadcast']);
        $routes->post('notification-broadcast/preview', 'Notification\NotificationBroadcastController::preview', ['filter' => 'permission:notifications.broadcast']);
        $routes->post('notification-broadcast/send', 'Notification\NotificationBroadcastController::send', ['filter' => ['permission:notifications.broadcast', 'idempotency:required']]);

        // Roles & Permissions (admin)
        // Static sub-route wajib sebelum resource /roles/(:num).
        $routes->get('roles/approval-requests', 'Identity\RoleController::approvalRequests', ['filter' => 'permission:role.manage']);
        $routes->resource('roles', ['controller' => 'Identity\RoleController', 'only' => ['index', 'show'], 'filter' => 'permission:role.manage']);
        $routes->post('roles', 'Identity\RoleController::create', ['filter' => ['permission:role.manage', 'idempotency:required', 'privilegedapproval:RBAC']]);
        $routes->put('roles/(:num)', 'Identity\RoleController::update/$1', ['filter' => ['permission:role.manage', 'idempotency:required', 'privilegedapproval:RBAC']]);
        $routes->delete('roles/(:num)', 'Identity\RoleController::delete/$1', ['filter' => ['permission:role.manage', 'idempotency:required', 'privilegedapproval:RBAC']]);
        $routes->resource('permissions', ['controller' => 'Identity\PermissionController', 'filter' => 'permission:role.manage']);
        $routes->resource('users', ['controller' => 'Identity\UserController', 'only' => ['index', 'show'], 'filter' => 'permission:user.manage']);
        $routes->post('users', 'Identity\UserController::create', ['filter' => ['permission:user.manage', 'idempotency:required', 'privilegedapproval:RBAC']]);
        $routes->put('users/(:num)', 'Identity\UserController::update/$1', ['filter' => ['permission:user.manage', 'idempotency:required', 'privilegedapproval:RBAC']]);
        $routes->delete('users/(:num)', 'Identity\UserController::delete/$1', ['filter' => ['permission:user.manage', 'idempotency:required', 'privilegedapproval:RBAC']]);

        // â”€â”€â”€ Menu (sidebar dinamis, Migration #076) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // GET /menus/me dipanggil setiap layout AppLayout mount untuk
        // dapatkan tree menu yang sudah difilter sesuai permission user.
        // Endpoint admin (CRUD + reorder) butuh permission `menu.manage`
        // yang di-cek di MenuController via tenant role check.
        // Definisikan endpoint khusus SEBELUM /menus/(:num) supaya path
        // /me dan /reorder tidak nyangkut sebagai numeric id.
        $routes->get('menus/me',           'Identity\MenuController::me', ['filter' => 'ownership:account']);
        $routes->post('menus/reorder',     'Identity\MenuController::reorder', ['filter' => 'permission:menu.manage']);
        $routes->get('menus',              'Identity\MenuController::index', ['filter' => 'permission:menu.view']);
        $routes->post('menus',             'Identity\MenuController::create', ['filter' => 'permission:menu.manage']);
        $routes->get('menus/(:num)',       'Identity\MenuController::show/$1', ['filter' => 'permission:menu.view']);
        $routes->put('menus/(:num)',       'Identity\MenuController::update/$1', ['filter' => 'permission:menu.manage']);
        $routes->delete('menus/(:num)',    'Identity\MenuController::delete/$1', ['filter' => 'permission:menu.manage']);

        // Reports
        $routes->get('reports/headcount', 'System\ReportController::headcount', ['filter' => 'permission:report.view']);
        $routes->get('reports/attendance-summary', 'System\ReportController::attendanceSummary', ['filter' => 'permission:report.view']);
        $routes->get('reports/attendance-heatmap', 'System\ReportController::attendanceHeatmap', ['filter' => 'permission:report.view']);
        $routes->get('reports/payroll-summary', 'System\ReportController::payrollSummary', ['filter' => 'permission:report.view']);
        $routes->get('reports/leave-summary', 'System\ReportController::leaveSummary', ['filter' => 'permission:report.view']);

        // Audit log (admin only)
        $routes->get('audit-logs', 'Compliance\AuditLogController::index', ['filter' => 'permission:audit.view']);
        $routes->get('audit-logs/stats', 'Compliance\AuditLogController::stats', ['filter' => 'permission:audit.view']);
        // Sprint 3 â€” throttle berat export
        $routes->get('audit-logs/export', 'Compliance\AuditLogController::export', ['filter' => ['permission:audit.view', 'privilegedapproval:PII_EXPORT', 'throttle:export']]);
        $routes->get('audit-logs/(:num)', 'Compliance\AuditLogController::show/$1', ['filter' => 'permission:audit.view']);

        // Settings (per company)
        $routes->get('settings', 'System\SettingController::index', ['filter' => 'permission:settings.manage']);
        $routes->put('settings', 'System\SettingController::update', ['filter' => 'permission:settings.manage']);

        // Email / SMTP settings (per company) â€” disimpan di tabel `settings`
        // dengan key prefix `email.*`. Password di-encrypt at-rest.
        $routes->get('email-settings',       'System\EmailSettingsController::index', ['filter' => 'permission:settings.manage']);
        $routes->put('email-settings',       'System\EmailSettingsController::update', ['filter' => 'permission:settings.manage']);
        $routes->post('email-settings/test', 'System\EmailSettingsController::test', ['filter' => 'permission:settings.manage']);

        // Organization Development â€” Easy Setting (drag-and-drop org structure)
        // Lihat docs/ORG_STRUCTURE.md untuk pola pemakaian.
        $routes->get('org-structure',                       'Master\OrgStructureController::index', ['filter' => 'permission:org_structure.view']);
        $routes->get('org-structure/department/(:num)',     'Master\OrgStructureController::departmentDetail/$1', ['filter' => 'permission:org_structure.view']);
        $routes->post('org-structure/move-department',      'Master\OrgStructureController::moveDepartment', ['filter' => 'permission:org_structure.manage']);
        $routes->post('org-structure/move-position',        'Master\OrgStructureController::movePosition', ['filter' => 'permission:org_structure.manage']);
        $routes->post('org-structure/move-mpp',             'Master\OrgStructureController::moveMpp', ['filter' => 'permission:org_structure.manage']);
        $routes->post('org-structure/move-employee',        'Master\OrgStructureController::moveEmployee', ['filter' => 'permission:org_structure.manage']);
        $routes->post('org-structure/set-manager',          'Master\OrgStructureController::setManager', ['filter' => 'permission:org_structure.manage']);
        $routes->post('org-structure/bulk-reorder',         'Master\OrgStructureController::bulkReorder', ['filter' => 'permission:org_structure.manage']);
        $routes->get('org-structure/recompute-reports/preview', 'Master\OrgStructureController::previewRecomputeReports', ['filter' => 'permission:org_structure.view']);
        $routes->post('org-structure/recompute-reports',    'Master\OrgStructureController::recomputeReports', ['filter' => 'permission:org_structure.manage']);

        // Organization Roles â€” master jabatan kunci untuk lookup approver.
        // Endpoint khusus didefinisikan SEBELUM CRUD agar /picker & /organogram
        // tidak ke-trap sebagai resource id.
        $routes->get('organization-roles/picker',           'Master\OrganizationRoleController::picker', ['filter' => 'permission:org_structure.view']);
        $routes->get('organization-roles/organogram',       'Master\OrganizationRoleController::organogram', ['filter' => 'permission:org_structure.view']);
        $routes->get('organization-roles',                  'Master\OrganizationRoleController::index', ['filter' => 'permission:org_structure.view']);
        $routes->post('organization-roles',                 'Master\OrganizationRoleController::create', ['filter' => 'permission:org_structure.manage']);
        $routes->get('organization-roles/(:num)',           'Master\OrganizationRoleController::show/$1', ['filter' => 'permission:org_structure.view']);
        $routes->put('organization-roles/(:num)',           'Master\OrganizationRoleController::update/$1', ['filter' => 'permission:org_structure.manage']);
        $routes->delete('organization-roles/(:num)',        'Master\OrganizationRoleController::delete/$1', ['filter' => 'permission:org_structure.manage']);
        $routes->post('organization-roles/(:num)/assign',   'Master\OrganizationRoleController::assign/$1', ['filter' => 'permission:org_structure.manage']);

        // Allowance mapping (per cabang & grade)
        $routes->get('allowance-types',          'Payroll\AllowanceController::listTypes', ['filter' => 'permission:payroll.view']);
        $routes->post('allowance-types',         'Payroll\MasterRateChangeController::createAllowanceType', ['filter' => ['permission:payroll.manage', 'idempotency:required']]);
        $routes->put('allowance-types/(:num)',   'Payroll\MasterRateChangeController::updateAllowanceType/$1', ['filter' => ['permission:payroll.manage', 'idempotency:required']]);
        $routes->delete('allowance-types/(:num)','Payroll\MasterRateChangeController::deleteAllowanceType/$1', ['filter' => ['permission:payroll.manage', 'idempotency:required']]);

        $routes->get('allowance-rates',          'Payroll\AllowanceController::listRates', ['filter' => 'permission:payroll.view']);
        $routes->post('allowance-rates',         'Payroll\MasterRateChangeController::createAllowanceRate', ['filter' => ['permission:payroll.manage', 'idempotency:required']]);
        $routes->put('allowance-rates/(:num)',   'Payroll\MasterRateChangeController::updateAllowanceRate/$1', ['filter' => ['permission:payroll.manage', 'idempotency:required']]);
        $routes->delete('allowance-rates/(:num)','Payroll\MasterRateChangeController::deleteAllowanceRate/$1', ['filter' => ['permission:payroll.manage', 'idempotency:required']]);

        $routes->get('employee-allowances/(:num)',                    'Payroll\AllowanceController::listForEmployee/$1', ['filter' => 'permission:payroll.view']);
        $routes->post('employee-allowances/(:num)/recompute',         'Payroll\AllowanceController::recomputeEmployee/$1', ['filter' => 'permission:payroll.manage']);
        $routes->put('employee-allowances/(:num)',                    'Payroll\AllowanceController::overrideAssignment/$1', ['filter' => 'permission:payroll.manage']);
        $routes->post('allowances/recompute-company',                 'Payroll\AllowanceController::recomputeCompany', ['filter' => ['permission:payroll.manage', 'idempotency:required']]);

        // â”€â”€â”€ Work Locations (lokasi kerja granular) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->resource('work-locations', ['controller' => 'Master\WorkLocationController', 'only' => ['index', 'show'], 'filter' => 'permission:work_location.view']);
        $routes->resource('work-locations', ['controller' => 'Master\WorkLocationController', 'only' => ['new', 'create', 'edit', 'update', 'delete'], 'filter' => 'permission:work_location.manage']);
        $routes->get('employees/(:num)/work-locations',  'Master\WorkLocationController::employeeHistory/$1', ['filter' => 'permission:work_location.view']);
        $routes->post('employees/(:num)/work-locations', 'Master\WorkLocationController::assignEmployee/$1', ['filter' => 'permission:work_location.manage']);

        // â”€â”€â”€ Anniversary / Birthday Widget â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->get('anniversary/upcoming', 'Employee\AnniversaryController::upcoming', ['filter' => 'permission:employee.view']);
        $routes->get('anniversary/today',    'Employee\AnniversaryController::today', ['filter' => 'permission:employee.view']);

        // â”€â”€â”€ Training & Sertifikasi â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->get('trainings-stats',                          'Training\TrainingController::stats', ['filter' => 'permission:training.view']);
        $routes->get('certificates/expiring',                    'Training\TrainingController::expiringCertificates', ['filter' => 'permission:certificate.view']);
        $routes->get('certificates',                             'Training\TrainingController::listCertificates', ['filter' => 'permission:certificate.view']);
        $routes->post('certificates',                            'Training\TrainingController::createCertificate', ['filter' => 'permission:certificate.manage']);
        $routes->get('certificates/(:num)',                      'Training\TrainingController::showCertificate/$1', ['filter' => 'permission:certificate.view']);
        $routes->put('certificates/(:num)',                      'Training\TrainingController::updateCertificate/$1', ['filter' => 'permission:certificate.manage']);
        $routes->delete('certificates/(:num)',                   'Training\TrainingController::deleteCertificate/$1', ['filter' => 'permission:certificate.manage']);
        $routes->get('training-budgets',                         'Training\TrainingController::listBudgets', ['filter' => 'permission:training_budget.manage']);
        $routes->post('training-budgets',                        'Training\TrainingController::createBudget', ['filter' => 'permission:training_budget.manage']);
        $routes->put('training-budgets/(:num)',                  'Training\TrainingController::updateBudget/$1', ['filter' => 'permission:training_budget.manage']);
        $routes->post('training-registrations/(:num)/approve',   'Training\TrainingController::approveRegistration/$1', ['filter' => ['permission:training.manage', 'idempotency:required']]);
        $routes->post('training-registrations/(:num)/reject',    'Training\TrainingController::rejectRegistration/$1', ['filter' => ['permission:training.manage', 'idempotency:required']]);
        $routes->post('training-registrations/(:num)/complete',  'Training\TrainingController::completeRegistration/$1', ['filter' => ['permission:training.manage', 'idempotency:required']]);
        $routes->post('training-registrations/(:num)/cancel',    'Training\TrainingController::cancelRegistration/$1', ['filter' => ['permission:training.register,training.manage', 'idempotency:required']]);
        $routes->get('trainings',                                'Training\TrainingController::index', ['filter' => 'permission:training.view']);
        $routes->post('trainings',                               'Training\TrainingController::create', ['filter' => 'permission:training.manage']);
        $routes->get('trainings/(:num)',                         'Training\TrainingController::show/$1', ['filter' => 'permission:training.view']);
        $routes->put('trainings/(:num)',                         'Training\TrainingController::update/$1', ['filter' => 'permission:training.manage']);
        $routes->delete('trainings/(:num)',                      'Training\TrainingController::delete/$1', ['filter' => 'permission:training.manage']);
        $routes->post('trainings/(:num)/publish',                'Training\TrainingController::publish/$1', ['filter' => ['permission:training.manage', 'idempotency:required']]);
        $routes->get('trainings/(:num)/registrations',           'Training\TrainingController::listRegistrations/$1', ['filter' => 'permission:training.manage']);
        $routes->post('trainings/(:num)/register',               'Training\TrainingController::register/$1', ['filter' => ['permission:training.register', 'idempotency:required']]);

        // â”€â”€â”€ Performance Management (OKR / KPI / Review) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // My (specific) â€” di atas resource biar tidak ke-trap
        $routes->get('performance/my',                         'Performance\PerformanceController::my', ['filter' => 'ownership:employee']);
        // Cycles
        $routes->get('performance-cycles',                     'Performance\PerformanceController::listCycles', ['filter' => 'permission:performance.view']);
        $routes->post('performance-cycles',                    'Performance\PerformanceController::createCycle', ['filter' => 'permission:performance.manage']);
        $routes->get('performance-cycles/(:num)',              'Performance\PerformanceController::showCycle/$1', ['filter' => 'permission:performance.view']);
        $routes->put('performance-cycles/(:num)',              'Performance\PerformanceController::updateCycle/$1', ['filter' => 'permission:performance.manage']);
        $routes->delete('performance-cycles/(:num)',           'Performance\PerformanceController::deleteCycle/$1', ['filter' => 'permission:performance.manage']);
        $routes->post('performance-cycles/(:num)/status',      'Performance\PerformanceController::changeCycleStatus/$1', ['filter' => ['permission:performance.manage', 'idempotency:required']]);
        // Goals â€” endpoint khusus di atas resource
        $routes->get('goals/weights/(:num)/(:num)',            'Performance\PerformanceController::validateWeights/$1/$2', ['filter' => 'permission:goal.view']);
        $routes->post('goals/(:num)/submit',                   'Performance\PerformanceController::submitGoal/$1', ['filter' => ['permission:goal.manage', 'idempotency:required']]);
        $routes->post('goals/(:num)/approve',                  'Performance\PerformanceController::approveGoal/$1', ['filter' => ['permission:goal.approve', 'idempotency:required']]);
        $routes->post('goals/(:num)/check-in',                 'Performance\PerformanceController::checkInGoal/$1', ['filter' => ['permission:goal.view,goal.manage', 'idempotency:required']]);
        $routes->get('goals',                                  'Performance\PerformanceController::listGoals', ['filter' => 'permission:goal.view']);
        $routes->post('goals',                                 'Performance\PerformanceController::createGoal', ['filter' => 'permission:goal.manage']);
        $routes->get('goals/(:num)',                           'Performance\PerformanceController::showGoal/$1', ['filter' => 'permission:goal.view']);
        $routes->put('goals/(:num)',                           'Performance\PerformanceController::updateGoal/$1', ['filter' => 'permission:goal.manage']);
        $routes->delete('goals/(:num)',                        'Performance\PerformanceController::deleteGoal/$1', ['filter' => 'permission:goal.manage']);
        // Reviews
        $routes->post('performance-reviews/(:num)/submit',     'Performance\PerformanceController::submitReview/$1', ['filter' => ['permission:review.conduct_self,review.conduct', 'idempotency:required']]);
        $routes->post('performance-reviews/(:num)/acknowledge','Performance\PerformanceController::acknowledgeReview/$1', ['filter' => ['permission:review.conduct_self,review.conduct', 'idempotency:required']]);
        $routes->post('performance-reviews/(:num)/finalize',   'Performance\PerformanceController::finalizeReview/$1', ['filter' => ['permission:performance.manage', 'idempotency:required']]);
        $routes->get('performance-reviews',                    'Performance\PerformanceController::listReviews', ['filter' => 'permission:review.view_team,review.conduct,review.conduct_self']);
        $routes->post('performance-reviews',                   'Performance\PerformanceController::createReview', ['filter' => 'permission:performance.manage']);
        $routes->get('performance-reviews/(:num)',             'Performance\PerformanceController::showReview/$1', ['filter' => 'permission:review.view_team,review.conduct,review.conduct_self']);
        $routes->put('performance-reviews/(:num)',             'Performance\PerformanceController::updateReview/$1', ['filter' => 'permission:review.conduct,review.conduct_self']);

        // â”€â”€â”€ Anti-Cheat Attendance (HR Review) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->get('attendance-anomaly',                    'Attendance\AnomalyController::index', ['filter' => 'permission:attendance.view']);
        $routes->get('attendance-anomaly/stats',              'Attendance\AnomalyController::stats', ['filter' => 'permission:attendance.view']);
        $routes->post('attendance-anomaly/(:num)/review',     'Attendance\AnomalyController::review/$1', ['filter' => 'permission:attendance.approve']);
        $routes->get('attendance-devices',                    'Attendance\AnomalyController::devices', ['filter' => 'permission:attendance.manage_devices']);
        $routes->post('attendance-devices/(:num)/trust',      'Attendance\AnomalyController::trustDevice/$1', ['filter' => 'permission:attendance.manage_devices']);
        $routes->post('attendance-devices/(:num)/block',      'Attendance\AnomalyController::blockDevice/$1', ['filter' => 'permission:attendance.manage_devices']);

        // â”€â”€â”€ Bulk Import (xlsx) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Migration #067. Generic dispatcher pakai ImportRegistry +
        // XlsxImportService. Lihat docs/XLSX_IMPORT_AUDIT.md untuk
        // daftar resource yg saat ini siap di-import (departments,
        // branches, job_titles, job_grades, positions, employees).
        // Endpoint statis didefinisikan duluan supaya tidak nyangkut
        // sebagai (:segment) dynamic di pattern di bawahnya.
        $routes->get('imports/resources',                     'System\ImportController::listResources', ['filter' => 'ownership:account']);
        $routes->get('imports/batches/(:num)',                'System\ImportController::batch/$1', ['filter' => 'ownership:account']);
        $routes->post('imports/batches/(:num)/cancel',        'System\ImportController::cancel/$1', ['filter' => ['ownership:account', 'idempotency:required']]);
        $routes->get('imports/(:segment)/template',           'System\ImportController::template/$1', ['filter' => 'importpermission']);
        $routes->post('imports/(:segment)/preview',           'System\ImportController::preview/$1', ['filter' => 'importpermission']);
        $routes->post('imports/(:segment)/commit/(:num)',     'System\ImportController::commit/$1/$2', ['filter' => ['importpermission', 'idempotency:required']]);
        $routes->get('imports/(:segment)/history',            'System\ImportController::history/$1', ['filter' => 'importpermission']);

        // â”€â”€â”€ HR Helpdesk / Tickets â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $routes->get('helpdesk/stats',                       'Helpdesk\HelpdeskController::stats', ['filter' => 'permission:helpdesk.view_all']);
        $routes->get('helpdesk/tickets',                     'Helpdesk\HelpdeskController::index', ['filter' => 'permission:helpdesk.view,helpdesk.view_all']);
        $routes->post('helpdesk/tickets',                    'Helpdesk\HelpdeskController::create', ['filter' => 'permission:helpdesk.view']);
        $routes->get('helpdesk/tickets/(:num)',              'Helpdesk\HelpdeskController::show/$1', ['filter' => 'permission:helpdesk.view,helpdesk.view_all']);
        $routes->put('helpdesk/tickets/(:num)',              'Helpdesk\HelpdeskController::update/$1', ['filter' => 'permission:helpdesk.view,helpdesk.handle']);
        $routes->delete('helpdesk/tickets/(:num)',           'Helpdesk\HelpdeskController::delete/$1', ['filter' => 'permission:helpdesk.handle']);
        $routes->post('helpdesk/tickets/(:num)/assign',      'Helpdesk\HelpdeskController::assign/$1', ['filter' => 'permission:helpdesk.handle']);
        $routes->post('helpdesk/tickets/(:num)/status',      'Helpdesk\HelpdeskController::changeStatus/$1', ['filter' => 'permission:helpdesk.handle']);
        $routes->post('helpdesk/tickets/(:num)/close',       'Helpdesk\HelpdeskController::close/$1', ['filter' => 'permission:helpdesk.view,helpdesk.handle']);
        $routes->post('helpdesk/tickets/(:num)/reopen',      'Helpdesk\HelpdeskController::reopen/$1', ['filter' => 'permission:helpdesk.view,helpdesk.handle']);
        $routes->post('helpdesk/tickets/(:num)/comments',    'Helpdesk\HelpdeskController::addComment/$1', ['filter' => 'permission:helpdesk.view,helpdesk.handle']);

        // â”€â”€â”€ Manpower Planning (MPP) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Endpoint khusus didefinisikan SEBELUM resource pattern supaya
        // path /manpower-requests/stats & /inbox tidak nyangkut sebagai id.
        $routes->get('manpower-requests/stats',                  'Manpower\ManpowerController::stats', ['filter' => 'permission:mpp.view']);
        $routes->get('manpower-requests/inbox',                  'Manpower\ManpowerController::inbox', ['filter' => 'permission:mpp.view']);
        // Migration #066 â€” MPP yg vacancy_started_at sudah lewat / mendekati
        $routes->get('manpower-requests/overdue',                'Manpower\ManpowerController::overdue', ['filter' => 'permission:mpp.view']);
        $routes->get('manpower-requests/approval-preview',       'Manpower\ManpowerController::approvalPreview', ['filter' => 'permission:mpp.approve']);
        $routes->get('manpower-requests',                        'Manpower\ManpowerController::index', ['filter' => 'permission:mpp.view']);
        $routes->post('manpower-requests',                       'Manpower\ManpowerController::create', ['filter' => ['permission:mpp.submit', 'idempotency:required']]);
        $routes->get('manpower-requests/(:num)',                 'Manpower\ManpowerController::show/$1', ['filter' => 'permission:mpp.view']);
        $routes->put('manpower-requests/(:num)',                 'Manpower\ManpowerController::update/$1', ['filter' => 'permission:mpp.submit']);
        $routes->delete('manpower-requests/(:num)',              'Manpower\ManpowerController::delete/$1', ['filter' => 'permission:mpp.submit']);
        $routes->post('manpower-requests/(:num)/submit',         'Manpower\ManpowerController::submit/$1', ['filter' => ['permission:mpp.submit', 'idempotency:required']]);
        $routes->post('manpower-requests/(:num)/approve',        'Manpower\ManpowerController::approve/$1', ['filter' => ['permission:mpp.approve', 'idempotency:required']]);
        $routes->post('manpower-requests/(:num)/reject',         'Manpower\ManpowerController::reject/$1', ['filter' => ['permission:mpp.approve', 'idempotency:required']]);
        $routes->post('manpower-requests/(:num)/cancel',         'Manpower\ManpowerController::cancel/$1', ['filter' => ['permission:mpp.submit', 'idempotency:required']]);
        $routes->post('manpower-requests/(:num)/create-posting', 'Manpower\ManpowerController::createPosting/$1', ['filter' => ['permission:mpp.fulfill', 'idempotency:required']]);
        $routes->post('manpower-requests/(:num)/close',          'Manpower\ManpowerController::close/$1', ['filter' => ['permission:mpp.fulfill', 'idempotency:required']]);
        // Sprint 5 â€” onboarding planning endpoints
        $routes->get('manpower-requests/(:num)/planned-assets',  'Manpower\ManpowerController::plannedAssets/$1', ['filter' => 'permission:mpp.view']);
        $routes->put('manpower-requests/(:num)/planned-assets',  'Manpower\ManpowerController::syncPlannedAssets/$1', ['filter' => 'permission:mpp.submit']);

    }
}
