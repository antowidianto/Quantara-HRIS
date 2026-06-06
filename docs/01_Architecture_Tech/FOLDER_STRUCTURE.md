# Struktur Folder Proyek — Quantara HRIS

Dokumen ini menggambarkan struktur folder dan turunan lengkap dari proyek **Quantara HRIS**, sebuah Human Resource Information System yang patuh regulasi Kemenaker, multi business unit, dengan payroll, notifikasi multi-channel, dan keamanan berlapis.

## Ringkasan Tech Stack

| Layer    | Teknologi                                          |
|----------|----------------------------------------------------|
| Backend  | CodeIgniter 4 (PHP 8.1+)                            |
| Frontend | Vue 3 + Vite + Tailwind CSS + Pinia                |
| Mobile   | React Native (TypeScript) + Android/iOS native     |
| Database | MySQL 8 / MariaDB 10.6+ (SQLite untuk dev)         |
| Auth     | JWT (Access + Refresh) + 2FA TOTP + Backup Codes   |
| Mail     | SMTP (PHPMailer + queue + exponential backoff)     |
| Cache    | Redis (production) / File (dev)                    |
| Push     | Web Push (VAPID) + Mobile Push                     |
| PDF      | dompdf                                             |
| Excel    | phpoffice/phpspreadsheet                           |

## Struktur Tingkat Atas

```
Quantara-HRIS/
├── .github/                 # CI/CD pipeline & dependabot
├── backend/                 # CodeIgniter 4 REST API
├── frontend/                # Vue 3 + Vite SPA (PWA-ready)
├── mobile/                  # React Native app (Android + iOS)
├── docs/                    # Dokumentasi fitur, ADR, OpenAPI
├── scripts/                 # Setup & switch-env scripts (cross-platform)
├── docker-compose.yml       # Dev stack (mysql + redis + mailhog + backend + frontend)
├── README.md                # Ringkasan proyek & quick start
├── SETUP.md                 # Panduan setup development
└── .gitignore
```

---

## 1. `.github/` — CI/CD & Automation

```
.github/
├── workflows/
│   └── ci.yml               # Pipeline: PHPUnit + PHPStan + ESLint + Vitest + build
└── dependabot.yml           # Auto-update dependencies mingguan
```

---

## 2. `backend/` — CodeIgniter 4 REST API

Backend adalah API utama berbasis CodeIgniter 4 dengan 65+ controller, 76 migration, dan 80+ library service.

### 2.1 Root Backend

```
backend/
├── .dockerignore
├── .env.example             # Template environment
├── Dockerfile               # Production multi-stage build
├── Dockerfile.dev           # Development build
├── composer.json            # Dependency PHP
├── composer.lock
├── phpstan-baseline.neon     # Baseline static analysis
├── phpstan.neon.dist         # Konfigurasi PHPStan
├── phpunit.xml.dist          # Konfigurasi PHPUnit
├── pint.json                 # Konfigurasi Laravel Pint (code style)
├── spark                     # CLI entrypoint CodeIgniter
├── app/                      # Kode aplikasi
├── env-templates/            # Template env per environment
├── public/                   # Web root (index.php + .htaccess)
├── scripts/                  # Script maintenance & test flow
├── tests/                    # Unit test
└── writable/                 # Cache, logs, session, uploads
```

### 2.2 `backend/app/Commands/` — CLI Commands (Cron/Scheduler)

```
Commands/
├── ApplyJic.php                    # Apply Job Information Change
├── ApplyTransfers.php              # Apply mutasi karyawan
├── ArchiveAuditLogs.php            # Arsip audit log lama
├── CheckAnniversary.php            # Cek hari jadi/ulang tahun
├── CheckBpjsCompliance.php         # Cek kepatuhan BPJS
├── CheckCertExpiry.php             # Cek expiry sertifikat training
├── CheckContractExpiry.php         # Cek expiry kontrak PKWT
├── CheckHelpdeskSla.php            # Cek SLA tiket helpdesk
├── CheckMppOverdue.php             # Cek MPP yang overdue
├── CheckOfferExpiry.php            # Cek expiry offer rekrutmen
├── CheckProbationDeadline.php      # Cek deadline masa percobaan
├── CleanupAttachments.php          # Bersihkan attachment orphan
├── DeactivateResigned.php          # Nonaktifkan karyawan resign
├── NotifyCarryOverCap.php          # Notif batas carry-over cuti
├── ProcessEmailQueue.php           # Proses antrian email
├── ProcessWebhooks.php             # Proses webhook outbound
├── PurgeIdempotencyKeys.php        # Hapus idempotency key expired
├── RotateEncryption.php            # Rotasi kunci enkripsi
├── RunAudit.php                    # Jalankan audit
├── RunLeaveAnniversaryCarry.php    # Carry-over cuti anniversary
├── RunLeaveYearEnd.php             # Proses akhir tahun cuti
├── SyncPermissions.php             # Sinkronisasi permission
├── VerifyAuditChain.php            # Verifikasi hash chain audit
└── WarmCache.php                   # Warm-up cache
```

