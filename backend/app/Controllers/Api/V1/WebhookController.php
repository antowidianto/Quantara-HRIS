<?php

namespace App\Controllers\Api\V1;

use App\Constants\ErrorCode;
use App\Constants\WebhookEvent;
use App\Libraries\WebhookSigner;
use Config\Services;

/**
 * WebhookController — admin CRUD untuk webhook outbound (Sprint 11+).
 *
 * Endpoints:
 *   GET    /webhooks                      — list webhook di tenant
 *   POST   /webhooks                      — create webhook + secret
 *   GET    /webhooks/{id}                 — detail
 *   PUT    /webhooks/{id}                 — update name/url/event_types/is_active
 *   DELETE /webhooks/{id}                 — hard delete
 *   POST   /webhooks/{id}/rotate-secret   — generate secret baru
 *   GET    /webhooks/{id}/deliveries      — riwayat delivery (paginated)
 *   GET    /webhooks/event-types          — list event yang valid (untuk dropdown UI)
 *
 * Multi-tenant: semua endpoint scoped ke `Services::tenant()->getCompanyId()`.
 * Permission: `webhook.manage` untuk CRUD, `webhook.view` untuk list/detail.
 */
class WebhookController extends ApiController
{
    /** GET /webhooks */
    public function index()
    {
        $companyId = Services::tenant()->getCompanyId();
        $page    = max(1, (int) $this->request->getGet('page'));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $db = \Config\Database::connect();
        $builder = $db->table('webhooks')->where('company_id', $companyId);
        $total = $builder->countAllResults(false);
        $rows = $builder
            ->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        // Sanitize: hide secret_enc raw, decode event_types JSON
        $rows = array_map(function ($r) {
            unset($r['secret_enc']);
            $r['event_types'] = json_decode($r['event_types'] ?? '[]', true) ?: [];
            return $r;
        }, $rows);

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /** POST /webhooks */
    public function create()
    {
        $companyId = Services::tenant()->getCompanyId();
        $userId    = Services::tenant()->getUserId();

        $body        = $this->request->getJSON(true) ?: $this->request->getPost();
        $name        = trim((string) ($body['name'] ?? ''));
        $url         = trim((string) ($body['url'] ?? ''));
        $eventsRaw   = $body['event_types'] ?? [];
        $eventTypes  = is_array($eventsRaw) ? $eventsRaw : [];

        $errors = [];
        if ($name === '') {
            $errors['name'] = ['Nama wajib diisi'];
        }
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            $errors['url'] = ['URL harus valid dan menggunakan HTTPS'];
        }
        $eventTypes = WebhookEvent::filterValid($eventTypes);
        if (empty($eventTypes)) {
            $errors['event_types'] = ['Minimal 1 event type valid harus dipilih'];
        }
        if (! empty($errors)) {
            return $this->fail('Validation failed', 422, ErrorCode::VALIDATION_FAILED, $errors);
        }

        // Generate + encrypt secret
        $secret    = WebhookSigner::generateSecret();
        $secretEnc = Services::encryption()->encrypt($secret);
        $now = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->table('webhooks')->insert([
            'company_id'  => $companyId,
            'name'        => $name,
            'url'         => $url,
            'event_types' => json_encode($eventTypes),
            'secret_enc'  => $secretEnc,
            'is_active'   => 1,
            'created_by'  => $userId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $id = (int) $db->insertID();

        Services::audit()->log('webhook.created', 'webhook', $id, [
            'name' => $name, 'url' => $url, 'events' => $eventTypes,
        ], 'NOTICE');

        // Return secret SEKALI di response create (tidak akan ditampilkan
        // lagi setelah ini — admin harus simpan)
        return $this->ok([
            'id'          => $id,
            'name'        => $name,
            'url'         => $url,
            'event_types' => $eventTypes,
            'is_active'   => 1,
            'secret'      => $secret,  // ⚠️ Hanya muncul sekali
        ], 'Webhook dibuat. Simpan secret — tidak akan ditampilkan lagi.', 201);
    }

    /** GET /webhooks/{id} */
    public function show($id)
    {
        $row = $this->findOwned((int) $id);
        if (! $row) {
            return $this->fail('Webhook tidak ditemukan', 404, ErrorCode::RESOURCE_NOT_FOUND);
        }
        unset($row['secret_enc']);
        $row['event_types'] = json_decode($row['event_types'] ?? '[]', true) ?: [];
        return $this->ok($row);
    }

    /** PUT /webhooks/{id} */
    public function update($id)
    {
        $row = $this->findOwned((int) $id);
        if (! $row) {
            return $this->fail('Webhook tidak ditemukan', 404, ErrorCode::RESOURCE_NOT_FOUND);
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

        if (isset($body['url'])) {
            $url = trim((string) $body['url']);
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
                return $this->fail('Validation failed', 422, ErrorCode::VALIDATION_FAILED,
                    ['url' => ['URL harus valid dan HTTPS']]);
            }
            $update['url'] = $url;
        }

        if (isset($body['event_types'])) {
            $events = is_array($body['event_types']) ? $body['event_types'] : [];
            $events = WebhookEvent::filterValid($events);
            if (empty($events)) {
                return $this->fail('Validation failed', 422, ErrorCode::VALIDATION_FAILED,
                    ['event_types' => ['Minimal 1 event valid']]);
            }
            $update['event_types'] = json_encode($events);
        }

        if (isset($body['is_active'])) {
            $isActive = (int) ((bool) $body['is_active']);
            $update['is_active'] = $isActive;
            // Reset failure counter saat re-enable
            if ($isActive === 1) {
                $update['consecutive_failures'] = 0;
            }
        }

        $db = \Config\Database::connect();
        $db->table('webhooks')->where('id', $row['id'])->update($update);
        Services::audit()->log('webhook.updated', 'webhook', (int) $row['id'], $update, 'NOTICE');

        return $this->ok(['id' => (int) $row['id'], 'updated' => array_keys($update)]);
    }

    /** DELETE /webhooks/{id} */
    public function delete($id)
    {
        $row = $this->findOwned((int) $id);
        if (! $row) {
            return $this->fail('Webhook tidak ditemukan', 404, ErrorCode::RESOURCE_NOT_FOUND);
        }
        $db = \Config\Database::connect();
        $db->table('webhooks')->where('id', $row['id'])->delete();
        Services::audit()->log('webhook.deleted', 'webhook', (int) $row['id'], [
            'name' => $row['name'], 'url' => $row['url'],
        ], 'NOTICE');
        return $this->ok(['id' => (int) $row['id']]);
    }

    /** POST /webhooks/{id}/rotate-secret */
    public function rotateSecret($id)
    {
        $row = $this->findOwned((int) $id);
        if (! $row) {
            return $this->fail('Webhook tidak ditemukan', 404, ErrorCode::RESOURCE_NOT_FOUND);
        }

        $newSecret = WebhookSigner::generateSecret();
        $newEnc    = Services::encryption()->encrypt($newSecret);
        $db = \Config\Database::connect();
        $db->table('webhooks')->where('id', $row['id'])->update([
            'secret_enc' => $newEnc,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->log('webhook.secret_rotated', 'webhook', (int) $row['id'], [], 'NOTICE');

        return $this->ok([
            'id'     => (int) $row['id'],
            'secret' => $newSecret,  // ⚠️ Sekali tampil
        ], 'Secret baru. Simpan — tidak akan ditampilkan lagi.');
    }

    /** GET /webhooks/{id}/deliveries — riwayat delivery untuk debug */
    public function deliveries($id)
    {
        $row = $this->findOwned((int) $id);
        if (! $row) {
            return $this->fail('Webhook tidak ditemukan', 404, ErrorCode::RESOURCE_NOT_FOUND);
        }

        $page    = max(1, (int) $this->request->getGet('page'));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status  = $this->request->getGet('status');

        $db = \Config\Database::connect();
        $b = $db->table('webhook_deliveries')->where('webhook_id', $row['id']);
        if ($status !== null && $status !== '') {
            $b->where('status', strtoupper($status));
        }
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /** GET /webhooks/event-types — list event valid untuk UI dropdown */
    public function eventTypes()
    {
        return $this->ok(['event_types' => WebhookEvent::ALL]);
    }

    /**
     * Helper: ambil row dengan auto-tenant scope, return null kalau bukan
     * milik tenant atau tidak ada.
     *
     * @return array<string, mixed>|null
     */
    private function findOwned(int $id): ?array
    {
        $companyId = Services::tenant()->getCompanyId();
        $db = \Config\Database::connect();
        $row = $db->table('webhooks')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        return $row ?: null;
    }
}