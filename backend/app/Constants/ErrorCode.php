<?php

namespace App\Constants;

/**
 * Standard error codes untuk API response.
 *
 * Tujuan: client (frontend / mobile / 3rd-party integration) bisa cek
 * `response.code` untuk handling spesifik (i18n, retry strategy, dll)
 * tanpa parsing message string.
 *
 * Konvensi: `snake_case` lowercase. Pakai `validation_failed` (bukan
 * `VALIDATION_FAILED`) untuk konsisten dengan pattern modern API.
 *
 * Migrasi dari error code lama (kadang Indonesian, kadang UPPER_SNAKE)
 * dilakukan bertahap — pakai constant ini untuk endpoint baru.
 */
final class ErrorCode
{
    // Authentication & authorization
    public const VALIDATION_FAILED      = 'validation_failed';
    public const UNAUTHORIZED           = 'unauthorized';
    public const PERMISSION_DENIED      = 'permission_denied';
    public const TWOFA_REQUIRED         = 'twofa_required';
    public const TWOFA_INVALID          = 'twofa_invalid';
    public const TOKEN_EXPIRED          = 'token_expired';
    public const TOKEN_INVALID          = 'token_invalid';
    public const ACCOUNT_LOCKED         = 'account_locked';
    public const PASSWORD_POLICY_VIOLATION = 'password_policy_violation';
    public const PASSWORD_REUSED        = 'password_reused';

    // Resource errors
    public const RESOURCE_NOT_FOUND     = 'resource_not_found';
    public const RESOURCE_CONFLICT      = 'resource_conflict';
    public const RESOURCE_LOCKED        = 'resource_locked';

    // Rate limiting
    public const RATE_LIMIT_EXCEEDED    = 'rate_limit_exceeded';

    // File upload
    public const FILE_TOO_LARGE         = 'file_too_large';
    public const FILE_INVALID_TYPE      = 'file_invalid_type';
    public const FILE_SIGNATURE_MISMATCH = 'file_signature_mismatch';

    // Business logic — attendance
    public const ATTENDANCE_OUTSIDE_GEOFENCE = 'attendance_outside_geofence';
    public const ATTENDANCE_OUTSIDE_SHIFT    = 'attendance_outside_shift';
    public const ATTENDANCE_DUPLICATE        = 'attendance_duplicate';
    public const ATTENDANCE_NO_OPEN_SLOT     = 'attendance_no_open_slot';

    // Business logic — leave
    public const LEAVE_INSUFFICIENT_BALANCE  = 'leave_insufficient_balance';
    public const LEAVE_OVERLAPPING           = 'leave_overlapping';

    // Business logic — payroll
    public const PAYROLL_PERIOD_FINALIZED    = 'payroll_period_finalized';
    public const PAYROLL_NO_EMPLOYEES        = 'payroll_no_employees';

    // Business logic — approval
    public const APPROVAL_INVALID_STATE      = 'approval_invalid_state';
    public const APPROVAL_NOT_AUTHORIZED     = 'approval_not_authorized';

    // Webhook (inbound + outbound)
    public const WEBHOOK_SIGNATURE_INVALID   = 'webhook_signature_invalid';
    public const WEBHOOK_SOURCE_INACTIVE     = 'webhook_source_inactive';
    public const WEBHOOK_HANDLER_FAILED      = 'webhook_handler_failed';

    // Generic
    public const OPERATION_FAILED            = 'operation_failed';
    public const INTERNAL_ERROR              = 'internal_error';
    public const SERVICE_UNAVAILABLE         = 'service_unavailable';
}
