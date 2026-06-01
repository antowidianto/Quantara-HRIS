<?php

namespace App\Libraries\Auth;

/**
 * TwoFactorBackupCodes
 *
 * Generate, hash, dan verifikasi kode backup 2FA. Single-use codes untuk
 * recovery saat user kehilangan device authenticator (HP hilang, app
 * di-reset, dll).
 *
 * Format kode: 8 grup × 5 karakter alfanumerik (A-Z0-9) dipisah strip,
 * mis. "A8K2N-Q3R7M". Total 10 karakter content + 1 strip → 11 char.
 * Entropi ~50 bit per kode (>10^15 kombinasi) — secure cukup untuk one-shot.
 *
 * Storage: hash dengan `password_hash()` Argon2id sama seperti password
 * user — equal-cost untuk attacker, dan tidak bisa di-reverse jika DB bocor.
 *
 * Lifecycle:
 *  1. `generate()` — return list<{code: string, hash: string}>
 *     User shown plaintext SEKALI saat enable 2FA atau regenerate.
 *     Backend simpan hash saja.
 *  2. `verify($userId, $inputCode)` — cek dan tandai used kalau match.
 *     Return true sekali per kode; subsequent attempt gagal.
 *  3. `remainingCount($userId)` — jumlah kode aktif (untuk UI alert
 *     "tinggal 2 kode tersisa").
 *  4. `regenerate($userId)` — invalidate semua kode lama dan generate set baru.
 *
 * Tabel `twofa_backup_codes` schema:
 *   id PK, user_id FK, code_hash VARCHAR(255), used_at DATETIME NULL,
 *   created_at DATETIME, INDEX (user_id, used_at)
 */
class TwoFactorBackupCodes
{
    /** Banyak kode yang di-generate sekali. Industry standard 8-10. */
    public const COUNT = 8;

    /** Karakter pool kode — exclude 0/O/1/I yang mudah salah baca. */
    private const POOL = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Generate N kode plaintext + hash. Caller bertanggung jawab simpan
     * hash ke DB dan show plaintext sekali ke user.
     *
     * @return array<int, array{code: string, hash: string}>
     */
    public function generate(int $count = self::COUNT): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $plain = $this->randomCode();
            $codes[] = [
                'code' => $plain,
                'hash' => password_hash($plain, PASSWORD_ARGON2ID),
            ];
        }
        return $codes;
    }

    /**
     * Save list of hashed codes untuk user. Idempotent — replace existing
     * codes (yang regenerate).
     *
     * @param array<int, array{code: string, hash: string}> $codes
     */
    public function persist(int $userId, array $codes): void
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('twofa_backup_codes')) return;

        $db->transStart();
        // Invalidate semua kode lama
        $db->table('twofa_backup_codes')->where('user_id', $userId)->delete();

        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($codes as $c) {
            $rows[] = [
                'user_id'    => $userId,
                'code_hash'  => $c['hash'],
                'used_at'    => null,
                'created_at' => $now,
            ];
        }
        if (! empty($rows)) {
            $db->table('twofa_backup_codes')->insertBatch($rows);
        }
        $db->transComplete();
    }

    /**
     * Verifikasi kode user. Kalau match dan belum dipakai, tandai used
     * dan return true. Kalau tidak match atau sudah dipakai, return false.
     *
     * Normalisasi: spasi/strip dihapus, case-insensitive.
     */
    public function verify(int $userId, string $inputCode): bool
    {
        $normalized = $this->normalize($inputCode);
        if ($normalized === '') return false;

        $db = \Config\Database::connect();
        if (! $db->tableExists('twofa_backup_codes')) return false;

        $rows = $db->table('twofa_backup_codes')
            ->where('user_id', $userId)
            ->where('used_at IS NULL')
            ->get()
            ->getResultArray();

        foreach ($rows as $r) {
            if (password_verify($normalized, (string) $r['code_hash'])) {
                $db->table('twofa_backup_codes')
                    ->where('id', (int) $r['id'])
                    ->update(['used_at' => date('Y-m-d H:i:s')]);
                return true;
            }
        }
        return false;
    }

    /**
     * Jumlah kode aktif (belum digunakan).
     */
    public function remainingCount(int $userId): int
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('twofa_backup_codes')) return 0;

        return $db->table('twofa_backup_codes')
            ->where('user_id', $userId)
            ->where('used_at IS NULL')
            ->countAllResults();
    }

    /**
     * Regenerate set kode baru — invalidate yang lama, generate yang baru,
     * dan return list<plaintext> untuk ditampilkan SEKALI ke user.
     *
     * @return string[]
     */
    public function regenerate(int $userId, int $count = self::COUNT): array
    {
        $codes = $this->generate($count);
        $this->persist($userId, $codes);
        return array_map(fn($c) => $c['code'], $codes);
    }

    /**
     * Normalisasi input user — hapus spasi/strip, uppercase.
     * Public agar caller bisa display ke user "kode setelah dinormalisasi".
     */
    public function normalize(string $input): string
    {
        return strtoupper(preg_replace('/[\s\-_]+/', '', $input) ?? '');
    }

    /**
     * Generate kode random 10 karakter dipecah jadi "XXXXX-XXXXX".
     * Pakai random_int untuk CSPRNG-grade randomness.
     */
    private function randomCode(): string
    {
        $poolLen = strlen(self::POOL);
        $first  = '';
        $second = '';
        for ($i = 0; $i < 5; $i++) {
            $first  .= self::POOL[random_int(0, $poolLen - 1)];
            $second .= self::POOL[random_int(0, $poolLen - 1)];
        }
        return $first . '-' . $second;
    }
}