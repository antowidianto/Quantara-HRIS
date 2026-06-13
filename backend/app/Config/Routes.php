<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes = service('routes');

// ─── CORS preflight catch-all ─────────────────────────────────────
// Match any OPTIONS request to /api/* and answer immediately.
// The ApiCorsFilter (global before) sets the actual CORS headers; this
// route just ensures routing succeeds so the filter can run.
$routes->options('api/(:any)', static function () {
    return service('response')->setStatusCode(204);
});

// API v1 routes
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function ($routes) {

    // ─── Public (no auth) ───────────────────────────────────────────
    // Sprint 3 — rate limit hardening: bucket khusus per endpoint
    // sensitif (lebih ketat dari `login`). Lihat ThrottleFilter::bucketLimits.
    $routes->group('auth', static function ($routes) {
        $routes->post('login', 'Auth\AuthController::login', ['filter' => 'throttle:login']);
        $routes->post('refresh', 'Auth\AuthController::refresh');
        $routes->post('forgot-password', 'Auth\AuthController::forgotPassword', ['filter' => 'throttle:forgot_password']);
        $routes->post('reset-password', 'Auth\AuthController::resetPassword', ['filter' => 'throttle:password_reset']);
        $routes->post('verify-2fa', 'Auth\AuthController::verifyTwoFactor', ['filter' => 'throttle:twofa']);

        // S1.3 SaaS — Self-Service Tenant Signup (publik, no JWT)
        // Rate limit: signup throttle:signup (3/hour/IP), verify tidak limited
        // karena token unique acts as authorization.
        $routes->post('signup',                'Auth\AuthSignupController::signup',
            ['filter' => 'throttle:signup']);
        $routes->get('verify-signup',          'Auth\AuthSignupController::verify');
        $routes->get('signup/(:num)/status',   'Auth\AuthSignupController::status/$1');
    });

    // Health check + observability (S2.1 Roadmap SaaS)
    $routes->get('health', 'System\HealthController::index');
    // /metrics — Prometheus scrape (publik tapi IP-allowlisted; super admin bypass)
    $routes->get('metrics', 'System\MetricsController::index');

    // ─── Mobile Prep / MOB-5 — OpenAPI spec (public) ────────────────
    // Spec sendiri tidak rahasia; endpoint actual tetap auth-protected.
    // Pakai dengan openapi-generator-cli untuk codegen client mobile.
    $routes->get('openapi.json', 'System\OpenApiController::spec');

    // ─── Mobile / M4 — version gate (public) ────────────────────────
    // Mobile app fetch saat boot. Returns min_supported, latest, dan
    // store URL per platform. Lihat docs/MOBILE_APP_PLAN.md & mobile
    // src/lib/versionCheck.ts untuk detail kontrak.
    $routes->get('mobile/version', 'System\MobileVersionController::index');

    // CSP violation report (browser POST sebagai application/csp-report) — public,
    // browser tidak forward auth header. Throttle:api di global filter sudah cover.
    $routes->post('csp-report', 'Compliance\CspReportController::report');

    // File serve via signed URL — public karena <img> tag tidak forward JWT.
    // Token HMAC + expiry pendek (lihat FileStorage::signedUrl) cover akses.
    $routes->get('files/serve', 'System\FileServeController::serve');

    // ─── Inbound Webhook receiver (PUBLIC, signature-verified) ──────
    // Sprint 14+. Sistem external (recruitment marketplace, accounting,
    // partner HRIS) kirim event ke HRIS via HTTP POST + HMAC signature.
    // Auth via X-HRIS-Signature + X-HRIS-Timestamp (lihat InboundWebhookController).
    // Bucket throttle terpisah supaya retry sender tidak kena throttle:api global.
    $routes->post('inbound/(:segment)', 'Webhook\InboundWebhookController::receive/$1', [
        'filter' => 'throttle:inbound_webhook',
    ]);

    // ─── S2.3 Roadmap SaaS — Public Data Export download (token-based) ───
    // Token short-lived 7 hari + signed di DB. Tidak butuh JWT karena
    // user akan klik link di email, browser tanpa session.
    $routes->get('data-exports/download', 'Backup\DataExportController::download',
        ['filter' => 'throttle:export']);

    // ─── S2.4 Roadmap SaaS — Email webhook receiver (PUBLIC) ───
    // Provider (SES/Postmark/Sendgrid) POST event bounce/complaint/delivery.
    // Signature verified per provider di EmailWebhookController.
    // Bucket throttle terpisah supaya retry sender tidak kena throttle:api.
    $routes->post('webhooks/email/(:segment)', 'Webhook\EmailWebhookController::receive/$1',
        ['filter' => 'throttle:inbound_webhook']);

    // ─── S3.1 Roadmap SaaS — Public branding lookup (no auth) ───
    // Login page panggil ini untuk dapat branding tenant via custom domain
    // sebelum user login. Fallback ke Quantara default kalau host tidak match.
    $routes->get('branding/by-host', 'WhiteLabel\BrandingController::byHost');

    // ─── Career Portal (PUBLIC, no auth) ───────────────────────────
    // Lihat docs/CAREER_PORTAL.md. Endpoint dipisah dari group ber-JWT
    // karena pelamar tidak login. Throttle:api global filter sudah cover
    // basic abuse, dan CareerService punya rate-limit per IP per jam.
    $routes->post('careers/upload',                          'Recruitment\CareerPublicController::upload');
    $routes->get('careers/track/(:segment)',                 'Recruitment\CareerPublicController::track/$1');

    // ─── Candidate Account (auth pelamar) ──────────────────────────
    // Lihat docs/ONLINE_TEST.md. Endpoint registrasi/login PUBLIC tapi
    // throttle khusus supaya tidak gampang di-brute-force / di-spam.
    $routes->post('careers/account/reset-password',          'Auth\CandidateAuthController::resetPassword',
        ['filter' => 'throttle:password_reset']);
    $routes->post('careers/account/refresh',                 'Auth\CandidateAuthController::refresh');
    $routes->post('careers/(:segment)/account/register',     'Auth\CandidateAuthController::register/$1',
        ['filter' => 'throttle:login']);
    $routes->post('careers/(:segment)/account/login',        'Auth\CandidateAuthController::login/$1',
        ['filter' => 'throttle:login']);
    $routes->post('careers/(:segment)/account/forgot-password', 'Auth\CandidateAuthController::forgotPassword/$1',
        ['filter' => 'throttle:forgot_password']);

    // Candidate-authenticated endpoints (filter candidatejwt)
    $routes->group('careers/account', ['filter' => 'candidatejwt'], static function ($routes) {
        $routes->post('logout',          'Auth\CandidateAuthController::logout');
        $routes->get('me',               'Auth\CandidateAuthController::me');
        $routes->post('change-password', 'Auth\CandidateAuthController::changePassword');

        // Sprint 23+ — Profil Saya: kandidat lihat & edit lamaran-nya
        // selama belum di stage final (HIRED/REJECTED/WITHDRAWN).
        $routes->get('applications/(:num)',  'Auth\CandidateAuthController::showApplication/$1');
        $routes->put('applications/(:num)',  'Auth\CandidateAuthController::updateApplication/$1');

        // Online tests (kandidat side)
        $routes->get('tests',                                'Recruitment\CandidateOnlineTestController::listAssignments');
        $routes->get('tests/(:num)',                         'Recruitment\CandidateOnlineTestController::showAssignment/$1');
        $routes->post('tests/(:num)/start',                  'Recruitment\CandidateOnlineTestController::startAttempt/$1');
        $routes->get('attempts/(:num)',                      'Recruitment\CandidateOnlineTestController::attemptState/$1');
        $routes->post('attempts/(:num)/answer',              'Recruitment\CandidateOnlineTestController::saveAnswer/$1');
        $routes->post('attempts/(:num)/submit',              'Recruitment\CandidateOnlineTestController::submitAttempt/$1');
        $routes->post('attempts/(:num)/event',               'Recruitment\CandidateOnlineTestController::logEvent/$1');
        $routes->get('attempts/(:num)/result',               'Recruitment\CandidateOnlineTestController::attemptResult/$1');

        // Sprint 25 / Option C — Offer self-service
        // Kandidat review + accept/reject offer-nya sendiri (mirror pola
        // online test). Audit pakai source CANDIDATE_PORTAL + IP/UA
        // metadata supaya ada trail forensic kalau ada dispute.
        $routes->get('offers',                               'Auth\CandidateAuthController::listOffers');
        $routes->get('offers/(:num)',                        'Auth\CandidateAuthController::showOffer/$1');
        $routes->post('offers/(:num)/respond',               'Auth\CandidateAuthController::respondToOffer/$1');
    });

    // Detail & apply pakai 2 segmen slug
    $routes->post('careers/(:segment)/jobs/(:segment)/apply', 'Recruitment\CareerPublicController::apply/$1/$2');
    $routes->get('careers/(:segment)/jobs/(:segment)',        'Recruitment\CareerPublicController::jobDetail/$1/$2');
    $routes->get('careers/(:segment)/jobs',                   'Recruitment\CareerPublicController::listJobs/$1');
    $routes->get('careers/(:segment)',                        'Recruitment\CareerPublicController::landing/$1');

    // ─── Horizon 2 / H2.5 — Referral slug resolver (PUBLIC) ────────
    // Pelamar klik link `/r/{slug}` → resolve ke job posting / career page.
    $routes->get('referrals/r/(:segment)',                    'System\Horizon2Controller::resolveReferralSlug/$1');

    // ─── SaaS / S1.1 — Pricing page publik ──────────────────────────
    // Marketing site tarik daftar plan + matrix fitur. No auth.
    $routes->get('plans',                                     'Billing\BillingController::plans');

    // ─── Authenticated routes ────────────────────────────────────────
    $routes->group('', ['filter' => 'jwt'], static function ($routes) {

        // Auth (authenticated)
        $routes->post('auth/logout', 'Auth\AuthController::logout');
        $routes->get('auth/me', 'Auth\AuthController::me');
        $routes->post('auth/change-password', 'Auth\AuthController::changePassword');
        $routes->post('auth/2fa/enable', 'Auth\AuthController::enableTwoFactor');
        $routes->post('auth/2fa/disable', 'Auth\AuthController::disableTwoFactor');
        // Sprint A / B5 — Active Sessions (device list + revoke per device)
        $routes->get('auth/sessions',                       'Auth\AuthController::listSessions');
        $routes->post('auth/sessions/revoke-others',        'Auth\AuthController::revokeOtherSessions');
        $routes->delete('auth/sessions/(:segment)',         'Auth\AuthController::revokeSession/$1');

        // ─── Mobile Prep / MOB-1 — Device push token registry ──────
        // Mobile app pair FCM/APNs token ke user untuk push notif fanout.
        // Lihat docs/MOBILE_APP_PLAN.md.
        $routes->post('devices/register',          'Notification\DeviceController::register');
        $routes->post('devices/unregister',        'Notification\DeviceController::unregisterByToken');
        $routes->get('devices',                    'Notification\DeviceController::index');
        $routes->delete('devices/(:num)',          'Notification\DeviceController::unregister/$1');
        // Foto profil — self-service upload/hapus, JPEG/PNG/WebP ≤ 2 MB.
        // Sprint A / B3 — Origin guard untuk multipart upload.
        $routes->post('auth/me/avatar',   'Auth\AuthController::uploadAvatar', ['filter' => 'sameorigin']);
        $routes->delete('auth/me/avatar', 'Auth\AuthController::deleteAvatar');

        // ─── SaaS / Billing (S1.1 + S1.2 + S1.4) ───────────────────
        // Subscription, feature flags, usage meters, plan management
        $routes->get('features/me',                     'Billing\BillingController::myFeatures');
        $routes->get('billing/subscription',            'Billing\BillingController::mySubscription');
        // S1.4 — Usage meter dashboard (semua tenant boleh lihat usage sendiri)
        $routes->get('billing/usage',                   'Billing\BillingController::myUsage');
        // Super admin only — fix drift counter dari source table
        $routes->post('billing/usage/recompute',        'Billing\BillingController::recomputeUsage');
        // Operasi mengubah subscription butuh permission billing.manage
        // (super_company_admin punya by default)
        $routes->post('billing/upgrade',
            'Billing\BillingController::upgrade',     ['filter' => 'permission:billing.manage']);
        $routes->post('billing/downgrade',
            'Billing\BillingController::downgrade',   ['filter' => 'permission:billing.manage']);
        $routes->post('billing/cancel',
            'Billing\BillingController::cancel',      ['filter' => 'permission:billing.manage']);
        $routes->post('billing/reactivate',
            'Billing\BillingController::reactivate',  ['filter' => 'permission:billing.manage']);
        // Super admin only
        $routes->post('admin/tenants/(:num)/extend-trial',
            'Billing\BillingController::extendTrial/$1');
        $routes->post('admin/tenants/(:num)/feature-override',
            'Billing\BillingController::featureOverride/$1');

        // ─── SaaS / S2.1 — Deep health (super admin) ───────────────
        $routes->get('health/deep', 'System\HealthController::deep');

        // ─── SaaS / S3.5 — Admin Console (super admin) ─────────────
        // Console internal Quantara untuk manage tenant + support
        $routes->get('admin/tenants',                          'Admin\AdminTenantController::index');
        $routes->get('admin/tenants/(:num)',                   'Admin\AdminTenantController::show/$1');
        $routes->post('admin/tenants/(:num)/impersonate',      'Admin\AdminTenantController::impersonate/$1');
        $routes->post('admin/tenants/(:num)/suspend',          'Admin\AdminTenantController::suspend/$1');
        $routes->post('admin/tenants/(:num)/unsuspend',        'Admin\AdminTenantController::unsuspend/$1');
        $routes->get('admin/metrics/saas',                     'Admin\AdminTenantController::saasMetrics');

        // ─── SaaS / S2.3 — Tenant Data Export (UU PDP / GDPR) ──────
        // Self-service tenant: HR admin request export semua data company.
        // Async generation; download link signed token (lihat public route
        // /data-exports/download di luar JWT group).
        $routes->post('companies/me/data-exports',             'Backup\DataExportController::create',
            ['filter' => 'throttle:export']);
        $routes->get('companies/me/data-exports',              'Backup\DataExportController::index');
        $routes->get('companies/me/data-exports/(:num)',       'Backup\DataExportController::show/$1');

        // ─── SaaS / S3.1 — White-Label Branding (ENTERPRISE plan) ──
        // GET selalu allowed. PUT + verify-domain butuh feature
        // `white_label.enabled`. Audit log severity HIGH (publik surface).
        $routes->get('companies/me/branding',                  'WhiteLabel\BrandingController::show');
        $routes->put('companies/me/branding',                  'WhiteLabel\BrandingController::update');
        $routes->post('companies/me/branding/verify-domain',   'WhiteLabel\BrandingController::verifyDomain');

        // ─── SaaS / S3.4 — Customer Success Tooling ────────────────
        // Onboarding progress + announcements + customer health score
        $routes->get('onboarding/progress',                    'CustomerSuccess\CustomerSuccessController::progress');
        $routes->post('onboarding/(:segment)/complete',        'CustomerSuccess\CustomerSuccessController::completeStep/$1');
        $routes->get('announcements/unread',                   'CustomerSuccess\CustomerSuccessController::unreadAnnouncements');
        $routes->post('announcements/(:num)/read',             'CustomerSuccess\CustomerSuccessController::markRead/$1');
        $routes->get('companies/me/health-score',              'CustomerSuccess\CustomerSuccessController::myHealth');
        // Super admin Quantara only
        $routes->get('admin/cs/health-scores',                 'CustomerSuccess\CustomerSuccessController::adminHealthScores');
        $routes->post('admin/cs/recompute/(:num)',             'CustomerSuccess\CustomerSuccessController::adminRecompute/$1');

        // ─── UU PDP (Sprint 4) — Data Subject Rights ───────────────
        // Self-service untuk hak akses, hak penghapusan, dan record consent.
        // Lihat docs/IMPROVEMENT_CHECKLIST.md section 3 + DataPrivacyService.
        $routes->get('me/privacy/policy-version',  'Compliance\PrivacyController::policyVersion');
        $routes->get('me/privacy/consents',        'Compliance\PrivacyController::consents');
        $routes->post('me/privacy/consents',       'Compliance\PrivacyController::recordConsent');
        // Throttle export berat — sama bucket dengan endpoint export lain
        $routes->get('me/data-export',             'Compliance\PrivacyController::dataExport',     ['filter' => 'throttle:export']);
        $routes->post('me/data-deletion-request',  'Compliance\PrivacyController::requestDeletion');

        // HR review penghapusan data (perlu permission `privacy.review`)
        $routes->get('admin/privacy/deletion-requests',                 'Compliance\PrivacyController::listRequests');
        $routes->post('admin/privacy/deletion-requests/(:num)/review', 'Compliance\PrivacyController::reviewRequest/$1');
        $routes->post('admin/privacy/deletion-requests/(:num)/process','Compliance\PrivacyController::processRequest/$1');

        // Master: Companies / Business Units
        $routes->resource('companies', ['controller' => 'Master\CompanyController']);
        $routes->resource('branches', ['controller' => 'Master\BranchController']);
        $routes->resource('departments', ['controller' => 'Master\DepartmentController']);
        $routes->resource('positions', ['controller' => 'Master\PositionController']);
        $routes->resource('job-grades', ['controller' => 'Master\JobGradeController']);

        // Master Job Titles — standar nama jabatan untuk reporting & WLKP.
        // Migration #064 menambahkan tabel ini + auto-backfill dari positions.
        // Endpoint khusus didefinisikan SEBELUM resource agar /picker dan
        // /(:num)/usage tidak nyangkut sebagai resource id.
        $routes->get('job-titles/picker',          'Master\JobTitleController::picker');
        $routes->get('job-titles/(:num)/usage',    'Master\JobTitleController::usage/$1');
        $routes->resource('job-titles', ['controller' => 'Master\JobTitleController']);

        // ─── Cost Centers (Sprint 6) ───────────────────────────────
        // Endpoint khusus didefinisikan SEBELUM /(:num) supaya path
        // /tree, /picker, /backfill-employees tidak nyangkut sebagai id.
        $routes->get('cost-centers/tree',                  'Master\CostCenterController::tree');
        $routes->get('cost-centers/picker',                'Master\CostCenterController::picker');
        $routes->post('cost-centers/backfill-employees',   'Master\CostCenterController::backfillEmployees');
        $routes->get('cost-centers',                       'Master\CostCenterController::index');
        $routes->post('cost-centers',                      'Master\CostCenterController::create');
        $routes->get('cost-centers/(:num)',                'Master\CostCenterController::show/$1');
        $routes->put('cost-centers/(:num)',                'Master\CostCenterController::update/$1');
        $routes->delete('cost-centers/(:num)',             'Master\CostCenterController::delete/$1');
        $routes->get('cost-centers/(:num)/budgets',        'Master\CostCenterController::listBudgets/$1');
        $routes->post('cost-centers/(:num)/budgets',       'Master\CostCenterController::setBudgets/$1');
        $routes->delete('cost-center-budgets/(:num)',      'Master\CostCenterController::deleteBudget/$1');
        $routes->get('cost-centers/(:num)/budget-vs-actual','Master\CostCenterController::budgetVsActual/$1');
        $routes->get('cost-centers/(:num)/actuals',        'Master\CostCenterController::actuals/$1');
        $routes->get('cost-centers/(:num)/committed',      'Master\CostCenterController::committed/$1');

        // Employees
        // Custom routes HARUS sebelum resource agar tidak tabrakan dengan resource show()
        $routes->get('employees/(:num)/contracts', 'Employee\EmployeeController::contracts/$1');
        $routes->post('employees/(:num)/contracts', 'Employee\EmployeeController::addContract/$1');
        $routes->get('employees/(:num)/documents', 'Employee\EmployeeController::documents/$1');
        $routes->post('employees/(:num)/documents', 'Employee\EmployeeController::uploadDocument/$1');
        // Pakai placeholder `(:num)` supaya `employees/(:any)` versi default
        // dari resource() (yang regex-nya `(.*)` — greedy, match juga slash)
        // tidak menelan sub-route seperti `/employees/1/bonus-history`,
        // `/employees/1/work-locations`, `/employees/1/360`, dll. yang
        // didaftarkan SETELAH resource. Tanpa ini, GET ke sub-route
        // tersebut salah ke `Employee\EmployeeController::show("1/bonus-history")`
        // dan mengembalikan data karyawan, bukan data sub-resource.
        $routes->resource('employees', [
            'controller' => 'Employee\EmployeeController',
            'placeholder' => '(:num)',
        ]);

        // Attendance
        $routes->get('attendance', 'Attendance\AttendanceController::index');
        // Sprint A / B3 — Origin guard untuk multipart selfie clock.
        $routes->post('attendance/clock-in', 'Attendance\AttendanceController::clockIn',
            ['filter' => 'sameorigin']);
        $routes->post('attendance/clock-out', 'Attendance\AttendanceController::clockOut',
            ['filter' => 'sameorigin']);
        $routes->get('attendance/my', 'Attendance\AttendanceController::my');
        $routes->get('attendance/calendar/(:num)', 'Attendance\AttendanceController::calendar/$1');
        
        // ADMS Machine Sync (from Node.js middleware)
        $routes->post('attendance/machine-sync', 'Attendance\MachineSyncController::sync');
        $routes->resource('physical-devices', ['controller' => 'Attendance\AttendanceDeviceController']);

        // ─── WFH Requests (Sprint 8 / H1.5) ─────────────────────────
        // Pengajuan WFH dengan flow PENDING → APPROVED/REJECTED yang
        // dipakai AttendanceController untuk skip geofence saat clock-in.
        $routes->get('wfh-requests',                       'Attendance\WfhRequestController::index');
        $routes->post('wfh-requests',                      'Attendance\WfhRequestController::create');
        $routes->get('wfh-requests/(:num)',                'Attendance\WfhRequestController::show/$1');
        $routes->post('wfh-requests/(:num)/approve',       'Attendance\WfhRequestController::approve/$1');
        $routes->post('wfh-requests/(:num)/reject',        'Attendance\WfhRequestController::reject/$1');
        $routes->post('wfh-requests/(:num)/cancel',        'Attendance\WfhRequestController::cancel/$1');

        // Attendance change requests (pengajuan perubahan + approval)
        // Catatan: definisikan SEBELUM route catch-all `attendance/(:num)`
        // supaya path /attendance/change-requests tidak nyangkut sebagai id.
        $routes->get('attendance/change-requests', 'Attendance\AttendanceChangeRequestController::index');
        $routes->post('attendance/change-requests', 'Attendance\AttendanceChangeRequestController::create');
        $routes->get('attendance/change-requests/(:num)', 'Attendance\AttendanceChangeRequestController::show/$1');
        $routes->post('attendance/change-requests/(:num)/approve', 'Attendance\AttendanceChangeRequestController::approve/$1');
        $routes->post('attendance/change-requests/(:num)/reject', 'Attendance\AttendanceChangeRequestController::reject/$1');
        $routes->post('attendance/change-requests/(:num)/cancel', 'Attendance\AttendanceChangeRequestController::cancel/$1');

        $routes->get('attendance/(:num)', 'Attendance\AttendanceController::show/$1');
        $routes->post('attendance/(:num)/correction', 'Attendance\AttendanceController::correction/$1');

        // Shifts & Roster
        $routes->resource('shifts', ['controller' => 'Attendance\ShiftController']);
        $routes->resource('shift-schedules', ['controller' => 'Attendance\ShiftScheduleController']);

        // Shift assignments — pemetaan shift ke scope
        $routes->get('shift-assignments',                'Attendance\ShiftAssignmentController::index');
        $routes->get('shift-assignments/preview',        'Attendance\ShiftAssignmentController::preview');
        $routes->get('shift-assignments/(:num)',         'Attendance\ShiftAssignmentController::show/$1');
        $routes->post('shift-assignments',               'Attendance\ShiftAssignmentController::create');
        $routes->put('shift-assignments/(:num)',         'Attendance\ShiftAssignmentController::update/$1');
        $routes->delete('shift-assignments/(:num)',      'Attendance\ShiftAssignmentController::delete/$1');

        // Shift patterns — pola siklus
        $routes->get('shift-patterns',                   'Attendance\ShiftPatternController::index');
        $routes->get('shift-patterns/(:num)',            'Attendance\ShiftPatternController::show/$1');
        $routes->post('shift-patterns',                  'Attendance\ShiftPatternController::create');
        $routes->put('shift-patterns/(:num)',            'Attendance\ShiftPatternController::update/$1');
        $routes->delete('shift-patterns/(:num)',         'Attendance\ShiftPatternController::delete/$1');
        $routes->get('shift-pattern-assignments',        'Attendance\ShiftPatternController::listAssignments');
        $routes->post('shift-pattern-assignments',       'Attendance\ShiftPatternController::createAssignment');
        $routes->delete('shift-pattern-assignments/(:num)', 'Attendance\ShiftPatternController::deleteAssignment/$1');

        // Roster — matrix view & generator
        $routes->get('roster',                           'Attendance\RosterController::index');
        $routes->post('roster/generate',                 'Attendance\RosterController::generate');
        $routes->post('roster/bulk-update',              'Attendance\RosterController::bulkUpdate');
        $routes->post('roster/clear',                    'Attendance\RosterController::clear');

        // Leaves
        // Endpoint khusus harus didefinisikan SEBELUM resource supaya
        // path /leave-types/for-me tidak nyangkut sebagai id resource.
        $routes->get('leave-types/for-me', 'Leave\LeaveController::eligibleTypes');
        $routes->resource('leave-types', ['controller' => 'Leave\LeaveTypeController']);
        // Sama: team-calendar harus di atas resource supaya tidak ke-trap
        // sebagai /leaves/(:num).
        $routes->get('leaves/team-calendar', 'Leave\LeaveController::teamCalendar');
        $routes->resource('leaves', ['controller' => 'Leave\LeaveController']);
        $routes->post('leaves/(:num)/approve', 'Leave\LeaveController::approve/$1');
        $routes->post('leaves/(:num)/reject', 'Leave\LeaveController::reject/$1');
        // Sprint 17+ — self-cancel oleh karyawan (atau HR atas nama)
        $routes->post('leaves/(:num)/cancel', 'Leave\LeaveController::cancel/$1');
        $routes->get('leave-balances/(:num)', 'Leave\LeaveController::balance/$1');

        // Overtime
        // H1.6 — pre-approval flow (plan + realize). Dideklarasikan di atas
        // resource() supaya path /overtimes/plan tidak ke-trap sebagai numeric id.
        $routes->post('overtimes/plan', 'Attendance\OvertimeController::plan');
        $routes->resource('overtimes', ['controller' => 'Attendance\OvertimeController']);
        $routes->post('overtimes/(:num)/approve', 'Attendance\OvertimeController::approve/$1');
        $routes->post('overtimes/(:num)/reject', 'Attendance\OvertimeController::reject/$1');
        $routes->post('overtimes/(:num)/realize', 'Attendance\OvertimeController::realize/$1');

        // Payroll
        $routes->resource('payroll-components', ['controller' => 'Payroll\PayrollComponentController']);
        $routes->resource('benefit-policies', ['controller' => 'Payroll\BenefitPolicyController']);

        // Payroll periods — endpoint khusus harus didefinisikan SEBELUM
        // resource() supaya path multi-segment seperti
        // /payroll-periods/4/export-summary tidak nyangkut sebagai
        // resource show() (yang akan return period biasa, bukan summary
        // export). Pola sama dengan leaves & goals di atas.
        $routes->post('payroll-periods/(:num)/calculate',     'Payroll\PayrollPeriodController::calculate/$1');
        $routes->post('payroll-periods/(:num)/finalize',      'Payroll\PayrollPeriodController::finalize/$1');
        $routes->post('payroll-periods/(:num)/disburse',      'Payroll\DisbursementController::disburse/$1');
        $routes->get('payroll-periods/(:num)/disburse-status', 'Payroll\DisbursementController::checkStatus/$1');
        // Pre-check sebelum calculate: deteksi pengajuan transaksi yang
        // memengaruhi payroll & masih PENDING approval. UI bisa show
        // warning supaya HR aware sebelum hitung.
        $routes->get('payroll-periods/(:num)/pending-approvals', 'Payroll\PayrollPeriodController::pendingApprovals/$1');
        $routes->get('payroll-periods/(:num)/export-summary', 'Payroll\PayrollPeriodController::exportSummary/$1');
        // Sprint 3 — throttle berat export ke 10/jam per IP+path
        $routes->get('payroll-periods/(:num)/bank-export',    'Payroll\PayrollPeriodController::bankExport/$1',  ['filter' => 'throttle:export']);
        $routes->get('payroll-periods/(:num)/pph21-export',   'Payroll\PayrollPeriodController::pph21Export/$1', ['filter' => 'throttle:export']);
        $routes->get('payroll-periods/(:num)/bpjs-export',    'Payroll\PayrollPeriodController::bpjsExport/$1',  ['filter' => 'throttle:export']);
        $routes->resource('payroll-periods', ['controller' => 'Payroll\PayrollPeriodController']);
        $routes->get('payslips', 'Payroll\PayslipController::index');
        $routes->get('payslips/my', 'Payroll\PayslipController::my');
        $routes->get('payslips/(:num)', 'Payroll\PayslipController::show/$1');
        $routes->get('payslips/(:num)/pdf', 'Payroll\PayslipController::pdf/$1');
        $routes->get('payslips/(:num)/bukti-potong', 'Payroll\PayslipController::buktiPotong/$1');
        $routes->post('payroll/thr', 'Payroll\PayrollController::generateThr');
        // Sprint 9 / H1.8 — THR Planning Dashboard
        $routes->get('thr/projection', 'Payroll\PayrollController::thrProjection');

        // Payroll (Reimbursements, dll)
        $routes->get('my-benefit-balances', 'Payroll\ReimbursementController::myBalances');
        $routes->resource('reimbursements', ['controller' => 'Payroll\ReimbursementController']);
        $routes->resource('loans', ['controller' => 'Payroll\LoanController']);

        // ─── Fase 4: Talent Management ──────────────────────────

        // 360-Degree Feedback Review
        $routes->get('review-360/cycles',                       'HR\Review360Controller::cycles');
        $routes->get('review-360/cycles/(:num)',                'HR\Review360Controller::showCycle/$1');
        $routes->post('review-360/cycles',                     'HR\Review360Controller::createCycle');
        $routes->put('review-360/cycles/(:num)',               'HR\Review360Controller::updateCycle/$1');
        $routes->post('review-360/nominations',                'HR\Review360Controller::addNomination');
        $routes->delete('review-360/nominations/(:num)',       'HR\Review360Controller::removeNomination/$1');
        $routes->post('review-360/nominations/(:num)/feedback','HR\Review360Controller::submitFeedback/$1');
        $routes->get('review-360/cycles/(:num)/aggregate',     'HR\Review360Controller::aggregateScores/$1');

        // Nine-Box Matrix (Talent Assessment)
        $routes->get('talent-assessments',                     'HR\TalentAssessmentController::index');
        $routes->post('talent-assessments',                    'HR\TalentAssessmentController::upsert');
        $routes->delete('talent-assessments/(:num)',           'HR\TalentAssessmentController::delete/$1');

        // Succession Planning
        $routes->get('succession/positions',                   'HR\SuccessionPlanController::positions');
        $routes->get('succession/positions/(:num)',            'HR\SuccessionPlanController::showPosition/$1');
        $routes->post('succession/positions',                  'HR\SuccessionPlanController::createPosition');
        $routes->put('succession/positions/(:num)',            'HR\SuccessionPlanController::updatePosition/$1');
        $routes->delete('succession/positions/(:num)',         'HR\SuccessionPlanController::deletePosition/$1');
        $routes->post('succession/candidates',                'HR\SuccessionPlanController::addCandidate');
        $routes->put('succession/candidates/(:num)',          'HR\SuccessionPlanController::updateCandidate/$1');
        $routes->delete('succession/candidates/(:num)',       'HR\SuccessionPlanController::removeCandidate/$1');

        // Notifications
        $routes->get('notifications', 'Notification\NotificationController::index');
        $routes->get('notifications/unread-count', 'Notification\NotificationController::unreadCount');
        $routes->post('notifications/(:num)/read', 'Notification\NotificationController::markRead/$1');
        $routes->post('notifications/read-all', 'Notification\NotificationController::markAllRead');

        // Sprint 7 — Web Push subscription endpoints
        $routes->get('notifications/push-public-key', 'Notification\WebPushController::publicKey');
        $routes->post('notifications/push-subscribe', 'Notification\WebPushController::subscribe');
        $routes->delete('notifications/push-subscribe', 'Notification\WebPushController::unsubscribe');

        // Sprint 11+ — Webhook outbound (admin-managed integrations).
        // Endpoint khusus didefinisikan SEBELUM /webhooks/(:num) supaya
        // path /event-types tidak nyangkut sebagai resource id.
        $routes->get('webhooks/event-types',                 'Webhook\WebhookController::eventTypes');
        $routes->get('webhooks',                             'Webhook\WebhookController::index');
        $routes->post('webhooks',                            'Webhook\WebhookController::create');
        $routes->get('webhooks/(:num)',                      'Webhook\WebhookController::show/$1');
        $routes->put('webhooks/(:num)',                      'Webhook\WebhookController::update/$1');
        $routes->delete('webhooks/(:num)',                   'Webhook\WebhookController::delete/$1');
        $routes->post('webhooks/(:num)/rotate-secret',       'Webhook\WebhookController::rotateSecret/$1');
        $routes->get('webhooks/(:num)/deliveries',           'Webhook\WebhookController::deliveries/$1');

        // Sprint 14+ — Inbound Webhook admin CRUD (counterpart untuk
        // outbound /webhooks). Receiver-nya public di atas (lihat
        // /api/v1/inbound/{slug}). Pattern routing sama: endpoint khusus
        // (events) di atas pattern /(:num).
        $routes->get('inbound-webhooks',                     'Webhook\InboundWebhookController::index');
        $routes->post('inbound-webhooks',                    'Webhook\InboundWebhookController::create');
        $routes->get('inbound-webhooks/(:num)',              'Webhook\InboundWebhookController::show/$1');
        $routes->put('inbound-webhooks/(:num)',              'Webhook\InboundWebhookController::update/$1');
        $routes->delete('inbound-webhooks/(:num)',           'Webhook\InboundWebhookController::delete/$1');
        $routes->post('inbound-webhooks/(:num)/rotate-secret','Webhook\InboundWebhookController::rotateSecret/$1');
        $routes->get('inbound-webhooks/(:num)/events',       'Webhook\InboundWebhookController::events/$1');

        // Notification preferences (per user, multi-channel)
        // Lihat docs/AUDIT.md item K. Quiet-hours endpoint ditambah di
        // sprint mobile M7 (DND window; lihat docs/SPRINT_MOBILE_M7_*).
        // Endpoint /quiet-hours didefinisikan duluan supaya tidak
        // ke-trap sebagai sub-resource dari index/update.
        $routes->get('notification-preferences/quiet-hours', 'Notification\NotificationPreferenceController::getQuietHours');
        $routes->put('notification-preferences/quiet-hours', 'Notification\NotificationPreferenceController::updateQuietHours');
        $routes->get('notification-preferences',  'Notification\NotificationPreferenceController::index');
        $routes->put('notification-preferences',  'Notification\NotificationPreferenceController::update');

        // Roles & Permissions (admin)
        $routes->resource('roles', ['controller' => 'Identity\RoleController']);
        $routes->resource('permissions', ['controller' => 'Identity\PermissionController']);
        $routes->resource('users', ['controller' => 'Identity\UserController']);

        // ─── Menu (sidebar dinamis, Migration #076) ────────────────
        // GET /menus/me dipanggil setiap layout AppLayout mount untuk
        // dapatkan tree menu yang sudah difilter sesuai permission user.
        // Endpoint admin (CRUD + reorder) butuh permission `menu.manage`
        // yang di-cek di MenuController via tenant role check.
        // Definisikan endpoint khusus SEBELUM /menus/(:num) supaya path
        // /me dan /reorder tidak nyangkut sebagai numeric id.
        $routes->get('menus/me',           'Identity\MenuController::me');
        $routes->post('menus/reorder',     'Identity\MenuController::reorder');
        $routes->get('menus',              'Identity\MenuController::index');
        $routes->post('menus',             'Identity\MenuController::create');
        $routes->get('menus/(:num)',       'Identity\MenuController::show/$1');
        $routes->put('menus/(:num)',       'Identity\MenuController::update/$1');
        $routes->delete('menus/(:num)',    'Identity\MenuController::delete/$1');

        // Reports
        $routes->get('reports/headcount', 'System\ReportController::headcount');
        $routes->get('reports/attendance-summary', 'System\ReportController::attendanceSummary');
        $routes->get('reports/attendance-heatmap', 'System\ReportController::attendanceHeatmap');
        $routes->get('reports/payroll-summary', 'System\ReportController::payrollSummary');
        $routes->get('reports/leave-summary', 'System\ReportController::leaveSummary');

        // Audit log (admin only)
        $routes->get('audit-logs', 'Compliance\AuditLogController::index');
        $routes->get('audit-logs/stats', 'Compliance\AuditLogController::stats');
        // Sprint 3 — throttle berat export
        $routes->get('audit-logs/export', 'Compliance\AuditLogController::export', ['filter' => 'throttle:export']);
        $routes->get('audit-logs/(:num)', 'Compliance\AuditLogController::show/$1');

        // Settings (per company)
        $routes->get('settings', 'System\SettingController::index');
        $routes->put('settings', 'System\SettingController::update');

        // Email / SMTP settings (per company) — disimpan di tabel `settings`
        // dengan key prefix `email.*`. Password di-encrypt at-rest.
        $routes->get('email-settings',       'System\EmailSettingsController::index');
        $routes->put('email-settings',       'System\EmailSettingsController::update');
        $routes->post('email-settings/test', 'System\EmailSettingsController::test');

        // Organization Development — Easy Setting (drag-and-drop org structure)
        // Lihat docs/ORG_STRUCTURE.md untuk pola pemakaian.
        $routes->get('org-structure',                       'Master\OrgStructureController::index');
        $routes->get('org-structure/department/(:num)',     'Master\OrgStructureController::departmentDetail/$1');
        $routes->post('org-structure/move-department',      'Master\OrgStructureController::moveDepartment');
        $routes->post('org-structure/move-position',        'Master\OrgStructureController::movePosition');
        $routes->post('org-structure/move-mpp',             'Master\OrgStructureController::moveMpp');
        $routes->post('org-structure/move-employee',        'Master\OrgStructureController::moveEmployee');
        $routes->post('org-structure/set-manager',          'Master\OrgStructureController::setManager');
        $routes->post('org-structure/bulk-reorder',         'Master\OrgStructureController::bulkReorder');
        $routes->post('org-structure/recompute-reports',    'Master\OrgStructureController::recomputeReports');

        // Organization Roles — master jabatan kunci untuk lookup approver.
        // Endpoint khusus didefinisikan SEBELUM CRUD agar /picker & /organogram
        // tidak ke-trap sebagai resource id.
        $routes->get('organization-roles/picker',           'Master\OrganizationRoleController::picker');
        $routes->get('organization-roles/organogram',       'Master\OrganizationRoleController::organogram');
        $routes->get('organization-roles',                  'Master\OrganizationRoleController::index');
        $routes->post('organization-roles',                 'Master\OrganizationRoleController::create');
        $routes->get('organization-roles/(:num)',           'Master\OrganizationRoleController::show/$1');
        $routes->put('organization-roles/(:num)',           'Master\OrganizationRoleController::update/$1');
        $routes->delete('organization-roles/(:num)',        'Master\OrganizationRoleController::delete/$1');
        $routes->post('organization-roles/(:num)/assign',   'Master\OrganizationRoleController::assign/$1');

        // Allowance mapping (per cabang & grade)
        $routes->get('allowance-types',          'Payroll\AllowanceController::listTypes');
        $routes->post('allowance-types',         'Payroll\AllowanceController::createType');
        $routes->put('allowance-types/(:num)',   'Payroll\AllowanceController::updateType/$1');
        $routes->delete('allowance-types/(:num)','Payroll\AllowanceController::deleteType/$1');

        $routes->get('allowance-rates',          'Payroll\AllowanceController::listRates');
        $routes->post('allowance-rates',         'Payroll\AllowanceController::createRate');
        $routes->put('allowance-rates/(:num)',   'Payroll\AllowanceController::updateRate/$1');
        $routes->delete('allowance-rates/(:num)','Payroll\AllowanceController::deleteRate/$1');

        $routes->get('employee-allowances/(:num)',                    'Payroll\AllowanceController::listForEmployee/$1');
        $routes->post('employee-allowances/(:num)/recompute',         'Payroll\AllowanceController::recomputeEmployee/$1');
        $routes->put('employee-allowances/(:num)',                    'Payroll\AllowanceController::overrideAssignment/$1');
        $routes->post('allowances/recompute-company',                 'Payroll\AllowanceController::recomputeCompany');

        // ─── Work Locations (lokasi kerja granular) ─────────────────
        $routes->resource('work-locations', ['controller' => 'Master\WorkLocationController']);
        $routes->get('employees/(:num)/work-locations',  'Master\WorkLocationController::employeeHistory/$1');
        $routes->post('employees/(:num)/work-locations', 'Master\WorkLocationController::assignEmployee/$1');

        // ─── Anniversary / Birthday Widget ─────────────────────────
        $routes->get('anniversary/upcoming', 'Employee\AnniversaryController::upcoming');
        $routes->get('anniversary/today',    'Employee\AnniversaryController::today');

        // ─── Training & Sertifikasi ────────────────────────────────
        $routes->get('trainings-stats',                          'Training\TrainingController::stats');
        $routes->get('certificates/expiring',                    'Training\TrainingController::expiringCertificates');
        $routes->get('certificates',                             'Training\TrainingController::listCertificates');
        $routes->post('certificates',                            'Training\TrainingController::createCertificate');
        $routes->get('certificates/(:num)',                      'Training\TrainingController::showCertificate/$1');
        $routes->put('certificates/(:num)',                      'Training\TrainingController::updateCertificate/$1');
        $routes->delete('certificates/(:num)',                   'Training\TrainingController::deleteCertificate/$1');
        $routes->get('training-budgets',                         'Training\TrainingController::listBudgets');
        $routes->post('training-budgets',                        'Training\TrainingController::createBudget');
        $routes->put('training-budgets/(:num)',                  'Training\TrainingController::updateBudget/$1');
        $routes->post('training-registrations/(:num)/approve',   'Training\TrainingController::approveRegistration/$1');
        $routes->post('training-registrations/(:num)/reject',    'Training\TrainingController::rejectRegistration/$1');
        $routes->post('training-registrations/(:num)/complete',  'Training\TrainingController::completeRegistration/$1');
        $routes->post('training-registrations/(:num)/cancel',    'Training\TrainingController::cancelRegistration/$1');
        $routes->get('trainings',                                'Training\TrainingController::index');
        $routes->post('trainings',                               'Training\TrainingController::create');
        $routes->get('trainings/(:num)',                         'Training\TrainingController::show/$1');
        $routes->put('trainings/(:num)',                         'Training\TrainingController::update/$1');
        $routes->delete('trainings/(:num)',                      'Training\TrainingController::delete/$1');
        $routes->post('trainings/(:num)/publish',                'Training\TrainingController::publish/$1');
        $routes->get('trainings/(:num)/registrations',           'Training\TrainingController::listRegistrations/$1');
        $routes->post('trainings/(:num)/register',               'Training\TrainingController::register/$1');

        // ─── Performance Management (OKR / KPI / Review) ───────────
        // My (specific) — di atas resource biar tidak ke-trap
        $routes->get('performance/my',                         'Performance\PerformanceController::my');
        // Cycles
        $routes->get('performance-cycles',                     'Performance\PerformanceController::listCycles');
        $routes->post('performance-cycles',                    'Performance\PerformanceController::createCycle');
        $routes->get('performance-cycles/(:num)',              'Performance\PerformanceController::showCycle/$1');
        $routes->put('performance-cycles/(:num)',              'Performance\PerformanceController::updateCycle/$1');
        $routes->delete('performance-cycles/(:num)',           'Performance\PerformanceController::deleteCycle/$1');
        $routes->post('performance-cycles/(:num)/status',      'Performance\PerformanceController::changeCycleStatus/$1');
        // Goals — endpoint khusus di atas resource
        $routes->get('goals/weights/(:num)/(:num)',            'Performance\PerformanceController::validateWeights/$1/$2');
        $routes->post('goals/(:num)/approve',                  'Performance\PerformanceController::approveGoal/$1');
        $routes->post('goals/(:num)/check-in',                 'Performance\PerformanceController::checkInGoal/$1');
        $routes->get('goals',                                  'Performance\PerformanceController::listGoals');
        $routes->post('goals',                                 'Performance\PerformanceController::createGoal');
        $routes->get('goals/(:num)',                           'Performance\PerformanceController::showGoal/$1');
        $routes->put('goals/(:num)',                           'Performance\PerformanceController::updateGoal/$1');
        $routes->delete('goals/(:num)',                        'Performance\PerformanceController::deleteGoal/$1');
        // Reviews
        $routes->post('performance-reviews/(:num)/submit',     'Performance\PerformanceController::submitReview/$1');
        $routes->post('performance-reviews/(:num)/acknowledge','Performance\PerformanceController::acknowledgeReview/$1');
        $routes->post('performance-reviews/(:num)/finalize',   'Performance\PerformanceController::finalizeReview/$1');
        $routes->get('performance-reviews',                    'Performance\PerformanceController::listReviews');
        $routes->post('performance-reviews',                   'Performance\PerformanceController::createReview');
        $routes->get('performance-reviews/(:num)',             'Performance\PerformanceController::showReview/$1');
        $routes->put('performance-reviews/(:num)',             'Performance\PerformanceController::updateReview/$1');

        // ─── Anti-Cheat Attendance (HR Review) ─────────────────────
        $routes->get('attendance-anomaly',                    'Attendance\AnomalyController::index');
        $routes->get('attendance-anomaly/stats',              'Attendance\AnomalyController::stats');
        $routes->post('attendance-anomaly/(:num)/review',     'Attendance\AnomalyController::review/$1');
        $routes->get('attendance-devices',                    'Attendance\AnomalyController::devices');
        $routes->post('attendance-devices/(:num)/trust',      'Attendance\AnomalyController::trustDevice/$1');
        $routes->post('attendance-devices/(:num)/block',      'Attendance\AnomalyController::blockDevice/$1');

        // ─── Bulk Import (xlsx) ───────────────────────────────────
        // Migration #067. Generic dispatcher pakai ImportRegistry +
        // XlsxImportService. Lihat docs/XLSX_IMPORT_AUDIT.md untuk
        // daftar resource yg saat ini siap di-import (departments,
        // branches, job_titles, job_grades, positions, employees).
        // Endpoint statis didefinisikan duluan supaya tidak nyangkut
        // sebagai (:segment) dynamic di pattern di bawahnya.
        $routes->get('imports/resources',                     'System\ImportController::listResources');
        $routes->get('imports/batches/(:num)',                'System\ImportController::batch/$1');
        $routes->post('imports/batches/(:num)/cancel',        'System\ImportController::cancel/$1');
        $routes->get('imports/(:segment)/template',           'System\ImportController::template/$1');
        $routes->post('imports/(:segment)/preview',           'System\ImportController::preview/$1');
        $routes->post('imports/(:segment)/commit/(:num)',     'System\ImportController::commit/$1/$2');
        $routes->get('imports/(:segment)/history',            'System\ImportController::history/$1');

        // ─── HR Helpdesk / Tickets ─────────────────────────────────
        $routes->get('helpdesk/stats',                       'Helpdesk\HelpdeskController::stats');
        $routes->get('helpdesk/tickets',                     'Helpdesk\HelpdeskController::index');
        $routes->post('helpdesk/tickets',                    'Helpdesk\HelpdeskController::create');
        $routes->get('helpdesk/tickets/(:num)',              'Helpdesk\HelpdeskController::show/$1');
        $routes->put('helpdesk/tickets/(:num)',              'Helpdesk\HelpdeskController::update/$1');
        $routes->delete('helpdesk/tickets/(:num)',           'Helpdesk\HelpdeskController::delete/$1');
        $routes->post('helpdesk/tickets/(:num)/assign',      'Helpdesk\HelpdeskController::assign/$1');
        $routes->post('helpdesk/tickets/(:num)/status',      'Helpdesk\HelpdeskController::changeStatus/$1');
        $routes->post('helpdesk/tickets/(:num)/close',       'Helpdesk\HelpdeskController::close/$1');
        $routes->post('helpdesk/tickets/(:num)/reopen',      'Helpdesk\HelpdeskController::reopen/$1');
        $routes->post('helpdesk/tickets/(:num)/comments',    'Helpdesk\HelpdeskController::addComment/$1');

        // ─── Manpower Planning (MPP) ───────────────────────────────
        // Endpoint khusus didefinisikan SEBELUM resource pattern supaya
        // path /manpower-requests/stats & /inbox tidak nyangkut sebagai id.
        $routes->get('manpower-requests/stats',                  'Manpower\ManpowerController::stats');
        $routes->get('manpower-requests/inbox',                  'Manpower\ManpowerController::inbox');
        // Migration #066 — MPP yg vacancy_started_at sudah lewat / mendekati
        $routes->get('manpower-requests/overdue',                'Manpower\ManpowerController::overdue');
        $routes->get('manpower-requests',                        'Manpower\ManpowerController::index');
        $routes->post('manpower-requests',                       'Manpower\ManpowerController::create');
        $routes->get('manpower-requests/(:num)',                 'Manpower\ManpowerController::show/$1');
        $routes->put('manpower-requests/(:num)',                 'Manpower\ManpowerController::update/$1');
        $routes->delete('manpower-requests/(:num)',              'Manpower\ManpowerController::delete/$1');
        $routes->post('manpower-requests/(:num)/submit',         'Manpower\ManpowerController::submit/$1');
        $routes->post('manpower-requests/(:num)/approve',        'Manpower\ManpowerController::approve/$1');
        $routes->post('manpower-requests/(:num)/reject',         'Manpower\ManpowerController::reject/$1');
        $routes->post('manpower-requests/(:num)/cancel',         'Manpower\ManpowerController::cancel/$1');
        $routes->post('manpower-requests/(:num)/create-posting', 'Manpower\ManpowerController::createPosting/$1');
        $routes->post('manpower-requests/(:num)/close',          'Manpower\ManpowerController::close/$1');
        // Sprint 5 — onboarding planning endpoints
        $routes->get('manpower-requests/(:num)/planned-assets',  'Manpower\ManpowerController::plannedAssets/$1');
        $routes->put('manpower-requests/(:num)/planned-assets',  'Manpower\ManpowerController::syncPlannedAssets/$1');

        // ─── Recruitment / ATS ─────────────────────────────────────
        $routes->get('recruitment/pipeline-stats',  'Recruitment\RecruitmentController::pipelineStats');
        // Sprint 9 / H1.4 — Time-in-Stage tracking
        $routes->get('recruitment/funnel-metrics',   'Recruitment\RecruitmentController::funnelMetrics');
        $routes->get('recruitment/stale-candidates', 'Recruitment\RecruitmentController::staleCandidates');
        $routes->get('job-postings',                'Recruitment\RecruitmentController::indexJobs');
        $routes->post('job-postings',               'Recruitment\RecruitmentController::createJob');
        $routes->get('job-postings/(:num)',         'Recruitment\RecruitmentController::showJob/$1');
        $routes->put('job-postings/(:num)',         'Recruitment\RecruitmentController::updateJob/$1');
        $routes->delete('job-postings/(:num)',      'Recruitment\RecruitmentController::deleteJob/$1');
        $routes->post('job-postings/(:num)/open',   'Recruitment\RecruitmentController::openJob/$1');
        $routes->post('job-postings/(:num)/close',  'Recruitment\RecruitmentController::closeJob/$1');
        // Career portal — publish/unpublish posting + settings
        $routes->post('job-postings/(:num)/publish',   'Recruitment\CareerController::publishJob/$1');
        $routes->post('job-postings/(:num)/unpublish', 'Recruitment\CareerController::unpublishJob/$1');
        $routes->get('career-page',                       'Recruitment\CareerController::show');
        $routes->put('career-page',                       'Recruitment\CareerController::update');
        $routes->post('career-page/regenerate-slug',      'Recruitment\CareerController::regenerateSlug');
        $routes->get('career-applicants',                 'Recruitment\CareerController::applicants');
        $routes->get('candidates',                          'Recruitment\RecruitmentController::indexCandidates');
        $routes->post('candidates',                         'Recruitment\RecruitmentController::createCandidate');
        $routes->get('candidates/(:num)',                   'Recruitment\RecruitmentController::showCandidate/$1');
        $routes->put('candidates/(:num)',                   'Recruitment\RecruitmentController::updateCandidate/$1');
        $routes->delete('candidates/(:num)',                'Recruitment\RecruitmentController::deleteCandidate/$1');
        $routes->post('candidates/(:num)/move-stage',       'Recruitment\RecruitmentController::moveStage/$1');
        $routes->post('candidates/(:num)/reject',           'Recruitment\RecruitmentController::rejectCandidate/$1');
        $routes->post('candidates/(:num)/hire',             'Recruitment\RecruitmentController::hireCandidate/$1');

        // ─── Candidate Offers (Sprint 2 / REC-1) ───────────────────
        // Letter-of-offer untuk kandidat di stage OFFER. Workflow:
        //   DRAFT → SENT → ACCEPTED/REJECTED/EXPIRED/WITHDRAWN.
        $routes->get('candidates/(:num)/offers',            'Recruitment\OfferController::listForCandidate/$1');
        $routes->post('candidates/(:num)/offers',           'Recruitment\OfferController::createForCandidate/$1');
        $routes->get('offers',                              'Recruitment\OfferController::index');
        $routes->get('offers/(:num)',                       'Recruitment\OfferController::show/$1');
        $routes->put('offers/(:num)',                       'Recruitment\OfferController::update/$1');
        $routes->delete('offers/(:num)',                    'Recruitment\OfferController::delete/$1');
        $routes->post('offers/(:num)/send',                 'Recruitment\OfferController::send/$1');
        $routes->post('offers/(:num)/respond',              'Recruitment\OfferController::respond/$1');
        $routes->post('offers/(:num)/withdraw',             'Recruitment\OfferController::withdraw/$1');

        // ─── Probation (Sprint 2 / HIRE-1) ─────────────────────────
        // PKWTT auto-tracking H+90 dengan reminder & decision flow.
        $routes->get('probation/expiring',                  'Employee\ProbationController::expiring');
        $routes->get('probation/expired',                   'Employee\ProbationController::expired');
        $routes->post('employees/(:num)/probation/decide',  'Employee\ProbationController::decide/$1');

        // ─── Individual Development Plan (Sprint 3 / OD-2) ─────────
        // Closing-the-loop performance review → development plan.
        // Endpoint khusus didefinisikan SEBELUM /(:num) supaya path
        // /idp/my dan /idp/from-review tidak nyangkut sebagai resource id.
        $routes->get('idp/my',                              'Performance\IdpController::my');
        $routes->post('idp/from-review/(:num)',             'Performance\IdpController::createFromReview/$1');
        $routes->get('idp',                                 'Performance\IdpController::index');
        $routes->post('idp',                                'Performance\IdpController::create');
        $routes->get('idp/(:num)',                          'Performance\IdpController::show/$1');
        $routes->put('idp/(:num)',                          'Performance\IdpController::update/$1');
        $routes->delete('idp/(:num)',                       'Performance\IdpController::delete/$1');
        $routes->post('idp/(:num)/finalize',                'Performance\IdpController::finalize/$1');
        $routes->post('idp/(:num)/complete',                'Performance\IdpController::complete/$1');
        $routes->post('idp/(:num)/cancel',                  'Performance\IdpController::cancel/$1');

        // ─── Online Tests (HR admin) ───────────────────────────────
        // Lihat docs/ONLINE_TEST.md. Kandidat-side endpoint terpisah
        // (filter: candidatejwt) — yang ini full-feature untuk HR.

        // Master kategori test (Migration #065) — built-in 14 kategori
        // populer di HR Indonesia (TPA, Pauli, Kraepelin, DISC, MBTI,
        // Wartegg, Holland, dll). HR boleh tambah kategori custom.
        // Endpoint khusus didefinisikan SEBELUM (:num).
        $routes->get('online-test-categories/picker',         'Recruitment\OnlineTestCategoryController::picker');
        $routes->get('online-test-categories',                'Recruitment\OnlineTestCategoryController::index');
        $routes->post('online-test-categories',               'Recruitment\OnlineTestCategoryController::create');
        $routes->get('online-test-categories/(:num)',         'Recruitment\OnlineTestCategoryController::show/$1');
        $routes->put('online-test-categories/(:num)',         'Recruitment\OnlineTestCategoryController::update/$1');
        $routes->delete('online-test-categories/(:num)',      'Recruitment\OnlineTestCategoryController::delete/$1');

        // Master pipeline rekrutmen — HR bisa custom urutan stages
        // (mis. ONLINE_TEST sebelum/sesudah HR Interview, FGD, Psikotest, dst).
        // Lihat docs/RECRUITMENT_PIPELINE.md.
        $routes->get('recruitment-pipelines/picker',          'Recruitment\RecruitmentPipelineController::picker');
        $routes->get('recruitment-pipelines',                 'Recruitment\RecruitmentPipelineController::index');
        $routes->post('recruitment-pipelines',                'Recruitment\RecruitmentPipelineController::create');
        $routes->get('recruitment-pipelines/(:num)',          'Recruitment\RecruitmentPipelineController::show/$1');
        $routes->put('recruitment-pipelines/(:num)',          'Recruitment\RecruitmentPipelineController::update/$1');
        $routes->delete('recruitment-pipelines/(:num)',       'Recruitment\RecruitmentPipelineController::delete/$1');
        $routes->post('recruitment-pipelines/(:num)/duplicate','Recruitment\RecruitmentPipelineController::duplicate/$1');

        // Endpoint khusus didefinisikan SEBELUM (:num) supaya path
        // /categories tidak nyangkut sebagai resource id.
        $routes->get('online-test-questions-bank/categories', 'Recruitment\OnlineTestController::bankCategories');
        $routes->get('online-test-questions-bank',            'Recruitment\OnlineTestController::listBank');
        $routes->post('online-test-questions-bank',           'Recruitment\OnlineTestController::createBank');
        $routes->put('online-test-questions-bank/(:num)',     'Recruitment\OnlineTestController::updateBank/$1');
        $routes->delete('online-test-questions-bank/(:num)',  'Recruitment\OnlineTestController::deleteBank/$1');

        $routes->get('online-tests',                          'Recruitment\OnlineTestController::listTests');
        $routes->post('online-tests',                         'Recruitment\OnlineTestController::createTest');
        $routes->get('online-tests/(:num)',                   'Recruitment\OnlineTestController::showTest/$1');
        $routes->put('online-tests/(:num)',                   'Recruitment\OnlineTestController::updateTest/$1');
        $routes->delete('online-tests/(:num)',                'Recruitment\OnlineTestController::deleteTest/$1');
        $routes->post('online-tests/(:num)/seed-template',    'Recruitment\OnlineTestController::seedTemplate/$1');
        $routes->get('online-tests/(:num)/questions',         'Recruitment\OnlineTestController::listQuestions/$1');
        $routes->post('online-tests/(:num)/questions',        'Recruitment\OnlineTestController::createQuestion/$1');
        $routes->post('online-tests/(:num)/import-from-bank', 'Recruitment\OnlineTestController::importFromBank/$1');

        $routes->put('online-test-questions/(:num)',          'Recruitment\OnlineTestController::updateQuestion/$1');
        $routes->delete('online-test-questions/(:num)',       'Recruitment\OnlineTestController::deleteQuestion/$1');

        $routes->get('online-test-assignments',               'Recruitment\OnlineTestController::listAssignments');
        $routes->post('online-test-assignments',              'Recruitment\OnlineTestController::createAssignment');
        $routes->get('online-test-assignments/(:num)',        'Recruitment\OnlineTestController::showAssignment/$1');
        $routes->put('online-test-assignments/(:num)',        'Recruitment\OnlineTestController::updateAssignment/$1');
        $routes->delete('online-test-assignments/(:num)',     'Recruitment\OnlineTestController::deleteAssignment/$1');
        $routes->post('online-test-assignments/(:num)/cancel','Recruitment\OnlineTestController::cancelAssignment/$1');

        $routes->get('online-test-attempts/(:num)',           'Recruitment\OnlineTestController::showAttempt/$1');
        $routes->post('online-test-attempts/(:num)/grade',    'Recruitment\OnlineTestController::gradeAnswer/$1');

        // ─── Business Trip / SPPD ──────────────────────────────────
        $routes->put('business-trip-expenses/(:num)',    'Attendance\BusinessTripController::updateExpense/$1');
        $routes->delete('business-trip-expenses/(:num)', 'Attendance\BusinessTripController::deleteExpense/$1');
        $routes->get('business-trips',                       'Attendance\BusinessTripController::index');
        $routes->post('business-trips',                      'Attendance\BusinessTripController::create');
        $routes->get('business-trips/(:num)',                'Attendance\BusinessTripController::show/$1');
        $routes->put('business-trips/(:num)',                'Attendance\BusinessTripController::update/$1');
        $routes->delete('business-trips/(:num)',             'Attendance\BusinessTripController::delete/$1');
        $routes->post('business-trips/(:num)/submit',        'Attendance\BusinessTripController::submit/$1');
        $routes->post('business-trips/(:num)/approve',       'Attendance\BusinessTripController::approve/$1');
        $routes->post('business-trips/(:num)/reject',        'Attendance\BusinessTripController::reject/$1');
        $routes->post('business-trips/(:num)/start',         'Attendance\BusinessTripController::start/$1');
        $routes->post('business-trips/(:num)/complete',      'Attendance\BusinessTripController::complete/$1');
        $routes->post('business-trips/(:num)/settle',        'Attendance\BusinessTripController::settle/$1');
        $routes->post('business-trips/(:num)/cancel',        'Attendance\BusinessTripController::cancel/$1');
        $routes->get('business-trips/(:num)/sppd',           'Attendance\BusinessTripController::sppd/$1');
        $routes->get('business-trips/(:num)/expenses',       'Attendance\BusinessTripController::listExpenses/$1');
        $routes->post('business-trips/(:num)/expenses',      'Attendance\BusinessTripController::addExpense/$1');

        // ─── Asset / Inventory ─────────────────────────────────────
        $routes->get('asset-assignments',                'Asset\AssetController::listAssignments');
        $routes->get('asset-assignments/employee/(:num)','Asset\AssetController::forEmployee/$1');
        $routes->get('asset-assignments/(:num)/bast',    'Asset\AssetController::bast/$1');
        $routes->get('assets',                           'Asset\AssetController::index');
        $routes->post('assets',                          'Asset\AssetController::create');
        $routes->get('assets/(:num)',                    'Asset\AssetController::show/$1');
        $routes->put('assets/(:num)',                    'Asset\AssetController::update/$1');
        $routes->delete('assets/(:num)',                 'Asset\AssetController::delete/$1');
        $routes->post('assets/(:num)/assign',            'Asset\AssetController::assign/$1');
        $routes->post('assets/(:num)/return',            'Asset\AssetController::returnAsset/$1');

        // ─── Onboarding & Offboarding Tasks ────────────────────────
        // Templates
        $routes->get('task-templates',                'Lifecycle\TaskTemplateController::index');
        $routes->get('task-templates/(:num)',         'Lifecycle\TaskTemplateController::show/$1');
        $routes->post('task-templates',               'Lifecycle\TaskTemplateController::create');
        $routes->put('task-templates/(:num)',         'Lifecycle\TaskTemplateController::update/$1');
        $routes->delete('task-templates/(:num)',      'Lifecycle\TaskTemplateController::delete/$1');
        // Employee tasks (instance)
        $routes->get('employee-tasks/board',          'Lifecycle\EmployeeTaskController::board');
        $routes->get('employee-tasks/employee/(:num)','Lifecycle\EmployeeTaskController::forEmployee/$1');
        $routes->post('employee-tasks/generate',      'Lifecycle\EmployeeTaskController::generate');
        $routes->get('employee-tasks',                'Lifecycle\EmployeeTaskController::index');
        $routes->post('employee-tasks',               'Lifecycle\EmployeeTaskController::create');
        $routes->get('employee-tasks/(:num)',         'Lifecycle\EmployeeTaskController::show/$1');
        $routes->put('employee-tasks/(:num)',         'Lifecycle\EmployeeTaskController::update/$1');
        $routes->delete('employee-tasks/(:num)',      'Lifecycle\EmployeeTaskController::delete/$1');
        $routes->post('employee-tasks/(:num)/complete','Lifecycle\EmployeeTaskController::complete/$1');

        // ─── Bonus Engine (Sprint 16+) ─────────────────────────────
        // Modul P3 baru untuk handle bonus selain THR & insentif (annual,
        // KPI, milestone, sign-on, retention). Workflow run:
        //   DRAFT → CALCULATED → APPROVED → PAID. Lihat docs/BONUS_ENGINE.md.
        $routes->post('bonus-schemes/preview',                  'Payroll\BonusController::previewScheme');
        $routes->get('bonus-schemes',                           'Payroll\BonusController::listSchemes');
        $routes->post('bonus-schemes',                          'Payroll\BonusController::createScheme');
        $routes->get('bonus-schemes/(:num)',                    'Payroll\BonusController::showScheme/$1');
        $routes->put('bonus-schemes/(:num)',                    'Payroll\BonusController::updateScheme/$1');
        $routes->delete('bonus-schemes/(:num)',                 'Payroll\BonusController::deleteScheme/$1');
        $routes->get('bonus-runs',                              'Payroll\BonusController::listRuns');
        $routes->post('bonus-runs',                             'Payroll\BonusController::createRun');
        $routes->get('bonus-runs/(:num)',                       'Payroll\BonusController::showRun/$1');
        $routes->put('bonus-runs/(:num)',                       'Payroll\BonusController::updateRun/$1');
        $routes->delete('bonus-runs/(:num)',                    'Payroll\BonusController::deleteRun/$1');
        $routes->post('bonus-runs/(:num)/calculate',            'Payroll\BonusController::calculateRun/$1');
        $routes->post('bonus-runs/(:num)/approve',              'Payroll\BonusController::approveRun/$1');
        $routes->post('bonus-runs/(:num)/mark-paid',            'Payroll\BonusController::markRunPaid/$1');
        $routes->post('bonus-runs/(:num)/cancel',               'Payroll\BonusController::cancelRun/$1');
        $routes->get('bonus-runs/(:num)/recipients',            'Payroll\BonusController::listRecipients/$1');
        $routes->post('bonus-runs/(:num)/recipients/include-all', 'Payroll\BonusController::includeAllRecipients/$1');
        $routes->put('bonus-recipients/(:num)',                 'Payroll\BonusController::overrideRecipient/$1');
        // Sprint 20+ — Bonus history per karyawan (untuk profile tab + HR analytics)
        $routes->get('employees/(:num)/bonus-history',          'Payroll\BonusController::employeeHistory/$1');

        // ─── Severance / Pesangon (PHK) ────────────────────────────
        // Endpoint khusus didefinisikan SEBELUM CRUD
        $routes->get('severance/reasons',                'Lifecycle\SeveranceController::reasons');
        $routes->post('severance/calculate',             'Lifecycle\SeveranceController::preview');
        $routes->get('severance',                        'Lifecycle\SeveranceController::index');
        $routes->post('severance',                       'Lifecycle\SeveranceController::create');
        $routes->get('severance/(:num)',                 'Lifecycle\SeveranceController::show/$1');
        $routes->put('severance/(:num)',                 'Lifecycle\SeveranceController::update/$1');
        $routes->delete('severance/(:num)',              'Lifecycle\SeveranceController::delete/$1');
        $routes->post('severance/(:num)/finalize',       'Lifecycle\SeveranceController::finalize/$1');
        $routes->post('severance/(:num)/mark-paid',      'Lifecycle\SeveranceController::markPaid/$1');
        $routes->post('severance/(:num)/cancel',         'Lifecycle\SeveranceController::cancel/$1');
        $routes->get('severance/(:num)/breakdown',       'Lifecycle\SeveranceController::breakdown/$1');

        // ─── Disciplinary Records (Surat Peringatan) ───────────────
        // Endpoint khusus didefinisikan SEBELUM CRUD agar path seperti
        // /disciplinary-records/categories tidak ke-trap sebagai id.
        $routes->get('disciplinary-records/categories',                  'Lifecycle\DisciplinaryController::listCategories');
        $routes->post('disciplinary-records/categories',                 'Lifecycle\DisciplinaryController::createCategory');
        $routes->put('disciplinary-records/categories/(:num)',           'Lifecycle\DisciplinaryController::updateCategory/$1');
        $routes->delete('disciplinary-records/categories/(:num)',        'Lifecycle\DisciplinaryController::deleteCategory/$1');
        $routes->get('disciplinary-records/employee-summary/(:num)',     'Lifecycle\DisciplinaryController::employeeSummary/$1');
        $routes->get('disciplinary-records',                             'Lifecycle\DisciplinaryController::index');
        $routes->post('disciplinary-records',                            'Lifecycle\DisciplinaryController::create');
        $routes->get('disciplinary-records/(:num)',                      'Lifecycle\DisciplinaryController::show/$1');
        $routes->put('disciplinary-records/(:num)',                      'Lifecycle\DisciplinaryController::update/$1');
        $routes->delete('disciplinary-records/(:num)',                   'Lifecycle\DisciplinaryController::delete/$1');
        $routes->post('disciplinary-records/(:num)/revoke',              'Lifecycle\DisciplinaryController::revoke/$1');
        $routes->post('disciplinary-records/(:num)/acknowledge',         'Lifecycle\DisciplinaryController::acknowledge/$1');
        $routes->get('disciplinary-records/(:num)/letter',               'Lifecycle\DisciplinaryController::letter/$1');

        // ─── Approval Delegations ──────────────────────────────────
        $routes->get('approval-delegations',           'Approval\ApprovalDelegationController::index');
        $routes->get('approval-delegations/(:num)',    'Approval\ApprovalDelegationController::show/$1');
        $routes->post('approval-delegations',          'Approval\ApprovalDelegationController::create');
        $routes->put('approval-delegations/(:num)',    'Approval\ApprovalDelegationController::update/$1');
        $routes->delete('approval-delegations/(:num)', 'Approval\ApprovalDelegationController::delete/$1');

        // ─── Approval Chains ───────────────────────────────────────
        $routes->get('approval-chains',            'Approval\ApprovalChainController::index');
        $routes->get('approval-chains/(:num)',     'Approval\ApprovalChainController::show/$1');
        $routes->post('approval-chains',           'Approval\ApprovalChainController::create');
        $routes->put('approval-chains/(:num)',     'Approval\ApprovalChainController::update/$1');
        $routes->delete('approval-chains/(:num)',  'Approval\ApprovalChainController::delete/$1');
        // Assignment rules — chain mana berlaku untuk dept/branch/grade tertentu
        $routes->get('approval-assignments',       'Approval\ApprovalChainController::listAssignments');
        $routes->post('approval-assignments',      'Approval\ApprovalChainController::createAssignment');
        $routes->delete('approval-assignments/(:num)', 'Approval\ApprovalChainController::deleteAssignment/$1');
        // Inbox + decision
        $routes->get('approval-requests/inbox',         'Approval\ApprovalChainController::inbox');
        // Bulk endpoints (Sprint 7 / H1.1) — harus didefinisikan SEBELUM
        // /(:num)/approve & /(:num)/reject supaya path /bulk-approve tidak
        // di-resolve sebagai numeric id.
        $routes->post('approval-requests/bulk-approve', 'Approval\ApprovalChainController::bulkApprove');
        $routes->post('approval-requests/bulk-reject',  'Approval\ApprovalChainController::bulkReject');
        $routes->get('approval-requests/(:num)',        'Approval\ApprovalChainController::showRequest/$1');
        $routes->post('approval-requests/(:num)/approve','Approval\ApprovalChainController::approveRequest/$1');
        $routes->post('approval-requests/(:num)/reject', 'Approval\ApprovalChainController::rejectRequest/$1');

        // ─── Company Regulations (klausul peraturan) ───────────────
        $routes->get('regulations',                'System\CompanyRegulationController::index');
        $routes->get('regulations/preview',        'System\CompanyRegulationController::preview');
        $routes->get('regulations/(:num)',         'System\CompanyRegulationController::show/$1');
        $routes->post('regulations',               'System\CompanyRegulationController::create');
        $routes->put('regulations/(:num)',         'System\CompanyRegulationController::update/$1');
        $routes->delete('regulations/(:num)',      'System\CompanyRegulationController::delete/$1');

        // ─── Contract Templates ────────────────────────────────────
        $routes->get('contract-templates',                       'Lifecycle\ContractTemplateController::index');
        $routes->get('contract-templates/variables',             'Lifecycle\ContractTemplateController::variables');
        $routes->get('contract-templates/(:num)',                'Lifecycle\ContractTemplateController::show/$1');
        $routes->post('contract-templates',                      'Lifecycle\ContractTemplateController::create');
        $routes->put('contract-templates/(:num)',                'Lifecycle\ContractTemplateController::update/$1');
        $routes->delete('contract-templates/(:num)',             'Lifecycle\ContractTemplateController::delete/$1');
        $routes->post('contract-templates/(:num)/preview',       'Lifecycle\ContractTemplateController::preview/$1');
        $routes->post('contract-templates/(:num)/generate',      'Lifecycle\ContractTemplateController::generate/$1');

        // ─── Generic File Upload (Sprint 3 item I) ─────────────────
        // POST /upload?context=ticket|candidate_doc|employee_doc|training_cert&entity_id=N
        // Validasi MIME + size + multi-tenant ownership di controller.
        // Sprint A / B3 — Origin guard untuk multipart upload.
        $routes->post('upload', 'System\FileUploadController::upload', ['filter' => 'sameorigin']);

        // ─── Pre-create attachment upload ──────────────────────────
        // POST /upload/attachment?context=leave|overtime|reimbursement|...
        // Untuk lampiran/bukti yang ditempel ke field `attachment_path`
        // di payload create form. Boleh dipanggil sebelum parent record
        // dibuat. Lihat System\FileUploadController::ATTACHMENT_CONTEXTS.
        // Sprint A / B3 — Origin guard untuk multipart upload.
        $routes->post('upload/attachment', 'System\FileUploadController::uploadAttachment',
            ['filter' => 'sameorigin']);

        // ─── Resignation Requests (self-service) ───────────────────
        // Karyawan ajukan surat resign → atasan approve → HR approve →
        // auto-trigger offboarding tasks (lihat docs/RESIGNATION.md).
        $routes->get('resignations/reasons',          'Lifecycle\ResignationController::reasons');
        $routes->get('resignations',                  'Lifecycle\ResignationController::index');
        $routes->post('resignations',                 'Lifecycle\ResignationController::create');
        $routes->get('resignations/(:num)',           'Lifecycle\ResignationController::show/$1');
        $routes->post('resignations/(:num)/approve',  'Lifecycle\ResignationController::approve/$1');
        $routes->post('resignations/(:num)/reject',   'Lifecycle\ResignationController::reject/$1');
        $routes->post('resignations/(:num)/cancel',   'Lifecycle\ResignationController::cancel/$1');

        // ─── Offboarding compliance (Sprint 1) ─────────────────────
        // Asset clearance, BPJS deactivate, paklaring, dan final settlement
        // tracking. Lihat docs/LIFECYCLE_AUDIT_2026.md item OFF-2/3/4/6 +
        // HIRE-4 (BPJS SLA scanner di /offboarding/bpjs-overdue).
        $routes->get('resignations/(:num)/offboarding',                                 'Lifecycle\OffboardingController::status/$1');
        $routes->post('resignations/(:num)/offboarding/asset-clearance',                'Lifecycle\OffboardingController::setAssetClearance/$1');
        $routes->post('resignations/(:num)/offboarding/bpjs-deactivated',               'Lifecycle\OffboardingController::setBpjsDeactivated/$1');
        $routes->post('resignations/(:num)/offboarding/paklaring',                      'Lifecycle\OffboardingController::generatePaklaring/$1');
        $routes->get('resignations/(:num)/offboarding/paklaring/(:num)',                'Lifecycle\OffboardingController::downloadPaklaring/$1/$2');
        $routes->post('resignations/(:num)/offboarding/final-settlement',               'Lifecycle\OffboardingController::linkFinalSettlement/$1');
        $routes->post('resignations/(:num)/offboarding/final-settlement/paid',          'Lifecycle\OffboardingController::markFinalSettlementPaid/$1');
        $routes->get('offboarding/bpjs-overdue',                                        'Lifecycle\OffboardingController::bpjsOverdue');

        // ─── Job Information Change (Promosi/Demosi/Mutasi) ────────
        // Workflow: Supervisor → Dept Head → HR → BOD (kondisional) →
        // Employee Consent (kondisional, hanya DEMOTION non-disipliner) →
        // APPROVED → IMPLEMENTED. Lihat docs/JIC.md.
        // Endpoint khusus harus didefinisikan SEBELUM /jic/(:num)
        // supaya path /jic/options tidak nyangkut sebagai id.
        $routes->get('jic/options',                  'Employee\JicController::options');
        $routes->get('jic',                          'Employee\JicController::index');
        $routes->post('jic',                         'Employee\JicController::create');
        $routes->get('jic/(:num)',                   'Employee\JicController::show/$1');
        $routes->put('jic/(:num)',                   'Employee\JicController::update/$1');
        $routes->delete('jic/(:num)',                'Employee\JicController::delete/$1');
        $routes->post('jic/(:num)/submit',           'Employee\JicController::submit/$1');
        $routes->post('jic/(:num)/approve',          'Employee\JicController::approve/$1');
        $routes->post('jic/(:num)/reject',           'Employee\JicController::reject/$1');
        $routes->post('jic/(:num)/consent',          'Employee\JicController::consent/$1');
        $routes->post('jic/(:num)/cancel',           'Employee\JicController::cancel/$1');
        $routes->post('jic/(:num)/implement',        'Employee\JicController::implement/$1');

        // Employee Transfer (Mutasi) — pemindahan karyawan antar
        // branch/department/posisi/work_location dengan workflow ringan
        // 1-tier HR approval. Beda dengan JIC: tidak menyentuh grade/upah.
        // Lihat docs/EMPLOYEE_TRANSFER.md.
        // Static routes harus didefinisikan SEBELUM /(:num) supaya
        // path /options tidak nyangkut sebagai id.
        $routes->get('employee-transfers/options',           'Employee\EmployeeTransferController::options');
        // Endpoint khusus karyawan: lihat mutasi yang menyangkut diri sendiri
        // (sebagai subjek). Tidak butuh permission view — auto scope ke
        // tenant.employee_id. Sama pola dengan /leaves/my, /payslips/my, dst.
        $routes->get('employee-transfers/my',                'Employee\EmployeeTransferController::my');
        $routes->get('employee-transfers',                   'Employee\EmployeeTransferController::index');
        $routes->post('employee-transfers',                  'Employee\EmployeeTransferController::create');
        $routes->get('employee-transfers/(:num)',            'Employee\EmployeeTransferController::show/$1');
        $routes->put('employee-transfers/(:num)',            'Employee\EmployeeTransferController::update/$1');
        $routes->delete('employee-transfers/(:num)',         'Employee\EmployeeTransferController::delete/$1');
        $routes->post('employee-transfers/(:num)/submit',    'Employee\EmployeeTransferController::submit/$1');
        $routes->post('employee-transfers/(:num)/approve',   'Employee\EmployeeTransferController::approve/$1');
        $routes->post('employee-transfers/(:num)/reject',    'Employee\EmployeeTransferController::reject/$1');
        $routes->post('employee-transfers/(:num)/cancel',    'Employee\EmployeeTransferController::cancel/$1');
        $routes->post('employee-transfers/(:num)/implement', 'Employee\EmployeeTransferController::implement/$1');
        $routes->post('employee-transfers/(:num)/consent',   'Employee\EmployeeTransferController::consent/$1');

        // ─── Generated Contracts ───────────────────────────────────
        $routes->get('generated-contracts',                  'Lifecycle\GeneratedContractController::index');
        $routes->get('generated-contracts/(:num)',           'Lifecycle\GeneratedContractController::show/$1');
        $routes->get('generated-contracts/(:num)/html',      'Lifecycle\GeneratedContractController::html/$1');
        $routes->put('generated-contracts/(:num)/status',    'Lifecycle\GeneratedContractController::updateStatus/$1');
        $routes->delete('generated-contracts/(:num)',        'Lifecycle\GeneratedContractController::delete/$1');
        // Tanda tangan digital kontrak oleh karyawan
        $routes->post('generated-contracts/(:num)/sign',     'Lifecycle\GeneratedContractController::sign/$1');
        // Daftar kontrak yang menunggu tanda tangan untuk employee login
        $routes->get('employee/pending-contracts',           'Lifecycle\GeneratedContractController::pendingForEmployee');

        // ─── Termination / PHK Formal Workflow (Sprint 4 / OFF-5) ──
        // Workflow: DRAFT → PENDING_BIPARTITE → PENDING_HR_DIRECTOR
        //   → PENDING_BOD → APPROVED → EXECUTED. Lihat
        //   docs/SPRINT_4_TERMINATION_POLISH.md.
        // Endpoint khusus didefinisikan SEBELUM /(:num) supaya path
        // /options tidak nyangkut sebagai resource id.
        $routes->get('terminations/options',                 'Lifecycle\TerminationController::options');
        $routes->get('terminations',                         'Lifecycle\TerminationController::index');
        $routes->post('terminations',                        'Lifecycle\TerminationController::create');
        $routes->get('terminations/(:num)',                  'Lifecycle\TerminationController::show/$1');
        $routes->put('terminations/(:num)',                  'Lifecycle\TerminationController::update/$1');
        $routes->delete('terminations/(:num)',               'Lifecycle\TerminationController::delete/$1');
        $routes->post('terminations/(:num)/submit',          'Lifecycle\TerminationController::submit/$1');
        $routes->post('terminations/(:num)/bipartite',       'Lifecycle\TerminationController::bipartite/$1');
        $routes->post('terminations/(:num)/approve',         'Lifecycle\TerminationController::approve/$1');
        $routes->post('terminations/(:num)/reject',          'Lifecycle\TerminationController::reject/$1');
        $routes->post('terminations/(:num)/cancel',          'Lifecycle\TerminationController::cancel/$1');
        $routes->post('terminations/(:num)/execute',         'Lifecycle\TerminationController::execute/$1');
        $routes->get('terminations/(:num)/letter',           'Lifecycle\TerminationController::letter/$1');

        // ─── Exit Interview (Sprint 4 / OFF-1) ─────────────────────
        // Wawancara terstruktur saat resign / PHK.
        // Endpoint khusus didefinisikan SEBELUM /(:num).
        $routes->get('exit-interviews/options',              'Lifecycle\ExitInterviewController::options');
        $routes->get('exit-interviews/analytics',            'Lifecycle\ExitInterviewController::analytics');
        $routes->get('exit-interviews',                      'Lifecycle\ExitInterviewController::index');
        $routes->post('exit-interviews',                     'Lifecycle\ExitInterviewController::create');
        $routes->get('exit-interviews/(:num)',               'Lifecycle\ExitInterviewController::show/$1');
        $routes->put('exit-interviews/(:num)',               'Lifecycle\ExitInterviewController::update/$1');
        $routes->delete('exit-interviews/(:num)',            'Lifecycle\ExitInterviewController::delete/$1');
        $routes->post('exit-interviews/(:num)/complete',     'Lifecycle\ExitInterviewController::complete/$1');
        $routes->post('exit-interviews/(:num)/skip',         'Lifecycle\ExitInterviewController::skip/$1');

        // ─── Employee 360 View (Sprint 4 / CC-4) ───────────────────
        // Aggregator endpoint — 12 section per karyawan.
        $routes->get('employees/(:num)/360',                 'Employee\Employee360Controller::show/$1');

        // ─── Compliance Reports (Sprint 4 / CC-5) ──────────────────
        // WLKP, BPJS, PKWT, PHK list, audit summary, turnover.
        // Throttle export berat (sama bucket dengan export lain).
        $routes->get('compliance-reports/types',             'Compliance\ComplianceReportController::types');
        $routes->get('compliance-reports/runs',              'Compliance\ComplianceReportController::runs');
        $routes->post('compliance-reports/generate',         'Compliance\ComplianceReportController::generate');
        $routes->get('compliance-reports/runs/(:num)',       'Compliance\ComplianceReportController::showRun/$1');
        $routes->get('compliance-reports/runs/(:num)/csv',   'Compliance\ComplianceReportController::exportCsv/$1',
            ['filter' => 'throttle:export']);

        // ─── Horizon 2 (Sprint 11–13) ──────────────────────────────
        // H2.4 Candidate composite scoring
        $routes->post('candidates/(:num)/recalculate-score',
            'System\Horizon2Controller::recalcCandidateScore/$1');
        $routes->post('job-postings/(:num)/recalculate-scores',
            'System\Horizon2Controller::recalcJobPostingScores/$1');

        // H2.2 Goals cascading
        $routes->get('performance/goals/tree',
            'System\Horizon2Controller::goalsTree');
        $routes->post('performance/goals/(:num)/recompute-rollup',
            'System\Horizon2Controller::recomputeGoalRollup/$1');

        // H2.3 360° peer feedback
        $routes->post('performance/cycles/(:num)/peer-nominations',
            'System\Horizon2Controller::nominatePeer/$1');
        $routes->get('performance/cycles/(:num)/peer-rating',
            'System\Horizon2Controller::peerRating/$1');

        // H2.5 Employee Referral
        $routes->post('referrals/links',          'System\Horizon2Controller::createReferralLink');
        $routes->get('referrals/links/me',        'System\Horizon2Controller::myReferralLinks');
        $routes->get('referrals/top-referrers',   'System\Horizon2Controller::topReferrers');

        // H2.6 Turnover Analytics
        $routes->get('turnover/snapshot',         'System\Horizon2Controller::turnoverSnapshot');
        $routes->post('turnover/snapshot',        'System\Horizon2Controller::turnoverSnapshotPersist');
        $routes->get('turnover/trend',            'System\Horizon2Controller::turnoverTrend');

        // H2.7 Payroll Simulasi (in-memory; no persist)
        $routes->post('payroll/simulate',         'System\Horizon2Controller::simulatePayroll');
        $routes->post('payroll/simulate/compare', 'System\Horizon2Controller::simulatePayrollCompare');
        $routes->post('payroll/simulate/reverse', 'System\Horizon2Controller::simulatePayrollReverse');

        // H2.8 Reimbursement OCR — extract from attachment_path
        $routes->post('reimbursements/(:num)/ocr-extract',
            'System\Horizon2Controller::ocrExtractReimbursement/$1');

        // ─── Horizon 3 (Sprint 14 + 17) — Talent Management ────────
        // H3.1 Competency Library
        $routes->get('competencies',                          'System\Horizon3Controller::listCompetencies');
        $routes->post('competencies',                         'System\Horizon3Controller::createCompetency');
        $routes->get('positions/(:num)/competency-profile',
            'System\Horizon3Controller::listPositionProfile/$1');
        $routes->put('positions/(:num)/competency-profile',
            'System\Horizon3Controller::setPositionProfile/$1');
        $routes->get('employees/(:num)/competency-gap',
            'System\Horizon3Controller::competencyGap/$1');
        $routes->get('employees/(:num)/training-recommendations',
            'System\Horizon3Controller::recommendTraining/$1');
        $routes->post('employee-competency-assessments',
            'System\Horizon3Controller::recordAssessment');

        // H3.2 Succession Planning
        $routes->get('critical-positions',                    'System\Horizon3Controller::listCriticalPositions');
        $routes->post('critical-positions',                   'System\Horizon3Controller::createCriticalPosition');
        $routes->post('critical-positions/(:num)/successors',
            'System\Horizon3Controller::nominateSuccessor/$1');
        $routes->get('critical-positions/(:num)/successors',
            'System\Horizon3Controller::listSuccessors/$1');
        $routes->post('succession-candidates/(:num)/recompute-readiness',
            'System\Horizon3Controller::recomputeReadiness/$1');
        $routes->get('succession/coverage',                   'System\Horizon3Controller::successionCoverage');

        // H3.6 Talent Pool & Internal Marketplace
        $routes->get('employees/(:num)/skills',               'System\Horizon3Controller::listEmployeeSkills/$1');
        $routes->post('employees/(:num)/skills',              'System\Horizon3Controller::declareSkill/$1');
        $routes->post('employee-skills/(:num)/validate',
            'System\Horizon3Controller::validateSkill/$1');
        $routes->get('internal-job-postings',                 'System\Horizon3Controller::listInternalPostings');
        $routes->post('internal-job-postings',                'System\Horizon3Controller::createInternalPosting');
        $routes->post('internal-job-postings/(:num)/apply',
            'System\Horizon3Controller::applyInternal/$1');
        $routes->get('internal-job-postings/(:num)/applications',
            'System\Horizon3Controller::listInternalApplications/$1');
        $routes->get('internal-job-postings/(:num)/recommended-candidates',
            'System\Horizon3Controller::recommendInternalCandidates/$1');
        $routes->get('talent-pool/9-box',                     'System\Horizon3Controller::nineBoxGrid');

        // H3.6 — Employee Aspirations (career goal CRUD self-service + HR view)
        $routes->get('employees/(:num)/aspirations',
            'System\Horizon3Controller::listAspirations/$1');
        $routes->post('employees/(:num)/aspirations',
            'System\Horizon3Controller::upsertAspiration/$1');
        $routes->patch('employees/(:num)/aspirations/(:num)',
            'System\Horizon3Controller::upsertAspiration/$1/$2');
        $routes->delete('employees/(:num)/aspirations/(:num)',
            'System\Horizon3Controller::deleteAspiration/$1/$2');

        // ─── Horizon 4 (Sprint 19+) — Platform Evolution ───────────
        // H4.1 AI HR Copilot
        $routes->get('ai/sessions',                           'System\Horizon4Controller::listAiSessions');
        $routes->post('ai/sessions',                          'System\Horizon4Controller::startAiSession');
        $routes->get('ai/sessions/(:num)/messages',           'System\Horizon4Controller::getAiMessages/$1');
        $routes->post('ai/sessions/(:num)/messages',          'System\Horizon4Controller::sendAiMessage/$1');
        $routes->post('ai/sessions/(:num)/archive',           'System\Horizon4Controller::archiveAiSession/$1');

        // H4.3 Workforce Planning
        $routes->get('workforce-scenarios',                   'System\Horizon4Controller::listScenarios');
        $routes->post('workforce-scenarios',                  'System\Horizon4Controller::createScenario');
        $routes->post('workforce-scenarios/(:num)/run',       'System\Horizon4Controller::runScenario/$1');
        $routes->post('workforce-scenarios/compare',          'System\Horizon4Controller::compareScenarios');

        // H4.4 Employee Wellbeing
        $routes->get('pulse-surveys',                         'System\Horizon4Controller::listSurveys');
        $routes->post('pulse-surveys',                        'System\Horizon4Controller::createSurvey');
        $routes->post('pulse-surveys/(:num)/open',            'System\Horizon4Controller::openSurvey/$1');
        $routes->get('pulse-surveys/(:num)/questions',        'System\Horizon4Controller::getSurveyQuestions/$1');
        $routes->post('pulse-surveys/(:num)/respond',         'System\Horizon4Controller::respondSurvey/$1');
        $routes->get('pulse-surveys/(:num)/aggregates',       'System\Horizon4Controller::surveyAggregates/$1');
        $routes->post('pulse-surveys/(:num)/refresh-aggregates',
            'System\Horizon4Controller::refreshSurveyAggregates/$1');

        // H4.5 Compensation Intelligence
        $routes->get('compensation/compa-ratio',              'System\Horizon4Controller::compaRatio');
        $routes->get('compensation/gender-equity',            'System\Horizon4Controller::genderEquity');
        $routes->get('compensation/band-recommendations',     'System\Horizon4Controller::bandRecommendations');
        $routes->get('salary-benchmarks',                     'System\Horizon4Controller::listBenchmarks');
        $routes->post('salary-benchmarks',                    'System\Horizon4Controller::createBenchmark');

        // ─── H3.4 — ERP Journal Integration ─────────────────────────
        // Connection management (per company × provider)
        $routes->get('erp/connections',                       'Erp\ErpJournalController::listConnections');
        $routes->post('erp/connections',                      'Erp\ErpJournalController::createConnection');
        $routes->put('erp/connections/(:num)',                'Erp\ErpJournalController::updateConnection/$1');
        $routes->delete('erp/connections/(:num)',             'Erp\ErpJournalController::deleteConnection/$1');
        $routes->post('erp/connections/(:num)/test',          'Erp\ErpJournalController::testConnection/$1');

        // COA mapping per cost_center × account_type
        $routes->get('erp/mappings',                          'Erp\ErpJournalController::listMappings');
        $routes->post('erp/mappings',                         'Erp\ErpJournalController::createMapping');
        $routes->put('erp/mappings/(:num)',                   'Erp\ErpJournalController::updateMapping/$1');
        $routes->delete('erp/mappings/(:num)',                'Erp\ErpJournalController::deleteMapping/$1');

        // Journal exports (preview, persist, post, reverse, download CSV)
        $routes->get('erp/journal-exports',                   'Erp\ErpJournalController::listExports');
        $routes->post('erp/journal-exports/preview',          'Erp\ErpJournalController::previewExport');
        $routes->post('erp/journal-exports',                  'Erp\ErpJournalController::createExport');
        $routes->get('erp/journal-exports/(:num)',            'Erp\ErpJournalController::showExport/$1');
        $routes->get('erp/journal-exports/(:num)/csv',        'Erp\ErpJournalController::downloadCsv/$1', ['filter' => 'throttle:export']);
        $routes->post('erp/journal-exports/(:num)/post',      'Erp\ErpJournalController::postExport/$1');
        $routes->post('erp/journal-exports/(:num)/reverse',   'Erp\ErpJournalController::reverseExport/$1');

        // ─── H3.7 — Digital Signing PSrE ────────────────────────────
        // Provider config (PrivyID/Vida/Peruri/MOCK_DEV)
        $routes->get('signing/providers',                     'Signing\SigningController::listProviders');
        $routes->post('signing/providers',                    'Signing\SigningController::createProvider');
        $routes->put('signing/providers/(:num)',              'Signing\SigningController::updateProvider/$1');
        $routes->delete('signing/providers/(:num)',           'Signing\SigningController::deleteProvider/$1');

        // Envelope lifecycle
        $routes->get('signing/envelopes',                     'Signing\SigningController::listEnvelopes');
        $routes->post('signing/envelopes',                    'Signing\SigningController::createEnvelope');
        $routes->get('signing/envelopes/(:num)',              'Signing\SigningController::showEnvelope/$1');
        $routes->post('signing/envelopes/(:num)/signers',     'Signing\SigningController::addSigner/$1');
        $routes->post('signing/envelopes/(:num)/send',        'Signing\SigningController::send/$1');
        $routes->post('signing/envelopes/(:num)/cancel',      'Signing\SigningController::cancel/$1');
        $routes->post('signing/envelopes/(:num)/wet-sign',    'Signing\SigningController::wetSignFallback/$1',
            ['filter' => 'sameorigin']);
        $routes->get('signing/envelopes/(:num)/audit',        'Signing\SigningController::audit/$1');
        // Callback PSrE (production via webhook + HMAC; dev via direct call)
        $routes->post('signing/envelopes/(:num)/signers/(:num)/event',
            'Signing\SigningController::recordSignerEvent/$1/$2');

        // ─── H3.8 — Open API & Developer Portal ─────────────────────
        $routes->get('api-scopes',                            'Api\ApiKeyController::listScopes');
        $routes->get('api-keys',                              'Api\ApiKeyController::listKeys');
        $routes->post('api-keys',                             'Api\ApiKeyController::createKey');
        $routes->get('api-keys/(:num)',                       'Api\ApiKeyController::showKey/$1');
        $routes->post('api-keys/(:num)/rotate',               'Api\ApiKeyController::rotateKey/$1');
        $routes->post('api-keys/(:num)/revoke',               'Api\ApiKeyController::revokeKey/$1');
        $routes->get('api-keys/(:num)/usage',                 'Api\ApiKeyController::usage/$1');

        // ─── Project Timesheet (jasa profesional / konsultan) ────────
        // Project master + assignments. burn-rate harus di atas /(:num)
        // generic agar tidak nyangkut sebagai resource id.
        $routes->get('projects/(:num)/burn-rate',             'Timesheet\ProjectController::burnRate/$1');
        $routes->get('projects/(:num)/assignments',           'Timesheet\ProjectController::listAssignments/$1');
        $routes->post('projects/(:num)/assignments',          'Timesheet\ProjectController::createAssignment/$1');
        $routes->get('projects',                              'Timesheet\ProjectController::index');
        $routes->post('projects',                             'Timesheet\ProjectController::create');
        $routes->get('projects/(:num)',                       'Timesheet\ProjectController::show/$1');
        $routes->put('projects/(:num)',                       'Timesheet\ProjectController::update/$1');
        $routes->delete('projects/(:num)',                    'Timesheet\ProjectController::delete/$1');
        $routes->put('project-assignments/(:num)',            'Timesheet\ProjectController::updateAssignment/$1');
        $routes->delete('project-assignments/(:num)',         'Timesheet\ProjectController::deleteAssignment/$1');

        // Timesheet entries — endpoint khusus didahulukan
        $routes->get('timesheet/my',                          'Timesheet\TimesheetController::my');
        $routes->get('timesheet/utilization',                 'Timesheet\TimesheetController::utilization');
        $routes->get('timesheet',                             'Timesheet\TimesheetController::index');
        $routes->post('timesheet',                            'Timesheet\TimesheetController::upsert');
        $routes->post('timesheet/(:num)/submit',              'Timesheet\TimesheetController::submit/$1');
        $routes->post('timesheet/(:num)/approve',             'Timesheet\TimesheetController::approve/$1');
        $routes->post('timesheet/(:num)/reject',              'Timesheet\TimesheetController::reject/$1');

        // ─── License & Certification Expiry Tracker ─────────────────
        // Master license types per company
        $routes->get('license-types',                         'License\LicenseController::listTypes');
        $routes->post('license-types',                        'License\LicenseController::createType');
        $routes->put('license-types/(:num)',                  'License\LicenseController::updateType/$1');
        $routes->delete('license-types/(:num)',               'License\LicenseController::deleteType/$1');

        // Karyawan ↔ license — endpoint khusus didahulukan
        $routes->get('licenses/expiring-dashboard',           'License\LicenseController::expiringDashboard');
        $routes->post('licenses/scan-now',                    'License\LicenseController::scanNow');
        $routes->get('employees/(:num)/license-compliance',   'License\LicenseController::compliance/$1');
        $routes->get('employee-licenses',                     'License\LicenseController::listLicenses');
        $routes->post('employee-licenses',                    'License\LicenseController::createLicense');
        $routes->get('employee-licenses/(:num)',              'License\LicenseController::showLicense/$1');
        $routes->put('employee-licenses/(:num)',              'License\LicenseController::updateLicense/$1');
        $routes->post('employee-licenses/(:num)/renew',       'License\LicenseController::renewLicense/$1');
        $routes->post('employee-licenses/(:num)/revoke',      'License\LicenseController::revokeLicense/$1');
    });
});

// Fallback 404 — MUST send & return string body, bukan Response object,
// supaya tidak memicu "Object cannot be converted to string" pada beberapa
// kondisi (mis. preflight OPTIONS yang nyasar ke 404).
$routes->set404Override(static function () {
    $resp = service('response')
        ->setStatusCode(404)
        ->setJSON(['status' => 'error', 'message' => 'Endpoint not found']);
    $resp->send();
    exit;
});