### 2.3 `backend/app/Config/` — Konfigurasi Framework

```
Config/
├── Boot/
│   ├── development.php
│   └── production.php
├── App.php                  # Konfigurasi aplikasi inti
├── Autoload.php
├── CURLRequest.php
├── Cache.php                # Konfigurasi cache (Redis/File)
├── Constants.php
├── ContentSecurityPolicy.php # CSP headers
├── Cookie.php
├── Cors.php                 # CORS whitelist
├── Database.php
├── DocTypes.php
├── Email.php                # Konfigurasi SMTP
├── Encryption.php
├── Events.php
├── Exceptions.php
├── Feature.php
├── Filters.php              # Registrasi filter (JWT, throttle, dll)
├── ForeignCharacters.php
├── Format.php
├── Generators.php
├── Honeypot.php
├── Hostnames.php
├── Images.php
├── Jwt.php                  # Konfigurasi JWT
├── Kint.php
├── Logger.php
├── Migrations.php
├── Mimes.php
├── Modules.php
├── Optimize.php
├── Pager.php
├── Paths.php
├── Publisher.php
├── Routes.php               # Definisi routing API
├── Routing.php
├── Security.php
├── Services.php
├── Session.php
├── Toolbar.php
├── UserAgents.php
├── Validation.php
├── View.php
└── WorkerMode.php
```

### 2.4 `backend/app/Constants/` — Enum & Konstanta

```
Constants/
├── ApprovalStatus.php       # Status approval
├── AttendanceStatus.php     # Status kehadiran
├── EmploymentStatus.php     # Status kepegawaian
├── ErrorCode.php            # Kode error standar
└── WebhookEvent.php         # Daftar event webhook
```

### 2.5 `backend/app/Controllers/` — Controllers API

Controller diorganisir per domain di bawah `Api/V1/`.

```
Controllers/
├── BaseController.php
└── Api/
    └── V1/
        ├── ApiController.php             # Base API controller
        ├── MasterControllers.php
        ├── MasterCrudController.php       # Generic CRUD master data
        ├── Approval/
        │   ├── ApprovalChainController.php
        │   └── ApprovalDelegationController.php
        ├── Asset/
        │   └── AssetController.php
        ├── Attendance/
        │   ├── AnomalyController.php
        │   ├── AttendanceChangeRequestController.php
        │   ├── AttendanceController.php
        │   ├── BusinessTripController.php
        │   ├── OvertimeController.php
        │   ├── RosterController.php
        │   ├── ShiftAssignmentController.php
        │   ├── ShiftController.php
        │   ├── ShiftPatternController.php
        │   ├── ShiftScheduleController.php
        │   └── WfhRequestController.php
        ├── Auth/
        │   ├── AuthController.php
        │   └── CandidateAuthController.php
        ├── Compliance/
        │   ├── AuditLogController.php
        │   ├── ComplianceReportController.php
        │   ├── CspReportController.php
        │   └── PrivacyController.php
        ├── Employee/
        │   ├── AnniversaryController.php
        │   ├── Employee360Controller.php
        │   ├── EmployeeController.php
        │   ├── EmployeeTransferController.php
        │   ├── JicController.php
        │   └── ProbationController.php
        ├── Helpdesk/
        │   └── HelpdeskController.php
        ├── Identity/
        │   ├── MenuController.php
        │   ├── PermissionController.php
        │   ├── RoleController.php
        │   └── UserController.php
        ├── Leave/
        │   ├── LeaveController.php
        │   └── LeaveTypeController.php
        ├── Lifecycle/
        │   ├── ContractTemplateController.php
        │   ├── DisciplinaryController.php
        │   ├── EmployeeTaskController.php
        │   ├── ExitInterviewController.php
        │   ├── GeneratedContractController.php
        │   ├── OffboardingController.php
        │   ├── ResignationController.php
        │   ├── SeveranceController.php
        │   ├── TaskTemplateController.php
        │   └── TerminationController.php
        ├── Manpower/
        │   └── ManpowerController.php
        ├── Master/
        │   ├── BranchController.php
        │   ├── CompanyController.php
        │   ├── CostCenterController.php
        │   ├── DepartmentController.php
        │   ├── JobGradeController.php
        │   ├── JobTitleController.php
        │   ├── OrgStructureController.php
        │   ├── OrganizationRoleController.php
        │   ├── PositionController.php
        │   └── WorkLocationController.php
        ├── Notification/
        │   ├── DeviceController.php
        │   ├── NotificationController.php
        │   ├── NotificationPreferenceController.php
        │   └── WebPushController.php
        ├── Payroll/
        │   ├── AllowanceController.php
        │   ├── BonusController.php
        │   ├── LoanController.php
        │   ├── PayrollComponentController.php
        │   ├── PayrollController.php
        │   ├── PayrollPeriodController.php
        │   ├── PayslipController.php
        │   └── ReimbursementController.php
        ├── Performance/
        │   ├── IdpController.php
        │   └── PerformanceController.php
        ├── Recruitment/
        │   ├── CandidateOnlineTestController.php
        │   ├── CareerController.php
        │   ├── CareerPublicController.php
        │   ├── OfferController.php
        │   ├── OnlineTestCategoryController.php
        │   ├── OnlineTestController.php
        │   ├── RecruitmentController.php
        │   └── RecruitmentPipelineController.php
        ├── System/
        │   ├── CompanyRegulationController.php
        │   ├── EmailSettingsController.php
        │   ├── FileServeController.php
        │   ├── FileUploadController.php
        │   ├── HealthController.php
        │   ├── Horizon2Controller.php
        │   ├── Horizon3Controller.php
        │   ├── Horizon4Controller.php
        │   ├── ImportController.php
        │   ├── MobileVersionController.php
        │   ├── OpenApiController.php
        │   ├── ReportController.php
        │   └── SettingController.php
        ├── Training/
        │   └── TrainingController.php
        └── Webhook/
            ├── InboundWebhookController.php
            └── WebhookController.php
```

