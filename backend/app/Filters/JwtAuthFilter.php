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

        // Simpan user + JWT payload di TenantContext (anti dynamic
        // property deprecation di PHP 8.2+). Controllers seperti
        // AuthController::logout() ambil via $this->tenant()->getJwtPayload().
        // Lama: $request->user/$request->jwt yang memicu warning
        // "Creation of dynamic property" pada IncomingRequest.
        Services::tenant()->setAuthUser($user);
        Services::tenant()->setJwtPayload($payload);

        // Set tenant context so models/queries can scope by company_id.
        // Untuk super admin (company_id NULL di DB), izinkan override via
        // header X-Company-Id supaya seluruh controller transparan terhadap
        // pilihan "active company" yang dikirim frontend setelah login.
        $tokenCompanyId = (int) ($payload['company_id'] ?? $user['company_id'] ?? 0);
        $companyId = $tokenCompanyId;
        $hdrCid = (int) $request->getHeaderLine('X-Company-Id');
        $isSuperAdmin = ((int) $user['role_id']) === 1;
        if ($hdrCid > 0 && ($companyId === 0 || $isSuperAdmin)) {
            // Super admin atau user lintas-tenant menggunakan header.
            $companyId = $hdrCid;

            // Sprint A / B4 — audit setiap kali super_admin switch tenant
            // di luar token claim. NOTICE severity supaya HR / security
            // bisa pantau aktivitas lintas-tenant tanpa request_id berubah.
            // Throttle audit per-IP+user via cache (1 entry per 5 menit per
            // (user, target_company)) supaya log tidak meledak saat user
            // refresh halaman atau polling.
            if ($isSuperAdmin && $tokenCompanyId !== $hdrCid) {
                static $auditedSwitches = [];
                $key = $user['id'] . ':' . $hdrCid;
                if (! isset($auditedSwitches[$key])) {
                    $auditedSwitches[$key] = true;
                    try {
                        Services::audit()->forModule('AUTH')->log(
                            action: 'auth.tenant.switch',
                            entity: 'company',
                            entityId: $hdrCid,
                            description: "Super admin switch context ke company={$hdrCid} (token claim={$tokenCompanyId})",
                            severity: 'NOTICE',
                            metadata: [
                                'user_id'           => (int) $user['id'],
                                'token_company_id'  => $tokenCompanyId,
                                'target_company_id' => $hdrCid,
                            ],
                        );
                    } catch (\Throwable $e) {
                        log_message('critical', 'Tenant switch audit failed: ' . $e->getMessage());
                        return $this->auditUnavailable();
                    }
                }
            }
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
                ->where('company_id', $companyId)
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

    private function auditUnavailable(): ResponseInterface
    {
        return Services::response()
            ->setStatusCode(503)
            ->setJSON([
                'status' => 'error',
                'code' => 'PRIVILEGED_AUDIT_UNAVAILABLE',
                'message' => 'Privileged tenant switch cannot continue without an audit record.',
            ]);
    }
}
