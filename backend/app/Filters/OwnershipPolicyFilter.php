<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/** Declares and enforces the authenticated principal required by self-service routes. */
final class OwnershipPolicyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $tenant = Services::tenant();
        $scope = (string) ($arguments[0] ?? 'account');
        if ($tenant->getUserId() <= 0 || ($scope === 'employee' && $tenant->getEmployeeId() <= 0)) {
            return Services::response()->setStatusCode(403)->setJSON([
                'status' => 'error',
                'code' => 'FORBIDDEN',
                'message' => 'Authenticated ownership context is required',
            ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No-op.
    }
}
