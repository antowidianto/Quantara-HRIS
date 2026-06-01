<?php

namespace App\Controllers\Api\V1\Webhook;

use App\Controllers\Api\V1\ApiController;
use App\Constants\ErrorCode;
use App\Libraries\Webhook\WebhookSigner;
use Config\Services;

/**
 * InboundWebhookController — receiver + admin CRUD untuk webhook inbound
 * (Sprint 14+).
 *
 * Receiver endpoint (PUBLIC, no JWT — diauthentikasi via HMAC signature):
 *   POST /api/v1/inbound/{slug}
 *
 * Admin endpoints (JWT-protected, scoped tenant):
 *   GET    /inbound-webhooks
 *   POST   /inbound-webhooks
 *   GET    /inbound-webhooks/{id}
 *   PUT    /inbound-webhooks/{id}
 *   DELETE /inbound-webhooks/{id}
 *   POST   /inbound-webhooks/{id}/rotate-secret
 *   GET    /inbound-webhooks/{id}/events    — log delivery
 *
 * Multi-tenant: source slug global UNIQUE → resolve ke `company_id` via
 * lookup `inbound_webhooks` table. Admin CRUD scoped ke tenant biasa.
 *
 * Idempotency: header `X-HRIS-Source-Event` (atau payload field `event_id`)
 * dipakai sebagai `source_event_id`. UNIQUE constraint di
 * `inbound_webhook_events(inbound_id, source_event_id)` mencegah double
 * processing kalau sender retry.
 */
class InboundWebhookController extends ApiController
{
    /* ──────────────────────────────────────────────────────────────────────
     * RECEIVER — public, dipanggil sistem external
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * POST /api/v1/inbound/{slug}
     *
     * Tidak pakai JWT filter. Auth via:
     *   1. Header X-HRIS-Signature (HMAC-SHA256 dari raw body + timestamp)
     *   2. Header X-HRIS-Timestamp (unix int, replay window 5 menit)
     *   3. Optional IP allowlist per source
     *
     * Response code:
     *   200 OK              — event sukses di-process oleh handler terdaftar
     *   202 Accepted        — event tercatat tapi belum ada handler (dapat
     *                         diproses ulang nanti)
     *   400 Bad Request     — body bukan JSON valid / event_type missing
     *   401 Unauthorized    — signature gagal / timestamp expired
     *   403 Forbidden       — IP tidak dalam allowlist
     *   404 Not Found       — slug tidak terdaftar / source non-aktif
     *   409 Conflict        — duplicate source_event_id (idempotency)
     *   422 Unprocessable   — event_type tidak dalam filter source
     */
    public function receive(string $slug = '')
    {
        $slug = trim($slug);
        if ($slug === '') {
            return $this->fail('Source slug missing', 404, ErrorCode::RESOURCE_NOT_FOUND);
        }

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // 1. Resolve source by slug
        $source = $db->table('inbound_webhooks')
            ->where('slug', $slug)
            ->where('is_active', 1)
            ->get()->getRowArray();

        if (! $source) {
            return $this->fail('Inbound source not registered or inactive',
                404, ErrorCode::RESOURCE_NOT_FOUND);
        }

        $remoteIp = $this->request->getIPAddress();
        $handler  = service('inbound');

        // 2. IP allowlist
        if (! $handler->ipAllowed($source, $remoteIp)) {
            $this->logEvent($source, '?', '?', '', [], 'REJECTED', 403,
                'IP not in allowlist', $remoteIp);
            return $this->fail('IP not allowed', 403, ErrorCode::PERMISSION_DENIED);
        }

        // 3. Read raw body & headers
        $rawBody = (string) $this->request->getBody();
        $sigHdr  = (string) $this->request->getHeaderLine('X-HRIS-Signature');
        $tsHdr   = $this->request->getHeaderLine('X-HRIS-Timestamp');
        $tsHdr   = $tsHdr === '' ? null : (string) $tsHdr;

        $verifyError = $handler->verifySignature($source, $rawBody, $sigHdr, $tsHdr);
        if ($verifyError !== null) {
            $this->logEvent($source, '?', '?', $rawBody, $this->captureHeaders(),
                'REJECTED', 401, $verifyError, $remoteIp);
            return $this->fail($verifyError, 401, ErrorCode::UNAUTHORIZED);
        }

        // 4. Parse body
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return $this->fail('Body must be valid JSON', 400, ErrorCode::VALIDATION_FAILED);
        }

