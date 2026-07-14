<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * PermissionFilter
 *
 * Usage: ['filter' => 'permission:employee.create']
 * Any-of usage: ['filter' => 'permission:payslip.view_self,payslip.view_all']
 * Checks the authenticated user's role for at least one required permission slug.
 */
class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $arguments || empty($arguments[0])) {
            return;
        }

        $required = array_values(array_filter(array_map('trim', $arguments)));
        $tenant   = Services::tenant();

        if ($tenant->isSuperAdmin()) {
            return;
        }

        if ($tenant->getRoleId() === 0) {
            return $this->forbidden('No role assigned');
        }

        foreach ($required as $permission) {
            if ($tenant->hasPermission($permission)) {
                return;
            }
        }

        if ($required !== []) {
            return $this->forbidden('Missing permission: any of ' . implode(', ', $required));
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No-op
    }

    private function forbidden(string $message): ResponseInterface
    {
        return Services::response()
            ->setStatusCode(403)
            ->setJSON([
                'status'  => 'error',
                'code'    => 'FORBIDDEN',
                'message' => $message,
            ]);
    }
}