### 2.6 `backend/app/Database/` — Migration & Seeder

```
Database/
├── Migrations/              # 76 file migration (additive-only)
│   ├── 2024-01-01-000001_CreateCoreTables.php
│   ├── 2024-01-01-000002_CreateEmployeeTables.php
│   ├── 2024-01-01-000003_CreateAttendanceTables.php
│   ├── 2024-01-01-000004_CreateLeaveOvertimeTables.php
│   ├── 2024-01-01-000005_CreatePayrollTables.php
│   ├── 2024-01-01-000006_CreateNotificationAuditTables.php
│   ├── … (000007 s/d 000075)
│   └── 2024-01-01-000076_CreateMenus.php
└── Seeds/
    ├── ApprovalAndContractSeeder.php
    ├── CareerPortalSeeder.php
    ├── DemoDataSeeder.php
    ├── DisciplinaryCategoriesSeeder.php
    ├── IndonesianMastersSeeder.php
    ├── IndonesianStakeholderSeeder.php
    ├── InitialSeeder.php
    ├── OnlineTestDummySeeder.php
    ├── OnlineTestExpandedBankSeeder.php
    ├── RosterSeeder.php
    ├── SeedAll.php
    └── TaskTemplatesSeeder.php
```

Daftar lengkap migration (76 file) mencakup: core tables, employee, attendance, leave/overtime, payroll, notification/audit, Indonesian masters, approval, shift/roster, disciplinary, severance, task checklist, asset, business trip, recruitment, helpdesk, anti-cheat, training, performance, P0 hardening, notification preferences, resignation, career portal, manpower, organization structure, JIC, employee transfer, security hardening, BPJS JKP, privacy/consent, webhooks, bonus engine, candidate accounts, offboarding clearance, recruitment polish, OD loop, termination polish, MPP onboarding, cost center, job queue, device tokens/idempotency, push locale, dan menus.

### 2.7 `backend/app/Filters/` — HTTP Filters (Middleware)

```
Filters/
├── ApiCorsFilter.php             # CORS enforcement
├── ApiSecurityHeadersFilter.php  # Security headers (HSTS, X-Frame, dll)
├── AuditFilter.php               # Audit logging request
├── CandidateJwtAuthFilter.php    # Auth JWT untuk kandidat
├── IdempotencyFilter.php         # Idempotency key handling
├── JwtAuthFilter.php             # Auth JWT utama
├── PermissionFilter.php          # RBAC permission check
├── SameOriginFilter.php          # Same-origin enforcement
├── TenantFilter.php              # Multi-tenant scoping
└── ThrottleFilter.php            # Rate limiting per endpoint
```

