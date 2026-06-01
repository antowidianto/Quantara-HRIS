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
    public static function jwt(bool $getShared = true): \App\Libraries\Auth\JwtService
    {
        if ($getShared) {
            return static::getSharedInstance('jwt');
        }

        return new \App\Libraries\Auth\JwtService();
    }

    public static function notification(bool $getShared = true): \App\Libraries\Notification\NotificationService
    {
        if ($getShared) {
            return static::getSharedInstance('notification');
        }

        return new \App\Libraries\Notification\NotificationService();
    }

    public static function payroll(bool $getShared = true): \App\Libraries\Payroll\PayrollService
    {
        if ($getShared) {
            return static::getSharedInstance('payroll');
        }

        return new \App\Libraries\Payroll\PayrollService();
    }

    public static function pph21(bool $getShared = true): \App\Libraries\Payroll\Pph21TerCalculator
    {
        if ($getShared) {
            return static::getSharedInstance('pph21');
        }

        return new \App\Libraries\Payroll\Pph21TerCalculator();
    }

    public static function bpjs(bool $getShared = true): \App\Libraries\Payroll\BpjsCalculator
    {
        if ($getShared) {
            return static::getSharedInstance('bpjs');
        }

        return new \App\Libraries\Payroll\BpjsCalculator();
    }

    public static function audit(bool $getShared = true): \App\Libraries\Compliance\AuditLogger
    {
        if ($getShared) {
            return static::getSharedInstance('audit');
        }

        return new \App\Libraries\Compliance\AuditLogger();
    }

    public static function tenant(bool $getShared = true): \App\Libraries\System\TenantContext
    {
        if ($getShared) {
            return static::getSharedInstance('tenant');
        }

        return new \App\Libraries\System\TenantContext();
    }

    /**
     * PrivacyScope — enforce employee-level data isolation + filter whitelist
     * permission-aware. Lihat docs/DATA_PRIVACY.md.
     */
    public static function privacy(bool $getShared = true): \App\Libraries\Compliance\PrivacyScope
    {
        if ($getShared) {
            return static::getSharedInstance('privacy');
        }

        return new \App\Libraries\Compliance\PrivacyScope(static::tenant());
    }

    public static function allowance(bool $getShared = true): \App\Libraries\Payroll\AllowanceService
    {
        if ($getShared) {
            return static::getSharedInstance('allowance');
        }

        return new \App\Libraries\Payroll\AllowanceService();
    }

    public static function approval(bool $getShared = true): \App\Libraries\Approval\ApprovalEngine
    {
        if ($getShared) {
            return static::getSharedInstance('approval');
        }

        return new \App\Libraries\Approval\ApprovalEngine();
    }

    public static function contractRenderer(bool $getShared = true): \App\Libraries\Lifecycle\ContractRenderer
    {
        if ($getShared) {
            return static::getSharedInstance('contractRenderer');
        }

        return new \App\Libraries\Lifecycle\ContractRenderer();
    }

    public static function shiftResolver(bool $getShared = true): \App\Libraries\Attendance\ShiftResolver
    {
        if ($getShared) {
            return static::getSharedInstance('shiftResolver');
        }

        return new \App\Libraries\Attendance\ShiftResolver();
    }

    public static function leaveBalance(bool $getShared = true): \App\Libraries\Leave\LeaveBalanceService
    {
        if ($getShared) {
            return static::getSharedInstance('leaveBalance');
        }

        return new \App\Libraries\Leave\LeaveBalanceService();
    }

    public static function payrollExporter(bool $getShared = true): \App\Libraries\Payroll\PayrollExporter
    {
        if ($getShared) {
            return static::getSharedInstance('payrollExporter');
        }

        return new \App\Libraries\Payroll\PayrollExporter();
    }

    public static function disciplinary(bool $getShared = true): \App\Libraries\Lifecycle\DisciplinaryService
    {
        if ($getShared) {
            return static::getSharedInstance('disciplinary');
        }

        return new \App\Libraries\Lifecycle\DisciplinaryService();
    }

    public static function severance(bool $getShared = true): \App\Libraries\Lifecycle\SeveranceCalculator
    {
        if ($getShared) {
            return static::getSharedInstance('severance');
        }

        return new \App\Libraries\Lifecycle\SeveranceCalculator();
    }

    public static function onboarding(bool $getShared = true): \App\Libraries\Recruitment\OnboardingService
    {
        if ($getShared) {
            return static::getSharedInstance('onboarding');
        }

        return new \App\Libraries\Recruitment\OnboardingService();
    }

    /**
     * OnboardingPlanService — orchestrator untuk fields yang di-plan saat
     * MPP submission (job_grade, supervisor, manager, planned assets) dan
     * yang otomatis dipopulasi saat hire (saldo cuti per gender, asset
     * pre-allocation). Dipanggil dari ManpowerService, RecruitmentService,
     * dan endpoint baru di MppController + OnboardingController.
     */
    public static function onboardingPlan(bool $getShared = true): \App\Libraries\Recruitment\OnboardingPlanService
    {
        if ($getShared) {
            return static::getSharedInstance('onboardingPlan');
        }

        return new \App\Libraries\Recruitment\OnboardingPlanService();
    }

    /**
     * XlsxImportService — pipeline bulk import dari xlsx (Migration #067).
     * Backed by phpoffice/phpspreadsheet. Lihat docs/XLSX_IMPORT_AUDIT.md
     * dan ImportRegistry untuk daftar resource yang siap di-import.
     */
    public static function xlsxImport(bool $getShared = true): \App\Libraries\Import\XlsxImportService
    {
        if ($getShared) {
            return static::getSharedInstance('xlsxImport');
        }

        return new \App\Libraries\Import\XlsxImportService();
    }

    /**
     * CostCenterService — dimensi accounting cost center (Sprint 6).
     * Resolver chain employee → department → branch → company root,
     * CRUD master + budget periodisasi, budget vs actual reporting.
     * Dipakai oleh payroll, reimbursement, loan, business trip, bonus,
     * severance, asset, MPP, employee profile.
     */
    public static function costCenter(bool $getShared = true): \App\Libraries\Master\CostCenterService
    {
        if ($getShared) {
            return static::getSharedInstance('costCenter');
        }

        return new \App\Libraries\Master\CostCenterService();
    }

    /**
     * CandidateScoringService — Sprint 11 / H2.4. Hitung composite score
     * weighted dari online test, salary fit, experience, availability,
     * education, interview rating. Output explainable breakdown untuk UI.
     */
    public static function candidateScoring(bool $getShared = true): \App\Libraries\Recruitment\CandidateScoringService
    {
        if ($getShared) {
            return static::getSharedInstance('candidateScoring');
        }
        return new \App\Libraries\Recruitment\CandidateScoringService();
    }

    /**
     * PerformanceCascadingService — Sprint 11 / H2.2 + H2.3. Goals
     * cascading (parent/child + weighted progress rollup) + 360° peer
     * feedback (nominate, aggregate, anonymize).
     */
    public static function performanceCascading(bool $getShared = true): \App\Libraries\Performance\PerformanceCascadingService
    {
        if ($getShared) {
            return static::getSharedInstance('performanceCascading');
        }
        return new \App\Libraries\Performance\PerformanceCascadingService();
    }

    /**
     * ReferralProgramService — Sprint 12 / H2.5. Generator referral link
     * + bonus auto-trigger setelah kandidat HIRED + lulus probasi.
     */
    public static function referralProgram(bool $getShared = true): \App\Libraries\Recruitment\ReferralProgramService
    {
        if ($getShared) {
            return static::getSharedInstance('referralProgram');
        }
        return new \App\Libraries\Recruitment\ReferralProgramService();
    }

    /**
     * TurnoverAnalyticsService — Sprint 12 / H2.6. Compute turnover &
     * retention metric per period + snapshot trend untuk dashboard.
     */
    public static function turnoverAnalytics(bool $getShared = true): \App\Libraries\Compliance\TurnoverAnalyticsService
    {
        if ($getShared) {
            return static::getSharedInstance('turnoverAnalytics');
        }
        return new \App\Libraries\Compliance\TurnoverAnalyticsService();
    }

    /**
     * PayrollSimulationService — Sprint 13 / H2.7. What-if calculator
     * gross → BPJS → PPh21 → THP. Re-use BpjsCalculator + Pph21TerCalculator.
     */
    public static function payrollSimulation(bool $getShared = true): \App\Libraries\Payroll\PayrollSimulationService
    {
        if ($getShared) {
            return static::getSharedInstance('payrollSimulation');
        }
        return new \App\Libraries\Payroll\PayrollSimulationService();
    }

    /**
     * OcrService — Sprint 13 / H2.8. Extract field dari struk
     * reimbursement (vendor, total, date) dengan driver pluggable
     * (mock|tesseract|gcloud_vision|aws_textract via env OCR_DRIVER).
     */
    public static function ocr(bool $getShared = true): \App\Libraries\System\OcrService
    {
        if ($getShared) {
            return static::getSharedInstance('ocr');
        }
        return new \App\Libraries\System\OcrService();
    }

    /**
     * CompetencyService — Sprint 14 / H3.1. Library kompetensi +
     * gap analysis terhadap profile posisi + rekomendasi training.
     */
    public static function competency(bool $getShared = true): \App\Libraries\Performance\CompetencyService
    {
        if ($getShared) {
            return static::getSharedInstance('competency');
        }
        return new \App\Libraries\Performance\CompetencyService();
    }

    /**
     * SuccessionService — Sprint 14 / H3.2. Critical positions +
     * succession candidates + coverage ratio dashboard.
     */
    public static function succession(bool $getShared = true): \App\Libraries\Performance\SuccessionService
    {
        if ($getShared) {
            return static::getSharedInstance('succession');
        }
        return new \App\Libraries\Performance\SuccessionService();
    }

    /**
     * TalentPoolService — Sprint 17 / H3.6. Skill matrix + internal
     * job marketplace + 9-box grid.
     */
    public static function talentPool(bool $getShared = true): \App\Libraries\Recruitment\TalentPoolService
    {
        if ($getShared) {
            return static::getSharedInstance('talentPool');
        }
        return new \App\Libraries\Recruitment\TalentPoolService();
    }

    /**
     * AiAssistantService — Sprint 19+ / H4.1. AI HR Copilot dengan
     * driver pluggable (mock|openai|anthropic|local) + PII redactor.
     */
    public static function aiAssistant(bool $getShared = true): \App\Libraries\Analytics\AiAssistantService
    {
        if ($getShared) return static::getSharedInstance('aiAssistant');
        return new \App\Libraries\Analytics\AiAssistantService();
    }

    /**
     * WorkforceSimulationService — Sprint 19+ / H4.3. Monte Carlo
     * scenario modeling untuk forecasting headcount + cost.
     */
    public static function workforceSim(bool $getShared = true): \App\Libraries\Manpower\WorkforceSimulationService
    {
        if ($getShared) return static::getSharedInstance('workforceSim');
        return new \App\Libraries\Manpower\WorkforceSimulationService();
    }

    /**
     * PulseSurveyService — Sprint 19+ / H4.4. Anonymous pulse survey
     * dengan eNPS + burnout score + privacy gate.
     */
    public static function pulseSurvey(bool $getShared = true): \App\Libraries\Performance\PulseSurveyService
    {
        if ($getShared) return static::getSharedInstance('pulseSurvey');
        return new \App\Libraries\Performance\PulseSurveyService();
    }

    /**
     * CompensationIntelligenceService — Sprint 19+ / H4.5. Compa-ratio,
     * pay equity (gender), salary band recommendation.
     */
    public static function compensationIntelligence(bool $getShared = true): \App\Libraries\Analytics\CompensationIntelligenceService
    {
        if ($getShared) return static::getSharedInstance('compensationIntelligence');
        return new \App\Libraries\Analytics\CompensationIntelligenceService();
    }

    /**
     * PaklaringService — generator Surat Keterangan Kerja (paklaring) untuk
     * karyawan yang berhenti. Wajib menurut UU 13/2003 Pasal 153, biasanya
     * jadi syarat aplikasi kerja di perusahaan baru. Lihat docs/RESIGNATION.md
     * dan docs/LIFECYCLE_AUDIT_2026.md (item OFF-2).
     */
    public static function paklaring(bool $getShared = true): \App\Libraries\Lifecycle\PaklaringService
    {
        if ($getShared) {
            return static::getSharedInstance('paklaring');
        }

        return new \App\Libraries\Lifecycle\PaklaringService();
    }

    /**
     * OffboardingService — clearance gates (asset/finance/BPJS) +
     * orchestrator final settlement → payroll. Lihat docs/RESIGNATION.md
     * dan docs/LIFECYCLE_AUDIT_2026.md (item OFF-3, OFF-4, OFF-6).
     */
    public static function offboarding(bool $getShared = true): \App\Libraries\Lifecycle\OffboardingService
    {
        if ($getShared) {
            return static::getSharedInstance('offboarding');
        }

        return new \App\Libraries\Lifecycle\OffboardingService();
    }

    /**
     * OfferService — manage candidate offer letter (Sprint 2 / REC-1).
     * Workflow DRAFT → SENT → ACCEPTED/REJECTED/EXPIRED/WITHDRAWN.
     * Lihat docs/LIFECYCLE_AUDIT_2026.md item REC-1.
     */
    public static function offer(bool $getShared = true): \App\Libraries\Recruitment\OfferService
    {
        if ($getShared) {
            return static::getSharedInstance('offer');
        }

        return new \App\Libraries\Recruitment\OfferService();
    }

    /**
     * ProbationService — masa percobaan PKWTT compliance (Sprint 2 / HIRE-1).
     * Auto-fill probation_end_date saat hire, decision PASS/FAIL/EXTEND,
     * scanner expired+reminder. Lihat UU 13/2003 Pasal 60.
     */
    public static function probation(bool $getShared = true): \App\Libraries\Employee\ProbationService
    {
        if ($getShared) {
            return static::getSharedInstance('probation');
        }

        return new \App\Libraries\Employee\ProbationService();
    }

    /**
     * IdpService — Individual Development Plan (Sprint 3 / OD-2).
     * Auto-bootstrap IDP dari performance review.improvements field saat
     * review difinalize. Lihat docs/LIFECYCLE_AUDIT_2026.md item OD-2.
     */
    public static function idp(bool $getShared = true): \App\Libraries\Performance\IdpService
    {
        if ($getShared) {
            return static::getSharedInstance('idp');
        }

        return new \App\Libraries\Performance\IdpService();
    }

    /**
     * ApprovalDelegationResolver — pure helper untuk module yang tidak
     * pakai ApprovalEngine (MPP, Resignation, Transfer, JIC). Cek delegasi
     * aktif berdasarkan tabel `approval_delegations`. Sprint 3 / CC-3.
     */
    public static function approvalDelegationResolver(bool $getShared = true): \App\Libraries\Approval\ApprovalDelegationResolver
    {
        if ($getShared) {
            return static::getSharedInstance('approvalDelegationResolver');
        }

        return new \App\Libraries\Approval\ApprovalDelegationResolver();
    }

    public static function asset(bool $getShared = true): \App\Libraries\Asset\AssetService
    {
        if ($getShared) {
            return static::getSharedInstance('asset');
        }

        return new \App\Libraries\Asset\AssetService();
    }

    public static function businessTrip(bool $getShared = true): \App\Libraries\Attendance\BusinessTripService
    {
        if ($getShared) {
            return static::getSharedInstance('businessTrip');
        }

        return new \App\Libraries\Attendance\BusinessTripService();
    }

    public static function recruitment(bool $getShared = true): \App\Libraries\Recruitment\RecruitmentService
    {
        if ($getShared) {
            return static::getSharedInstance('recruitment');
        }

        return new \App\Libraries\Recruitment\RecruitmentService();
    }

    /**
     * ManpowerService — Manpower Planning (MPP) request workflow.
     * Pengajuan kebutuhan SDM oleh line manager (N+1) → approval HRD/Direksi
     * → Talent Acquisition buatkan job_postings → tracking sampai
     * candidate HIRED + onboarding. Lihat docs/MPP.md.
     */
    public static function manpower(bool $getShared = true): \App\Libraries\Manpower\ManpowerService
    {
        if ($getShared) {
            return static::getSharedInstance('manpower');
        }

        return new \App\Libraries\Manpower\ManpowerService();
    }

    /**
     * OrganizationStructureService — master jabatan kunci HR Indonesia
     * (BOD, GM, HR Manager, dst). Single source of truth untuk lookup
     * approver per role_key. Dipakai oleh ManpowerService untuk routing
     * approval & frontend untuk dropdown picker.
     * Lihat docs/ORG_STRUCTURE.md.
     */
    public static function orgStructure(bool $getShared = true): \App\Libraries\Master\OrganizationStructureService
    {
        if ($getShared) {
            return static::getSharedInstance('orgStructure');
        }

        return new \App\Libraries\Master\OrganizationStructureService();
    }

    /**
     * CareerService — public-facing job board yang terhubung ke
     * RecruitmentService & OnboardingService. Lihat docs/CAREER_PORTAL.md.
     */
    public static function career(bool $getShared = true): \App\Libraries\Recruitment\CareerService
    {
        if ($getShared) {
            return static::getSharedInstance('career');
        }

        return new \App\Libraries\Recruitment\CareerService();
    }

    public static function antiCheat(bool $getShared = true): \App\Libraries\Attendance\AntiCheatService
    {
        if ($getShared) {
            return static::getSharedInstance('antiCheat');
        }

        return new \App\Libraries\Attendance\AntiCheatService();
    }

    public static function training(bool $getShared = true): \App\Libraries\Training\TrainingService
    {
        if ($getShared) {
            return static::getSharedInstance('training');
        }

        return new \App\Libraries\Training\TrainingService();
    }

    public static function performance(bool $getShared = true): \App\Libraries\Performance\PerformanceService
    {
        if ($getShared) {
            return static::getSharedInstance('performance');
        }

        return new \App\Libraries\Performance\PerformanceService();
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
    public static function encryption(bool $getShared = true): \App\Libraries\System\Encryption
    {
        if ($getShared) {
            return static::getSharedInstance('encryption');
        }

        return new \App\Libraries\System\Encryption();
    }

    /**
     * JicService — Job Information Change (Promosi/Demosi/Mutasi).
     * Workflow approval Supervisor → Dept Head → HR → BOD (kondisional)
     * → Employee Consent (kondisional) → APPROVED → IMPLEMENTED.
     * Lihat docs/JIC.md.
     */
    public static function jic(bool $getShared = true): \App\Libraries\Employee\JicService
    {
        if ($getShared) {
            return static::getSharedInstance('jic');
        }

        return new \App\Libraries\Employee\JicService();
    }

    /**
     * EmployeeTransferService — Mutasi karyawan antar branch / department /
     * posisi / work_location dengan workflow ringan (HR-only approval).
     * Beda dengan JIC: tidak menyentuh grade/gaji, fokus ke perpindahan
     * lokasi/dept lateral. Lihat docs/EMPLOYEE_TRANSFER.md.
     */
    public static function employeeTransfer(bool $getShared = true): \App\Libraries\Employee\EmployeeTransferService
    {
        if ($getShared) {
            return static::getSharedInstance('employeeTransfer');
        }

        return new \App\Libraries\Employee\EmployeeTransferService();
    }

    /**
     * PasswordPolicy — aturan & utility untuk validasi password (Sprint 3).
     * Aturan R1-R6 (panjang, kompleksitas, common, history, rotation,
     * user-specific). Lihat docs/IMPROVEMENT_CHECKLIST.md Sprint 3.
     */
    public static function passwordPolicy(bool $getShared = true): \App\Libraries\Auth\PasswordPolicy
    {
        if ($getShared) {
            return static::getSharedInstance('passwordPolicy');
        }

        return new \App\Libraries\Auth\PasswordPolicy();
    }

    /**
     * TwoFactorBackupCodes — generate/verify 8 single-use recovery codes
     * untuk user 2FA (Sprint 3). Codes di-hash Argon2id sama seperti password.
     */
    public static function twoFactorBackupCodes(bool $getShared = true): \App\Libraries\Auth\TwoFactorBackupCodes
    {
        if ($getShared) {
            return static::getSharedInstance('twoFactorBackupCodes');
        }

        return new \App\Libraries\Auth\TwoFactorBackupCodes();
    }

    /**
     * DataPrivacyService — UU PDP (UU 27/2022) compliance (Sprint 4):
     * consent record, data export, deletion request workflow, anonimisasi PII.
     */
    public static function dataPrivacy(bool $getShared = true): \App\Libraries\Compliance\DataPrivacyService
    {
        if ($getShared) {
            return static::getSharedInstance('dataPrivacy');
        }

        return new \App\Libraries\Compliance\DataPrivacyService();
    }

    /**
     * PayrollSettings — config payroll per-company (Sprint 5-6):
     * working_days_per_month, overtime_hours_per_month, overtime→leave
     * conversion ratio, absent_deduction_enabled. Override via tabel `settings`.
     */
    public static function payrollSettings(bool $getShared = true): \App\Libraries\Payroll\PayrollSettings
    {
        if ($getShared) {
            return static::getSharedInstance('payrollSettings');
        }

        return new \App\Libraries\Payroll\PayrollSettings();
    }

    /**
     * ShiftTimeHelper — resolve waktu shift termasuk cross-midnight
     * (Sprint 5-6 fix). Pakai untuk hitung effectiveShiftStart/End,
     * lateMinutes, earlyLeaveMinutes saat shift overnight 22:00→06:00.
     */
    public static function shiftTime(bool $getShared = true): \App\Libraries\Attendance\ShiftTimeHelper
    {
        if ($getShared) {
            return static::getSharedInstance('shiftTime');
        }

        return new \App\Libraries\Attendance\ShiftTimeHelper();
    }

    /**
     * EmailTemplateRenderer — render template per company × event_type
     * dengan variable substitution {{var}} (Sprint 7).
     */
    public static function emailTemplate(bool $getShared = true): \App\Libraries\System\EmailTemplateRenderer
    {
        if ($getShared) {
            return static::getSharedInstance('emailTemplate');
        }

        return new \App\Libraries\System\EmailTemplateRenderer();
    }

    /**
     * CacheService — wrapper Redis/file cache untuk lookup yang sering
     * dipakai (UMP/UMK, holidays, role permissions, BPJS rates) dengan
     * TTL convention terpusat. Sprint 10+.
     */
    public static function cacheService(bool $getShared = true): \App\Libraries\System\CacheService
    {
        if ($getShared) {
            return static::getSharedInstance('cacheService');
        }

        return new \App\Libraries\System\CacheService();
    }

    /**
     * LookupCache — Sprint D+1 / D4. High-level read-through cache untuk
     * data quasi-static (UMP/UMK, holidays, role permissions, BPJS rates,
     * payroll settings, TER rates). Bungkus CacheService dengan loader
     * callback dan TTL convention per resource.
     *
     * Caller di controller payroll, leave, attendance bisa
     *   `Services::lookup()->getHolidays($companyId, $year)`
     * supaya tidak query DB tiap request.
     */
    public static function lookup(bool $getShared = true): \App\Libraries\System\LookupCache
    {
        if ($getShared) {
            return static::getSharedInstance('lookup');
        }

        return new \App\Libraries\System\LookupCache();
    }

    /**
     * WebhookDispatcher — orchestrate webhook outbound delivery dengan
     * retry exponential backoff + HMAC-SHA256 signing. Sprint 11+.
     * Lihat docs/WEBHOOKS.md.
     */
    public static function webhook(bool $getShared = true): \App\Libraries\Webhook\WebhookDispatcher
    {
        if ($getShared) {
            return static::getSharedInstance('webhook');
        }

        return new \App\Libraries\Webhook\WebhookDispatcher();
    }

    /**
     * InboundWebhookHandler — registry + verifikasi untuk webhook inbound
     * (Sprint 14+). Counterpart dari WebhookDispatcher: terima event dari
     * sistem external lewat HTTP POST + HMAC signature, dispatch ke
     * handler terdaftar (RecruitmentService, AccountingSync, dll).
     * Lihat docs/WEBHOOKS.md section "Inbound".
     */
    public static function inbound(bool $getShared = true): \App\Libraries\Webhook\InboundWebhookHandler
    {
        if ($getShared) {
            return static::getSharedInstance('inbound');
        }

        return new \App\Libraries\Webhook\InboundWebhookHandler();
    }

    /**
     * CandidateAuthService — auth khusus pelamar (kandidat) di Career Portal.
     * JWT terpisah dari user internal (claim aud=candidate). Lihat
     * docs/CAREER_PORTAL.md section "Candidate Auth".
     */
    public static function candidateAuth(bool $getShared = true): \App\Libraries\Auth\CandidateAuthService
    {
        if ($getShared) {
            return static::getSharedInstance('candidateAuth');
        }

        return new \App\Libraries\Auth\CandidateAuthService();
    }

    /**
     * OnlineTestService — engine test online untuk kandidat (TPA, English,
     * DISC, technical). Auto-grade MCQ/TF/Numeric/Likert, manual grade
     * essay. Lihat docs/ONLINE_TEST.md.
     */
    public static function onlineTest(bool $getShared = true): \App\Libraries\Recruitment\OnlineTestService
    {
        if ($getShared) {
            return static::getSharedInstance('onlineTest');
        }

        return new \App\Libraries\Recruitment\OnlineTestService();
    }

    /**
     * TerminationService — PHK formal workflow (Sprint 4 / OFF-5).
     * Multi-step approval: HR Director → BOD (kondisional). Auto-create
     * severance + PHK letter saat execute. Lihat docs/SPRINT_4_TERMINATION_POLISH.md.
     */
    public static function termination(bool $getShared = true): \App\Libraries\Lifecycle\TerminationService
    {
        if ($getShared) {
            return static::getSharedInstance('termination');
        }

        return new \App\Libraries\Lifecycle\TerminationService();
    }

    /**
     * ExitInterviewService — wawancara terstruktur saat resign / PHK
     * (Sprint 4 / OFF-1). Analytics: would_rehire, primary_reason breakdown,
     * eNPS, satisfaction. Lihat docs/SPRINT_4_TERMINATION_POLISH.md.
     */
    public static function exitInterview(bool $getShared = true): \App\Libraries\Lifecycle\ExitInterviewService
    {
        if ($getShared) {
            return static::getSharedInstance('exitInterview');
        }

        return new \App\Libraries\Lifecycle\ExitInterviewService();
    }

    /**
     * Employee360Service — aggregator profile lengkap karyawan untuk
     * Employee 360 view (Sprint 4 / CC-4). Aggregate 12 section:
     * profile, employment, compensation, leave, attendance, payroll YTD,
     * performance, training, assets, disciplinary, documents, lifecycle.
     */
    public static function employee360(bool $getShared = true): \App\Libraries\Employee\Employee360Service
    {
        if ($getShared) {
            return static::getSharedInstance('employee360');
        }

        return new \App\Libraries\Employee\Employee360Service();
    }

    /**
     * ComplianceReportService — generator laporan kepatuhan HR
     * (Sprint 4 / CC-5). Reports: WLKP tahunan, BPJS bulanan, PKWT expiring,
     * PHK list, audit summary, probation/BPJS overdue, turnover summary.
     * Hasil di-cache di compliance_report_runs (TTL 24 jam).
     */
    public static function complianceReport(bool $getShared = true): \App\Libraries\Lifecycle\ComplianceReportService
    {
        if ($getShared) {
            return static::getSharedInstance('complianceReport');
        }

        return new \App\Libraries\Lifecycle\ComplianceReportService();
    }

    /**
     * CircuitBreaker factory — Sprint D / D6.
     *
     * Registry per-service circuit breaker dengan threshold & cooldown
     * dari env (default fallback aman). Shared instance per service name
     * supaya state konsisten di lifecycle request.
     *
     * Env keys (optional, fallback ke default):
     *   - circuitBreaker.{service}.threshold (default 5)
     *   - circuitBreaker.{service}.cooldown  (default 60 detik)
     *
     * Usage:
     *   $cb = Services::circuitBreaker('whatsapp');
     *   $cb->call(fn() => $client->send($payload));
     *
     * Kalau service tidak terdaftar, factory tetap return CircuitBreaker
     * dengan default config — fail-safe. Untuk dapat snapshot semua
     * breaker untuk dashboard ops, panggil
     * `CircuitBreaker::snapshot()` per service yang dipakai.
     */
    public static function circuitBreaker(string $service, bool $getShared = true): \App\Libraries\System\CircuitBreaker
    {
        $key = 'circuitBreaker.' . $service;
        if ($getShared) {
            $existing = static::getSharedInstance($key);
            if ($existing instanceof \App\Libraries\System\CircuitBreaker) {
                return $existing;
            }
        }

        $threshold = (int) (env('circuitBreaker.' . $service . '.threshold') ?: 5);
        $cooldown  = (int) (env('circuitBreaker.' . $service . '.cooldown') ?: 60);

        $cb = new \App\Libraries\System\CircuitBreaker($service, $threshold, $cooldown);
        if ($getShared) {
            static::injectMock($key, $cb);
        }
        return $cb;
    }

    /**
     * PushNotifier — Mobile Prep / MOB-2. FCM HTTP v1 adapter untuk
     * push notification ke Android/iOS. Lihat docs/MOBILE_APP_PLAN.md
     * section 6.2.
     *
     * Env keys:
     *   - fcm.projectId           : Firebase project ID
     *   - fcm.serviceAccountPath  : Absolute path ke service account JSON
     */
    public static function pushNotifier(bool $getShared = true): \App\Libraries\Notification\PushNotifier
    {
        if ($getShared) {
            return static::getSharedInstance('pushNotifier');
        }
        return new \App\Libraries\Notification\PushNotifier();
    }
}
