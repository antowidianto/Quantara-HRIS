<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class TimeRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // Attendance
        $routes->get('attendance', 'Attendance\AttendanceController::index', ['filter' => 'permission:attendance.view']);
        // Sprint A / B3 — Origin guard untuk multipart selfie clock.
        $routes->post('attendance/clock-in', 'Attendance\AttendanceController::clockIn',
            ['filter' => ['permission:attendance.clock', 'sameorigin', 'idempotency:required']]);
        $routes->post('attendance/clock-out', 'Attendance\AttendanceController::clockOut',
            ['filter' => ['permission:attendance.clock', 'sameorigin', 'idempotency:required']]);
        // Self-service baseline: the surrounding api/v1 group already requires
        // JWT, while the controller resolves employee_id from that identity and
        // verifies the active tenant. Functional roles must not lose own attendance.
        $routes->get('attendance/my', 'Attendance\AttendanceController::my');
        $routes->get('attendance/calendar/(:num)', 'Attendance\AttendanceController::calendar/$1', ['filter' => 'permission:attendance.view']);
        
        // ADMS Machine Sync (from Node.js middleware)
        $routes->post('attendance/machine-sync', 'Attendance\MachineSyncController::sync', ['filter' => 'permission:attendance.manage_devices']);
        $routes->resource('physical-devices', ['controller' => 'Attendance\AttendanceDeviceController', 'filter' => 'permission:attendance.manage_devices']);

        // ─── WFH Requests (Sprint 8 / H1.5) ─────────────────────────
        // Pengajuan WFH dengan flow PENDING → APPROVED/REJECTED yang
        // dipakai AttendanceController untuk skip geofence saat clock-in.
        $routes->get('wfh-requests',                       'Attendance\WfhRequestController::index', ['filter' => 'permission:attendance.view']);
        $routes->post('wfh-requests',                      'Attendance\WfhRequestController::create', ['filter' => ['permission:attendance.request_change', 'idempotency:required']]);
        $routes->get('wfh-requests/(:num)',                'Attendance\WfhRequestController::show/$1', ['filter' => 'permission:attendance.view']);
        $routes->post('wfh-requests/(:num)/approve',       'Attendance\WfhRequestController::approve/$1', ['filter' => ['permission:attendance.approve', 'idempotency:required']]);
        $routes->post('wfh-requests/(:num)/reject',        'Attendance\WfhRequestController::reject/$1', ['filter' => ['permission:attendance.approve', 'idempotency:required']]);
        $routes->post('wfh-requests/(:num)/cancel',        'Attendance\WfhRequestController::cancel/$1', ['filter' => ['permission:attendance.request_change', 'idempotency:required']]);

        // Attendance change requests (pengajuan perubahan + approval)
        // Catatan: definisikan SEBELUM route catch-all `attendance/(:num)`
        // supaya path /attendance/change-requests tidak nyangkut sebagai id.
        $routes->get('attendance/change-requests', 'Attendance\AttendanceChangeRequestController::index', ['filter' => 'permission:attendance.view']);
        $routes->post('attendance/change-requests', 'Attendance\AttendanceChangeRequestController::create', ['filter' => ['permission:attendance.request_change', 'idempotency:required']]);
        $routes->get('attendance/change-requests/(:num)', 'Attendance\AttendanceChangeRequestController::show/$1', ['filter' => 'permission:attendance.view']);
        $routes->post('attendance/change-requests/(:num)/approve', 'Attendance\AttendanceChangeRequestController::approve/$1', ['filter' => ['permission:attendance.approve_change', 'idempotency:required']]);
        $routes->post('attendance/change-requests/(:num)/reject', 'Attendance\AttendanceChangeRequestController::reject/$1', ['filter' => ['permission:attendance.approve_change', 'idempotency:required']]);
        $routes->post('attendance/change-requests/(:num)/cancel', 'Attendance\AttendanceChangeRequestController::cancel/$1', ['filter' => ['permission:attendance.request_change', 'idempotency:required']]);

        $routes->get('attendance/(:num)', 'Attendance\AttendanceController::show/$1', ['filter' => 'permission:attendance.view']);
        $routes->post('attendance/(:num)/correction', 'Attendance\AttendanceController::correction/$1', ['filter' => ['permission:attendance.correct', 'idempotency:required']]);

        // Shifts & Roster
        $routes->resource('shifts', ['controller' => 'Attendance\ShiftController', 'only' => ['index', 'show'], 'filter' => 'permission:shift.view']);
        $routes->resource('shifts', ['controller' => 'Attendance\ShiftController', 'only' => ['create', 'update', 'delete'], 'filter' => 'permission:shift.manage']);
        $routes->resource('shift-schedules', ['controller' => 'Attendance\ShiftScheduleController', 'filter' => 'permission:shift_schedules.manage']);

        // Shift assignments — pemetaan shift ke scope
        $routes->get('shift-assignments',                'Attendance\ShiftAssignmentController::index', ['filter' => 'permission:shift_schedules.view']);
        $routes->get('shift-assignments/preview',        'Attendance\ShiftAssignmentController::preview', ['filter' => 'permission:shift_schedules.view']);
        $routes->get('shift-assignments/(:num)',         'Attendance\ShiftAssignmentController::show/$1', ['filter' => 'permission:shift_schedules.view']);
        $routes->post('shift-assignments',               'Attendance\ShiftAssignmentController::create', ['filter' => 'permission:shift_schedules.manage']);
        $routes->put('shift-assignments/(:num)',         'Attendance\ShiftAssignmentController::update/$1', ['filter' => 'permission:shift_schedules.manage']);
        $routes->delete('shift-assignments/(:num)',      'Attendance\ShiftAssignmentController::delete/$1', ['filter' => 'permission:shift_schedules.manage']);

        // Shift patterns — pola siklus
        $routes->get('shift-patterns',                   'Attendance\ShiftPatternController::index', ['filter' => 'permission:shift_pattern.view']);
        $routes->get('shift-patterns/(:num)',            'Attendance\ShiftPatternController::show/$1', ['filter' => 'permission:shift_pattern.view']);
        $routes->post('shift-patterns',                  'Attendance\ShiftPatternController::create', ['filter' => 'permission:shift_pattern.manage']);
        $routes->put('shift-patterns/(:num)',            'Attendance\ShiftPatternController::update/$1', ['filter' => 'permission:shift_pattern.manage']);
        $routes->delete('shift-patterns/(:num)',         'Attendance\ShiftPatternController::delete/$1', ['filter' => 'permission:shift_pattern.manage']);
        $routes->get('shift-pattern-assignments',        'Attendance\ShiftPatternController::listAssignments', ['filter' => 'permission:shift_pattern.view']);
        $routes->post('shift-pattern-assignments',       'Attendance\ShiftPatternController::createAssignment', ['filter' => 'permission:shift_pattern.manage']);
        $routes->delete('shift-pattern-assignments/(:num)', 'Attendance\ShiftPatternController::deleteAssignment/$1', ['filter' => 'permission:shift_pattern.manage']);

        // Roster — matrix view & generator
        $routes->get('roster',                           'Attendance\RosterController::index', ['filter' => 'permission:roster.view']);
        $routes->post('roster/generate',                 'Attendance\RosterController::generate', ['filter' => ['permission:roster.manage', 'idempotency:required']]);
        $routes->post('roster/bulk-update',              'Attendance\RosterController::bulkUpdate', ['filter' => ['permission:roster.manage', 'idempotency:required']]);
        $routes->post('roster/clear',                    'Attendance\RosterController::clear', ['filter' => ['permission:roster.manage', 'idempotency:required']]);

        // Leaves
        // Endpoint khusus harus didefinisikan SEBELUM resource supaya
        // path /leave-types/for-me tidak nyangkut sebagai id resource.
        $routes->get('leave-types/for-me', 'Leave\LeaveController::eligibleTypes', ['filter' => 'permission:leave.view']);
        $routes->resource('leave-types', ['controller' => 'Leave\LeaveTypeController', 'only' => ['index', 'show'], 'filter' => 'permission:leave.view']);
        $routes->resource('leave-types', ['controller' => 'Leave\LeaveTypeController', 'only' => ['create', 'update', 'delete'], 'filter' => 'permission:leave.manage_types']);
        // Sama: team-calendar harus di atas resource supaya tidak ke-trap
        // sebagai /leaves/(:num).
        $routes->get('leaves/team-calendar', 'Leave\LeaveController::teamCalendar', ['filter' => 'permission:leave.team_calendar']);
        $routes->post('leaves', 'Leave\LeaveController::create',
            ['filter' => ['permission:leave.create', 'idempotency:required']]);
        $routes->resource('leaves', ['controller' => 'Leave\LeaveController', 'only' => ['index', 'show'], 'filter' => 'permission:leave.view']);
        $routes->resource('leaves', ['controller' => 'Leave\LeaveController', 'only' => ['update', 'delete'], 'filter' => 'permission:leave.approve']);
        $routes->post('leaves/(:num)/approve', 'Leave\LeaveController::approve/$1', ['filter' => ['permission:leave.approve', 'idempotency:required']]);
        $routes->post('leaves/(:num)/reject', 'Leave\LeaveController::reject/$1', ['filter' => ['permission:leave.approve', 'idempotency:required']]);
        // Sprint 17+ — self-cancel oleh karyawan (atau HR atas nama)
        $routes->post('leaves/(:num)/cancel', 'Leave\LeaveController::cancel/$1', ['filter' => ['permission:leave.create', 'idempotency:required']]);
        $routes->get('leave-balances/(:num)', 'Leave\LeaveController::balance/$1', ['filter' => 'permission:leave.view']);

        // Overtime
        // H1.6 — pre-approval flow (plan + realize). Dideklarasikan di atas
        // resource() supaya path /overtimes/plan tidak ke-trap sebagai numeric id.
        $routes->post('overtimes/plan', 'Attendance\OvertimeController::plan', ['filter' => ['permission:overtime.create', 'idempotency:required']]);
        $routes->post('overtimes', 'Attendance\OvertimeController::create',
            ['filter' => ['permission:overtime.create', 'idempotency:required']]);
        $routes->resource('overtimes', ['controller' => 'Attendance\OvertimeController', 'only' => ['index', 'show'], 'filter' => 'permission:overtime.view']);
        $routes->resource('overtimes', ['controller' => 'Attendance\OvertimeController', 'only' => ['update', 'delete'], 'filter' => 'permission:overtime.approve']);
        $routes->post('overtimes/(:num)/approve', 'Attendance\OvertimeController::approve/$1', ['filter' => ['permission:overtime.approve', 'idempotency:required']]);
        $routes->post('overtimes/(:num)/reject', 'Attendance\OvertimeController::reject/$1', ['filter' => ['permission:overtime.approve', 'idempotency:required']]);
        $routes->post('overtimes/(:num)/realize', 'Attendance\OvertimeController::realize/$1', ['filter' => ['permission:overtime.create', 'idempotency:required']]);
    }
}
