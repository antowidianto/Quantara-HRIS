<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need.
 */
class Services extends BaseService
{
    public static function jwt(bool $getShared = true): \App\Libraries\JwtService
    {
        if ($getShared) {
            return static::getSharedInstance('jwt');
        }

        return new \App\Libraries\JwtService();
    }

    public static function notification(bool $getShared = true): \App\Libraries\NotificationService
    {
        if ($getShared) {
            return static::getSharedInstance('notification');
        }

        return new \App\Libraries\NotificationService();
    }

    public static function payroll(bool $getShared = true): \App\Libraries\PayrollService
    {
        if ($getShared) {
            return static::getSharedInstance('payroll');
        }

        return new \App\Libraries\PayrollService();
    }

    public static function pph21(bool $getShared = true): \App\Libraries\Pph21TerCalculator
    {
        if ($getShared) {
            return static::getSharedInstance('pph21');
        }

        return new \App\Libraries\Pph21TerCalculator();
    }

    public static function bpjs(bool $getShared = true): \App\Libraries\BpjsCalculator
    {
        if ($getShared) {
            return static::getSharedInstance('bpjs');
        }

        return new \App\Libraries\BpjsCalculator();
    }

    public static function audit(bool $getShared = true): \App\Libraries\AuditLogger
    {
        if ($getShared) {
            return static::getSharedInstance('audit');
        }

        return new \App\Libraries\AuditLogger();
    }

    public static function tenant(bool $getShared = true): \App\Libraries\TenantContext
    {
        if ($getShared) {
            return static::getSharedInstance('tenant');
        }

        return new \App\Libraries\TenantContext();
    }

    /**
     * PrivacyScope — enforce employee-level data isolation + filter whitelist
     * permission-aware. Lihat docs/DATA_PRIVACY.md.
     */
    public static function privacy(bool $getShared = true): \App\Libraries\PrivacyScope
    {
        if ($getShared) {
            return static::getSharedInstance('privacy');
        }

        return new \App\Libraries\PrivacyScope(static::tenant());
    }

    public static function allowance(bool $getShared = true): \App\Libraries\AllowanceService
    {
        if ($getShared) {
            return static::getSharedInstance('allowance');
        }

        return new \App\Libraries\AllowanceService();
    }

    public static function approval(bool $getShared = true): \App\Libraries\ApprovalEngine
    {
        if ($getShared) {
            return static::getSharedInstance('approval');
        }

        return new \App\Libraries\ApprovalEngine();
    }

    public static function contractRenderer(bool $getShared = true): \App\Libraries\ContractRenderer
    {
        if ($getShared) {
            return static::getSharedInstance('contractRenderer');
        }

        return new \App\Libraries\ContractRenderer();
    }

    public static function shiftResolver(bool $getShared = true): \App\Libraries\ShiftResolver
    {
        if ($getShared) {
            return static::getSharedInstance('shiftResolver');
        }

        return new \App\Libraries\ShiftResolver();
    }

    public static function leaveBalance(bool $getShared = true): \App\Libraries\LeaveBalanceService
    {
        if ($getShared) {
            return static::getSharedInstance('leaveBalance');
        }

        return new \App\Libraries\LeaveBalanceService();
    }

    public static function payrollExporter(bool $getShared = true): \App\Libraries\PayrollExporter
    {
        if ($getShared) {
            return static::getSharedInstance('payrollExporter');
        }

        return new \App\Libraries\PayrollExporter();
    }

    public static function disciplinary(bool $getShared = true): \App\Libraries\DisciplinaryService
    {
        if ($getShared) {
            return static::getSharedInstance('disciplinary');
        }

        return new \App\Libraries\DisciplinaryService();
    }

    public static function severance(bool $getShared = true): \App\Libraries\SeveranceCalculator
    {
        if ($getShared) {
            return static::getSharedInstance('severance');
        }

        return new \App\Libraries\SeveranceCalculator();
    }

    public static function onboarding(bool $getShared = true): \App\Libraries\OnboardingService
    {
        if ($getShared) {
            return static::getSharedInstance('onboarding');
        }

        return new \App\Libraries\OnboardingService();
    }

    public static function asset(bool $getShared = true): \App\Libraries\AssetService
    {
        if ($getShared) {
            return static::getSharedInstance('asset');
        }

        return new \App\Libraries\AssetService();
    }

    public static function businessTrip(bool $getShared = true): \App\Libraries\BusinessTripService
    {
        if ($getShared) {
            return static::getSharedInstance('businessTrip');
        }

        return new \App\Libraries\BusinessTripService();
    }

    public static function recruitment(bool $getShared = true): \App\Libraries\RecruitmentService
    {
        if ($getShared) {
            return static::getSharedInstance('recruitment');
        }

        return new \App\Libraries\RecruitmentService();
    }

    public static function antiCheat(bool $getShared = true): \App\Libraries\AntiCheatService
    {
        if ($getShared) {
            return static::getSharedInstance('antiCheat');
        }

        return new \App\Libraries\AntiCheatService();
    }

    public static function training(bool $getShared = true): \App\Libraries\TrainingService
    {
        if ($getShared) {
            return static::getSharedInstance('training');
        }

        return new \App\Libraries\TrainingService();
    }

    public static function performance(bool $getShared = true): \App\Libraries\PerformanceService
    {
        if ($getShared) {
            return static::getSharedInstance('performance');
        }

        return new \App\Libraries\PerformanceService();
    }

    /**
     * FileStorage — abstraksi storage backend untuk file user-uploaded.
     * Default LocalDriver. Future: env('storage.driver') = 's3' bisa pilih S3Driver.
     * Lihat docs/AUDIT.md item C.5.
     */
    public static function fileStorage(bool $getShared = true): \App\Libraries\FileStorage\FileStorageInterface
    {
        if ($getShared) {
            return static::getSharedInstance('fileStorage');
        }

        $driver = (string) (env('storage.driver') ?? 'local');
        return match ($driver) {
            // 's3' => new \App\Libraries\FileStorage\S3Driver(),  // future
            default => new \App\Libraries\FileStorage\LocalDriver(),
        };
    }

    /**
     * Encryption — AES-256-GCM dengan multi-key versioning.
     * Lihat docs/AUDIT.md item B.
     */
    public static function encryption(bool $getShared = true): \App\Libraries\Encryption
    {
        if ($getShared) {
            return static::getSharedInstance('encryption');
        }

        return new \App\Libraries\Encryption();
    }
}
