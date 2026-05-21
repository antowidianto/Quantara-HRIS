<?php

namespace App\Libraries\FileStorage;

/**
 * FileStorageInterface — abstraksi storage backend untuk file user-uploaded.
 *
 * Driver yang akan tersedia:
 *   - LocalDriver  → writable/uploads (default, sekarang)
 *   - S3Driver     → AWS S3 / MinIO / Spaces (future, lihat AUDIT.md C.5)
 *
 * Setiap driver wajib idempotent untuk delete (tidak throw kalau file sudah hilang).
 */
interface FileStorageInterface
{
    /**
     * Simpan binary content ke storage path tertentu.
     *
     * @param string $relativePath Path relatif dari root storage, contoh:
     *                              `selfies/123/20250520_120000_abc.jpg`.
     * @param string $contents Binary string (sudah base64-decoded jika perlu).
     * @return bool True kalau sukses tulis.
     */
    public function put(string $relativePath, string $contents): bool;

    /**
     * Baca binary content. Return null kalau file tidak ada.
     */
    public function get(string $relativePath): ?string;

    /**
     * Cek keberadaan file.
     */
    public function exists(string $relativePath): bool;

    /**
     * Hapus file. Idempotent — return true meskipun file sudah tidak ada.
     */
    public function delete(string $relativePath): bool;

    /**
     * Generate URL singkat-tempo (signed) yang bisa diakses tanpa auth header.
     * Pakai untuk render <img> di Vue tanpa harus include JWT.
     *
     * Local driver: return path dengan token HMAC + expiry di query string.
     * S3 driver:    return signed URL bawaan AWS.
     *
     * @param int $expirySeconds Default 5 menit
     */
    public function signedUrl(string $relativePath, int $expirySeconds = 300): string;

    /**
     * Verifikasi token signed URL valid (HMAC + belum expired).
     */
    public function verifySignature(string $relativePath, string $token, int $expiresAt): bool;

    /**
     * Ukuran file dalam byte. Return 0 kalau tidak ada.
     */
    public function size(string $relativePath): int;

    /**
     * Mtime UNIX timestamp. 0 kalau tidak ada.
     */
    public function mtime(string $relativePath): int;
}