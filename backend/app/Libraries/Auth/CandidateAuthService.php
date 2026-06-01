<?php

namespace App\Libraries\Auth;

use Config\Services;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Ramsey\Uuid\Uuid;

/**
 * CandidateAuthService — auth tersendiri untuk pelamar (kandidat) di
 * Career Portal. TIDAK menggunakan tabel `users` — pakai
 * `candidate_accounts` yang terpisah supaya:
 *
 *   1. Domain berbeda: kandidat tidak boleh akses HRIS internal.
 *   2. Lifecycle berbeda: account kandidat lebih short-lived & banyak.
 *   3. Tenant scope: candidate_accounts.company_id bukan user.company_id.
 *
 * JWT secret dipakai bersama (config/Jwt.php) tapi dengan claim
 * `aud` = "candidate" untuk membedakan dari token internal user.
 *
 * Flow:
 *   - register(email, password, full_name, company_id)
 *   - login(email, password, company_id)
 *   - refresh(refresh_token)
 *   - logout(jti)
 *   - changePassword(account_id, current, new)
 *   - requestPasswordReset(email, company_id) → token plaintext (kirim email)
 *   - resetPassword(token, new_password)
 *
 * Anti-bruteforce: 5 failed attempts → lock 15 menit.
 */
class CandidateAuthService
{
    private const MAX_FAILED = 5;
    private const LOCK_MINUTES = 15;
    private const RESET_TOKEN_TTL_MINUTES = 60;
    private const ACCESS_TTL = 1800;     // 30 menit
    private const REFRESH_TTL = 1209600; // 14 hari

    private \Config\Jwt $jwtConfig;

    public function __construct()
    {
        $this->jwtConfig = config('Jwt');
    }

    // ─── Registration ───────────────────────────────────────────────

