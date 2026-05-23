<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * WebPushController — kelola subscription Web Push per user (Sprint 7).
 *
 * Browser register subscription via:
 *
 *   const sub = await navigator.serviceWorker.ready
 *     .then(sw => sw.pushManager.subscribe({
 *       userVisibleOnly: true,
 *       applicationServerKey: VAPID_PUBLIC_KEY,
 *     }));
 *   await fetch('/api/v1/notifications/push-subscribe', {
 *     method: 'POST',
 *     headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
 *     body: JSON.stringify(sub),
 *   });
 *
 * Subscription disimpan di `users.web_push_subscription` (TEXT, JSON).
 * `WebPushChannel` baca kolom ini saat dispatch notification.
 *
 * Endpoint:
 *   GET    /notifications/push-public-key  — return VAPID public key
 *   POST   /notifications/push-subscribe   — save subscription
 *   DELETE /notifications/push-subscribe   — clear subscription (mis. user opt-out)
 */
class WebPushController extends ApiController
{
    /**
     * Return VAPID public key untuk register subscription.
     * Public key safe di-expose; private key TIDAK PERNAH di-share.
     */
    public function publicKey()
    {
        $key = (string) env('webpush.vapid.public', '');
        if ($key === '') {
            return $this->fail(
                'Web Push belum dikonfigurasi: VAPID public key kosong. ' .
                'Set env webpush.vapid.public terlebih dahulu.',
                503,
                'WEBPUSH_NOT_CONFIGURED'
            );
        }
        return $this->ok([
            'public_key' => $key,
        ]);
    }

    /**
     * Simpan subscription user. Body harus berisi:
     *   { endpoint: "...", keys: { auth: "...", p256dh: "..." } }
     */
    public function subscribe()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthenticated', 401);

        $data = $this->getJsonInput();
        $endpoint = (string) ($data['endpoint'] ?? '');
        $keys     = $data['keys'] ?? [];

        if ($endpoint === '' || ! is_array($keys)
            || empty($keys['auth']) || empty($keys['p256dh'])
        ) {
            return $this->fail(
                'Invalid subscription: butuh field endpoint + keys.auth + keys.p256dh',
                422,
                'INVALID_SUBSCRIPTION'
            );
        }

        // Validasi format URL endpoint (push service URL biasanya https://*.push.*)
        if (! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->fail('Endpoint URL tidak valid', 422, 'INVALID_ENDPOINT');
        }

        $subscription = [
            'endpoint' => $endpoint,
            'keys' => [
                'auth'   => (string) $keys['auth'],
                'p256dh' => (string) $keys['p256dh'],
            ],
        ];

        $db = \Config\Database::connect();
        $db->table('users')->where('id', $userId)->update([
            'web_push_subscription' => json_encode($subscription, JSON_UNESCAPED_SLASHES),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        Services::audit()->forModule('NOTIFICATION')->log(
            action: 'webpush.subscribe',
            entity: 'user',
            entityId: (int) $userId,
            description: 'User register web push subscription',
            severity: 'NOTICE',
            metadata: ['endpoint_host' => parse_url($endpoint, PHP_URL_HOST)],
        );

        return $this->ok(null, 'Subscription saved');
    }

    /**
     * Hapus subscription (user opt-out atau ganti device).
     */
    public function unsubscribe()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthenticated', 401);

        $db = \Config\Database::connect();
        $db->table('users')->where('id', $userId)->update([
            'web_push_subscription' => null,
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        Services::audit()->forModule('NOTIFICATION')->log(
            action: 'webpush.unsubscribe',
            entity: 'user',
            entityId: (int) $userId,
            description: 'User unsubscribe web push',
            severity: 'NOTICE',
        );

        return $this->ok(null, 'Subscription removed');
    }
}