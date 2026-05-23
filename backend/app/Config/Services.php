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

    /**
     * ManpowerService — Manpower Planning (MPP) request workflow.
     * Pengajuan kebutuhan SDM oleh line manager (N+1) → approval HRD/Direksi
     * → Talent Acquisition buatkan job_postings → tracking sampai
     * candidate HIRED + onboarding. Lihat docs/MPP.md.
     */
    public static function manpower(bool $getShared = true): \App\Libraries\ManpowerService
    {
        if ($getShared) {
            return static::getSharedInstance('manpower');
        }

        return new \App\Libraries\ManpowerService();
    }

    /**
     * OrganizationStructureService — master jabatan kunci HR Indonesia
     * (BOD, GM, HR Manager, dst). Single source of truth untuk lookup
     * approver per role_key. Dipakai oleh ManpowerService untuk routing
     * approval & frontend untuk dropdown picker.
     * Lihat docs/ORG_STRUCTURE.md.
     */
    public static function orgStructure(bool $getShared = true): \App\Libraries\OrganizationStructureService
    {
        if ($getShared) {
            return static::getSharedInstance('orgStructure');
        }

        return new \App\Libraries\OrganizationStructureService();
    }

    /**
     * CareerService — public-facing job board yang terhubung ke
     * RecruitmentService & OnboardingService. Lihat docs/CAREER_PORTAL.md.
     */
    public static function career(bool $getShared = true): \App\Libraries\CareerService
    {
        if ($getShared) {
            return static::getSharedInstance('career');
        }

        return new \App\Libraries\CareerService();
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

    /**
     * JicService — Job Information Change (Promosi/Demosi/Mutasi).
     * Workflow approval Supervisor → Dept Head → HR → BOD (kondisional)
     * → Employee Consent (kondisional) → APPROVED → IMPLEMENTED.
     * Lihat docs/JIC.md.
     */
    public static function jic(bool $getShared = true): \App\Libraries\JicService
    {
        if ($getShared) {
            return static::getSharedInstance('jic');
        }

        return new \App\Libraries\JicService();
    }

    /**
     * EmployeeTransferService — Mutasi karyawan antar branch / department /
     * posisi / work_location dengan workflow ringan (HR-only approval).
     * Beda dengan JIC: tidak menyentuh grade/gaji, fokus ke perpindahan
     * lokasi/dept lateral. Lihat docs/EMPLOYEE_TRANSFER.md.
     */
    public static function employeeTransfer(bool $getShared = true): \App\Libraries\EmployeeTransferService
    {
        if ($getShared) {
            return static::getSharedInstance('employeeTransfer');
        }

        return new \App\Libraries\EmployeeTransferService();
    }

    /**
     * PasswordPolicy — aturan & utility untuk validasi password (Sprint 3).
     * Aturan R1-R6 (panjang, kompleksitas, common, history, rotation,
     * user-specific). Lihat docs/IMPROVEMENT_CHECKLIST.md Sprint 3.
     */
    public static function passwordPolicy(bool $getShared = true): \App\Libraries\PasswordPolicy
    {
        if ($getShared) {
            return static::getSharedInstance('passwordPolicy');
        }

        return new \App\Libraries\PasswordPolicy();
    }

    /**
     * TwoFactorBackupCodes — generate/verify 8 single-use recovery codes
     * untuk user 2FA (Sprint 3). Codes di-hash Argon2id sama seperti password.
     */
    public static function twoFactorBackupCodes(bool $getShared = true): \App\Libraries\TwoFactorBackupCodes
    {
        if ($getShared) {
            return static::getSharedInstance('twoFactorBackupCodes');
        }

        return new \App\Libraries\TwoFactorBackupCodes();
    }

    /**
     * DataPrivacyService — UU PDP (UU 27/2022) compliance (Sprint 4):
     * consent record, data export, deletion request workflow, anonimisasi PII.
     */
    public static function dataPrivacy(bool $getShared = true): \App\Libraries\DataPrivacyService
    {
        if ($getShared) {
            return static::getSharedInstance('dataPrivacy');
        }

        return new \App\Libraries\DataPrivacyService();
    }

    /**
     * PayrollSettings — config payroll per-company (Sprint 5-6):
     * working_days_per_month, overtime_hours_per_month, overtime→leave
     * conversion ratio, absent_deduction_enabled. Override via tabel `settings`.
     */
    public static function payrollSettings(bool $getShared = true): \App\Libraries\PayrollSettings
    {
        if ($getShared) {
            return static::getSharedInstance('payrollSettings');
        }

        return new \App\Libraries\PayrollSettings();
    }

    /**
     * ShiftTimeHelper — resolve waktu shift termasuk cross-midnight
     * (Sprint 5-6 fix). Pakai untuk hitung effectiveShiftStart/End,
     * lateMinutes, earlyLeaveMinutes saat shift overnight 22:00→06:00.
     */
    public static function shiftTime(bool $getShared = true): \App\Libraries\ShiftTimeHelper
    {
        if ($getShared) {
            return static::getSharedInstance('shiftTime');
        }

        return new \App\Libraries\ShiftTimeHelper();
    }

    /**
     * EmailTemplateRenderer — render template per company × event_type
     * dengan variable substitution {{var}} (Sprint 7).
     */
    public static function emailTemplate(bool $getShared = true): \App\Libraries\EmailTemplateRenderer
    {
        if ($getShared) {
            return static::getSharedInstance('emailTemplate');
        }

        return new \App\Libraries\EmailTemplateRenderer();
    }

    /**
     * CacheService — wrapper Redis/file cache untuk lookup yang sering
     * dipakai (UMP/UMK, holidays, role permissions, BPJS rates) dengan
     * TTL convention terpusat. Sprint 10+.
     */
    public static function cacheService(bool $getShared = true): \App\Libraries\CacheService
    {
        if ($getShared) {
            return static::getSharedInstance('cacheService');
        }

        return new \App\Libraries\CacheService();
    }

    /**
     * WebhookDispatcher — orchestrate webhook outbound delivery dengan
     * retry exponential backoff + HMAC-SHA256 signing. Sprint 11+.
     * Lihat docs/WEBHOOKS.md.
     */
    public static function webhook(bool $getShared = true): \App\Libraries\WebhookDispatcher
    {
        if ($getShared) {
            return static::getSharedInstance('webhook');
        }

        return new \App\Libraries\WebhookDispatcher();
    }

    /**
     * InboundWebhookHandler — registry + verifikasi untuk webhook inbound
     * (Sprint 14+). Counterpart dari WebhookDispatcher: terima event dari
     * sistem external lewat HTTP POST + HMAC signature, dispatch ke
     * handler terdaftar (RecruitmentService, AccountingSync, dll).
     * Lihat docs/WEBHOOKS.md section "Inbound".
     */
    public static function inbound(bool $getShared = true): \App\Libraries\InboundWebhookHandler
    {
        if ($getShared) {
            return static::getSharedInstance('inbound');
        }

        return new \App\Libraries\InboundWebhookHandler();
    }

    /**
     * CandidateAuthService — auth khusus pelamar (kandidat) di Career Portal.
     * JWT terpisah dari user internal (claim aud=candidate). Lihat
     * docs/CAREER_PORTAL.md section "Candidate Auth".
     */
    public static function candidateAuth(bool $getShared = true): \App\Libraries\CandidateAuthService
    {
        if ($getShared) {
            return static::getSharedInstance('candidateAuth');
        }

        return new \App\Libraries\CandidateAuthService();
    }

    /**
     * OnlineTestService — engine test online untuk kandidat (TPA, English,
     * DISC, technical). Auto-grade MCQ/TF/Numeric/Likert, manual grade
     * essay. Lihat docs/ONLINE_TEST.md.
     */
    public static function onlineTest(bool $getShared = true): \App\Libraries\OnlineTestService
    {
        if ($getShared) {
            return static::getSharedInstance('onlineTest');
        }

        return new \App\Libraries\OnlineTestService();
    }
}
