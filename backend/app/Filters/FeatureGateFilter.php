<?php

namespace App\Filters;

use App\Libraries\Billing\FeatureGateService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * S1.1 — FeatureGateFilter (Roadmap SaaS)
 *
 * Memblokir akses ke endpoint yang feature-nya tidak include di plan
 * tenant atau di-disable via override.
 *
 * Usage di Routes.php:
 *   $routes->post('reimbursements/(:num)/ocr-extract', '...',
 *       ['filter' => 'feature:reimbursement.ocr']);
 *
 * Multi-feature (semua harus include):
 *   ['filter' => 'feature:performance.cascading,performance.peer_360']
 *
 * Super admin selalu lolos. Tanpa company context (mis. endpoint publik
 * yang harusnya tidak pakai filter ini), defensif allow + warning log.
 */
class FeatureGateFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $arguments || empty($arguments[0])) {
            return; // tidak ada feature key spesifik → no-op
        }

        $tenant = Services::tenant();

        // Super admin bypass
        if ($tenant->isSuperAdmin()) {
            return;
        }

        $companyId = $tenant->getCompanyId();
        if (! $companyId) {
            log_message('warning', 'FeatureGateFilter dipakai tanpa company context');
            return;
        }

        $svc = new FeatureGateService();

        foreach ($arguments as $featureKey) {
            $featureKey = trim((string) $featureKey);
            if ($featureKey === '') continue;

            if (! $svc->enabled($featureKey, $companyId)) {
                return $this->forbidden($featureKey);
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No-op
    }

    private function forbidden(string $featureKey): ResponseInterface
    {
        return Services::response()
            ->setStatusCode(403)
            ->setJSON([
                'status'      => 'error',
                'code'        => 'FEATURE_NOT_AVAILABLE',
                'message'     => "Fitur '{$featureKey}' tidak tersedia di plan Anda. Silakan upgrade.",
                'feature_key' => $featureKey,
            ]);
    }
}