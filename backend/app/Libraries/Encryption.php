<?php

namespace App\Libraries;

/**
 * Encryption — AES-256-GCM untuk PII at-rest (NIK, NPWP, rekening bank, dst.).
 *
 * Version-aware ciphertext format
 * --------------------------------
 *   v1 (legacy, no prefix):  base64(iv[12] | tag[16] | ct[N])
 *   v2+ (versioned):         "v2:base64(iv[12] | tag[16] | ct[N])"
 *
 * Saat decrypt, prefix `vX:` di-extract → key versi-X dipakai. Tidak ada prefix
 * → diasumsikan v1 (backward compat). Saat encrypt, library selalu pakai versi
 * "active" yang dideklarasikan via env `encryption.active_version` (default v1).
 *
 * Multi-key
 * ---------
 *   encryption.key       = key versi-1 (legacy default)
 *   encryption.key_v2    = key versi-2 (saat rotasi)
 *   encryption.key_v3    = key versi-3, dst.
 *   encryption.active_version = "v2"  (versi yang dipakai untuk encrypt baru)
 *
 * Saat rotasi (hr:rotate-encryption command):
 *   1. Generate key v2, taruh di env `encryption.key_v2=base64:...`
 *   2. Set `encryption.active_version = v2`
 *   3. Run `php spark hr:rotate-encryption` — re-encrypt baris dengan v1
 *      pakai v2, bump kolom `key_version` di tabel.
 *   4. Setelah semua row migrated, key v1 boleh di-disable (decrypt tidak akan
 *      panggil v1 lagi). Untuk audit, simpan v1 di vault offline minimal 1 thn.
 *
 * Lihat docs/AUDIT.md item B.
 */
class Encryption
{
    /** @var array<string,string> Map versi → raw 32-byte key */
    private array $keys = [];
    private string $activeVersion;

    public function __construct()
    {
        // Versi 1 — wajib ada (kompatibilitas lama). Pakai env utama.
        $this->keys['v1'] = $this->resolveKey((string) env('encryption.key', ''));

        // Versi tambahan (v2..v9) — opt-in. Dideclare hanya saat rotasi.
        for ($i = 2; $i <= 9; $i++) {
            $raw = (string) env("encryption.key_v{$i}", '');
            if ($raw !== '') {
                $this->keys["v{$i}"] = $this->resolveKey($raw);
            }
        }

        // Versi aktif default = highest version yang available.
        $declared = (string) env('encryption.active_version', '');
        if ($declared !== '' && isset($this->keys[$declared])) {
            $this->activeVersion = $declared;
        } else {
            $this->activeVersion = array_key_last($this->keys) ?: 'v1';
        }
    }

    public function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt(
            $plaintext, 'aes-256-gcm',
            $this->keys[$this->activeVersion],
            OPENSSL_RAW_DATA, $iv, $tag,
        );
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed');
        }
        $body = base64_encode($iv . $tag . $ct);

        // Versi 1 tidak diberi prefix supaya ciphertext lama tetap kompatibel
        // dengan kolom yang sudah ada dan tidak perlu bulk re-write saat upgrade.
        return $this->activeVersion === 'v1' ? $body : "{$this->activeVersion}:{$body}";
    }

    public function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }

        // Parse versi dari prefix "vN:..." kalau ada. Tanpa prefix → v1.
        $version = 'v1';
        $body    = $cipher;
        if (preg_match('/^(v[1-9]):(.+)$/s', $cipher, $m)) {
            $version = $m[1];
            $body    = $m[2];
        }

        if (! isset($this->keys[$version])) {
            // Versi key tidak tersedia di runtime — biasanya berarti data
            // dari rotasi sebelumnya yang key-nya sudah di-revoke. Return
            // null daripada throw supaya UI tidak crash, tapi tetap audit.
            log_message('warning', "Encryption: ciphertext versi {$version} tapi key tidak tersedia");
            return null;
        }

        $raw = base64_decode($body, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt(
            $ct, 'aes-256-gcm',
            $this->keys[$version],
            OPENSSL_RAW_DATA, $iv, $tag,
        );
        return $pt === false ? null : $pt;
    }

    /**
     * Hash deterministik untuk lookup index (NIK → cari karyawan tanpa decrypt).
     * Tidak terpengaruh rotasi key supaya index tidak perlu di-rebuild.
     */
    public function hash(string $value): string
    {
        return hash('sha256', $value . '_hris_lookup');
    }

    /** Versi key yang dipakai untuk encrypt baru. */
    public function activeVersion(): string
    {
        return $this->activeVersion;
    }

    /** Daftar versi key yang tersedia di runtime. */
    public function availableVersions(): array
    {
        return array_keys($this->keys);
    }

    /**
     * Cek apakah ciphertext perlu di-rotate (versinya bukan active).
     * Dipakai oleh hr:rotate-encryption command.
     */
    public function needsRotation(?string $cipher): bool
    {
        if ($cipher === null || $cipher === '') return false;
        $version = 'v1';
        if (preg_match('/^(v[1-9]):/', $cipher, $m)) {
            $version = $m[1];
        }
        return $version !== $this->activeVersion;
    }

    private function resolveKey(string $raw): string
    {
        if ($raw === '') {
            // Fallback supaya app tidak crash di dev tanpa key — derive dari
            // string kosong. Tidak aman, tapi cukup untuk dev loopback.
            return hash('sha256', 'hris_dev_no_key', true);
        }
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded !== false && strlen($decoded) >= 32) {
                return $decoded;
            }
        }
        if (strlen($raw) >= 32) {
            return $raw;
        }
        // Pad pendek string supaya jadi 32-byte deterministik (dev only).
        return hash('sha256', $raw . '_hris_pad', true);
    }
}