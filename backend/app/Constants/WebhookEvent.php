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
    public const ATTENDANCE_CHANGE_REJECTED  = 'attendance.change_rejected';

    public const REIMBURSEMENT_APPROVED = 'reimbursement.approved';
    public const REIMBURSEMENT_REJECTED = 'reimbursement.rejected';
    public const LOAN_APPROVED = 'loan.approved';
    public const LOAN_REJECTED = 'loan.rejected';
    public const BUSINESS_TRIP_APPROVED = 'business_trip.approved';
    public const BUSINESS_TRIP_REJECTED = 'business_trip.rejected';

    // ─── Recruitment ─────────────────────────────────────────────────
    public const CANDIDATE_HIRED  = 'recruitment.candidate_hired';
    public const POSTING_PUBLISHED = 'recruitment.posting_published';
    public const CANDIDATE_REGISTERED = 'recruitment.candidate_registered';
    public const CANDIDATE_HIRE_REJECTED = 'recruitment.candidate_hire_rejected';
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
    public const BONUS_RUN_REJECTED   = 'bonus.run_rejected';
    public const BONUS_RUN_PAID       = 'bonus.run_paid';
    public const PAYROLL_ADJUSTMENT_APPROVED = 'payroll_adjustment.approved';
    public const PAYROLL_ADJUSTMENT_REJECTED = 'payroll_adjustment.rejected';
    public const COMPENSATION_REVIEW_APPROVED = 'compensation_review.approved';
    public const COMPENSATION_REVIEW_REJECTED = 'compensation_review.rejected';
    public const TRAINING_REGISTRATION_APPROVED = 'training.registration_approved';
    public const TRAINING_REGISTRATION_REJECTED = 'training.registration_rejected';
    public const TIMESHEET_APPROVED = 'timesheet.approved';
    public const TIMESHEET_REJECTED = 'timesheet.rejected';
    public const CANDIDATE_OFFER_APPROVED = 'candidate_offer.approved';
    public const CANDIDATE_OFFER_REJECTED = 'candidate_offer.rejected';
    public const GENERATED_CONTRACT_APPROVED = 'generated_contract.approved';
    public const GENERATED_CONTRACT_REJECTED = 'generated_contract.rejected';
    public const ASSET_TRANSACTION_APPROVED = 'asset.transaction_approved';
    public const ASSET_TRANSACTION_REJECTED = 'asset.transaction_rejected';
    public const PERFORMANCE_GOAL_APPROVED = 'performance.goal_approved';
    public const PERFORMANCE_GOAL_REJECTED = 'performance.goal_rejected';
    public const IDP_ACTIVATION_APPROVED = 'performance.idp_activation_approved';
    public const IDP_ACTIVATION_REJECTED = 'performance.idp_activation_rejected';
    public const EMPLOYEE_SENSITIVE_CHANGE_APPROVED = 'employee.sensitive_change_approved';
    public const EMPLOYEE_SENSITIVE_CHANGE_REJECTED = 'employee.sensitive_change_rejected';
    public const MASTER_RATE_CHANGE_APPROVED = 'payroll.master_rate_change_approved';
    public const MASTER_RATE_CHANGE_REJECTED = 'payroll.master_rate_change_rejected';
    public const PRIVILEGED_ACTION_APPROVED = 'security.privileged_action_approved';
    public const PRIVILEGED_ACTION_REJECTED = 'security.privileged_action_rejected';

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
        self::ATTENDANCE_CHANGE_REJECTED,
        self::REIMBURSEMENT_APPROVED,
        self::REIMBURSEMENT_REJECTED,
        self::LOAN_APPROVED,
        self::LOAN_REJECTED,
        self::BUSINESS_TRIP_APPROVED,
        self::BUSINESS_TRIP_REJECTED,
        self::CANDIDATE_HIRED,
        self::POSTING_PUBLISHED,
        self::CANDIDATE_REGISTERED,
        self::CANDIDATE_HIRE_REJECTED,
        self::ONLINE_TEST_ASSIGNED,
        self::ONLINE_TEST_COMPLETED,
        self::SP_ISSUED,
        self::SP_REVOKED,
        self::SEVERANCE_FINALIZED,
        self::SEVERANCE_PAID,
        self::BONUS_RUN_CALCULATED,
        self::BONUS_RUN_APPROVED,
        self::BONUS_RUN_REJECTED,
        self::BONUS_RUN_PAID,
        self::PAYROLL_ADJUSTMENT_APPROVED,
        self::PAYROLL_ADJUSTMENT_REJECTED,
        self::COMPENSATION_REVIEW_APPROVED,
        self::COMPENSATION_REVIEW_REJECTED,
        self::TRAINING_REGISTRATION_APPROVED,
        self::TRAINING_REGISTRATION_REJECTED,
        self::TIMESHEET_APPROVED,
        self::TIMESHEET_REJECTED,
        self::CANDIDATE_OFFER_APPROVED,
        self::CANDIDATE_OFFER_REJECTED,
        self::GENERATED_CONTRACT_APPROVED,
        self::GENERATED_CONTRACT_REJECTED,
        self::ASSET_TRANSACTION_APPROVED,
        self::ASSET_TRANSACTION_REJECTED,
        self::PERFORMANCE_GOAL_APPROVED,
        self::PERFORMANCE_GOAL_REJECTED,
        self::IDP_ACTIVATION_APPROVED,
        self::IDP_ACTIVATION_REJECTED,
        self::EMPLOYEE_SENSITIVE_CHANGE_APPROVED,
        self::EMPLOYEE_SENSITIVE_CHANGE_REJECTED,
        self::MASTER_RATE_CHANGE_APPROVED,
        self::MASTER_RATE_CHANGE_REJECTED,
        self::PRIVILEGED_ACTION_APPROVED,
        self::PRIVILEGED_ACTION_REJECTED,
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
