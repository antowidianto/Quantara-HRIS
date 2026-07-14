<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

final class SuperAdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (Services::tenant()->isSuperAdmin()) {
            return null;
        }

        return Services::response()->setStatusCode(403)->setJSON([
            'status' => 'error',
            'code' => 'FORBIDDEN',
            'message' => 'Super administrator access is required.',
        ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

