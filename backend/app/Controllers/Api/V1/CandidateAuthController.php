<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * CandidateAuthController — endpoint auth pelamar (kandidat) di Career Portal.
 *
 * SEMUA endpoint di sini PUBLIC kecuali yang explicit pakai
 * filter `candidatejwt` (logout, me, change-password). Auth pakai
 * CandidateAuthService → JWT terpisah dari user internal.
 *
 * Routes (lihat Routes.php):
 *   POST /careers/(:slug)/account/register
 *   POST /careers/(:slug)/account/login
 *   POST /careers/(:slug)/account/forgot-password
 *   POST /careers/account/reset-password
 *   POST /careers/account/refresh
 *   POST /careers/account/logout                (filter: candidatejwt)
 *   GET  /careers/account/me                    (filter: candidatejwt)
 *   POST /careers/account/change-password       (filter: candidatejwt)
 *
 * Tenant resolution: slug company → company_id (sama pola dengan
 * CareerPublicController). Setelah login, semua endpoint kandidat
 * pakai company_id dari JWT claim.
 */
class CandidateAuthController extends BaseController
{
    use \App\Traits\DispatchesWebhooks;

    // ─── Public endpoints (slug-scoped) ─────────────────────────────

    public function register(?string $slug = null)
    {
        $company = $this->resolveCompanyOrFail($slug);
        if (! $company) return $this->response;

        $body = $this->getBody();
        try {
            $tokens = Services::candidateAuth()->register(
                (int) $company['id'],
                (string) ($body['email'] ?? ''),
                (string) ($body['password'] ?? ''),
                (string) ($body['full_name'] ?? ''),
                isset($body['phone']) ? (string) $body['phone'] : null,
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'Candidate register failed: ' . $e->getMessage());
            return $this->fail('Pendaftaran gagal, silakan coba lagi.', 500);
        }

        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::CANDIDATE_REGISTERED,
            (int) $company['id'],
            [
                'candidate_account_id' => $tokens['account']['id'],
                'email'                => $tokens['account']['email'],
                'full_name'            => $tokens['account']['full_name'],
                'company_slug'         => $company['career_slug'] ?? null,
                'registered_at'        => date('c'),
            ],
        );

