<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class PublicRoutes
{
    public static function register(RouteCollection $routes): void
    {
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
        $routes->post('tests/(:num)/complete',               'Recruitment\CandidateOnlineTestController::completeAssignment/$1');
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
        $routes->post('offers/(:num)/respond',               'Auth\CandidateAuthController::respondToOffer/$1',
            ['filter' => 'idempotency:required']);
    });

    // Detail & apply pakai 2 segmen slug
    $routes->post('careers/(:segment)/jobs/(:segment)/apply', 'Recruitment\CareerPublicController::apply/$1/$2');
    $routes->get('careers/(:segment)/jobs/(:segment)',        'Recruitment\CareerPublicController::jobDetail/$1/$2');
    $routes->get('careers/(:segment)/jobs',                   'Recruitment\CareerPublicController::listJobs/$1');
    $routes->get('careers/(:segment)/options',                'Recruitment\CareerPublicController::formOptions/$1');
    $routes->get('careers/(:segment)',                        'Recruitment\CareerPublicController::landing/$1');

    // ─── Horizon 2 / H2.5 — Referral slug resolver (PUBLIC) ────────
    // Pelamar klik link `/r/{slug}` → resolve ke job posting / career page.
    $routes->get('referrals/r/(:segment)',                    'System\Horizon2Controller::resolveReferralSlug/$1');

    // ─── SaaS / S1.1 — Pricing page publik ──────────────────────────
    // Marketing site tarik daftar plan + matrix fitur. No auth.
    $routes->get('plans',                                     'Billing\BillingController::plans');
    }
}