### 2.8 `backend/app/Libraries/` — Service Layer (Business Logic)

```
Libraries/
├── Analytics/
│   ├── AiAssistantService.php
│   └── CompensationIntelligenceService.php
├── Approval/
│   ├── ApprovalConditionEvaluator.php
│   ├── ApprovalDelegationResolver.php
│   ├── ApprovalEngine.php
│   └── ReportingChainService.php
├── Asset/
│   └── AssetService.php
├── Attendance/
│   ├── AntiCheatService.php
│   ├── BusinessTripService.php
│   ├── OvertimeCalculator.php
│   ├── ShiftResolver.php
│   ├── ShiftTimeHelper.php
│   └── WfhRequestService.php
├── Auth/
│   ├── CandidateAuthService.php
│   ├── JwtService.php
│   ├── PasswordPolicy.php
│   └── TwoFactorBackupCodes.php
├── Compliance/
│   ├── AuditLogger.php
│   ├── DataPrivacyService.php
│   ├── PrivacyScope.php
│   └── TurnoverAnalyticsService.php
├── Employee/
│   ├── Employee360Service.php
│   ├── EmployeeTransferService.php
│   ├── JicService.php
│   └── ProbationService.php
├── FileStorage/
│   ├── FileStorageInterface.php
│   └── LocalDriver.php
├── Import/
│   ├── ImportRegistry.php
│   └── XlsxImportService.php
├── Leave/
│   ├── HalfDayLeaveCalculator.php
│   ├── LeaveBalanceService.php
│   ├── LongSickLeaveCalculator.php
│   └── TenureLeaveQuotaCalculator.php
├── Lifecycle/
│   ├── ComplianceReportService.php
│   ├── ContractRenderer.php
│   ├── DisciplinaryService.php
│   ├── ExitInterviewService.php
│   ├── OffboardingService.php
│   ├── PaklaringService.php
│   ├── SeveranceCalculator.php
│   └── TerminationService.php
├── Manpower/
│   ├── ManpowerService.php
│   └── WorkforceSimulationService.php
├── Master/
│   ├── CostCenterService.php
│   └── OrganizationStructureService.php
├── Notification/
│   ├── NotificationService.php
│   ├── PushLocalizer.php
│   ├── PushNotifier.php
│   ├── QuietHours.php
│   └── WhatsAppService.php
├── Payroll/
│   ├── AllowanceService.php
│   ├── BonusCalculator.php
│   ├── BonusService.php
│   ├── BpjsCalculator.php
│   ├── JkpPayslipIntegration.php
│   ├── PayrollExporter.php
│   ├── PayrollService.php
│   ├── PayrollSettings.php
│   ├── PayrollSimulationService.php
│   ├── Pph21TerCalculator.php
│   ├── Pph21YearEndCorrection.php
│   └── ThrMultiContractCalculator.php
├── Performance/
│   ├── CompetencyService.php
│   ├── IdpService.php
│   ├── PerformanceCascadingService.php
│   ├── PerformanceService.php
│   ├── PulseSurveyService.php
│   └── SuccessionService.php
├── Recruitment/
│   ├── CandidateScoringService.php
│   ├── CareerService.php
│   ├── OfferService.php
│   ├── OnboardingPlanService.php
│   ├── OnboardingService.php
│   ├── OnlineTestService.php
│   ├── RecruitmentService.php
│   ├── ReferralProgramService.php
│   └── TalentPoolService.php
├── System/
│   ├── CacheService.php
│   ├── CircuitBreaker.php
│   ├── CircuitOpenException.php
│   ├── EmailQueueBackoff.php
│   ├── EmailTemplateRenderer.php
│   ├── Encryption.php
│   ├── ExceptionLogger.php
│   ├── FieldsHelper.php
│   ├── FileSignatureValidator.php
│   ├── IndonesianValidator.php
│   ├── JobQueue.php
│   ├── LookupCache.php
│   ├── OcrService.php
│   ├── Telemetry.php
│   └── TenantContext.php
├── Training/
│   └── TrainingService.php
└── Webhook/
    ├── InboundWebhookHandler.php
    ├── WebhookDispatcher.php
    └── WebhookSigner.php
```

### 2.9 `backend/app/Models/` — Data Models

