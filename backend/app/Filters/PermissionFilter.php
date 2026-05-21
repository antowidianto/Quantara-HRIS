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
 * Checks the authenticated user's role for the required permission slug.
 */
class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $arguments || empty($arguments[0])) {
            return;
        }

        $required = $arguments[0];
        $tenant   = Services::tenant();

        if ($tenant->isSuperAdmin()) {
            return;
        }

        $roleId = $tenant->getRoleId();
        if ($roleId === 0) {
            return $this->forbidden('No role assigned');
        }

        $db  = \Config\Database::connect();
        $row = $db->table('role_permissions rp')
            ->select('rp.id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->where('p.slug', $required)
            ->limit(1)
            ->get()
            ->getRowArray();

        if (! $row) {
            return $this->forbidden("Missing permission: {$required}");
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