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
 * Delivery production memerlukan library minishlink/web-push dan VAPID keys.
 * Jika dependency atau konfigurasi tidak tersedia, channel gagal tertutup dan
 * NotificationService dapat mencatat kegagalan; tidak boleh mengklaim terkirim.
 *
 * Subscription disimpan di `users.web_push_subscription` sebagai JSON:
 *   {"endpoint":"...","keys":{"auth":"...","p256dh":"..."}}
 *
 * Browser register subscription via `navigator.serviceWorker.pushManager.subscribe()`,
 * lalu POST ke `/api/v1/notifications/push-subscribe`.
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
        if (
            ! class_exists('\Minishlink\WebPush\WebPush')
            || ! class_exists('\Minishlink\WebPush\Subscription')
        ) {
            log_message('warning', 'WebPushChannel unavailable: minishlink/web-push is not installed');
            return false;
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
            /** @var class-string $subscriptionClass */
            $subscriptionClass = '\Minishlink\WebPush\Subscription';
            $subscription = $subscriptionClass::create($decoded);
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
