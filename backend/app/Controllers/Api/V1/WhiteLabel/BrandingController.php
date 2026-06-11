<?php

namespace App\Controllers\Api\V1\WhiteLabel;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\WhiteLabel\BrandingService;
use App\Libraries\Billing\FeatureGateService;

/**
 * S3.1 — White-Label Branding Controller (ENTERPRISE plan only).
 *
 * Endpoint:
 *   GET    /api/v1/companies/me/branding              — return current branding
 *   PUT    /api/v1/companies/me/branding              — update (gated by feature)
 *   POST   /api/v1/companies/me/branding/verify-domain — DNS TXT verify
 *   GET    /api/v1/branding/by-host?host=…            — public, untuk login page resolver
 *
 * Feature gating:
 *   - GET selalu allowed (return Quantara default kalau tidak ada override)
 *   - PUT + verify-domain butuh feature `white_label.enabled` (plan ENTERPRISE)
 *
 * Audit log severity HIGH karena perubahan branding affect public surface.
 */
class BrandingController extends ApiController
{
    /**
     * GET /api/v1/companies/me/branding
     */
    public function show()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('Tenant context unavailable', 401, 'NO_TENANT');
        }
        $svc = new BrandingService();
        return $this->ok($svc->resolve($companyId));
    }

    /**
     * PUT /api/v1/companies/me/branding
     * Body: {
     *   app_name?, primary_color?, secondary_color?,
     *   logo_path?, favicon_path?, login_bg_path?,
     *   email_footer_html?, support_email?, custom_domain?
     * }
     */
    public function update()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('Tenant context unavailable', 401, 'NO_TENANT');
        }
        $userId = $this->userId() ?: 0;

        // Permission: super company admin atau punya permission `branding.manage`
        $tenant = $this->tenant();
        $hasPermission = false;
        if ($tenant->isSuperAdmin()) {
            $hasPermission = true;
        } elseif (method_exists($tenant, 'hasPermission')
            && $tenant->hasPermission('branding.manage')) {
            $hasPermission = true;
        } elseif (method_exists($tenant, 'getRole')) {
            $role = strtolower((string) $tenant->getRole());
            if (in_array($role, ['super_company_admin', 'super_admin'], true)) {
                $hasPermission = true;
            }
        }
        if (! $hasPermission) {
            return $this->fail('Hanya super company admin yang boleh ubah branding', 403, 'FORBIDDEN');
        }

        // Feature gate: hanya ENTERPRISE plan boleh white-label
        // (Quantara internal super_admin bypass feature gate untuk testing)
        if (! $tenant->isSuperAdmin()) {
            $gate = new FeatureGateService();
            if (! $gate->isEnabled($companyId, 'white_label.enabled')) {
                return $this->fail(
                    'White-labeling hanya tersedia di plan ENTERPRISE. Upgrade plan kamu untuk pakai fitur ini.',
                    402,
                    'FEATURE_NOT_AVAILABLE'
                );
            }
        }

        $payload = $this->getJsonInput();
        $svc = new BrandingService();
        $result = $svc->update($companyId, $userId, $payload);

        if (! $result['ok']) {
            return $this->fail('Validation failed', 422, 'VALIDATION_ERROR', $result['errors']);
        }

        $this->writeAudit($companyId, $userId, 'branding.updated', $payload);

        return $this->ok($result['data'] ?? $svc->resolve($companyId), 'Branding updated');
    }

    /**
     * POST /api/v1/companies/me/branding/verify-domain
     * Tenant pasang DNS TXT record `_quantara.{domain}` dengan value =
     * verification_token. Endpoint ini cek dan flag verified.
     */
    public function verifyDomain()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('Tenant context unavailable', 401, 'NO_TENANT');
        }
        $userId = $this->userId() ?: 0;

        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin()) {
            $gate = new FeatureGateService();
            if (! $gate->isEnabled($companyId, 'white_label.enabled')) {
                return $this->fail('Custom domain butuh plan ENTERPRISE', 402, 'FEATURE_NOT_AVAILABLE');
            }
        }

        $svc = new BrandingService();
        $result = $svc->verifyDomain($companyId, $userId);

        if (! $result['ok']) {
            return $this->fail('Domain verification failed: ' . ($result['reason'] ?? 'unknown'), 422, 'VERIFICATION_FAILED', $result);
        }

        $this->writeAudit($companyId, $userId, 'branding.domain_verified', $result);
        return $this->ok($result, 'Domain verified');
    }

    /**
     * GET /api/v1/branding/by-host?host={hostname}
     * PUBLIC endpoint untuk login page yang harus tampil dengan branding
     * tenant sebelum user login. Return Quantara default kalau host tidak
     * match custom domain manapun.
     */
    public function byHost()
    {
        $host = (string) $this->request->getGet('host');
        if ($host === '') {
            // Fallback: pakai Host header dari request
            $host = (string) $this->request->getHeaderLine('Host');
        }
        $svc = new BrandingService();
        $branding = $svc->resolveByHost($host);
        if (! $branding) {
            return $this->ok(BrandingService::DEFAULTS);
        }
        return $this->ok($branding);
    }

    private function writeAudit(int $companyId, int $userId, string $event, array $meta = []): void
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('audit_logs')) {
                return;
            }
            $db->table('audit_logs')->insert([
                'company_id'    => $companyId,
                'user_id'       => $userId,
                'event'         => $event,
                'entity_type'   => 'companies',
                'entity_id'     => $companyId,
                'severity'      => 'HIGH',
                'metadata_json' => json_encode($meta),
                'ip_address'    => $this->request->getIPAddress(),
                'user_agent'    => (string) $this->request->getUserAgent(),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', '[Branding] audit insert failed: ' . $e->getMessage());
        }
    }
}