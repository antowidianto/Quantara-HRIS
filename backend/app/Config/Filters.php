<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseConfig
{
    /**
     * Aliases for filter classes.
     *
     * @var array<string, class-string|list<class-string>>
     */
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,

        // Custom filters
        'jwt'           => \App\Filters\JwtAuthFilter::class,
        'candidatejwt'  => \App\Filters\CandidateJwtAuthFilter::class,
        'permission'    => \App\Filters\PermissionFilter::class,
        'ownership'     => \App\Filters\OwnershipPolicyFilter::class,
        'importpermission' => \App\Filters\ImportPermissionFilter::class,
        'superadmin'    => \App\Filters\SuperAdminFilter::class,
        'tenant'        => \App\Filters\TenantFilter::class,
        'throttle'      => \App\Filters\ThrottleFilter::class,
        'audit'         => \App\Filters\AuditFilter::class,
        'apicors'       => \App\Filters\ApiCorsFilter::class,
        'apiheaders'    => \App\Filters\ApiSecurityHeadersFilter::class,
        // Sprint A / B3 — Origin/Referer guard untuk endpoint multipart upload
        'sameorigin'    => \App\Filters\SameOriginFilter::class,
        // Mobile Prep / MOB-3 — Idempotency-Key anti-duplikat untuk POST critical
        'idempotency'   => \App\Filters\IdempotencyFilter::class,
        'privilegedapproval' => \App\Filters\PrivilegedActionApprovalFilter::class,
        // S1.1 SaaS — Feature flag gate untuk plan tiers
        'feature'       => \App\Filters\FeatureGateFilter::class,
        // S1.6 SaaS — Block write op untuk tenant SUSPENDED/CANCELLED
        'suspended'     => \App\Filters\SuspendedTenantFilter::class,
    ];

    /**
     * Filters that are required to be run on every request.
     */
    public array $required = [
        'before' => [
            'forcehttps',
            'pagecache',
        ],
        'after'  => [
            'pagecache',
            'performance',
            'toolbar',
        ],
    ];

    /**
     * List of filter aliases that are always applied before/after every request.
     */
    public array $globals = [
        'before' => [
            'apicors',
            'invalidchars',
            'audit',
        ],
        'after' => [
            'apiheaders',
            'audit',
        ],
    ];

    /**
     * List of filter aliases that works on a particular HTTP method.
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any before or after URI patterns.
     */
    public array $filters = [
        'throttle:api' => ['before' => ['api/v1/*']],
        // S1.6 SaaS — Block POST/PUT/DELETE untuk tenant SUSPENDED.
        // Filter cek subscription status di setiap request; cepat (1 query
        // dengan index company_id). Read-only (GET/HEAD/OPTIONS) tetap
        // allowed supaya user bisa lihat data + reactivate via /billing.
        'suspended' => ['before' => ['api/v1/*']],
    ];
}
