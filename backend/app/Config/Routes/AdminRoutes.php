<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class AdminRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // ─── SaaS / Billing (S1.1 + S1.2 + S1.4) ───────────────────
        // Subscription, feature flags, usage meters, plan management
        $routes->get('features/me',                     'Billing\BillingController::myFeatures', ['filter' => 'ownership:account']);
        $routes->get('billing/subscription',            'Billing\BillingController::mySubscription', ['filter' => 'ownership:account']);
        // S1.4 — Usage meter dashboard (semua tenant boleh lihat usage sendiri)
        $routes->get('billing/usage',                   'Billing\BillingController::myUsage', ['filter' => 'permission:billing.manage']);
        // Super admin only — fix drift counter dari source table
        $routes->post('billing/usage/recompute',        'Billing\BillingController::recomputeUsage', ['filter' => 'permission:billing.manage']);
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
            'Billing\BillingController::extendTrial/$1', ['filter' => 'superadmin']);
        $routes->post('admin/tenants/(:num)/feature-override',
            'Billing\BillingController::featureOverride/$1', ['filter' => 'superadmin']);

        // ─── SaaS / S2.1 — Deep health (super admin) ───────────────
        $routes->get('health/deep', 'System\HealthController::deep', ['filter' => 'superadmin']);

        // ─── SaaS / S3.5 — Admin Console (super admin) ─────────────
        // Console internal Quantara untuk manage tenant + support
        $routes->get('admin/tenants',                          'Admin\AdminTenantController::index', ['filter' => 'superadmin']);
        $routes->get('admin/tenants/(:num)',                   'Admin\AdminTenantController::show/$1', ['filter' => 'superadmin']);
        $routes->post('admin/tenants/(:num)/impersonate',      'Admin\AdminTenantController::impersonate/$1', ['filter' => 'superadmin']);
        $routes->post('admin/tenants/(:num)/suspend',          'Admin\AdminTenantController::suspend/$1', ['filter' => 'superadmin']);
        $routes->post('admin/tenants/(:num)/unsuspend',        'Admin\AdminTenantController::unsuspend/$1', ['filter' => 'superadmin']);
        $routes->get('admin/metrics/saas',                     'Admin\AdminTenantController::saasMetrics', ['filter' => 'superadmin']);

        // ─── SaaS / S2.3 — Tenant Data Export (UU PDP / GDPR) ──────
        // Self-service tenant: HR admin request export semua data company.
        // Async generation; download link signed token (lihat public route
        // /data-exports/download di luar JWT group).
        $routes->post('companies/me/data-exports',             'Backup\DataExportController::create',
            ['filter' => ['permission:data_export.manage', 'throttle:export']]);
        $routes->get('companies/me/data-exports',              'Backup\DataExportController::index', ['filter' => 'permission:data_export.manage']);
        $routes->get('companies/me/data-exports/(:num)',       'Backup\DataExportController::show/$1', ['filter' => 'permission:data_export.manage']);

        // ─── SaaS / S3.1 — White-Label Branding (ENTERPRISE plan) ──
        // GET selalu allowed. PUT + verify-domain butuh feature
        // `white_label.enabled`. Audit log severity HIGH (publik surface).
        $routes->get('companies/me/branding',                  'WhiteLabel\BrandingController::show', ['filter' => 'permission:branding.manage']);
        $routes->put('companies/me/branding',                  'WhiteLabel\BrandingController::update', ['filter' => 'permission:branding.manage']);
        $routes->post('companies/me/branding/verify-domain',   'WhiteLabel\BrandingController::verifyDomain', ['filter' => 'permission:branding.manage']);

        // ─── SaaS / S3.4 — Customer Success Tooling ────────────────
        // Onboarding progress + announcements + customer health score
        $routes->get('onboarding/progress',                    'CustomerSuccess\CustomerSuccessController::progress', ['filter' => 'ownership:account']);
        $routes->post('onboarding/(:segment)/complete',        'CustomerSuccess\CustomerSuccessController::completeStep/$1', ['filter' => 'ownership:account']);
        $routes->get('announcements/unread',                   'CustomerSuccess\CustomerSuccessController::unreadAnnouncements', ['filter' => 'ownership:account']);
        $routes->post('announcements/(:num)/read',             'CustomerSuccess\CustomerSuccessController::markRead/$1', ['filter' => 'ownership:account']);
        $routes->get('companies/me/health-score',              'CustomerSuccess\CustomerSuccessController::myHealth', ['filter' => 'ownership:account']);
        // Super admin Quantara only
        $routes->get('admin/cs/health-scores',                 'CustomerSuccess\CustomerSuccessController::adminHealthScores', ['filter' => 'superadmin']);
        $routes->post('admin/cs/recompute/(:num)',             'CustomerSuccess\CustomerSuccessController::adminRecompute/$1', ['filter' => 'superadmin']);

        // ─── UU PDP (Sprint 4) — Data Subject Rights ───────────────
        // Self-service untuk hak akses, hak penghapusan, dan record consent.
        // Lihat docs/IMPROVEMENT_CHECKLIST.md section 3 + DataPrivacyService.
        $routes->get('me/privacy/policy-version',  'Compliance\PrivacyController::policyVersion', ['filter' => 'ownership:account']);
        $routes->get('me/privacy/consents',        'Compliance\PrivacyController::consents', ['filter' => 'ownership:account']);
        $routes->post('me/privacy/consents',       'Compliance\PrivacyController::recordConsent', ['filter' => 'ownership:account']);
        // Throttle export berat — sama bucket dengan endpoint export lain
        $routes->get('me/data-export',             'Compliance\PrivacyController::dataExport',     ['filter' => ['ownership:account', 'throttle:export']]);
        $routes->post('me/data-deletion-request',  'Compliance\PrivacyController::requestDeletion', ['filter' => ['ownership:account', 'idempotency:required']]);

        // HR review penghapusan data (perlu permission `privacy.review`)
        $routes->get('admin/privacy/deletion-requests',                 'Compliance\PrivacyController::listRequests', ['filter' => 'permission:privacy.review']);
        $routes->post('admin/privacy/deletion-requests/(:num)/review', 'Compliance\PrivacyController::reviewRequest/$1', ['filter' => 'permission:privacy.review']);
        $routes->post('admin/privacy/deletion-requests/(:num)/process','Compliance\PrivacyController::processRequest/$1', ['filter' => 'permission:privacy.review']);
    }
}