```
Models/
├── BaseModel.php
├── Attendance/
│   └── OvertimeRequestModel.php
├── Employee/
│   └── EmployeeModel.php
├── Identity/
│   ├── DeviceTokenModel.php
│   ├── MenuModel.php
│   └── UserModel.php
├── Leave/
│   └── LeaveRequestModel.php
├── Master/
│   ├── BranchModel.php
│   ├── CompanyModel.php
│   ├── DepartmentModel.php
│   ├── JobGradeModel.php
│   └── PositionModel.php
├── Payroll/
│   └── PayslipModel.php
└── System/
    └── IdempotencyKeyModel.php
```

### 2.10 `backend/app/` — Modul Lainnya

```
Notifications/
├── NotificationChannel.php
└── Channels/
    ├── EmailChannel.php
    ├── InAppChannel.php
    ├── WebPushChannel.php
    └── WhatsAppChannel.php

Traits/
└── DispatchesWebhooks.php

Validation/
└── IndonesianRules.php          # Aturan validasi NIK, NPWP, dll

Views/
├── payslip_pdf.php              # Template PDF slip gaji
└── errors/
```

### 2.11 `backend/` — Folder Pendukung

```
env-templates/
├── README.md
├── local.env.example
├── production.env.example
└── staging.env.example

public/
├── .htaccess
└── index.php                    # Front controller

scripts/                         # 50+ script maintenance & test flow
├── _reset-mysql.sql
├── _run-migrate-mysql.php
├── audit_permissions.php
├── migrate-sqlite-to-mysql.php
├── migrate-to-mysql.cmd
├── refactor_modules*.php        # Script refactor modular
├── seed-kaldu-richeese.php
├── sync-leave-types.php
├── test-*-flow.php              # ±35 script test flow per fitur
└── verify-*.php                 # Script verifikasi data

tests/
└── Unit/                        # 60+ unit test
    ├── AuditChainHashTest.php
    ├── BonusCalculatorTest.php
    ├── BpjsCalculatorTest.php
    ├── LeaveBalanceServiceTest.php
    ├── OvertimeCalculatorTest.php
    ├── Pph21*Test.php
    ├── SeveranceCalculatorTest.php
    ├── … (test lainnya)
    └── __fixtures__/
        └── bonus-history/       # Fixture JSON untuk test

writable/                        # Runtime (cache, logs, session, uploads)
└── .gitignore
```

---

## 3. `frontend/` — Vue 3 + Vite SPA

Frontend adalah Single Page Application berbasis Vue 3 dengan 90+ view, PWA-ready.

### 3.1 Root Frontend

```
frontend/
├── .dockerignore
├── .env.example
├── .eslintrc.cjs
├── .husky/
│   └── pre-commit            # Git hook lint sebelum commit
├── .prettierignore
├── .prettierrc.json
├── Dockerfile               # Production (multi-stage + nginx)
├── Dockerfile.dev
├── index.html
├── nginx.conf               # SPA fallback + gzip + cache headers
├── package.json
├── package-lock.json
├── postcss.config.js
├── tailwind.config.js
├── vite.config.js
├── vitest.config.js
├── env-templates/
│   ├── local.env.example
│   ├── production.env.example
│   └── staging.env.example
├── public/                  # Aset statis + PWA
└── src/                     # Source code
```

### 3.2 `frontend/public/` — Aset Statis & PWA

```
public/
├── icons/
│   └── README.md
├── manifest.webmanifest     # PWA manifest
└── sw.js                    # Service worker
```

### 3.3 `frontend/src/` — Source Code

```
src/
├── App.vue                  # Root component
├── main.js                  # Entry point
├── api/
│   ├── careerPublic.js
│   └── client.js            # Axios client + interceptor
├── assets/
│   └── main.css             # Global styles (Tailwind)
├── components/              # Komponen reusable
│   ├── AttendanceHeatmap.vue
│   ├── ConfirmDialog.vue
│   ├── CostCenterPicker.vue
│   ├── EmployeePicker.vue
│   ├── EmployeeSearchSelect.vue
│   ├── ExcelImporter.vue
│   ├── FileUploader.vue
│   ├── LocationPicker.vue
│   ├── OcrReceiptExtract.vue
│   ├── PayrollPeriodModal.vue
│   ├── PwaBanner.vue
│   ├── SearchableSelect.vue
│   ├── SimpleMaster.vue
│   └── menu/
│       ├── MenuRow.vue
│       ├── SidebarMenu.vue
│       └── iconMap.js
├── composables/             # Composition API hooks
│   ├── __tests__/
│   │   ├── useConfirm.spec.js
│   │   ├── useDarkMode.spec.js
│   │   └── usePwa.spec.js
│   ├── bonusTemplates.js
│   ├── useAvatar.js
│   ├── useConfirm.js
│   ├── useDarkMode.js
│   ├── usePwa.js
│   └── useResource.js
├── layouts/
│   ├── AppLayout.vue        # Layout aplikasi utama
│   └── CareerPublicLayout.vue
├── locales/                 # i18n
│   ├── en.js
│   ├── id.js
│   └── index.js
├── router/
│   └── index.js             # Vue Router definitions
├── stores/                  # Pinia stores
│   ├── auth.js
│   ├── candidateAuth.js
│   └── menu.js
└── views/                   # Halaman (90+ view, lihat di bawah)
```