        return $this->ok($tokens, 'Pendaftaran berhasil', 201);
    }

    public function login(?string $slug = null)
    {
        $company = $this->resolveCompanyOrFail($slug);
        if (! $company) return $this->response;

        $body = $this->getBody();
        $ip = $this->request->getIPAddress();
        $ua = $this->request->getHeaderLine('User-Agent');
        try {
            $tokens = Services::candidateAuth()->login(
                (int) $company['id'],
                (string) ($body['email'] ?? ''),
                (string) ($body['password'] ?? ''),
                $ip,
                $ua,
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 401);
        }
        return $this->ok($tokens, 'Login berhasil');
    }

    /**
     * Trigger forgot password — selalu return 200 supaya tidak ada
     * email enumeration. Token kita kirim via email kalau email valid.
     */
    public function forgotPassword(?string $slug = null)
    {
        $company = $this->resolveCompanyOrFail($slug);
        if (! $company) return $this->response;

        $body = $this->getBody();
        $email = (string) ($body['email'] ?? '');
        $token = Services::candidateAuth()->createPasswordResetToken((int) $company['id'], $email);

        if ($token) {
            // Kirim email dengan link: /careers/{slug}/account/reset?token=...
            // Pakai queueEmail biar konsisten dengan sistem notifikasi yang ada.
            try {
                $resetUrl = '/careers/' . ($company['career_slug'] ?? '') . '/account/reset?token=' . $token;
                $body = '<p>Klik link berikut untuk reset password akun career portal Anda.</p>'
                    . '<p><a href="' . htmlspecialchars($resetUrl) . '">Reset Password</a></p>'
                    . '<p>Link berlaku 60 menit. Kalau Anda tidak pernah meminta reset, abaikan email ini.</p>';
                Services::notification()->queueEmail(
                    (int) $company['id'],
                    (string) $email,
                    null,
                    'Reset Password — ' . ($company['name'] ?? 'Career Portal'),
                    $body,
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Reset email send failed: ' . $e->getMessage());
            }
        }

        return $this->ok(null, 'Jika email terdaftar, link reset sudah dikirim ke email Anda.');
    }

    public function resetPassword()
    {
        $body = $this->getBody();
        $token = (string) ($body['token'] ?? '');
        $newPassword = (string) ($body['password'] ?? '');
        try {
            Services::candidateAuth()->resetPassword($token, $newPassword);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(null, 'Password berhasil direset. Silakan login ulang.');
    }

    public function refresh()
    {
        $body = $this->getBody();
        $token = (string) ($body['refresh_token'] ?? '');
        $ip = $this->request->getIPAddress();
        $ua = $this->request->getHeaderLine('User-Agent');
        $rotated = Services::candidateAuth()->rotate($token, $ip, $ua);
        if (! $rotated) return $this->fail('Refresh token tidak valid', 401);
        return $this->ok($rotated);
    }

    // ─── Authenticated endpoints (candidate JWT required) ──────────

    public function logout()
    {
        // Revoke current refresh token via jti dari refresh body kalau ada,
        // atau revoke all sessions kalau tidak.
        $body = $this->getBody();
        if (! empty($body['refresh_token'])) {
            try {
                $payload = (array) \Firebase\JWT\JWT::decode(
                    (string) $body['refresh_token'],
                    new \Firebase\JWT\Key(config('Jwt')->refreshSecret, config('Jwt')->algo),
                );
                if (! empty($payload['jti'])) {
                    Services::candidateAuth()->revokeRefresh((string) $payload['jti']);
                }
            } catch (\Throwable $e) {
                // ignore — invalid token, idempotent logout
            }
        } else {
            // Without refresh token, revoke all sessions for current account
            $account = $this->candidateAccount();
            if ($account) Services::candidateAuth()->revokeAllForAccount((int) $account['id']);
        }
        return $this->ok(null, 'Logged out');
    }

    public function me()
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        $db = \Config\Database::connect();

        // List lamaran kandidat ini
        $candidates = $db->table('candidates c')
            ->select('c.id, c.full_name, c.email, c.current_stage, c.applied_at,'
                . ' c.rating, c.rejection_reason,'
                . ' jp.id AS job_id, jp.title AS job_title, jp.reference_number,'
                . ' jp.public_slug AS job_slug, jp.work_arrangement, jp.city')
            ->join('job_postings jp', 'jp.id = c.job_posting_id')
            ->where('c.candidate_account_id', (int) $account['id'])
            ->where('c.deleted_at IS NULL')
            ->orderBy('c.applied_at', 'DESC')
            ->get()->getResultArray();

        // List test assignments yang dimiliki account ini
        $tests = $db->table('online_test_assignments ota')
            ->select('ota.id, ota.online_test_id, ota.candidate_id, ota.status,'
                . ' ota.available_from, ota.due_at, ota.max_attempts, ota.attempts_used,'
                . ' ota.best_percentage, ota.best_result, ota.completed_at,'
                . ' ot.name AS test_name, ot.category AS test_category, ot.duration_minutes,'
                . ' jp.title AS job_title')
            ->join('online_tests ot', 'ot.id = ota.online_test_id')
            ->join('job_postings jp', 'jp.id = ota.job_posting_id', 'left')
            ->where('ota.candidate_account_id', (int) $account['id'])
            ->orderBy('ota.created_at', 'DESC')
            ->get()->getResultArray();

        return $this->ok([
            'account' => [
                'id'         => (int) $account['id'],
                'email'      => $account['email'],
                'full_name'  => $account['full_name'],
                'phone'      => $account['phone'],
                'company_id' => (int) $account['company_id'],
            ],
            'applications' => $candidates,
            'tests'        => $tests,
        ]);
    }

    public function changePassword()
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);
        $body = $this->getBody();
        try {
            Services::candidateAuth()->changePassword(
                (int) $account['id'],
                (string) ($body['current_password'] ?? ''),
                (string) ($body['new_password'] ?? ''),
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(null, 'Password berhasil diganti. Silakan login ulang.');
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function getBody(): array
    {
        $body = $this->request->getJSON(true);
        if (! is_array($body)) $body = $this->request->getPost();
        return $body ?: [];
    }

    private function candidateAccount(): ?array
    {
        return $this->request->candidate ?? null;
    }

    private function resolveCompanyOrFail(?string $slug): ?array
    {
        $slug = $this->normalizeSlug($slug);
        if (! $slug) {
            $this->response = $this->fail('Slug perusahaan tidak valid', 400);
            return null;
        }
        $db = \Config\Database::connect();
        $row = $db->table('companies')
            ->where('career_slug', $slug)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Career portal tidak ditemukan', 404);
            return null;
        }
        return $row;
    }

    private function normalizeSlug(?string $slug): ?string
    {
        if (! $slug) return null;
        $s = strtolower(trim($slug));
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $s)) return null;
        return $s;
    }

    protected function ok($data = null, string $message = 'OK', int $status = 200): ResponseInterface
    {
        $payload = ['status' => 'success', 'message' => $message];
        if ($data !== null) $payload['data'] = $data;
        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    protected function fail(string $message, int $status = 400, ?string $code = null): ResponseInterface
    {
        $payload = ['status' => 'error', 'message' => $message];
        if ($code) $payload['code'] = $code;
        return $this->response->setStatusCode($status)->setJSON($payload);
    }
}