        $eventType = (string) ($payload['event'] ?? $payload['event_type'] ?? '');
        if ($eventType === '') {
            return $this->fail('Missing event/event_type in payload', 400,
                ErrorCode::VALIDATION_FAILED);
        }

        // 5. Filter event_type kalau source punya filter
        $allowedEvents = $this->decodeEvents($source['event_types'] ?? '');
        if (! empty($allowedEvents) && ! in_array($eventType, $allowedEvents, true)) {
            $this->logEvent($source, $eventType, '?', $rawBody, $this->captureHeaders(),
                'REJECTED', 422, 'Event type not subscribed', $remoteIp);
            return $this->fail("Event '{$eventType}' not in subscription filter",
                422, ErrorCode::VALIDATION_FAILED);
        }

        // 6. Idempotency — source_event_id
        $sourceEventId = (string) (
            $this->request->getHeaderLine('X-HRIS-Source-Event')
            ?: ($payload['event_id'] ?? $payload['id'] ?? '')
        );
        if ($sourceEventId === '') {
            // Generate fallback (UUID v4) — sender boleh tidak include id,
            // tapi maka tidak idempotent secara default
            $sourceEventId = $this->generateUuid();
        }

        // 7. INSERT event row (UNIQUE will prevent duplicate)
        $headersJson = $this->captureHeaders();
        try {
            $db->table('inbound_webhook_events')->insert([
                'inbound_id'      => (int) $source['id'],
                'company_id'      => (int) $source['company_id'],
                'event_type'      => $eventType,
                'source_event_id' => $sourceEventId,
                'payload'         => $rawBody,
                'headers'         => json_encode($headersJson, JSON_UNESCAPED_UNICODE),
                'status'          => 'RECEIVED',
                'http_status'     => 200,
                'remote_ip'       => $remoteIp,
                'created_at'      => $now,
            ]);
            $eventRowId = (int) $db->insertID();
        } catch (\Throwable $e) {
            // UNIQUE violation = sender retry dengan event_id sama → balas
            // 200 OK supaya retry berhenti, tapi flag dengan note di body
            log_message('debug', 'Inbound event idempotency hit: ' . $e->getMessage());
            return $this->ok([
                'duplicate'      => true,
                'source_event_id' => $sourceEventId,
            ], 'Event already processed (idempotency)');
        }

        // 8. Update last_received_at di parent source
        $db->table('inbound_webhooks')
            ->where('id', $source['id'])
            ->update(['last_received_at' => $now]);

        // 9. Dispatch ke handler terdaftar
        $result = $handler->dispatch($eventType, $payload, $source);

        if (! $handler->hasHandler($eventType)) {
            // No handler — return 202 Accepted, status tetap RECEIVED
            $db->table('inbound_webhook_events')
                ->where('id', $eventRowId)
                ->update(['http_status' => 202]);

            return $this->ok([
                'event_id'         => (int) $eventRowId,
                'source_event_id'  => $sourceEventId,
                'status'           => 'RECEIVED',
                'note'             => 'No handler registered for this event type',
            ], 'Event received', 202);
        }

        // 10. Update status berdasarkan hasil handler
        if ($result['failed'] > 0 && $result['processed'] === 0) {
            $db->table('inbound_webhook_events')
                ->where('id', $eventRowId)
                ->update([
                    'status'       => 'FAILED',
                    'http_status'  => 500,
                    'last_error'   => substr(implode('; ', $result['errors']), 0, 500),
                    'processed_at' => $now,
                ]);

            // Bump failure counter — auto-disable threshold sama dengan outbound
            $db->table('inbound_webhooks')
                ->where('id', $source['id'])
                ->update(['last_failure_at' => $now]);

            return $this->fail('Handler failed: ' . implode('; ', $result['errors']),
                500, ErrorCode::OPERATION_FAILED);
        }

