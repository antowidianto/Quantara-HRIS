<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class JwtAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (! $authHeader || ! preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            return $this->unauthorized('Missing or invalid Authorization header');
        }

        $token = trim($m[1]);
        $jwt   = Services::jwt();
        $payload = $jwt->validateAccess($token);

        if (! $payload) {
            return $this->unauthorized('Invalid or expired token');
        }

        // Verify user still active
        $db = \Config\Database::connect();
        $user = $db->table('users')
            ->select('id, email, role_id, company_id, is_active, employee_id')
            ->where('id', (int) $payload['sub'])
            ->get()
            ->getRowArray();

        if (! $user || (int) $user['is_active'] !== 1) {
            return $this->unauthorized('User no longer active');
        }

        // Stash user + tenant context onto the request for downstream use
        $request->user = $user;
        $request->jwt  = $payload;

        // Set tenant context so models/queries can scope by company_id.
        // Untuk super admin (company_id NULL di DB), izinkan override via
        // header X-Company-Id supaya seluruh controller transparan terhadap
        // pilihan "active company" yang dikirim frontend setelah login.
        $companyId = (int) ($payload['company_id'] ?? $user['company_id'] ?? 0);
        $hdrCid = (int) $request->getHeaderLine('X-Company-Id');
        if ($hdrCid > 0 && ($companyId === 0 || ((int) $user['role_id']) === 1)) {
            // Super admin atau user lintas-tenant menggunakan header.
            $companyId = $hdrCid;
        }
        Services::tenant()->setCompanyId($companyId);
        Services::tenant()->setUserId((int) $user['id']);
        Services::tenant()->setRoleId((int) ($user['role_id'] ?? 0));
        Services::tenant()->setEmployeeId((int) ($user['employee_id'] ?? 0));

        // Resolve branch_id from the linked employee record (if any).
        // Branch becomes part of the authorization scope: HR/manager dari
        // satu cabang tidak boleh melihat data cabang lain kecuali punya
        // permission `attendance.view_all_branches`.
        $branchId = 0;
        if (! empty($user['employee_id'])) {
            $emp = $db->table('employees')
                ->select('branch_id')
                ->where('id', (int) $user['employee_id'])
                ->get()
                ->getRowArray();
            if ($emp) {
                $branchId = (int) ($emp['branch_id'] ?? 0);
            }
        }
        Services::tenant()->setBranchId($branchId);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No-op
    }

    private function unauthorized(string $message): ResponseInterface
    {
        return Services::response()
            ->setStatusCode(401)
            ->setJSON([
                'status'  => 'error',
                'code'    => 'UNAUTHENTICATED',
                'message' => $message,
            ]);
    }
}