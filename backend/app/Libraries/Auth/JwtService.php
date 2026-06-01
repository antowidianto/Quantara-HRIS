<?php

namespace App\Libraries\Auth;

use Config\Services;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Ramsey\Uuid\Uuid;

/**
 * JwtService
 *
 * Handles issuing and validating JWT tokens (access + refresh).
 * Refresh tokens are persisted in DB so they can be revoked.
 */
class JwtService
{
    private \Config\Jwt $config;

    public function __construct()
    {
        $this->config = config('Jwt');
    }

    /**
     * Issue an access + refresh token pair for a user.
     */
    public function issueTokenPair(array $user, ?int $companyId = null): array
    {
        $now = time();
        $accessJti  = Uuid::uuid4()->toString();
        $refreshJti = Uuid::uuid4()->toString();

        $accessPayload = [
            'iss'        => $this->config->issuer,
            'aud'        => $this->config->audience,
            'iat'        => $now,
            'nbf'        => $now,
            'exp'        => $now + $this->config->accessTtl,
            'jti'        => $accessJti,
            'type'       => 'access',
            'sub'        => (int) $user['id'],
            'email'      => $user['email'] ?? null,
            'company_id' => $companyId,
            'role_id'    => $user['role_id'] ?? null,
            'employee_id'=> $user['employee_id'] ?? null,
        ];

        $refreshPayload = [
            'iss'  => $this->config->issuer,
            'aud'  => $this->config->audience,
            'iat'  => $now,
            'nbf'  => $now,
            'exp'  => $now + $this->config->refreshTtl,
            'jti'  => $refreshJti,
            'type' => 'refresh',
            'sub'  => (int) $user['id'],
        ];

        $accessToken  = JWT::encode($accessPayload, $this->config->secret, $this->config->algo);
        $refreshToken = JWT::encode($refreshPayload, $this->config->refreshSecret, $this->config->algo);

        // Persist refresh token
        $db = \Config\Database::connect();
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $row = [
            'jti'        => $refreshJti,
            'user_id'    => (int) $user['id'],
            'expires_at' => date('Y-m-d H:i:s', $now + $this->config->refreshTtl),
            'created_at' => date('Y-m-d H:i:s', $now),
            'revoked'    => 0,
            'user_agent' => $ua,
            'ip_address' => $ip,
        ];
        // Sprint A / B5 — device tracking. Conditional supaya kompatibel
        // dengan DB yang belum jalan migration #072.
        if ($db->fieldExists('device_fingerprint', 'refresh_tokens')) {
            $row['device_fingerprint'] = $this->fingerprintDevice($ua);
            $row['device_name']        = $this->describeDevice($ua);
            $row['last_used_at']       = date('Y-m-d H:i:s', $now);
        }
        $db->table('refresh_tokens')->insert($row);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $this->config->accessTtl,
        ];
    }

    /**
     * Validate an access token. Returns decoded payload as array, or null on failure.
     */
    public function validateAccess(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->config->secret, $this->config->algo));
            $payload = (array) $decoded;

            if (($payload['type'] ?? null) !== 'access') {
                return null;
            }
            return $payload;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Validate a refresh token, ensuring it's not revoked.
     *
     * Hanya menelan exception yang berasal dari library JWT (token rusak,
     * expired, signature mismatch). Exception lain — terutama
     * `DatabaseException` — dibiarkan naik supaya kegagalan operasional
     * (DB down, credential salah, plugin auth tidak kompatibel) tidak
     * tersamar jadi "INVALID_REFRESH" yang menyesatkan.
     */
    public function validateRefresh(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->config->refreshSecret, $this->config->algo));
        } catch (
            \Firebase\JWT\ExpiredException
            | \Firebase\JWT\SignatureInvalidException
            | \Firebase\JWT\BeforeValidException
            | \UnexpectedValueException
            | \DomainException
            | \InvalidArgumentException $e
        ) {
            // Token memang invalid — log untuk audit, return null.
            log_message('info', 'JwtService::validateRefresh JWT invalid: ' . $e->getMessage());
            return null;
        }

        $payload = (array) $decoded;
        if (($payload['type'] ?? null) !== 'refresh') {
            return null;
        }

        $db = \Config\Database::connect();
        $row = $db->table('refresh_tokens')
            ->where('jti', $payload['jti'])
            ->where('revoked', 0)
            ->get()
            ->getRowArray();

        if (! $row) {
            return null;
        }

        if (strtotime($row['expires_at']) < time()) {
            return null;
        }

        // Sprint A / B5 — track last_used_at supaya UI Active Sessions
        // bisa display "kapan terakhir aktif". Best-effort: kalau update
        // gagal (kolom belum ada), abaikan.
        if ($db->fieldExists('last_used_at', 'refresh_tokens')) {
            try {
                $db->table('refresh_tokens')
                    ->where('jti', $payload['jti'])
                    ->update(['last_used_at' => date('Y-m-d H:i:s')]);
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        return $payload;
    }

    /**
     * Revoke a refresh token by JTI.
     */
    public function revokeRefresh(string $jti): void
    {
        $db = \Config\Database::connect();
        $db->table('refresh_tokens')
            ->where('jti', $jti)
            ->update(['revoked' => 1, 'revoked_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Revoke all refresh tokens for a user (e.g. on password change).
     */
    public function revokeAllForUser(int $userId): void
    {
        $db = \Config\Database::connect();
        $db->table('refresh_tokens')
            ->where('user_id', $userId)
            ->where('revoked', 0)
            ->update(['revoked' => 1, 'revoked_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Revoke a single refresh token by user + jti (untuk "sign out this device").
     * Return true kalau ada row yang ter-update.
     */
    public function revokeByUserAndJti(int $userId, string $jti): bool
    {
        $db = \Config\Database::connect();
        $db->table('refresh_tokens')
            ->where('user_id', $userId)
            ->where('jti', $jti)
            ->where('revoked', 0)
            ->update(['revoked' => 1, 'revoked_at' => date('Y-m-d H:i:s')]);
        return $db->affectedRows() > 0;
    }

    /**
     * List active sessions (refresh tokens) untuk user. Sprint A / B5.
     * Return: [{jti, device_name, ip, ua, created_at, last_used_at, expires_at}]
     */
    public function listActiveSessions(int $userId): array
    {
        $db = \Config\Database::connect();
        $hasDeviceCols = $db->fieldExists('device_fingerprint', 'refresh_tokens');

        $cols = ['jti', 'user_agent', 'ip_address', 'created_at', 'expires_at'];
        if ($hasDeviceCols) {
            $cols = array_merge($cols, ['device_fingerprint', 'device_name', 'last_used_at']);
        }

        $rows = $db->table('refresh_tokens')
            ->select(implode(', ', $cols))
            ->where('user_id', $userId)
            ->where('revoked', 0)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        // Decorate dengan device_name fallback kalau kolomnya belum ada
        foreach ($rows as &$r) {
            if (! isset($r['device_name'])) {
                $r['device_name'] = $this->describeDevice((string) ($r['user_agent'] ?? ''));
            }
        }
        return $rows;
    }

    /**
     * Revoke semua sesi user kecuali jti yang aktif sekarang.
     * Untuk fitur "sign out from all other devices".
     */
    public function revokeAllExcept(int $userId, string $exceptJti): int
    {
        $db = \Config\Database::connect();
        $db->table('refresh_tokens')
            ->where('user_id', $userId)
            ->where('revoked', 0)
            ->where('jti !=', $exceptJti)
            ->update(['revoked' => 1, 'revoked_at' => date('Y-m-d H:i:s')]);
        return $db->affectedRows();
    }

    /**
     * Issue new access+refresh from a valid refresh token (rotation).
     */
    public function rotate(string $refreshToken): ?array
    {
        $payload = $this->validateRefresh($refreshToken);
        if (! $payload) {
            return null;
        }

        // Revoke old refresh
        $this->revokeRefresh($payload['jti']);

        $db = \Config\Database::connect();
        $user = $db->table('users')
            ->where('id', $payload['sub'])
            ->where('is_active', 1)
            ->get()
            ->getRowArray();

        if (! $user) {
            return null;
        }

        // Pull primary company assignment for token claims
        $employee = $db->table('employees')->where('user_id', $user['id'])->get()->getRowArray();
        $companyId = $employee['company_id'] ?? $user['company_id'] ?? null;
        $user['employee_id'] = $employee['id'] ?? null;

        return $this->issueTokenPair($user, $companyId);
    }

    // ─── Device tracking helpers (Sprint A / B5) ─────────────────────

    /**
     * Compute fingerprint deterministik dari User-Agent. Tidak bisa di-spoof
     * dengan murah, tapi juga bukan replacement untuk fingerprint client-side
     * (FingerprintJS) — hanya tier server-side default. Caller (controller)
     * boleh override via header `X-Device-Fingerprint` di issue path.
     */
    private function fingerprintDevice(string $userAgent): string
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        // Override dari client kalau ada (lebih akurat)
        $client = (string) ($_SERVER['HTTP_X_DEVICE_FINGERPRINT'] ?? '');
        if ($client !== '') {
            // Trust kalau format sha256 hex (64 char). Kalau bukan, hash.
            return preg_match('/^[a-f0-9]{64}$/i', $client)
                ? strtolower($client)
                : hash('sha256', $client);
        }
        return hash('sha256', $userAgent . '|' . $accept);
    }

    /**
     * Format human-readable device label dari User-Agent.
     * Tidak presisi, tapi cukup untuk UI Active Sessions.
     */
    private function describeDevice(string $ua): string
    {
        if ($ua === '') return 'Unknown device';

        $lower = strtolower($ua);

        // Mobile
        if (str_contains($lower, 'iphone')) return 'iPhone';
        if (str_contains($lower, 'ipad'))   return 'iPad';
        if (str_contains($lower, 'android')) {
            if (str_contains($lower, 'mobile')) return 'Android phone';
            return 'Android tablet';
        }

        // Desktop OS
        $os = match (true) {
            str_contains($lower, 'windows nt 10')   => 'Windows 10/11',
            str_contains($lower, 'windows nt 6.3')  => 'Windows 8.1',
            str_contains($lower, 'windows')         => 'Windows',
            str_contains($lower, 'mac os x')        => 'macOS',
            str_contains($lower, 'linux')           => 'Linux',
            default                                 => 'Desktop',
        };

        $browser = match (true) {
            str_contains($lower, 'edg/')        => 'Edge',
            str_contains($lower, 'chrome/')     => 'Chrome',
            str_contains($lower, 'firefox/')    => 'Firefox',
            str_contains($lower, 'safari/')     => 'Safari',
            str_contains($lower, 'okhttp')      => 'Mobile App',
            default                             => 'Browser',
        };

        return "{$browser} di {$os}";
    }
}
