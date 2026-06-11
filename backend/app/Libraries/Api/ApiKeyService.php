<?php

namespace App\Libraries\Api;

use Config\Database;

/**
 * H3.8 — API Key Service.
 *
 * Lifecycle API key untuk integrasi enterprise:
 *  - generate: hasilkan plain key (sekali tampil) + simpan SHA-256 hash
 *  - verify  : terima header Authorization → resolve api_key + scope
 *  - rotate  : revoke key lama, buat baru dengan scope sama
 *  - revoke  : marker non-destructive (tidak boleh hard-delete demi audit)
 *  - log     : append api_request_logs untuk rate-limit + analytics
 *
 * Format plain key: `qhk_{env}_{32-char-base32}` (contoh:
 *   qhk_live_4f8a2c1d3e9b7c6a5f0e8d4c2b3a9f7e). Prefix `qhk_live_` (12
 *   char) disimpan plaintext untuk identifikasi cepat di UI; sisa
 *   karakter di-hash.
 */
class ApiKeyService
{
    public const TIERS = [
        'BASIC'      => 60,    // 60 req/min
        'PRO'        => 300,   // 300 req/min
        'ENTERPRISE' => 1200,  // 1200 req/min
    ];

    private const KEY_PREFIX_LIVE    = 'qhk_live_';
    private const KEY_PREFIX_SANDBOX = 'qhk_sand_';

    /**
     * Generate API key baru.
     * IMPORTANT: plain key hanya dikembalikan SEKALI di response. Setelah
     * itu hanya hash yang tersimpan di DB.
     *
     * @return array{
     *   id: int,
     *   plain_key: string,
     *   key_prefix: string,
     *   tier: string,
     *   rate_limit_per_minute: int,
     * }
     */
    public function generate(
        int $companyId,
        int $userId,
        string $name,
        array $scopes,
        string $tier = 'BASIC',
        bool $isSandbox = false,
        ?string $expiresAt = null
    ): array {
        if (! isset(self::TIERS[$tier])) {
            throw new \InvalidArgumentException('tier tidak valid');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) < 3) {
            throw new \InvalidArgumentException('name minimal 3 karakter');
        }
        $scopes = self::sanitizeScopesPure($scopes);
        if ($scopes === []) {
            throw new \InvalidArgumentException('scopes minimal 1');
        }

        $prefix    = $isSandbox ? self::KEY_PREFIX_SANDBOX : self::KEY_PREFIX_LIVE;
        $randomTok = self::randomBase32(40);
        $plain     = $prefix . $randomTok;
        $keyPrefix = substr($plain, 0, 16); // contoh: qhk_live_4f8a2c1d
        $hash      = hash('sha256', $plain);

        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $row = [
            'company_id'            => $companyId,
            'name'                  => mb_substr($name, 0, 200),
            'key_prefix'            => $keyPrefix,
            'key_hash'              => $hash,
            'tier'                  => $tier,
            'rate_limit_per_minute' => self::TIERS[$tier],
            'is_sandbox'            => $isSandbox ? 1 : 0,
            'expires_at'            => $expiresAt,
            'created_by_user_id'    => $userId,
            'created_at'            => $now,
            'updated_at'            => $now,
        ];
        $db->table('api_keys')->insert($row);
        $id = (int) $db->insertID();

        // Insert scopes
        $scopeRows = [];
        foreach ($scopes as $s) {
            $scopeRows[] = ['api_key_id' => $id, 'scope' => $s, 'created_at' => $now];
        }
        if ($scopeRows !== []) {
            $db->table('api_key_scopes')->insertBatch($scopeRows);
        }

