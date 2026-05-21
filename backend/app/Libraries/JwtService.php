<?php

namespace App\Libraries;

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
        $db->table('refresh_tokens')->insert([
            'jti'        => $refreshJti,
            'user_id'    => (int) $user['id'],
            'expires_at' => date('Y-m-d H:i:s', $now + $this->config->refreshTtl),
            'created_at' => date('Y-m-d H:i:s', $now),
            'revoked'    => 0,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

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
     */
    public function validateRefresh(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->config->refreshSecret, $this->config->algo));
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

            return $payload;
        } catch (\Throwable $e) {
            return null;
        }
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
}