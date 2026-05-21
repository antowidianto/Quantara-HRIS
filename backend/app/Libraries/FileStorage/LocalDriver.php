<?php

namespace App\Libraries\FileStorage;

/**
 * LocalDriver — implementasi FileStorage di filesystem lokal (writable/uploads).
 *
 * Signed URL pakai HMAC-SHA256 dengan secret dari app encryption key, supaya
 * URL `?token=...&expires=...` tidak bisa di-forge dan otomatis kadaluarsa.
 *
 * Future: ada `S3Driver` yang implement interface yang sama.
 */
class LocalDriver implements FileStorageInterface
{
    private string $rootPath;
    private string $signSecret;
    private string $publicUrlPrefix;

    public function __construct(?string $rootPath = null, ?string $signSecret = null)
    {
        $this->rootPath = rtrim($rootPath ?? (WRITEPATH . 'uploads'), DIRECTORY_SEPARATOR);
        // Pakai encryption.key sebagai source HMAC secret. Tidak boleh share
        // ke client. Strip prefix `base64:` kalau ada.
        $key = (string) ($signSecret ?? env('encryption.key', ''));
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }
        $this->signSecret = $key !== '' ? $key : 'local-dev-fallback-secret-change-in-prod';

        // Endpoint serve di /api/v1/files/serve, lihat FileServeController
        $this->publicUrlPrefix = '/api/v1/files/serve';
    }

    public function put(string $relativePath, string $contents): bool
    {
        $full = $this->fullPath($relativePath);
        $dir = dirname($full);
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            return false;
        }
        return file_put_contents($full, $contents) !== false;
    }

    public function get(string $relativePath): ?string
    {
        $full = $this->fullPath($relativePath);
        if (! is_file($full)) return null;
        $bytes = @file_get_contents($full);
        return $bytes === false ? null : $bytes;
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->fullPath($relativePath));
    }

    public function delete(string $relativePath): bool
    {
        $full = $this->fullPath($relativePath);
        if (! is_file($full)) return true; // idempotent
        return @unlink($full);
    }

    public function size(string $relativePath): int
    {
        $full = $this->fullPath($relativePath);
        if (! is_file($full)) return 0;
        return (int) @filesize($full);
    }

    public function mtime(string $relativePath): int
    {
        $full = $this->fullPath($relativePath);
        if (! is_file($full)) return 0;
        return (int) @filemtime($full);
    }

    public function signedUrl(string $relativePath, int $expirySeconds = 300): string
    {
        $expiresAt = time() + $expirySeconds;
        $token = $this->sign($relativePath, $expiresAt);
        $query = http_build_query([
            'path'    => $relativePath,
            'expires' => $expiresAt,
            'token'   => $token,
        ]);
        return $this->publicUrlPrefix . '?' . $query;
    }

    public function verifySignature(string $relativePath, string $token, int $expiresAt): bool
    {
        if ($expiresAt < time()) return false;
        $expected = $this->sign($relativePath, $expiresAt);
        return hash_equals($expected, $token);
    }

    /** Convert relative path → absolute path dengan path traversal guard. */
    private function fullPath(string $relativePath): string
    {
        // Normalize separator dan blokir `..`
        $rel = str_replace(['\\', '..'], ['/', ''], $relativePath);
        $rel = ltrim($rel, '/');
        return $this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    private function sign(string $relativePath, int $expiresAt): string
    {
        return hash_hmac('sha256', $relativePath . '|' . $expiresAt, $this->signSecret);
    }
}