        return [
            'id'                    => $id,
            'plain_key'             => $plain,
            'key_prefix'            => $keyPrefix,
            'tier'                  => $tier,
            'rate_limit_per_minute' => self::TIERS[$tier],
        ];
    }

    /**
     * Verifikasi plain key dari header `Authorization: Bearer ...`.
     * Return null kalau invalid / revoked / expired.
     *
     * @return array{
     *   id: int,
     *   company_id: int,
     *   tier: string,
     *   rate_limit_per_minute: int,
     *   is_sandbox: int,
     *   scopes: string[],
     * }|null
     */
    public function verify(string $plain): ?array
    {
        if (! self::isValidKeyFormat($plain)) return null;
        $hash = hash('sha256', $plain);
        $db = Database::connect();
        $row = $db->table('api_keys')->where('key_hash', $hash)->get()->getRowArray();
        if (! $row) return null;
        if (! self::isUsablePure($row)) return null;

        $scopes = array_column(
            $db->table('api_key_scopes')->where('api_key_id', $row['id'])->get()->getResultArray(),
            'scope'
        );

        return [
            'id'                    => (int) $row['id'],
            'company_id'            => (int) $row['company_id'],
            'tier'                  => (string) $row['tier'],
            'rate_limit_per_minute' => (int) $row['rate_limit_per_minute'],
            'is_sandbox'            => (int) ($row['is_sandbox'] ?? 0),
            'scopes'                => $scopes,
        ];
    }

    /**
     * Rotate key: revoke yang lama, buat key baru dengan scope yang sama.
     * Plain key baru dikembalikan sekali.
     */
    public function rotate(int $apiKeyId, int $userId, ?string $reason = null): array
    {
        $db = Database::connect();
        $row = $db->table('api_keys')->where('id', $apiKeyId)->get()->getRowArray();
        if (! $row) {
            throw new \RuntimeException('API key tidak ditemukan');
        }
        $scopes = array_column(
            $db->table('api_key_scopes')->where('api_key_id', $apiKeyId)->get()->getResultArray(),
            'scope'
        );
        $this->revoke($apiKeyId, $reason ?? 'Rotated by ' . $userId);
        return $this->generate(
            (int) $row['company_id'],
            $userId,
            (string) $row['name'] . ' (rotated)',
            $scopes,
            (string) $row['tier'],
            (bool) ($row['is_sandbox'] ?? 0),
            $row['expires_at'] ?: null,
        );
    }

    /**
     * Revoke key (soft, audit-friendly).
     */
    public function revoke(int $apiKeyId, ?string $reason = null): void
    {
        Database::connect()->table('api_keys')
            ->where('id', $apiKeyId)
            ->update([
                'revoked_at'     => date('Y-m-d H:i:s'),
                'revoked_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Append request log + update last_used_at. Filter calls ini dari
     * ApiKeyAuthFilter setelah verify sukses (atau dari rate limit reject).
     */
    public function logRequest(
        int $companyId,
        int $apiKeyId,
        string $endpoint,
        string $method,
        int $statusCode,
        int $durationMs,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $requestId = null,
        bool $rateLimited = false
    ): void {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('api_request_logs')->insert([
            'company_id'   => $companyId,
            'api_key_id'   => $apiKeyId,
            'endpoint'     => mb_substr($endpoint, 0, 255),
            'method'       => mb_substr($method, 0, 10),
            'status_code'  => $statusCode,
            'duration_ms'  => $durationMs,
            'ip_address'   => $ip,
            'user_agent'   => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'request_id'   => $requestId !== null ? mb_substr($requestId, 0, 64) : null,
            'rate_limited' => $rateLimited ? 1 : 0,
            'created_at'   => $now,
        ]);
        $db->table('api_keys')->where('id', $apiKeyId)->update([
            'last_used_at' => $now,
            'last_used_ip' => $ip,
            'updated_at'   => $now,
        ]);
    }

    /**
     * Hitung request count untuk window 60 detik terakhir (rate limit).
     */
    public function countRecentRequests(int $apiKeyId, int $windowSeconds = 60): int
    {
        $db = Database::connect();
        $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
        return (int) $db->table('api_request_logs')
            ->where('api_key_id', $apiKeyId)
            ->where('created_at >=', $cutoff)
            ->countAllResults();
    }

    // ─── PURE HELPERS (untuk unit test) ───────────────────────────────

    /**
     * Validasi format plain key.
     */
    public static function isValidKeyFormat(string $plain): bool
    {
        if (! preg_match('/^qhk_(live|sand)_[A-Za-z0-9]{30,80}$/', $plain)) {
            return false;
        }
        return true;
    }

    /**
     * Cek apakah row api_keys masih usable (tidak revoked, tidak expired).
     *
     * @param array<string, mixed> $row
     */
    public static function isUsablePure(array $row): bool
    {
        if (! empty($row['revoked_at'])) return false;
        if (! empty($row['expires_at'])) {
            $exp = strtotime((string) $row['expires_at']);
            if ($exp !== false && $exp < time()) return false;
        }
        return true;
    }

    /**
     * Sanitize scope list: dedupe, lowercase, valid pattern only.
     *
     * @param string[] $scopes
     * @return string[]
     */
    public static function sanitizeScopesPure(array $scopes): array
    {
        $clean = [];
        foreach ($scopes as $s) {
            $s = trim((string) $s);
            if ($s === '') continue;
            if (! preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $s)) continue;
            $clean[$s] = true;
        }
        return array_keys($clean);
    }

    /**
     * Cek apakah `requested` ada di whitelist `granted` (exact match).
     *
     * @param string[] $granted
     */
    public static function hasScopePure(array $granted, string $requested): bool
    {
        return in_array($requested, $granted, true);
    }

    /**
     * Random base32-like string (a-z, 0-9 lowercase).
     */
    private static function randomBase32(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}