### 3.4 `frontend/src/views/` — Halaman per Domain

```
views/
├── Dashboard.vue
├── Login.vue
├── Profile.vue
├── ChangePassword.vue
├── ForgotPassword.vue
├── ResetPassword.vue
├── Notifications.vue
├── Reports.vue
├── NotFound.vue
├── Admin/                   # AuditLogs, Companies, EmailSettings, Menus,
│                           #   Roles, Settings, Users, WhatsAppSettings
├── Approvals/               # Inbox, MyDelegations
├── Attendance/             # Anomalies, AttendanceList, ChangeRequest*,
│                           #   Clock, MyAttendance, QuickLeaveModal, WfhRequests
├── Career/                  # Candidate* (Dashboard, Login, Offers, Profile,
│                           #   Register, TakeTest, TestResult) + Career* (Apply,
│                           #   Home, JobDetail, Settings, Success, Track)
├── Employees/              # ContractSign, Employee360, EmployeeDetail,
│                           #   EmployeeForm, EmployeeList, EmployeeModal,
│                           #   GeneratedContracts
├── Finance/                 # LoanList, ReimbursementList
├── HR/                      # AiCopilot, Assets, CompensationIntel,
│                           #   CompetencyLibrary, ComplianceReports, Disciplinary,
│                           #   EmployeeTransfers, ExitInterviews, IdpDashboard,
│                           #   JobInformationChange, MyResignation, MyTransfers,
│                           #   OnboardingBoard, ProbationDashboard, PulseSurvey,
│                           #   ReportingCard, ResignationReview, Severance,
│                           #   SuccessionPlanning, TalentMarketplace,
│                           #   TerminationBoard, TurnoverAnalytics, WorkforceScenarios
├── Helpdesk/                # TicketDetail, TicketList
├── Leaves/                  # LeaveBalance, LeaveForm, LeaveList, TeamCalendar
├── Master/                  # Allowances, ApprovalChains, Branches,
│                           #   ContractTemplates, CostCenter*, Department*,
│                           #   JobGrades, JobTitles, LeaveTypes,
│                           #   OnlineTestCategories, OrgStructure,
│                           #   OrganizationRoles, PayrollComponents, Positions,
│                           #   RecruitmentPipelines, Regulations, Shift*,
│                           #   TaskTemplates, WorkLocations
├── Overtimes/               # OvertimeForm, OvertimeList, OvertimePlan
├── Payroll/                 # MyBonuses, MyPayslips, PayrollSimulation,
│                           #   Payslip*, Period*, ThrPlanning, Bonus/*
├── Performance/             # CycleDetail, GoalsTree, MyPerformance,
│                           #   PeerReview, PerformanceList, ReviewDetail
├── Recruitment/             # CandidateScoring, FunnelMetrics, JobList,
│                           #   MppList, PipelineBoard, ReferralProgram,
│                           #   OnlineTests/*
├── Roster/                  # RosterMatrix
├── Training/                # CertificateList, TrainingDetail, TrainingList
└── Travel/                  # TripList
```

---

## 4. `mobile/` — React Native App

Aplikasi mobile berbasis React Native (TypeScript) dengan native Android & iOS.

### 4.1 Root Mobile

```
mobile/
├── .env.example
├── .eslintrc.js
├── .gitignore
├── .prettierrc.js
├── App.tsx                  # Root component
├── README.md
├── TROUBLESHOOTING.md
├── app.json
├── babel.config.js
├── index.js                 # Entry point
├── jest.setup.js
├── metro.config.js
├── package.json
├── package-lock.json
├── react-native.config.js
├── rn-config-output.txt
├── tsconfig.json
├── native-config/
│   ├── android-manifest-additions.xml
│   └── info-plist-additions.plist
├── scripts/
│   ├── build-android.cmd
│   └── init-native.sh
├── android/                 # Native Android project
├── ios/                     # Native iOS project
└── src/                     # Source TypeScript
```

