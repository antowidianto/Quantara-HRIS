<?php

namespace App\Constants;

/**
 * Status presensi (kolom `attendances.status`).
 *
 * String values match enum constraint di migration #003 + #008. Pakai class
 * constants (bukan PHP 8.1 native enum) supaya backward-compatible dengan
 * controller existing yang pass string value langsung ke query builder.
 *
 * Migrasi controller ke pakai `AttendanceStatus::PRESENT` instead of `'PRESENT'`
 * bisa bertahap — keduanya menghasilkan string yang sama.
 *
 * @see backend/app/Database/Migrations/2024-01-01-000003_CreateAttendanceTables.php
 */
final class AttendanceStatus
{
    public const PRESENT  = 'PRESENT';
    public const LATE     = 'LATE';
    public const ABSENT   = 'ABSENT';
    public const LEAVE    = 'LEAVE';
    public const HOLIDAY  = 'HOLIDAY';
    public const SICK     = 'SICK';
    public const HALF_DAY = 'HALF_DAY';

    /** All valid values (untuk validation + ENUM constraint). */
    public const ALL = [
        self::PRESENT,
        self::LATE,
        self::ABSENT,
        self::LEAVE,
        self::HOLIDAY,
        self::SICK,
        self::HALF_DAY,
    ];

    /**
     * Status yang dianggap "hadir" untuk perhitungan payroll absent_deduction.
     * (lihat PayrollService::calculatePeriod)
     */
    public const PRESENT_LIKE = [
        self::PRESENT,
        self::LATE,
        self::HALF_DAY,
        self::LEAVE,
        self::HOLIDAY,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isPresentLike(string $status): bool
    {
        return in_array($status, self::PRESENT_LIKE, true);
    }

    /** Mapping ke label + warna untuk UI badge (lihat AttendanceController::buildCalendar). */
    public static function label(string $status): array
    {
        $map = [
            self::PRESENT  => ['Hadir',         'green'],
            self::LATE     => ['Terlambat',     'amber'],
            self::ABSENT   => ['Tanpa Kabar',   'red'],
            self::LEAVE    => ['Cuti',          'blue'],
            self::HOLIDAY  => ['Libur',         'blue'],
            self::SICK     => ['Sakit',         'amber'],
            self::HALF_DAY => ['Setengah Hari', 'amber'],
        ];
        return $map[$status] ?? ['Unknown', 'gray'];
    }
}