<?php

namespace App\Controllers\Api\V1\Auth;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Tenant\TenantProvisioningService;
use Config\Database;
use Config\Services;

/**
 * S1.3 — Self-Service Tenant Signup Controller (Roadmap SaaS).
 *
 * Endpoint publik (NO JWT) untuk acquisition tenant baru:
 *
 *   POST /api/v1/auth/signup
 *     → buat row signup_attempts (PENDING) + kirim email verifikasi
 *
 *   GET /api/v1/auth/verify-signup?token=…
 *     → mark VERIFIED + langsung provision tenant + return JWT
 *
 *   GET /api/v1/auth/signup/{id}/status
 *     → polling status (untuk UI progress bar setelah verify)
 *
 * Rate limit: signup 3/hour/IP, verify tidak limited (token sudah unique).
 *
 * Security:
 *   - Email lowercased + dedup check
 *   - Password di-hash via password_hash() bcrypt cost 12
 *   - Verification token: random 32 bytes hex (64 chars)
 *   - Token expiry 24 jam
 *   - Audit setiap step (signup_requested, verified, provisioned)
 */
class AuthSignupController extends ApiController
{
    private const TOKEN_TTL_HOURS = 24;
    private const PASSWORD_MIN    = 8;
    private const PASSWORD_MAX    = 200;

