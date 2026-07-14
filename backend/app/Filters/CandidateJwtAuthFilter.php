<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * CandidateJwtAuthFilter
 *
 * Filter JWT khusus untuk endpoint Candidate Portal (di luar HRIS internal).
 *
 * Beda dengan JwtAuthFilter:
 *   - Token type harus `candidate_access` (bukan `access`).
 *   - aud claim harus `candidate`.
 *   - Lookup ke tabel `candidate_accounts` (bukan `users`).
 *   - Hanya set company scope di TenantContext; employee/role/permission
 *     internal tetap kosong karena kandidat bukan internal user.
 *   - Stash payload + record ke request supaya controller bisa baca lewat
 *     `$request->candidate`.
 *
 * Catatan: filter ini TIDAK mendelegasikan ke ThrottleFilter — endpoint
 * candidate sudah masuk grup `throttle:api` di Filters.php global.
 */
class CandidateJwtAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (! $authHeader || ! preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            return $this->unauthorized('Missing or invalid Authorization header');
        }

        $token = trim($m[1]);
        $svc   = Services::candidateAuth();
        $payload = $svc->validateAccess($token);
        if (! $payload) {
            return $this->unauthorized('Invalid or expired candidate token');
        }

        $db = \Config\Database::connect();
        $account = $db->table('candidate_accounts')
            ->where('id', (int) $payload['sub'])
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $account || (int) $account['is_active'] !== 1) {
            return $this->unauthorized('Candidate account no longer active');
        }

        // Stash ke request — controller akses lewat $this->request->candidate
        $request->candidate = $account;
        $request->candidateJwt = $payload;
        // Candidate bukan user internal, tetapi company scope tetap dibutuhkan
        // oleh layanan tenant-aware seperti penyimpanan idempotency key.
        Services::tenant()->setCompanyId((int) $account['company_id']);
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
