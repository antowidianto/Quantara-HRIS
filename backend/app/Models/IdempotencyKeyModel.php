<?php

namespace App\Models;

/**
 * IdempotencyKeyModel — Mobile Prep / MOB-3
 *
 * Tabel `idempotency_keys` untuk anti-duplikat POST critical (clock-in,
 * leave create, reimbursement, dll.) dari mobile yang offline. Pola sesuai
 * Stripe API + draft RFC `Idempotency-Key`:
 *
 *   - Mobile generate UUID v4 di sisi client, kirim di header
 *     `Idempotency-Key`.
 *   - Filter `IdempotencyFilter` cek tabel ini sebelum controller jalan.
 *     - First request → state IN_FLIGHT, controller jalan, response disimpan.
 *     - Replay (sebelum response sampai HP) → return 409 + state IN_FLIGHT.
 *     - Replay (sesudah response sampai DB) → return cached response 200/...
 *     - Replay dengan body berbeda (body_hash mismatch) → return 422.
 *
 * Cleanup: cron `IdempotencyKeyCleaner` hapus row yang `expired_at < NOW()`.
 *
 * Field key_hash menyimpan SHA-256 dari Idempotency-Key, bukan plaintext —
 * supaya kalau DB bocor, attacker tidak bisa replay request lewat key user.
 */
class IdempotencyKeyModel extends BaseModel
{
    protected $table         = 'idempotency_keys';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'company_id', 'user_id',
        'key_hash', 'method', 'path', 'request_hash',
        'status_code', 'response_body', 'response_headers',
        'state', 'expired_at',
    ];

    protected bool $multiTenant = true;

    public const STATE_IN_FLIGHT = 'IN_FLIGHT';
    public const STATE_COMPLETED = 'COMPLETED';
    public const STATE_FAILED    = 'FAILED';

    /** TTL default: 24 jam. */
    public const DEFAULT_TTL_HOURS = 24;

    /**
     * Hash key client (UUID atau opaque string) jadi storage-safe.
     */
    public static function hashKey(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /**
     * Hash request body untuk deteksi key reuse dengan body beda.
     * Body yang sudah di-canonicalize (sorted JSON, dll.) jauh lebih
     * akurat tapi untuk MVP cukup hash raw body.
     */
    public static function hashBody(string $body): string
    {
        return hash('sha256', $body);
    }

    /**
     * Cari row by hashed key + tenant.
     *
     * @return array<string,mixed>|null
     */
    public function findByHash(int $companyId, string $keyHash): ?array
    {
        $row = $this->where('company_id', $companyId)
            ->where('key_hash', $keyHash)
            ->first();
        return $row ?: null;
    }

    /**
     * Reservasi key (state IN_FLIGHT). Return id baru, atau false kalau
     * race-condition (duplikat insert) — caller harus retry findByHash.
     *
     * @param array<string,mixed> $data
     */
    public function reserve(array $data): int|false
    {
        try {
            $id = $this->insert(array_merge([
                'state'      => self::STATE_IN_FLIGHT,
                'expired_at' => date('Y-m-d H:i:s', time() + (self::DEFAULT_TTL_HOURS * 3600)),
            ], $data), true);
            return $id === false ? false : (int) $id;
        } catch (\Throwable $e) {
            // Unique constraint hit — race lost, return false supaya caller refetch
            return false;
        }
    }

    /**
     * Tulis hasil response setelah controller selesai.
     *
     * @param array<string,string> $headers
     */
    public function complete(int $id, int $statusCode, string $body, array $headers = []): void
    {
        $this->update($id, [
            'state'            => $statusCode >= 400 ? self::STATE_FAILED : self::STATE_COMPLETED,
            'status_code'      => $statusCode,
            'response_body'    => $body,
            'response_headers' => json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Hapus key expired. Dipakai cron cleanup harian.
     */
    public function purgeExpired(): int
    {
        $this->where('expired_at <', date('Y-m-d H:i:s'))
            ->delete();
        return (int) $this->db->affectedRows();
    }
}