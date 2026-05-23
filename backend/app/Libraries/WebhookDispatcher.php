<?php

namespace App\Libraries;

use App\Constants\WebhookEvent;
use Config\Services;

/**
 * WebhookDispatcher — orchestrate webhook outbound delivery.
 *
 * Pattern:
 *   1. `dispatch($eventType, $companyId, $payload)` — query webhooks table
 *      yang subscribe ke event ini di company tsb, INSERT row di
 *      `webhook_deliveries` dengan status QUEUED. Return jumlah enqueued.
 *   2. Cron job `php spark webhooks:process` panggil `processQueue()`
 *      yang ambil row QUEUED/FAILED siap retry, kirim HTTP POST,
 *      update status berdasar response.
 *
 * Retry: pakai `EmailQueueBackoff` (1m → 5m → 30m → 2h → 6h, max 5 attempts).
 * Setelah gagal final, increment `webhooks.consecutive_failures`. Kalau ≥ N
 * (default 10) → auto-disable webhook (`is_active=0`) + alert admin.
 *
 * Signing: `WebhookSigner::sign()` produce header X-HRIS-Signature/Timestamp/Event.
 * Secret di-decrypt dari `webhooks.secret_enc` lewat `Services::encryption()`.
 *
 * HTTP client: `\Config\Services::curlrequest()` dengan timeout 10s.
 * Response body di-truncate ke 1KB untuk audit (privacy + storage).
 */
class WebhookDispatcher
{
    /** Auto-disable webhook setelah N consecutive failure. */
    public const AUTO_DISABLE_THRESHOLD = 10;

    /** Timeout HTTP request per delivery (detik). */
    public const HTTP_TIMEOUT = 10;

    /** Max length response body yang disimpan (byte). */
    public const RESPONSE_BODY_LIMIT = 1024;

