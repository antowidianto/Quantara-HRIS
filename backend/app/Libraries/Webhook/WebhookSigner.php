<?php

namespace App\Libraries\Webhook;

/**
 * WebhookSigner — pure HMAC-SHA256 signing untuk webhook outbound.
 *
 * Pattern signing mengikuti GitHub/Stripe convention:
 *   Header: X-HRIS-Signature: sha256=<hex>
 *           X-HRIS-Timestamp: <unix>
 *           X-HRIS-Event:     leave.approved
 *
 * Signing string: `{timestamp}.{raw_body}` — timestamp dibutuhkan untuk
 * mitigasi replay attack (consumer reject kalau timestamp > 5 menit lama).
 *
 * Verifikasi di consumer side (untuk dokumentasi consumer):
 *   1. Cek `X-HRIS-Timestamp` masih dalam window 5 menit
 *   2. Compute expected = hmac_sha256("{ts}.{body}", secret)
 *   3. Compare dengan `X-HRIS-Signature` (split prefix `sha256=`) via
 *      timing-safe comparison (hash_equals di PHP, crypto.timingSafeEqual di Node)
 *
 * Pure class — tidak ada dependency CodeIgniter, mudah unit-test.
 */
class WebhookSigner
{
    /** Window toleransi clock skew + delivery delay. */
    public const REPLAY_TOLERANCE_SECONDS = 300;

    /**
     * Sign body dengan secret. Return array berisi 3 header values
     * yang harus dikirim bersama request.
     *
     * @param string      $rawBody  Body request (JSON string biasanya)
     * @param string      $secret   Per-webhook secret (decrypted dari secret_enc)
     * @param string      $eventType  Mis. "leave.approved"
     * @param int|null    $timestamp Unix timestamp (default: time())
     * @return array{signature:string, timestamp:int, event:string}
     */
    public static function sign(
        string $rawBody,
        string $secret,
        string $eventType,
        ?int $timestamp = null,
    ): array {
        $ts = $timestamp ?? time();
        $payload   = $ts . '.' . $rawBody;
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return [
            'signature' => $signature,
            'timestamp' => $ts,
            'event'     => $eventType,
        ];
    }

    /**
     * Verify incoming request signature (untuk simulasi dan testing).
     * Production consumer sebaiknya implement verifikasi sendiri di runtime
     * masing-masing — method ini ada untuk testing + dokumentasi.
     *
     * @param string $rawBody          Body diterima
     * @param string $signatureHeader  X-HRIS-Signature value (`sha256=...`)
     * @param int    $timestampHeader  X-HRIS-Timestamp value (unix int)
     * @param string $secret           Shared secret
     * @param int    $now              Override current time untuk test
     */
    public static function verify(
        string $rawBody,
        string $signatureHeader,
        int $timestampHeader,
        string $secret,
        ?int $now = null,
    ): bool {
        // 1) Replay protection — reject kalau timestamp di luar window
        $now ??= time();
        if (abs($now - $timestampHeader) > self::REPLAY_TOLERANCE_SECONDS) {
            return false;
        }

        // 2) Compute expected signature
        $expected = 'sha256=' . hash_hmac(
            'sha256',
            $timestampHeader . '.' . $rawBody,
            $secret,
        );

        // 3) Timing-safe comparison
        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Generate cryptographically secure random secret (untuk webhook baru).
     * 32 bytes (256 bit) di-encode hex → 64 char string.
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}