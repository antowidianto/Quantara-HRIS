<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * TenantFilter
 *
 * Ensures a company_id is set in the tenant context (for non-super-admin users).
 * Should run after JwtAuthFilter.
 */
class TenantFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $tenant = Services::tenant();

        if ($tenant->isSuperAdmin()) {
            return;
        }

        if ($tenant->getCompanyId() === 0) {
            return Services::response()
                ->setStatusCode(403)
                ->setJSON([
                    'status'  => 'error',
                    'code'    => 'TENANT_NOT_RESOLVED',
                    'message' => 'No business unit assigned to this user.',
                ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No-op
    }
}