### 4.2 `mobile/src/` — Source TypeScript

```
src/
├── MainTabs.tsx             # Tab navigation
├── MoreMenu.tsx
├── config.ts
├── api/
│   ├── __tests__/
│   │   └── outbox.test.ts
│   ├── client.ts            # API client
│   └── outbox.ts            # Offline outbox queue
├── components/
│   ├── DateInput.tsx
│   ├── ErrorBoundary.tsx
│   ├── ForceUpdateGate.tsx
│   └── IdleLockGate.tsx
├── features/                # Fitur per domain (screen + api)
│   ├── approvals/           # ApprovalsScreen + api
│   ├── attendance/          # AttendanceScreen + api + geo
│   ├── auth/                # AuthStack, Login, ForgotPassword,
│   │                       #   TwoFactor, api, biometric, forgot
│   ├── business-trip/       # BusinessTripScreen + api
│   ├── devices/             # register.ts
│   ├── helpdesk/            # HelpdeskScreen + api
│   ├── leave/               # LeaveCreate/LeaveScreen + api + index
│   ├── notifications/       # Notifications*, api, preferencesApi, push
│   ├── overtime/            # OvertimeScreen + api
│   ├── payslip/             # PayslipScreen + api
│   ├── performance/         # PerformanceScreen + api
│   ├── profile/             # ProfileScreen
│   ├── reimbursement/       # ReimbursementScreen + api
│   ├── settings/            # ChangePassword, Preferences, Settings,
│   │                       #   TwoFactorManage, api
│   ├── tasks/               # TasksScreen + api
│   └── training/            # TrainingScreen + api
├── i18n/
│   ├── __tests__/
│   │   └── i18n.test.ts
│   └── index.ts
├── lib/
│   ├── __tests__/
│   │   ├── idleLock.test.ts
│   │   └── versionCheck.test.ts
│   ├── crash.ts
│   ├── idleLock.ts
│   └── versionCheck.ts
├── store/
│   ├── auth.ts
│   ├── mmkv.ts              # MMKV storage
│   └── secureStorage.ts
└── theme/
    ├── __tests__/
    │   └── themeProvider.test.ts
    ├── ThemeProvider.tsx
    └── index.ts
```

### 4.3 `mobile/android/` — Native Android

```
android/
├── build.gradle
├── gradle.properties
├── gradlew / gradlew.bat
├── settings.gradle
├── gradle/wrapper/
│   ├── gradle-wrapper.jar
│   └── gradle-wrapper.properties
└── app/
    ├── build.gradle
    ├── debug.keystore
    ├── release.keystore
    ├── proguard-rules.pro
    ├── .cxx/                # Build artifact native (generated, JANGAN diedit)
    └── src/
        ├── debug/AndroidManifest.xml
        └── main/
            ├── AndroidManifest.xml
            ├── java/com/hrismobile/
            │   ├── MainActivity.kt
            │   └── MainApplication.kt
            └── res/         # drawable, mipmap (ic_launcher), values
```

> Catatan: folder `android/app/.cxx/` berisi ratusan file build artifact (CMake, ninja, codegen C++) yang di-generate otomatis saat build native. File-file ini tidak perlu diedit manual.

### 4.4 `mobile/ios/` — Native iOS

```
ios/
├── .xcode.env
├── Podfile
├── HrisMobile.xcodeproj/
│   ├── project.pbxproj
│   └── xcshareddata/xcschemes/HrisMobile.xcscheme
├── HrisMobile/
│   ├── AppDelegate.h
│   ├── AppDelegate.mm
│   ├── Info.plist
│   ├── LaunchScreen.storyboard
│   ├── PrivacyInfo.xcprivacy
│   ├── main.m
│   └── Images.xcassets/
│       ├── AppIcon.appiconset/Contents.json
│       └── Contents.json
└── HrisMobileTests/
    ├── HrisMobileTests.m
    └── Info.plist
```

---

## 5. `docs/` — Dokumentasi

