<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\System\HealthChecker;

/**
 * S2.1 — Health Check Endpoints (Roadmap SaaS)
 *
 * Memberikan dua tingkat status untuk konsumsi monitoring eksternal:
 *  - GET /health       — single-line minimal (200 = ok, 503 = down). Pingdom/Uptimerobot.
 *  - GET /health/deep  — detail per dependency (DB, Redis, queue, S3). Auth diperlukan
 *                        karena membuka info teknis. Super admin only.
 *
 * Strategi:
 *  - `/health` cukup cek DB ping → cepat, low-overhead.
 *  - `/health/deep` cek seluruh dependency dengan timeout per check.
 *  - Status code 503 dikembalikan jika ada dependency critical yang down,
 *    supaya load balancer / k8s liveness probe bisa drop instance dan
 *    monitoring eksternal bisa fire alert.
 */
class HealthController extends ApiController
{
    /**
     * GET /api/v1/health (publik, no auth)
     * Liveness probe minimal. DB ping ≤ 100ms expected.
     */
    public function index()
    {
        $checker = new HealthChecker();
        $dbOk = $checker->checkDatabase();

        $payload = [
            'service'  => 'hris-api',
            'time'     => date('c'),
            'database' => $dbOk['ok'] ? 'up' : 'down',
            'version'  => env('APP_VERSION', '1.0.0'),
            'env'      => env('CI_ENVIRONMENT', 'production'),
        ];

        if (! $dbOk['ok']) {
            return $this->response->setStatusCode(503)->setJSON([
                'status'  => 'error',
                'message' => 'Service unavailable',
                'data'    => $payload + ['reason' => $dbOk['error'] ?? 'database unreachable'],
            ]);
        }

        return $this->ok($payload);
    }

    /**
     * GET /api/v1/health/deep (auth required, super admin only)
     * Detail per dependency dengan response time per check.
     */
    public function deep()
    {
        if (! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }

        $checker = new HealthChecker();
        $checks = $checker->all();

        $allOk = true;
        foreach ($checks as $c) {
            if (! $c['ok'] && ($c['critical'] ?? false)) $allOk = false;
        }

        $http = $allOk ? 200 : 503;

        return $this->response->setStatusCode($http)->setJSON([
            'status'  => $allOk ? 'success' : 'error',
            'message' => $allOk ? 'All systems operational' : 'Degraded',
            'data'    => [
                'overall'    => $allOk ? 'up' : 'down',
                'checks'     => $checks,
                'time'       => date('c'),
                'version'    => env('APP_VERSION', '1.0.0'),
            ],
        ]);
    }
}