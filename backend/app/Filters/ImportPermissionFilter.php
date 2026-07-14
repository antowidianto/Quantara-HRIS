<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\Import\ImportRegistry;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/** Enforces the permission declared by ImportRegistry for /imports/{resource} routes. */
final class ImportPermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $segments = $request->getUri()->getSegments();
        $index = array_search('imports', $segments, true);
        $slug = $index === false ? '' : (string) ($segments[$index + 1] ?? '');
        $spec = ImportRegistry::find($slug);
        $permission = (string) ($spec['permission'] ?? '');
        $tenant = Services::tenant();

        if ($permission !== '' && ($tenant->isSuperAdmin() || $tenant->hasPermission($permission))) {
            return;
        }

        return Services::response()->setStatusCode(403)->setJSON([
            'status' => 'error',
            'code' => 'FORBIDDEN',
            'message' => $permission !== '' ? "Missing permission: {$permission}" : 'Unknown import resource',
        ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No-op.
    }
}