    /**
     * Enqueue event ke semua webhook yang subscribe.
     *
     * Idempotent via `event_id` (UUID per event) — kalau dispatch dipanggil
     * 2× untuk event yang sama (duplicate trigger dari dual-write), entry
     * kedua akan ditolak oleh UNIQUE(webhook_id, event_id).
     *
     * @param string $eventType  Salah satu dari WebhookEvent::*
     * @param int    $companyId  Tenant scope
     * @param array  $payload    Event body (akan di-JSON encode)
     * @param string|null $eventId  UUID; default generate baru
     * @return int Jumlah delivery enqueued
     */
    public function dispatch(
        string $eventType,
        int $companyId,
        array $payload,
        ?string $eventId = null,
    ): int {
        if (! WebhookEvent::isValid($eventType)) {
            log_message('warning', "WebhookDispatcher: unknown event type '{$eventType}', skipped");
            return 0;
        }

        $eventId ??= $this->generateUuid();
        $db = \Config\Database::connect();

        // Query webhooks yang aktif di company ini & subscribe ke event ini.
        // event_types disimpan sebagai JSON array; kita filter di PHP karena
        // SQLite tidak punya JSON_CONTAINS.
        $rows = $db->table('webhooks')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->get()->getResultArray();

        if (empty($rows)) return 0;

        $now = date('Y-m-d H:i:s');
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $count = 0;

        foreach ($rows as $hook) {
            $events = $this->decodeEventsList($hook['event_types']);
            if (! in_array($eventType, $events, true)) continue;

            try {
                $db->table('webhook_deliveries')->insert([
                    'webhook_id'    => $hook['id'],
                    'company_id'    => $companyId,
                    'event_type'    => $eventType,
                    'event_id'      => $eventId,
                    'payload'       => $jsonPayload,
                    'status'        => 'QUEUED',
                    'attempts'      => 0,
                    'created_at'    => $now,
                ]);
                $count++;
            } catch (\Throwable $e) {
                // Likely UNIQUE violation (duplicate event_id) — sengaja
                // di-swallow karena ini path idempotency kita.
                log_message('debug', 'WebhookDispatcher enqueue skip: ' . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Process pending deliveries. Run via cron `php spark webhooks:process`
     * atau loop background worker (lihat docs/DOCKER.md).
     *
     * @param int $batch Max delivery di-process per call
     * @return array{sent:int, failed:int, skipped:int}
     */
    public function processQueue(int $batch = 50): array
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $rows = $db->table('webhook_deliveries')
            ->whereIn('status', ['QUEUED', 'FAILED'])
            ->where('attempts <', EmailQueueBackoff::MAX_ATTEMPTS)
            ->groupStart()
                ->where('next_retry_at IS NULL')
                ->orWhere('next_retry_at <=', $now)
            ->groupEnd()
            ->orderBy('id', 'ASC')
            ->limit($batch)
            ->get()->getResultArray();

        $sent = $failed = $skipped = 0;
        foreach ($rows as $row) {
            // Lookup webhook config (mungkin sudah deleted/disabled di tengah)
            $hook = $db->table('webhooks')->where('id', $row['webhook_id'])->get()->getRowArray();
            if (! $hook || (int) $hook['is_active'] === 0) {
                $db->table('webhook_deliveries')->where('id', $row['id'])->update([
                    'status'     => 'FAILED',
                    'last_error' => 'Webhook disabled or deleted',
                    'last_attempt_at' => $now,
                ]);
                $skipped++;
                continue;
            }

            $newAttempt = (int) $row['attempts'] + 1;
            $db->table('webhook_deliveries')->where('id', $row['id'])->update([
                'status'          => 'SENDING',
                'attempts'        => $newAttempt,
                'last_attempt_at' => $now,
            ]);

            $result = $this->deliver($hook, $row);
            if ($result['ok']) {
                $db->table('webhook_deliveries')->where('id', $row['id'])->update([
                    'status'        => 'DELIVERED',
                    'http_status'   => $result['http_status'],
                    'response_body' => $result['body'],
                    'delivered_at'  => date('Y-m-d H:i:s'),
                    'next_retry_at' => null,
                ]);
                $db->table('webhooks')->where('id', $hook['id'])->update([
                    'last_success_at'      => date('Y-m-d H:i:s'),
                    'consecutive_failures' => 0,
                ]);
                $sent++;
            } else {
                $exhausted = $newAttempt >= EmailQueueBackoff::MAX_ATTEMPTS;
                $update = [
                    'status'        => 'FAILED',
                    'http_status'   => $result['http_status'],
                    'response_body' => $result['body'],
                    'last_error'    => substr($result['error'] ?? '', 0, 500),
                ];
                if (! $exhausted) {
                    $update['next_retry_at'] = EmailQueueBackoff::nextRetryAt($newAttempt);
                } else {
                    $update['next_retry_at'] = null;
                }
                $db->table('webhook_deliveries')->where('id', $row['id'])->update($update);

                // Bump consecutive failures di parent webhook; auto-disable
                // kalau threshold tercapai
                $newFailureCount = (int) $hook['consecutive_failures'] + 1;
                $hookUpdate = [
                    'last_failure_at'      => date('Y-m-d H:i:s'),
                    'consecutive_failures' => $newFailureCount,
                ];
                if ($newFailureCount >= self::AUTO_DISABLE_THRESHOLD) {
                    $hookUpdate['is_active'] = 0;
                    log_message('warning', "Webhook #{$hook['id']} auto-disabled after {$newFailureCount} consecutive failures");
                }
                $db->table('webhooks')->where('id', $hook['id'])->update($hookUpdate);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Eksekusi 1 HTTP delivery. Return [ok, http_status, body, error].
     *
     * @param array $hook Row dari `webhooks`
     * @param array $row  Row dari `webhook_deliveries`
     * @return array{ok:bool, http_status:?int, body:?string, error:?string}
     */
    private function deliver(array $hook, array $row): array
    {
        $secret = $this->decryptSecret($hook['secret_enc'] ?? '');
        if ($secret === '') {
            return ['ok' => false, 'http_status' => null, 'body' => null,
                    'error' => 'Empty/invalid secret (decrypt failed)'];
        }

        $headers = WebhookSigner::sign(
            $row['payload'],
            $secret,
            $row['event_type'],
        );

        try {
            $client = Services::curlrequest([
                'timeout'  => self::HTTP_TIMEOUT,
                'headers'  => [
                    'Content-Type'      => 'application/json; charset=utf-8',
                    'User-Agent'        => 'HRIS-Webhook/1.0',
                    'X-HRIS-Signature'  => $headers['signature'],
                    'X-HRIS-Timestamp'  => (string) $headers['timestamp'],
                    'X-HRIS-Event'      => $headers['event'],
                    'X-HRIS-Delivery'   => $row['event_id'],
                ],
                'http_errors' => false,  // jangan throw on 4xx/5xx
            ]);

            $response = $client->post($hook['url'], [
                'body' => $row['payload'],
            ]);
            $status = (int) $response->getStatusCode();
            $body   = substr((string) $response->getBody(), 0, self::RESPONSE_BODY_LIMIT);
            $ok     = $status >= 200 && $status < 300;

            return [
                'ok'          => $ok,
                'http_status' => $status,
                'body'        => $body,
                'error'       => $ok ? null : "HTTP {$status}",
            ];
        } catch (\Throwable $e) {
            return [
                'ok'          => false,
                'http_status' => null,
                'body'        => null,
                'error'       => 'HTTP error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Decode JSON event_types kolom; kalau corrupt return [].
     * @return string[]
     */
    private function decodeEventsList(?string $json): array
    {
        if ($json === null || $json === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Decrypt webhook secret. Fallback ke string kosong (deliver akan abort
     * dengan error message yang jelas).
     */
    private function decryptSecret(string $encrypted): string
    {
        if ($encrypted === '') return '';
        try {
            $plain = Services::encryption()->decrypt($encrypted);
            return is_string($plain) ? $plain : '';
        } catch (\Throwable $e) {
            log_message('warning', 'WebhookDispatcher: secret decrypt failed: ' . $e->getMessage());
            return '';
        }
    }

    /** RFC 4122 v4 UUID untuk event_id. */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}