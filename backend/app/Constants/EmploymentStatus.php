<?php

namespace App\Constants;

/**
 * Status kepegawaian (kolom `employees.employment_status`).
 *
 * Match enum constraint di migration #002 + #010. Dipakai untuk eligibility
 * check (mis. JKP di BpjsCalculator hanya untuk PKWT/PKWTT).
 */
final class EmploymentStatus
{
    public const PKWT        = 'PKWT';        // Perjanjian Kerja Waktu Tertentu (kontrak)
    public const PKWTT       = 'PKWTT';       // Perjanjian Kerja Waktu Tidak Tertentu (tetap)
    public const PROBATION   = 'PROBATION';   // Masa percobaan
    public const HARIAN      = 'HARIAN';      // Pekerja harian (PKHL)
    public const MAGANG      = 'MAGANG';      // Pemagang
    public const OUTSOURCING = 'OUTSOURCING'; // Outsourcing / pihak ketiga

    public const ALL = [
        self::PKWT,
        self::PKWTT,
        self::PROBATION,
        self::HARIAN,
        self::MAGANG,
        self::OUTSOURCING,
    ];

    /**
     * Status yang eligible untuk BPJS JKP per PP 37/2021.
     * Excludes PROBATION (masa percobaan) dan MAGANG/HARIAN/OUTSOURCING.
     */
    public const JKP_ELIGIBLE = [
        self::PKWT,
        self::PKWTT,
    ];

    /**
     * Status yang harus tunduk PPh21 + BPJS Kesehatan (FULL_TIME-like).
     * MAGANG biasanya bebas pajak, OUTSOURCING di-handle vendor.
     */
    public const TAX_ELIGIBLE = [
        self::PKWT,
        self::PKWTT,
        self::PROBATION,
        self::HARIAN,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isJkpEligible(?string $status): bool
    {
        if ($status === null) return true;  // backward-compat: null treated eligible
        return in_array(strtoupper($status), self::JKP_ELIGIBLE, true);
    }
}