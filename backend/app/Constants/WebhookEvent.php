<?php

namespace App\Constants;

/**
 * Standard event types untuk webhook outbound (Sprint 11+).
 *
 * Konvensi: `{module}.{action}` snake_case lowercase. Customer subscribe
 * spesifik event yang mereka care, dispatcher hanya kirim payload sesuai
 * subscription.
 *
 * Backward compatibility: NEVER rename event type yang sudah dipublish —
 * tambah event baru sebagai `{module}.{action}_v2` kalau perlu schema
 * berubah signifikan.
 */
final class WebhookEvent
{
    // ─── Employee lifecycle ──────────────────────────────────────────
    public const EMPLOYEE_CREATED   = 'employee.created';
    public const EMPLOYEE_UPDATED   = 'employee.updated';
    public const EMPLOYEE_RESIGNED  = 'employee.resigned';
    public const EMPLOYEE_REJOINED  = 'employee.rejoined';

    // ─── Payroll ─────────────────────────────────────────────────────
    public const PAYROLL_PERIOD_FINALIZED = 'payroll.period_finalized';
    public const PAYROLL_PAID             = 'payroll.paid';
    public const PAYSLIP_PUBLISHED        = 'payslip.published';
    public const THR_GENERATED            = 'thr.generated';

    // ─── Leave ───────────────────────────────────────────────────────
    public const LEAVE_REQUESTED = 'leave.requested';
    public const LEAVE_APPROVED  = 'leave.approved';
    public const LEAVE_REJECTED  = 'leave.rejected';
    public const LEAVE_CANCELED  = 'leave.canceled';

    // ─── Overtime ────────────────────────────────────────────────────
    public const OVERTIME_REQUESTED = 'overtime.requested';
    public const OVERTIME_APPROVED  = 'overtime.approved';
    public const OVERTIME_REJECTED  = 'overtime.rejected';

    // ─── Attendance ──────────────────────────────────────────────────
    public const ATTENDANCE_LATE_ABSENT      = 'attendance.late_absent';
    public const ATTENDANCE_ANOMALY_FLAGGED  = 'attendance.anomaly_flagged';
    public const ATTENDANCE_CHANGE_APPROVED  = 'attendance.change_approved';

    // ─── Recruitment ─────────────────────────────────────────────────
    public const CANDIDATE_HIRED  = 'recruitment.candidate_hired';
    public const POSTING_PUBLISHED = 'recruitment.posting_published';
    public const CANDIDATE_REGISTERED = 'recruitment.candidate_registered';
    public const ONLINE_TEST_ASSIGNED = 'recruitment.online_test_assigned';
    public const ONLINE_TEST_COMPLETED = 'recruitment.online_test_completed';

    // ─── Disciplinary ────────────────────────────────────────────────
    public const SP_ISSUED  = 'disciplinary.sp_issued';
    public const SP_REVOKED = 'disciplinary.sp_revoked';

    // ─── Severance / PHK ─────────────────────────────────────────────
    public const SEVERANCE_FINALIZED = 'severance.finalized';
    public const SEVERANCE_PAID      = 'severance.paid';

    // ─── Bonus Engine (Sprint 16+) ───────────────────────────────────
    public const BONUS_RUN_CALCULATED = 'bonus.run_calculated';
    public const BONUS_RUN_APPROVED   = 'bonus.run_approved';
    public const BONUS_RUN_PAID       = 'bonus.run_paid';

    /** All known events — untuk validation di WebhookController create. */
    public const ALL = [
        self::EMPLOYEE_CREATED,
        self::EMPLOYEE_UPDATED,
        self::EMPLOYEE_RESIGNED,
        self::EMPLOYEE_REJOINED,
        self::PAYROLL_PERIOD_FINALIZED,
        self::PAYROLL_PAID,
        self::PAYSLIP_PUBLISHED,
        self::THR_GENERATED,
        self::LEAVE_REQUESTED,
        self::LEAVE_APPROVED,
        self::LEAVE_REJECTED,
        self::LEAVE_CANCELED,
        self::OVERTIME_REQUESTED,
        self::OVERTIME_APPROVED,
        self::OVERTIME_REJECTED,
        self::ATTENDANCE_LATE_ABSENT,
        self::ATTENDANCE_ANOMALY_FLAGGED,
        self::ATTENDANCE_CHANGE_APPROVED,
        self::CANDIDATE_HIRED,
        self::POSTING_PUBLISHED,
        self::CANDIDATE_REGISTERED,
        self::ONLINE_TEST_ASSIGNED,
        self::ONLINE_TEST_COMPLETED,
        self::SP_ISSUED,
        self::SP_REVOKED,
        self::SEVERANCE_FINALIZED,
        self::SEVERANCE_PAID,
        self::BONUS_RUN_CALCULATED,
        self::BONUS_RUN_APPROVED,
        self::BONUS_RUN_PAID,
    ];

    public static function isValid(string $eventType): bool
    {
        return in_array($eventType, self::ALL, true);
    }

    /**
     * Filter list event yang valid (drop yang tidak dikenal).
     * @param string[] $events
     * @return string[]
     */
    public static function filterValid(array $events): array
    {
        return array_values(array_filter($events, fn ($e) => self::isValid($e)));
    }
}