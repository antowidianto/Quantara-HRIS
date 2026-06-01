<?php

namespace App\Libraries\Webhook;

use Config\Services;

/**
 * InboundWebhookHandler — registry + verifikasi untuk webhook inbound.
 *
 * Counterpart dari `WebhookDispatcher` (outbound). Sistem external (mis.
 * recruitment marketplace, accounting tool, partner HRIS) kirim event ke
 * HRIS lewat HTTP POST yang sudah ditandatangani HMAC-SHA256.
 *
 * Pattern registry:
 *   service('inbound')->register('candidate.applied', function (array $payload, array $source) {
 *       // logic apply candidate ke RecruitmentService
 *       return ['status' => 'PROCESSED', 'candidate_id' => $id];
 *   });
 *
 *   // Saat receive request:
 *   $result = service('inbound')->dispatch($eventType, $payload, $sourceRow);
 *
 * Tanpa handler terdaftar:
 *   - Event tetap dicatat di `inbound_webhook_events` (audit + replay)
 *   - Status 'RECEIVED' (bukan PROCESSED) dengan http_status 202 Accepted
 *   - Bisa di-reprocess manual nanti via admin tool / spark command
 *
 * Verifikasi signature:
 *   - Pakai pattern sama dengan outbound (`WebhookSigner::verify`):
 *     header `X-HRIS-Signature: sha256=<hex>` + `X-HRIS-Timestamp: <unix>`
 *   - Replay protection: tolak request kalau timestamp > 5 menit
 *   - Idempotency: source_event_id (dari header X-HRIS-Source-Event atau
 *     payload `event_id`) di-UPSERT supaya retry consumer aman
 *
 * Multi-tenant: setiap inbound source scoped ke company. URL endpoint
 * include slug yang resolve ke company_id (registered di tabel
 * `inbound_webhooks`).
 */
class InboundWebhookHandler
{
    /**
     * Registry global handler, diisi via `register()`.
     *
     * @var array<string, callable[]>
     */
    private array $handlers = [];

    /**
     * Daftarkan callable handler untuk event type tertentu. Multiple handler
     * boleh terdaftar untuk event yang sama — semua akan dijalankan
     * sekuensial; kalau salah satu throw, status FAILED tapi yang lain
     * tetap eksekusi (best-effort).
     */
    public function register(string $eventType, callable $handler): void
    {
        $this->handlers[$eventType] ??= [];
        $this->handlers[$eventType][] = $handler;
    }

    /**
     * Apakah ada handler terdaftar untuk event ini?
     */
    public function hasHandler(string $eventType): bool
    {
        return ! empty($this->handlers[$eventType]);
    }

    /**
     * Dispatch event ke semua handler terdaftar. Return array status per
     * handler. Tidak pernah throw — controller bisa langsung pakai untuk
     * decide HTTP status balasan.
     *
     * @param string $eventType
     * @param array  $payload   Event body yang sudah di-decode dari JSON
     * @param array  $source    Row dari `inbound_webhooks` (untuk context)
     * @return array{processed:int, failed:int, errors:string[]}
     */
    public function dispatch(string $eventType, array $payload, array $source): array
    {
        $processed = 0;
        $failed    = 0;
        $errors    = [];

        if (! $this->hasHandler($eventType)) {
            return ['processed' => 0, 'failed' => 0, 'errors' => []];
        }

        foreach ($this->handlers[$eventType] as $handler) {
            try {
                $handler($payload, $source);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $e->getMessage();
                if (function_exists('log_message')) {
                    log_message('warning', sprintf(
                        'Inbound webhook handler failed for %s (source=%s): %s',
                        $eventType,
                        $source['slug'] ?? '?',
                        $e->getMessage(),
                    ));
                }
            }
        }

        return ['processed' => $processed, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Verifikasi signature inbound + replay window. Pakai pattern
     * sama dengan outbound — secret di-decrypt dari `inbound_webhooks.secret_enc`.
     *
     * Return null kalau lolos, string error message kalau gagal.
     */
    public function verifySignature(
        array $source,
        string $rawBody,
        string $signatureHeader,
        ?string $timestampHeader,
        ?int $now = null,
    ): ?string {
        if ($signatureHeader === '' || $timestampHeader === null || $timestampHeader === '') {
            return 'Missing signature or timestamp header';
        }

        $secret = $this->decryptSecret($source['secret_enc'] ?? '');
        if ($secret === '') {
            return 'Source secret invalid (decrypt failed)';
        }

        $ts = (int) $timestampHeader;
        if ($ts <= 0) {
            return 'Invalid timestamp header';
        }

        $valid = WebhookSigner::verify(
            $rawBody,
            $signatureHeader,
            $ts,
            $secret,
            $now,
        );

        return $valid ? null : 'Signature mismatch or timestamp out of window';
    }

    /**
     * Cek IP allowlist (CIDR / exact). Return true kalau tidak ada filter
     * atau IP cocok. False kalau di-filter & tidak match.
     */
    public function ipAllowed(array $source, string $remoteIp): bool
    {
        $rawList = $source['allowed_ips'] ?? null;
        if ($rawList === null || $rawList === '') return true;
        $list = json_decode((string) $rawList, true);
        if (! is_array($list) || empty($list)) return true;

        foreach ($list as $entry) {
            if (! is_string($entry) || $entry === '') continue;
            if ($entry === $remoteIp) return true;
            if (str_contains($entry, '/') && $this->ipInCidr($remoteIp, $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pure CIDR match for IPv4. Return false untuk IPv6 atau format invalid
     * (controller akan skip request — fail-closed lebih aman).
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) return false;
        [$subnet, $maskStr] = explode('/', $cidr, 2);
        $mask = (int) $maskStr;
        if ($mask < 0 || $mask > 32) return false;

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;

        $shift = 32 - $mask;
        if ($shift === 32) return true; // /0 = match all
        $maskLong = ~((1 << $shift) - 1) & 0xFFFFFFFF;

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * Decrypt source secret dengan graceful fallback.
     */
    private function decryptSecret(string $encrypted): string
    {
        if ($encrypted === '') return '';
        try {
            $plain = Services::encryption()->decrypt($encrypted);
            return is_string($plain) ? $plain : '';
        } catch (\Throwable $e) {
            if (function_exists('log_message')) {
                log_message('warning', 'InboundWebhookHandler: secret decrypt failed: ' . $e->getMessage());
            }
            return '';
        }
    }
}