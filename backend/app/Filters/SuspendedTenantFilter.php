<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * S1.6 — Block write endpoint untuk tenant SUSPENDED.
 *
 * Tenant yang subscription-nya berstatus SUSPENDED masuk read-only mode:
 *   - GET / HEAD / OPTIONS → tetap allowed
 *   - POST / PUT / PATCH / DELETE → blocked dengan 402 Payment Required
 *
 * Exception:
 *   - Endpoint /billing/* (supaya tenant bisa bayar/reactivate)
 *   - Endpoint /auth/* (login/logout/refresh tetap jalan)
 *   - Super admin Quantara internal bypass
 *
 * Register di Config/Filters sebagai global before, atau per-route.
 *
 * Cara skip per-route: tambahkan ['skip_suspended' => true] di route opts —
 * filter ini cek query attribute kalau ada flag tersebut (untuk testing).
 */
class SuspendedTenantFilter implements FilterInterface
{
    /**
     * Path prefix yang selalu allowed walaupun suspended.
     */
    private const ALLOWED_PATHS = [
        'api/v1/auth',
        'api/v1/billing',
        'api/v1/health',
        'api/v1/metrics',
        'api/v1/plans',
        'api/v1/features/me',
        'api/v1/menus/me',
        'api/v1/companies/me/branding', // tetap bisa lihat branding
    ];

    /**
     * HTTP method yang tetap allowed (read-only).
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function before(RequestInterface $request, $arguments = null)
    {
        $method = strtoupper($request->getMethod() ?? 'GET');

        // Read-only operations selalu allowed
        if (in_array($method, self::SAFE_METHODS, true)) {
            return null;
        }

        // Cek path whitelist
        $uri = (string) $request->getUri()->getPath();
        $uri = ltrim($uri, '/');
        foreach (self::ALLOWED_PATHS as $allowed) {
            if (str_starts_with($uri, $allowed)) {
                return null;
            }
        }

        // Resolve tenant context
        try {
            $tenant = service('tenant');
            if (! $tenant) {
                return null; // tidak bisa resolve, biarkan controller lain yang reject
            }
            // Super admin Quantara bypass
            if (method_exists($tenant, 'isSuperAdmin') && $tenant->isSuperAdmin()) {
                return null;
            }
            $companyId = method_exists($tenant, 'getCompanyId') ? (int) $tenant->getCompanyId() : 0;
            if ($companyId <= 0) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        // Cek subscription status
        $db = Database::connect();
        if (! $db->tableExists('subscriptions')) {
            return null;
        }

        $sub = $db->table('subscriptions')
            ->select('status, current_period_end')
            ->where('company_id', $companyId)
            ->limit(1)
            ->get()->getRowArray();

        if (! $sub) {
            return null;
        }

        $status = strtoupper((string) ($sub['status'] ?? ''));
        if ($status !== 'SUSPENDED' && $status !== 'CANCELLED') {
            return null;
        }

        // Block dengan 402 Payment Required
        $reason = $status === 'SUSPENDED'
            ? 'Akun Anda sedang ter-suspend karena tagihan belum dibayar. Mode read-only aktif.'
            : 'Subscription Anda sudah dibatalkan. Reactivate untuk melanjutkan.';

        return service('response')
            ->setStatusCode(402)
            ->setJSON([
                'status'  => 'error',
                'code'    => $status === 'SUSPENDED' ? 'TENANT_SUSPENDED' : 'TENANT_CANCELLED',
                'message' => $reason,
                'data'    => [
                    'subscription_status' => $status,
                    'current_period_end'  => $sub['current_period_end'] ?? null,
                    'reactivate_url'      => '/billing',
                ],
            ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No-op
    }
}