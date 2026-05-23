<?php

namespace App\Constants;

/**
 * Status approval workflow generik (cuti, lembur, reimbursement, business trip,
 * resignation, JIC, employee transfer, dll).
 *
 * Tidak semua module pakai semua state — `IMPLEMENTED` hanya di JIC &
 * EmployeeTransfer (post-approval action). `CONSENT_REQUIRED` di JIC
 * untuk demosi non-disipliner. Adapter list di tiap module sesuai kebutuhan.
 */
final class ApprovalStatus
{
    public const DRAFT             = 'DRAFT';             // Belum di-submit
    public const PENDING           = 'PENDING';           // Menunggu approval (general)
    public const APPROVED          = 'APPROVED';          // Disetujui
    public const REJECTED          = 'REJECTED';          // Ditolak
    public const CANCELED          = 'CANCELED';          // Dibatalkan oleh pengaju / HR
    public const CONSENT_REQUIRED  = 'CONSENT_REQUIRED';  // Menunggu persetujuan karyawan (mis. demosi)
    public const IMPLEMENTED       = 'IMPLEMENTED';       // Approved + sudah dieksekusi (mis. JIC commit)

    public const ALL = [
        self::DRAFT,
        self::PENDING,
        self::APPROVED,
        self::REJECTED,
        self::CANCELED,
        self::CONSENT_REQUIRED,
        self::IMPLEMENTED,
    ];

    /** Status yang menandakan workflow belum selesai. */
    public const PENDING_LIKE = [
        self::DRAFT,
        self::PENDING,
        self::CONSENT_REQUIRED,
    ];

    /** Status final (tidak bisa diubah lagi tanpa intervensi admin). */
    public const FINAL_STATES = [
        self::APPROVED,
        self::REJECTED,
        self::CANCELED,
        self::IMPLEMENTED,
    ];

    public static function isPending(string $status): bool
    {
        return in_array($status, self::PENDING_LIKE, true);
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL_STATES, true);
    }
}