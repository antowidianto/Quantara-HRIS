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
        'tenant'        => \App\Filters\TenantFilter::class,
        'throttle'      => \App\Filters\ThrottleFilter::class,
        'audit'         => \App\Filters\AuditFilter::class,
        'apicors'       => \App\Filters\ApiCorsFilter::class,
        'apiheaders'    => \App\Filters\ApiSecurityHeadersFilter::class,
        // Sprint A / B3 — Origin/Referer guard untuk endpoint multipart upload
        'sameorigin'    => \App\Filters\SameOriginFilter::class,
        // Mobile Prep / MOB-3 — Idempotency-Key anti-duplikat untuk POST critical
        'idempotency'   => \App\Filters\IdempotencyFilter::class,
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
        // Mobile Prep / MOB-3 — Idempotency-Key untuk POST critical.
        // Filter optional: hanya aktif kalau client kirim header
        // `Idempotency-Key`. Dipasang di pattern POST yang sering jadi
        // sumber duplikat (clock-in/out, leaves create, reimbursement,
        // approval decisions). after-pass diperlukan untuk tulis
        // response cache.
        'idempotency' => [
            'before' => [
                'api/v1/attendance/clock-in',
                'api/v1/attendance/clock-out',
                'api/v1/leaves',
                'api/v1/overtimes',
                'api/v1/reimbursements',
                'api/v1/business-trips',
                'api/v1/approval-requests/*/approve',
                'api/v1/approval-requests/*/reject',
                'api/v1/approval-requests/bulk-approve',
                'api/v1/approval-requests/bulk-reject',
            ],
            'after' => [
                'api/v1/attendance/clock-in',
                'api/v1/attendance/clock-out',
                'api/v1/leaves',
                'api/v1/overtimes',
                'api/v1/reimbursements',
                'api/v1/business-trips',
                'api/v1/approval-requests/*/approve',
                'api/v1/approval-requests/*/reject',
                'api/v1/approval-requests/bulk-approve',
                'api/v1/approval-requests/bulk-reject',
            ],
        ],
    ];
}