        $db->table('inbound_webhook_events')
            ->where('id', $eventRowId)
            ->update([
                'status'       => 'PROCESSED',
                'http_status'  => 200,
                'processed_at' => $now,
            ]);

        return $this->ok([
            'event_id'        => (int) $eventRowId,
            'source_event_id' => $sourceEventId,
            'status'          => 'PROCESSED',
            'processed'       => $result['processed'],
            'failed'          => $result['failed'],
        ], 'Event processed');
    }

    /* ──────────────────────────────────────────────────────────────────────
     * ADMIN CRUD — JWT-protected, scoped ke tenant
     * ──────────────────────────────────────────────────────────────────── */

    /** GET /inbound-webhooks */
    public function index()
    {
        $companyId = Services::tenant()->getCompanyId();
        $page    = max(1, (int) $this->request->getGet('page'));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $db = \Config\Database::connect();
        $b  = $db->table('inbound_webhooks')->where('company_id', $companyId);
        $total = $b->countAllResults(false);
        $rows  = $b->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        $rows = array_map(function ($r) {
            unset($r['secret_enc']);
            $r['event_types'] = $this->decodeEvents($r['event_types'] ?? '');
            $r['allowed_ips'] = $this->decodeAllowedIps($r['allowed_ips'] ?? null);
            return $r;
        }, $rows);

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /** POST /inbound-webhooks */
    public function create()
    {
        $companyId = Services::tenant()->getCompanyId();
        $userId    = Services::tenant()->getUserId();

        $body = $this->request->getJSON(true) ?: $this->request->getPost();

        $name        = trim((string) ($body['name'] ?? ''));
        $source      = trim((string) ($body['source'] ?? 'custom'));
        $events      = is_array($body['event_types'] ?? null) ? $body['event_types'] : [];
        $allowedIps  = is_array($body['allowed_ips']  ?? null) ? $body['allowed_ips']  : [];

        $errors = [];
        if ($name === '') {
            $errors['name'] = ['Nama wajib diisi'];
        }
        if ($source === '' || strlen($source) > 50) {
            $errors['source'] = ['Source identifier wajib & max 50 char'];
        }
        // event_types boleh kosong — artinya terima semua event dari source

        if (! empty($errors)) {
            return $this->fail('Validation failed', 422, ErrorCode::VALIDATION_FAILED, $errors);
        }

        // Generate slug unik (URL-safe)
        $slug = $this->makeUniqueSlug($name);
        $secret = WebhookSigner::generateSecret();
        $secretEnc = Services::encryption()->encrypt($secret);
        $now = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->table('inbound_webhooks')->insert([
            'company_id'  => $companyId,
            'slug'        => $slug,
            'name'        => $name,
            'source'      => $source,
            'event_types' => json_encode(array_values(array_filter($events, 'is_string'))),
            'secret_enc'  => $secretEnc,
            'allowed_ips' => empty($allowedIps) ? null
                            : json_encode(array_values(array_filter($allowedIps, 'is_string'))),
            'is_active'   => 1,
            'created_by'  => $userId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $id = (int) $db->insertID();

        Services::audit()->log('inbound_webhook.created', 'inbound_webhook', $id, [
            'name' => $name, 'slug' => $slug, 'source' => $source,
        ], 'NOTICE');

        // Receiver URL untuk dishare ke source vendor
        $baseUrl = rtrim((string) (env('app.baseURL') ?: ''), '/');
        $url = $baseUrl !== ''
            ? $baseUrl . '/api/v1/inbound/' . $slug
            : '/api/v1/inbound/' . $slug;

        return $this->ok([
            'id'         => $id,
            'slug'       => $slug,
            'name'       => $name,
            'source'     => $source,
            'url'        => $url,
            'secret'     => $secret,  // ⚠️ Hanya muncul sekali
            'is_active'  => 1,
        ], 'Inbound webhook dibuat. Bagikan URL + secret ke pihak source — secret tidak akan ditampilkan lagi.', 201);
    }

    /** GET /inbound-webhooks/{id} */
    public function show($id)
    {
        $row = $this->findOwned((int) $id);
        if (! $row) {
            return $this->fail('Inbound webhook tidak ditemukan', 404, ErrorCode::RESOURCE_NOT_FOUND);
        }
        unset($row['secret_enc']);
        $row['event_types'] = $this->decodeEvents($row['event_types'] ?? '');
        $row['allowed_ips'] = $this->decodeAllowedIps($row['allowed_ips'] ?? null);
        return $this->ok($row);
    }

    /** PUT /inbound-webhooks/{id} */
    public function update($id)
    {
        $row = $this->findOwned((int) $id);
        if (! $row) {
            return $this->fail('Inbound webhook tidak ditemukan', 404, ErrorCode::RESOURCE_NOT_FOUND);
        }

        $body = $this->request->getJSON(true) ?: $this->request->getRawInput();
        $update = ['updated_at' => date('Y-m-d H:i:s')];

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return $this->fail('Validation failed', 422, ErrorCode::VALIDATION_FAILED,
                    ['name' => ['Nama tidak boleh kosong']]);
            }
            $update['name'] = $name;
        }

        if (isset($body['event_types'])) {
            $events = is_array($body['event_types']) ? $body['event_types'] : [];
            $update['event_types'] = json_encode(array_values(array_filter($events, 'is_string')));
        }

        if (array_key_exists('allowed_ips', $body)) {
            $ips = is_array($body['allowed_ips']) ? $body['allowed_ips'] : [];
            $update['allowed_ips'] = empty($ips) ? null
                : json_encode(array_values(array_filter($ips, 'is_string')));
        }

        if (isset($body['is_active'])) {
            $update['is_active'] = (int) ((bool) $body['is_active']);
        }

        $db = \Config\Database::connect();
        $db->table('inbound_webhooks')->where('id', $row['id'])->update($update);
        Services::audit()->log('inbound_webhook.updated', 'inbound_webhook',
            (int) $row['id'], $update, 'NOTICE');

        return $this->ok(['id' => (int) $row['id'], 'updated' => array_keys($update)]);
    }

    /** DELETE /inbound-webhooks/{id} */
    public function delete($id)
    {
        $row = $this->findOwned((int) $id);
        if (! $row) {
            return $this->fail('Inbound webhook tidak ditemukan', 404, ErrorCode::RESOURCE_NOT_FOUND);
        }
        $db = \Config\Database::connect();
        $db->table('inbound_webhooks')->where('id', $row['id'])->delete();
        Services::audit()->log('inbound_webhook.deleted', 'inbound_webhook',
            (int) $row['id'], ['name' => $row['name'], 'slug' => $row['slug']], 'NOTICE');
        return $this->ok(['id' => (int) $row['id']]);
    }

    /** POST /inbound-webhooks/{id}/rotate-secret */
    public function rotateSecret($id)
    {
        $row = $this->findOwned((int) $id);
        if (! $row) {
            return $this->fail('Inbound webhook tidak ditemukan', 404, ErrorCode::RESOURCE_NOT_FOUND);
        }
        $newSecret = WebhookSigner::generateSecret();
        $newEnc    = Services::encryption()->encrypt($newSecret);
        $db = \Config\Database::connect();
        $db->table('inbound_webhooks')->where('id', $row['id'])->update([
            'secret_enc' => $newEnc,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->log('inbound_webhook.secret_rotated', 'inbound_webhook',
            (int) $row['id'], [], 'NOTICE');

        return $this->ok([
            'id'     => (int) $row['id'],
            'secret' => $newSecret,
        ], 'Secret baru. Bagikan ke source — tidak akan ditampilkan lagi.');
    }

    /** GET /inbound-webhooks/{id}/events — riwayat event diterima */
    public function events($id)
    {
        $row = $this->findOwned((int) $id);
        if (! $row) {
            return $this->fail('Inbound webhook tidak ditemukan', 404, ErrorCode::RESOURCE_NOT_FOUND);
        }

        $page    = max(1, (int) $this->request->getGet('page'));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status  = $this->request->getGet('status');

        $db = \Config\Database::connect();
        $b = $db->table('inbound_webhook_events')->where('inbound_id', $row['id']);
        if ($status !== null && $status !== '') {
            $b->where('status', strtoupper((string) $status));
        }
        $total = $b->countAllResults(false);
        $rows  = $b->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /* ──────────────────────────────────────────────────────────────────────
     * Helpers
     * ──────────────────────────────────────────────────────────────────── */

    /** Find row dengan tenant scope (defensive). */
    private function findOwned(int $id): ?array
    {
        $companyId = Services::tenant()->getCompanyId();
        $db = \Config\Database::connect();
        $row = $db->table('inbound_webhooks')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Generate slug unik. Kalau collision (rare), append random suffix.
     */
    private function makeUniqueSlug(string $name): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? 'inbound');
        $base = trim($base, '-');
        $base = $base === '' ? 'inbound' : substr($base, 0, 40);

        $db = \Config\Database::connect();
        $candidate = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $tries = 0;
        while ($tries < 5) {
            $exists = $db->table('inbound_webhooks')->where('slug', $candidate)
                ->countAllResults() > 0;
            if (! $exists) return $candidate;
            $candidate = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $tries++;
        }
        return $base . '-' . bin2hex(random_bytes(8));
    }

    /**
     * Capture relevant request headers (untuk audit). Hindari header secret
     * (Authorization, Cookie) — hanya yang relevan untuk debug protocol.
     *
     * @return array<string, string>
     */
    private function captureHeaders(): array
    {
        $headersWanted = [
            'X-HRIS-Signature', 'X-HRIS-Timestamp', 'X-HRIS-Source-Event',
            'X-HRIS-Event', 'X-HRIS-Delivery', 'User-Agent', 'Content-Type',
        ];
        $out = [];
        foreach ($headersWanted as $h) {
            $val = $this->request->getHeaderLine($h);
            if ($val !== '') $out[$h] = $val;
        }
        return $out;
    }

    /**
     * @return string[]
     */
    private function decodeEvents(string $json): array
    {
        if ($json === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded)
            ? array_values(array_filter($decoded, 'is_string'))
            : [];
    }

    /**
     * @return string[]|null
     */
    private function decodeAllowedIps(?string $json): ?array
    {
        if ($json === null || $json === '') return null;
        $decoded = json_decode($json, true);
        return is_array($decoded)
            ? array_values(array_filter($decoded, 'is_string'))
            : null;
    }

    /**
     * INSERT log row untuk request yang fail di awal validasi (sebelum
     * sempat masuk normal flow di receive()).
     */
    private function logEvent(
        array $source,
        string $eventType,
        string $sourceEventId,
        string $rawBody,
        array $headers,
        string $status,
        int $httpStatus,
        string $error,
        string $remoteIp,
    ): void {
        try {
            $db = \Config\Database::connect();
            $db->table('inbound_webhook_events')->insert([
                'inbound_id'      => (int) $source['id'],
                'company_id'      => (int) $source['company_id'],
                'event_type'      => $eventType !== '' ? $eventType : 'unknown',
                'source_event_id' => $sourceEventId !== '' ? $sourceEventId : $this->generateUuid(),
                'payload'         => $rawBody,
                'headers'         => json_encode($headers, JSON_UNESCAPED_UNICODE),
                'status'          => $status,
                'http_status'     => $httpStatus,
                'last_error'      => substr($error, 0, 500),
                'remote_ip'       => $remoteIp,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Best-effort logging — kalau insert gagal (mis. UNIQUE collision
            // dari fallback uuid yang sama, sangat jarang), swallow.
            log_message('debug', 'Inbound logEvent failed: ' . $e->getMessage());
        }
    }

    /** RFC 4122 v4 UUID (sama dengan WebhookDispatcher). */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}