    /**
     * Register kandidat baru.
     *
     * @throws \RuntimeException kalau email sudah dipakai / password lemah
     */
    public function register(int $companyId, string $email, string $password, string $fullName, ?string $phone = null): array
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Format email tidak valid');
        }
        if ($companyId <= 0) {
            throw new \RuntimeException('Company tidak valid');
        }
        $fullName = trim($fullName);
        if (mb_strlen($fullName) < 3) {
            throw new \RuntimeException('Nama lengkap minimal 3 karakter');
        }

        // Password policy: min 8 chars, ada huruf + angka.
        // Pakai PasswordPolicy::isAcceptable untuk konsistensi dengan user internal.
        $policy = new PasswordPolicy();
        $errors = $policy->validate($password);
        if ($errors) {
            throw new \RuntimeException('Password lemah: ' . implode(', ', $errors));
        }

        $db = \Config\Database::connect();
        $existing = $db->table('candidate_accounts')
            ->where('company_id', $companyId)
            ->where('email', $email)
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($existing > 0) {
            throw new \RuntimeException('Email sudah terdaftar untuk perusahaan ini. Silakan login.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('candidate_accounts')->insert([
            'company_id'    => $companyId,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]),
            'full_name'     => mb_substr($fullName, 0, 200),
            'phone'         => $phone ? mb_substr(trim($phone), 0, 30) : null,
            'is_active'     => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $accountId = (int) $db->insertID();

        // Audit (PERSONAL DATA — limited info)
        try {
            Services::audit()->forModule('CAREER')->log(
                action: 'candidate.register',
                entity: 'candidate_account',
                entityId: $accountId,
                description: "Pendaftaran akun kandidat: {$email}",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return $this->issueTokens($accountId, $companyId);
    }

    // ─── Login ───────────────────────────────────────────────────────

    public function login(int $companyId, string $email, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        $email = strtolower(trim($email));
        $db = \Config\Database::connect();
        $row = $db->table('candidate_accounts')
            ->where('company_id', $companyId)
            ->where('email', $email)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();

        if (! $row) {
            // Hindari user enumeration: pesan generik
            throw new \RuntimeException('Email atau password salah');
        }
        if ((int) $row['is_active'] !== 1) {
            throw new \RuntimeException('Akun nonaktif. Hubungi tim recruitment.');
        }
        $now = date('Y-m-d H:i:s');
        if (! empty($row['locked_until']) && $row['locked_until'] > $now) {
            throw new \RuntimeException('Akun terkunci sementara karena terlalu banyak percobaan login. Coba lagi nanti.');
        }

        if (! password_verify($password, $row['password_hash'])) {
            $failed = (int) $row['failed_login_count'] + 1;
            $update = [
                'failed_login_count' => $failed,
                'updated_at'         => $now,
            ];
            if ($failed >= self::MAX_FAILED) {
                $update['locked_until'] = date('Y-m-d H:i:s', time() + (self::LOCK_MINUTES * 60));
                $update['failed_login_count'] = 0; // reset counter setelah lock
            }
            $db->table('candidate_accounts')->where('id', (int) $row['id'])->update($update);
            throw new \RuntimeException('Email atau password salah');
        }

        // Success — reset counters
        $db->table('candidate_accounts')->where('id', (int) $row['id'])->update([
            'failed_login_count' => 0,
            'locked_until'       => null,
            'last_login_at'      => $now,
            'last_login_ip'      => $ip ? substr($ip, 0, 64) : null,
            'updated_at'         => $now,
        ]);

        return $this->issueTokens((int) $row['id'], (int) $row['company_id'], $ip, $userAgent);
    }

    // ─── Token issuance ─────────────────────────────────────────────

    public function issueTokens(int $accountId, int $companyId, ?string $ip = null, ?string $userAgent = null): array
    {
        $db = \Config\Database::connect();
        $account = $db->table('candidate_accounts')->where('id', $accountId)->get()->getRowArray();
        if (! $account) throw new \RuntimeException('Account tidak ditemukan');

        $now = time();
        $accessJti  = Uuid::uuid4()->toString();
        $refreshJti = Uuid::uuid4()->toString();

        $accessPayload = [
            'iss'        => $this->jwtConfig->issuer,
            'aud'        => 'candidate',
            'iat'        => $now,
            'nbf'        => $now,
            'exp'        => $now + self::ACCESS_TTL,
            'jti'        => $accessJti,
            'type'       => 'candidate_access',
            'sub'        => (int) $account['id'],
            'email'      => $account['email'],
            'company_id' => (int) $account['company_id'],
        ];
        $refreshPayload = [
            'iss'  => $this->jwtConfig->issuer,
            'aud'  => 'candidate',
            'iat'  => $now,
            'nbf'  => $now,
            'exp'  => $now + self::REFRESH_TTL,
            'jti'  => $refreshJti,
            'type' => 'candidate_refresh',
            'sub'  => (int) $account['id'],
        ];

        $accessToken  = JWT::encode($accessPayload, $this->jwtConfig->secret, $this->jwtConfig->algo);
        $refreshToken = JWT::encode($refreshPayload, $this->jwtConfig->refreshSecret, $this->jwtConfig->algo);

        $db->table('candidate_refresh_tokens')->insert([
            'jti'                 => $refreshJti,
            'candidate_account_id'=> (int) $account['id'],
            'expires_at'          => date('Y-m-d H:i:s', $now + self::REFRESH_TTL),
            'created_at'          => date('Y-m-d H:i:s', $now),
            'revoked'             => 0,
            'user_agent'          => $userAgent ? substr($userAgent, 0, 255) : null,
            'ip_address'          => $ip ? substr($ip, 0, 64) : null,
        ]);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'account'       => [
                'id'         => (int) $account['id'],
                'company_id' => (int) $account['company_id'],
                'email'      => $account['email'],
                'full_name'  => $account['full_name'],
                'phone'      => $account['phone'],
            ],
        ];
    }

    public function validateAccess(string $token): ?array
    {
        try {
            $decoded = (array) JWT::decode($token, new Key($this->jwtConfig->secret, $this->jwtConfig->algo));
            if (($decoded['type'] ?? null) !== 'candidate_access') return null;
            if (($decoded['aud'] ?? null) !== 'candidate') return null;
            return $decoded;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function rotate(string $refreshToken, ?string $ip = null, ?string $userAgent = null): ?array
    {
        try {
            $decoded = (array) JWT::decode($refreshToken, new Key($this->jwtConfig->refreshSecret, $this->jwtConfig->algo));
        } catch (\Throwable $e) {
            return null;
        }
        if (($decoded['type'] ?? null) !== 'candidate_refresh') return null;

        $db = \Config\Database::connect();
        $row = $db->table('candidate_refresh_tokens')
            ->where('jti', $decoded['jti'])
            ->where('revoked', 0)
            ->get()->getRowArray();
        if (! $row || strtotime((string) $row['expires_at']) < time()) return null;

        // Revoke old + issue new
        $this->revokeRefresh($decoded['jti']);

        $account = $db->table('candidate_accounts')->where('id', (int) $decoded['sub'])->get()->getRowArray();
        if (! $account || (int) $account['is_active'] !== 1) return null;

        return $this->issueTokens((int) $account['id'], (int) $account['company_id'], $ip, $userAgent);
    }

    public function revokeRefresh(string $jti): void
    {
        $db = \Config\Database::connect();
        $db->table('candidate_refresh_tokens')->where('jti', $jti)->update([
            'revoked'    => 1,
            'revoked_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function revokeAllForAccount(int $accountId): void
    {
        $db = \Config\Database::connect();
        $db->table('candidate_refresh_tokens')
            ->where('candidate_account_id', $accountId)
            ->where('revoked', 0)
            ->update(['revoked' => 1, 'revoked_at' => date('Y-m-d H:i:s')]);
    }

    // ─── Password management ────────────────────────────────────────

    public function changePassword(int $accountId, string $currentPassword, string $newPassword): void
    {
        $db = \Config\Database::connect();
        $row = $db->table('candidate_accounts')->where('id', $accountId)->get()->getRowArray();
        if (! $row) throw new \RuntimeException('Account tidak ditemukan');
        if (! password_verify($currentPassword, $row['password_hash'])) {
            throw new \RuntimeException('Password saat ini salah');
        }

        $policy = new PasswordPolicy();
        $errors = $policy->validate($newPassword);
        if ($errors) throw new \RuntimeException('Password baru lemah: ' . implode(', ', $errors));

        $db->table('candidate_accounts')->where('id', $accountId)->update([
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 11]),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        // Revoke all sessions
        $this->revokeAllForAccount($accountId);
    }

    /**
     * Buat password reset token. Return plaintext token (perlu dikirim
     * via email — caller). Token disimpan sebagai SHA-256 hash.
     *
     * Untuk anti-enumeration, kalau email tidak ada, tetap return token
     * dummy supaya response time mirip — tapi tidak ada record di DB.
     */
    public function createPasswordResetToken(int $companyId, string $email): ?string
    {
        $email = strtolower(trim($email));
        $db = \Config\Database::connect();
        $row = $db->table('candidate_accounts')
            ->where('company_id', $companyId)
            ->where('email', $email)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            // Sleep sebentar supaya response time mirip
            usleep(random_int(100000, 250000));
            return null;
        }
        $plain = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $plain);
        $db->table('candidate_password_resets')->insert([
            'candidate_account_id' => (int) $row['id'],
            'token_hash'           => $hash,
            'expires_at'           => date('Y-m-d H:i:s', time() + (self::RESET_TOKEN_TTL_MINUTES * 60)),
            'created_at'           => date('Y-m-d H:i:s'),
        ]);
        return $plain;
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        $hash = hash('sha256', trim($token));
        $db = \Config\Database::connect();
        $row = $db->table('candidate_password_resets')
            ->where('token_hash', $hash)
            ->where('used_at IS NULL')
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();
        if (! $row || strtotime((string) $row['expires_at']) < time()) {
            throw new \RuntimeException('Token tidak valid atau sudah kadaluarsa');
        }

        $policy = new PasswordPolicy();
        $errors = $policy->validate($newPassword);
        if ($errors) throw new \RuntimeException('Password baru lemah: ' . implode(', ', $errors));

        $now = date('Y-m-d H:i:s');
        $db->transStart();
        $db->table('candidate_accounts')->where('id', (int) $row['candidate_account_id'])->update([
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 11]),
            'failed_login_count' => 0,
            'locked_until'  => null,
            'updated_at'    => $now,
        ]);
        $db->table('candidate_password_resets')->where('id', (int) $row['id'])->update([
            'used_at' => $now,
        ]);
        // Revoke semua sesi aktif
        $this->revokeAllForAccount((int) $row['candidate_account_id']);
        $db->transComplete();
    }
}