    /**
     * POST /api/v1/auth/signup
     */
    public function signup()
    {
        $data = $this->getJsonInput();

        $valid = $this->validateOrFail($data, [
            'email'        => 'required|valid_email|max_length[191]',
            'company_name' => 'required|min_length[2]|max_length[191]',
            'admin_name'   => 'permit_empty|max_length[100]',
            'phone'        => 'permit_empty|max_length[32]',
            'password'     => 'required|min_length[' . self::PASSWORD_MIN . ']|max_length[' . self::PASSWORD_MAX . ']',
            'password_confirm' => 'required|matches[password]',
            'employees_count_estimate' => 'permit_empty|integer|greater_than[0]|less_than[100000]',
            'industry'     => 'permit_empty|max_length[64]',
            'signup_source'=> 'permit_empty|max_length[64]',
            'referrer_code'=> 'permit_empty|max_length[64]',
            'utm_campaign' => 'permit_empty|max_length[100]',
            'utm_medium'   => 'permit_empty|max_length[64]',
            'utm_source'   => 'permit_empty|max_length[64]',
            'agreed_terms' => 'required|in_list[true,1]',
        ]);
        if (! $valid) return null;

        $email = strtolower(trim((string) $data['email']));
        $companyName = trim((string) $data['company_name']);
        $db = Database::connect();

        // ─── Anti-abuse checks ────────────────────────────────
        // 1. Email sudah jadi user existing (legacy / tenant existing)
        $existingUser = $db->table('users')
            ->where('email', $email)
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($existingUser > 0) {
            return $this->fail(
                'Email sudah terdaftar. Silakan login atau gunakan email lain.',
                409,
                'EMAIL_EXISTS',
            );
        }

        // 2. Sudah ada signup PENDING/VERIFIED dengan email sama yang belum expired
        $pending = $db->table('signup_attempts')
            ->where('email', $email)
            ->whereIn('status', ['PENDING', 'VERIFIED'])
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
        if ($pending) {
            // Re-send verification email (idempotent), tidak buat row baru
            $this->sendVerificationEmail($pending);
            return $this->ok([
                'signup_id'   => (int) $pending['id'],
                'email'       => $email,
                'status'      => $pending['status'],
                'expires_at'  => $pending['expires_at'],
                'note'        => 'Email verifikasi sudah dikirim sebelumnya, silakan cek inbox.',
            ], 'Verification email re-sent', 200);
        }

        // 3. Rate limit per IP — max 3 signup PENDING/FAILED per IP per jam
        $ip = $this->request->getIPAddress();
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $ipRecentCount = $db->table('signup_attempts')
            ->where('ip_address', $ip)
            ->where('created_at >=', $oneHourAgo)
            ->countAllResults();
        if ($ipRecentCount >= 3) {
            return $this->fail(
                'Terlalu banyak signup attempt dari IP ini. Coba lagi dalam 1 jam.',
                429,
                'RATE_LIMITED',
            );
        }

        // ─── Insert row signup_attempts ───────────────────────
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_TTL_HOURS . ' hours'));
        $token = bin2hex(random_bytes(32));
        $passwordHash = password_hash((string) $data['password'], PASSWORD_BCRYPT, ['cost' => 12]);

        $signupData = [
            'email'                    => $email,
            'company_name'             => $companyName,
            'admin_name'               => isset($data['admin_name']) ? trim((string) $data['admin_name']) : null,
            'phone'                    => isset($data['phone']) ? trim((string) $data['phone']) : null,
            'password_hash'            => $passwordHash,
            'employees_count_estimate' => isset($data['employees_count_estimate'])
                ? (int) $data['employees_count_estimate'] : null,
            'industry'                 => isset($data['industry']) ? trim((string) $data['industry']) : null,
            'signup_source'            => isset($data['signup_source']) ? trim((string) $data['signup_source']) : 'organic',
            'referrer_code'            => isset($data['referrer_code']) ? trim((string) $data['referrer_code']) : null,
            'utm_campaign'             => isset($data['utm_campaign']) ? trim((string) $data['utm_campaign']) : null,
            'utm_medium'               => isset($data['utm_medium']) ? trim((string) $data['utm_medium']) : null,
            'utm_source'               => isset($data['utm_source']) ? trim((string) $data['utm_source']) : null,
            'ip_address'               => $ip,
            'user_agent'               => substr((string) $this->request->getUserAgent(), 0, 255),
            'status'                   => 'PENDING',
            'verification_token'       => $token,
            'expires_at'               => $expiresAt,
            'created_at'               => $now,
            'updated_at'               => $now,
        ];

        $db->table('signup_attempts')->insert($signupData);
        $signupId = (int) $db->insertID();

        // ─── Audit ─────────────────────────────────────────────
        try {
            Services::audit()
                ->forModule('AUTH_SIGNUP')
                ->log(
                    action: 'signup.requested',
                    entity: 'signup_attempt',
                    entityId: $signupId,
                    description: "Signup baru: {$email} ({$companyName}) dari IP {$ip}",
                    severity: 'NOTICE',
                    metadata: [
                        'email'         => $email,
                        'company_name'  => $companyName,
                        'signup_source' => $signupData['signup_source'],
                    ],
                );
        } catch (\Throwable $e) {
            log_message('warning', '[AuthSignup] audit failed: ' . $e->getMessage());
        }

        // ─── Kirim verification email ──────────────────────────
        $signupData['id'] = $signupId; // patch supaya helper bisa pakai
        $this->sendVerificationEmail($signupData);

        return $this->ok([
            'signup_id'  => $signupId,
            'email'      => $email,
            'status'     => 'PENDING',
            'expires_at' => $expiresAt,
            'message'    => 'Cek email Anda untuk link verifikasi (berlaku 24 jam).',
        ], 'Signup created', 201);
    }

    /**
     * GET /api/v1/auth/verify-signup?token=…
     * Verify email + provision tenant + return JWT untuk auto-login.
     */
    public function verify()
    {
        $token = trim((string) $this->request->getGet('token'));
        if ($token === '' || strlen($token) !== 64) {
            return $this->fail('Token tidak valid', 422, 'INVALID_TOKEN');
        }

        $db = Database::connect();
        $signup = $db->table('signup_attempts')
            ->where('verification_token', $token)
            ->get()->getRowArray();

        if (! $signup) {
            return $this->fail('Token tidak ditemukan atau sudah kadaluarsa', 404, 'TOKEN_NOT_FOUND');
        }

        // Idempotent: kalau sudah PROVISIONED, return data tenant existing
        if ($signup['status'] === 'PROVISIONED') {
            return $this->ok([
                'signup_id'   => (int) $signup['id'],
                'company_id'  => (int) $signup['provisioned_company_id'],
                'user_id'     => (int) $signup['provisioned_user_id'],
                'already_provisioned' => true,
                'message'     => 'Akun sudah aktif. Silakan login.',
            ], 'Already provisioned');
        }

        if ($signup['status'] === 'FAILED') {
            return $this->fail(
                'Provisioning sebelumnya gagal: ' . ($signup['error_message'] ?? 'unknown'),
                422,
                'PROVISIONING_FAILED',
            );
        }
        if ($signup['status'] === 'EXPIRED' || strtotime($signup['expires_at']) < time()) {
            // Auto-mark EXPIRED supaya cleanup bisa
            if ($signup['status'] !== 'EXPIRED') {
                $db->table('signup_attempts')->where('id', $signup['id'])->update([
                    'status'     => 'EXPIRED',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            return $this->fail('Token sudah expired. Silakan signup ulang.', 410, 'TOKEN_EXPIRED');
        }

        // Mark VERIFIED + provision dalam satu request flow
        $now = date('Y-m-d H:i:s');
        $db->table('signup_attempts')->where('id', $signup['id'])->update([
            'status'      => 'VERIFIED',
            'verified_at' => $now,
            'updated_at'  => $now,
        ]);

        try {
            $svc = new TenantProvisioningService();
            $result = $svc->provision((int) $signup['id']);
        } catch (\Throwable $e) {
            return $this->fail('Provisioning gagal: ' . $e->getMessage(), 500, 'PROVISIONING_FAILED');
        }

        // Generate JWT untuk auto-login user baru
        $token = null;
        try {
            $jwt = Services::jwt();
            $token = $jwt->generate([
                'user_id'    => $result['user_id'],
                'company_id' => $result['company_id'],
                'role_id'    => 1,
            ], 60 * 60 * 8); // 8 jam
        } catch (\Throwable $e) {
            log_message('warning', '[AuthSignup] generate JWT failed: ' . $e->getMessage());
        }

        return $this->ok([
            'signup_id'       => (int) $signup['id'],
            'company_id'      => $result['company_id'],
            'user_id'         => $result['user_id'],
            'subscription_id' => $result['subscription_id'],
            'token'           => $token,
            'expires_in'      => 8 * 3600,
            'redirect_to'     => '/onboarding',
            'message'         => 'Akun berhasil dibuat! Selamat datang di Quantara HRIS.',
        ], 'Verified & provisioned');
    }

    /**
     * GET /api/v1/auth/signup/{id}/status
     * Polling status signup (untuk UI loading bar)
     */
    public function status(int $signupId)
    {
        $db = Database::connect();
        $row = $db->table('signup_attempts')
            ->select('id, email, status, verified_at, provisioned_at, expires_at, error_message')
            ->where('id', $signupId)
            ->get()->getRowArray();
        if (! $row) {
            return $this->fail('Signup not found', 404);
        }
        return $this->ok($row);
    }

    /**
     * Helper kirim email verifikasi. Best-effort: kalau email service belum
     * setup, log warning saja jangan fail signup.
     */
    private function sendVerificationEmail(array $signup): void
    {
        try {
            $token = $signup['verification_token'] ?? '';
            $email = $signup['email'] ?? '';
            $name  = $signup['admin_name'] ?? $signup['company_name'] ?? '';
            $appUrl = rtrim((string) (env('app.frontendURL') ?: env('FRONTEND_URL') ?: site_url()), '/');
            $verifyUrl = $appUrl . '/verify-email?token=' . urlencode($token);

            $subject = 'Verifikasi email Anda — Quantara HRIS';
            $html = $this->buildVerificationEmailBody((string) $name, $verifyUrl);

            // Coba pakai service email tenant (pakai default config untuk public signup)
            $emailService = \Config\Services::email();
            $emailService->setFrom(
                (string) (env('email.fromEmail') ?: 'noreply@quantara.io'),
                'Quantara HRIS',
            );
            $emailService->setTo($email);
            $emailService->setSubject($subject);
            $emailService->setMessage($html);
            $emailService->setMailType('html');
            $sent = $emailService->send(false);
            if (! $sent) {
                log_message('warning', '[AuthSignup] email send failed (config issue?): ' . $emailService->printDebugger(['headers']));
            }
        } catch (\Throwable $e) {
            log_message('warning', '[AuthSignup] sendVerificationEmail exception: ' . $e->getMessage());
        }
    }

    private function buildVerificationEmailBody(string $name, string $verifyUrl): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeUrl  = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<body style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; padding: 24px; color: #333;">
  <h2 style="color: #2563eb;">Selamat datang di Quantara HRIS</h2>
  <p>Halo <strong>{$safeName}</strong>,</p>
  <p>Terima kasih sudah mendaftar. Klik tombol di bawah untuk verifikasi email dan mulai trial 14 hari gratis (tanpa kartu kredit).</p>
  <p style="text-align: center; margin: 32px 0;">
    <a href="{$safeUrl}" style="display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;">
      Verifikasi Email
    </a>
  </p>
  <p style="color: #666; font-size: 13px;">
    Atau copy link berikut ke browser Anda:<br>
    <code style="background: #f3f4f6; padding: 4px 8px; border-radius: 4px; word-break: break-all;">{$safeUrl}</code>
  </p>
  <p style="color: #666; font-size: 13px;">
    Link ini berlaku selama <strong>24 jam</strong>. Bila bukan Anda yang signup, abaikan email ini.
  </p>
  <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">
  <p style="color: #999; font-size: 12px;">
    Quantara HRIS · HR & Payroll otomatis untuk Indonesia · PMK 168, UU PDP, BPJS compliant
  </p>
</body>
</html>
HTML;
    }
}