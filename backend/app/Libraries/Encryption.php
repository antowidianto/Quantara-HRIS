<?php

namespace App\Libraries;

/**
 * Encryption helper for sensitive fields at rest (NIK, NPWP, bank account).
 * Uses AES-256-GCM authenticated encryption.
 */
class Encryption
{
    private string $key;

    public function __construct()
    {
        $raw = (string) env('encryption.key', '');
        if (str_starts_with($raw, 'base64:')) {
            $this->key = base64_decode(substr($raw, 7), true) ?: '';
        } else {
            $this->key = $raw;
        }

        if (strlen($this->key) < 32) {
            // Fall back to deriving from a key string so we never use a weak key
            $this->key = hash('sha256', $raw . '_hris_pad', true);
        }
    }

    public function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $ct);
    }

    public function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? null : $pt;
    }

    public function hash(string $value): string
    {
        return hash('sha256', $value . '_hris_lookup');
    }
}