```
docs/
├── openapi.yaml             # OpenAPI 3.0 spec (Swagger-compatible)
├── adr/                     # Architecture Decision Records
│   ├── README.md
│   ├── 0001-codeigniter-vs-laravel.md
│   ├── 0002-jwt-vs-session-auth.md
│   ├── 0003-sqlite-dev-mysql-prod.md
│   └── 0004-additive-only-migrations.md
│
├── ── Setup & Operasional ──
├── DEPLOYMENT.md
├── DOCKER.md
├── ENVIRONMENTS.md
├── MONITORING.md
├── TROUBLESHOOTING.md
├── CRON_JOBS.md
├── MYSQL_MIGRATION.md
├── DEMO_LAN_HP.md
├── PWA.md
│
├── ── Compliance & Audit ──
├── AUDIT.md
├── DATA_PRIVACY.md
├── LIFECYCLE_AUDIT_2026.md
├── INDONESIAN_STAKEHOLDER_SEED.md
│
├── ── Dokumentasi Fitur ──
├── ALLOWANCE_AND_UX.md
├── ANNIVERSARY.md
├── ANTICHEAT.md
├── APPROVAL_AND_CONTRACTS.md
├── APPROVAL_DELEGATION.md
├── ASSET_INVENTORY.md
├── ATTENDANCE_FEATURE.md
├── BONUS_ENGINE.md
├── BUSINESS_TRIP.md
├── CAREER_PORTAL.md
├── COST_CENTER.md
├── DISCIPLINARY.md
├── EMPLOYEE_TRANSFER.md
├── HELPDESK.md
├── HRIS_FEATURES_AND_BUSINESS_FLOW.md
├── JIC.md
├── JOB_TITLES.md
├── LEAVE_BALANCE.md
├── MPP.md
├── ONBOARDING.md
├── ORG_STRUCTURE.md
├── OVERTIME_LEAVE_COMP.md
├── PAYROLL_OUTPUT.md
├── PERFORMANCE.md
├── RECRUITMENT.md
├── RECRUITMENT_PIPELINE.md
├── RESIGNATION.md
├── ROLES.md
├── SEVERANCE.md
├── SHIFT_AND_ROSTER.md
├── TRAINING.md
├── WEBHOOKS.md
├── XLSX_IMPORT_AUDIT.md
│
├── ── Roadmap & Sprint ──
├── ROADMAP.md
├── IMPROVEMENTS.md
├── IMPROVEMENT_CHECKLIST.md
├── QUANTARA_HRIS_ROADMAP_PENGEMBANGAN.md
├── QUANTARA_HRIS_ROADMAP_CHECKLIST_EKSEKUSI.md
├── TEST_PLAN_END_TO_END.md
├── HORIZON_2_CORE_DEEPENING.md
├── HORIZON_3_TALENT_MANAGEMENT.md
├── HORIZON_4_PLATFORM_EVOLUTION.md
├── SPRINT_1_COMPLIANCE.md
├── SPRINT_2_RECRUITMENT_POLISH.md
├── SPRINT_25_CANDIDATE_OFFER_SELF_SERVICE.md
├── SPRINT_3_OD_LOOP.md
├── SPRINT_4_TERMINATION_POLISH.md
├── SPRINT_5_LIFECYCLE_AUDIT.md
└── SPRINT_5_ONBOARDING_ACCEL.md
```

---

## 6. `scripts/` — Setup & Environment

```
scripts/
├── setup-dev.ps1            # Onboarding Windows (PowerShell)
├── setup-dev.sh             # Onboarding Linux/macOS/WSL
├── switch-env.cmd           # Switch environment (Windows cmd)
├── switch-env.ps1           # Switch environment (PowerShell)
└── switch-env.sh            # Switch environment (bash)
```

---

## 7. File Root Proyek

```
Quantara-HRIS/
├── docker-compose.yml       # Dev stack: mysql + redis + mailhog + backend + frontend
├── README.md                # Ringkasan proyek, tech stack, quick start
├── SETUP.md                 # Panduan setup development detail
└── .gitignore
```

---

## Catatan Arsitektur

- **Pola berlapis (layered)**: `Controllers` (HTTP) → `Filters` (middleware) → `Libraries` (business logic/service) → `Models` (data access). Controller tipis, logika bisnis ada di Libraries.
- **Multi-tenant**: scoping otomatis lewat `TenantFilter` + `PrivacyScope` di seluruh endpoint.
- **Migration additive-only**: lihat ADR `0004-additive-only-migrations.md`. Migration tidak pernah destruktif.
- **API versioned**: semua endpoint di bawah `Api/V1/` untuk kemudahan evolusi.
- **Cross-platform clients**: satu backend melayani SPA web (Vue 3) dan aplikasi mobile (React Native) lewat REST API yang sama.
- **Build artifact Android**: folder `mobile/android/app/.cxx/` di-generate otomatis dan dapat diabaikan saat membaca struktur kode sumber.