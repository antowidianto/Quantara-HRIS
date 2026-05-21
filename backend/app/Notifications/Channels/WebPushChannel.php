<?php

namespace App\Notifications\Channels;

use App\Notifications\NotificationChannel;

/**
 * WebPushChannel — kirim push notification ke browser via Web Push protocol.
 *
 * Service Worker frontend (`frontend/public/sw.js`) menerima push event,
 * tampilkan native notification. Untuk implementasi production, install:
 *   composer require minishlink/web-push
 * dan generate VAPID key:
 *   php -r "require 'vendor/autoload.php'; print_r(\Minishlink\WebPush\VAPID::createVapidKeys());"
 *
 * Channel ini sengaja IMPLEMENTASI MINIMAL — kalau library minishlink/web-push
 * tidak terpasang, fallback ke logging saja (untuk dev). Caller code
 * (NotificationService) tidak perlu peduli.
 *
 * Subscription disimpan di `users.web_push_subscription` sebagai JSON:
 *   {"endpoint":"...","keys":{"auth":"...","p256dh":"..."}}
 *
 * Browser register subscription via `navigator.serviceWorker.pushManager.subscribe()`,
 * lalu POST ke endpoint backend (TODO: endpoint subscription belum dibuat —
 * masuk ke catatan future Sprint).
 */
class WebPushChannel implements NotificationChannel
{
    public function slug(): string
    {
        return 'web_push';
    }

    public function isAvailable(array $user): bool
    {
        $sub = $user['web_push_subscription'] ?? null;
        if (! $sub) return false;
        $decoded = is_string($sub) ? json_decode($sub, true) : $sub;
        return is_array($decoded)
            && ! empty($decoded['endpoint'])
            && ! empty($decoded['keys']['p256dh'])
            && ! empty($decoded['keys']['auth']);
    }

    public function send(array $user, array $payload): bool
    {
        $sub = $user['web_push_subscription'] ?? null;
        $decoded = is_string($sub) ? json_decode($sub, true) : $sub;
        if (! is_array($decoded) || empty($decoded['endpoint'])) {
            return false;
        }

        // Cek apakah library minishlink/web-push terpasang.
        if (! class_exists('\Minishlink\WebPush\WebPush')) {
            log_message('info', '[WebPushChannel:dev-log] subscription endpoint=' . $decoded['endpoint']);
            return true; // dev mode: anggap sukses
        }

        $publicKey = (string) env('webpush.vapid.public', '');
        $privateKey = (string) env('webpush.vapid.private', '');
        $subject = (string) (env('webpush.vapid.subject') ?: 'mailto:noreply@hris.local');
        if ($publicKey === '' || $privateKey === '') {
            log_message('warning', 'VAPID key belum di-set, web push skipped');
            return false;
        }

        try {
            $auth = [
                'VAPID' => [
                    'subject'    => $subject,
                    'publicKey'  => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ];
            $webPush = new \Minishlink\WebPush\WebPush($auth);
            $subscription = \Minishlink\WebPush\Subscription::create($decoded);
            $body = json_encode([
                'title'   => $payload['title']    ?? 'Notifikasi',
                'message' => $payload['message']  ?? '',
                'url'     => $payload['link_url'] ?? null,
                'id'      => $payload['notification_id'] ?? null,
            ], JSON_UNESCAPED_UNICODE);
            $webPush->queueNotification($subscription, $body);
            $reports = $webPush->flush();
            $allOk = true;
            foreach ($reports as $report) {
                if (! $report->isSuccess()) {
                    $allOk = false;
                    log_message('warning', 'Web push fail: ' . $report->getReason());
                }
            }
            return $allOk;
        } catch (\Throwable $e) {
            log_message('warning', 'WebPushChannel send failed: ' . $e->getMessage());
            return false;
        }
    }
}