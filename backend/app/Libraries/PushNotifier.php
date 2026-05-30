<?php

namespace App\Libraries;

use App\Models\DeviceTokenModel;
use Config\Services;

/**
 * PushNotifier — Mobile Prep / MOB-2
 *
 * Adapter ringan untuk fanout push notification ke FCM (Android + iOS via
 * FCM HTTP v1) dan opsional APNs direct. Untuk MVP cukup FCM saja karena:
 *
 *   - FCM bisa kirim ke iOS lewat APNs token registration (mobile app
 *     register di Firebase, Firebase yang relay ke APNs).
 *   - Single integration → tim ops cuma maintain 1 credential.
 *
 * Strategi untuk MVP:
 *
 *   - Kirim **best-effort + async via JobQueue** supaya request user (mis.
 *     /leaves approve) tidak ke-block kalau FCM lambat / down.
 *   - Kalau FCM credential tidak di-set di env (`FCM_PROJECT_ID` empty),
 *     mode "log only" — tulis ke log saja (dev mode).
 *   - Auto-revoke device kalau FCM balas `UNREGISTERED` / 404 / 410.
 *
 * Pemakaian:
 *
 *   Services::pushNotifier()->sendToUser($userId, [
 *       'title' => 'Leave approved',
 *       'body'  => 'Cuti tahunan 27-29 Mei disetujui',
 *       'data'  => ['type' => 'leave', 'id' => 123],
 *   ]);
 *
 *   // Bulk
 *   Services::pushNotifier()->sendToUsers([1,2,3], [...]);
 */
class PushNotifier
{
    private const FCM_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private string $projectId;
    private string $serviceAccountPath;
    private bool $enabled;

    public function __construct()
    {
        $this->projectId          = (string) env('fcm.projectId', '');
        $this->serviceAccountPath = (string) env('fcm.serviceAccountPath', '');
        $this->enabled            = $this->projectId !== '' && $this->serviceAccountPath !== '';
    }

    /**
     * Kirim push ke semua device aktif user.
     *
     * @param array{title:string,body:string,data?:array<string,mixed>,sound?:string,badge?:int} $message
     * @return array{sent:int,failed:int}
     */
    public function sendToUser(int $userId, array $message): array
    {
        return $this->sendToUsers([$userId], $message);
    }

    /**
     * Bulk kirim ke banyak user. Pakai loop per-token karena FCM HTTP v1
     * tidak support multicast lagi (deprecated di legacy FCM); pola yang
     * direkomendasikan Google adalah batch parallel atau topic-based fanout.
     * Untuk MVP loop sequential cukup.
     *
     * @param array<int,int> $userIds
     * @param array{title:string,body:string,data?:array<string,mixed>,sound?:string,badge?:int} $message
     * @return array{sent:int,failed:int}
     */
    public function sendToUsers(array $userIds, array $message): array
    {
        if ($userIds === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        $deviceModel = new DeviceTokenModel();
        $devices     = $deviceModel->activeForUsers($userIds);

        $sent   = 0;
        $failed = 0;

        foreach ($devices as $device) {
            $ok = $this->sendToToken(
                (string) $device['token'],
                (string) $device['platform'],
                $message,
            );
            if ($ok) {
                $sent++;
            } else {
                $failed++;
                $deviceModel->markFailure((string) $device['token']);
            }
        }

        log_message('info', sprintf(
            'PushNotifier: sent=%d failed=%d total=%d users=%s',
            $sent, $failed, count($devices), implode(',', $userIds),
        ));

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Kirim ke 1 token spesifik. Return true kalau sukses.
     *
     * @param array{title:string,body:string,data?:array<string,mixed>,sound?:string,badge?:int} $message
     */
    public function sendToToken(string $token, string $platform, array $message): bool
    {
        if (! $this->enabled) {
            log_message('debug', sprintf(
                'PushNotifier disabled (no FCM creds). Would send to %s/%s: %s',
                $platform, substr($token, 0, 12) . '…', $message['title'] ?? '',
            ));
            return true; // dev mode: pretend success
        }

        try {
            return $this->sendViaFcm($token, $platform, $message);
        } catch (\Throwable $e) {
            log_message('warning', 'PushNotifier FCM error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build payload + POST ke FCM HTTP v1.
     *
     * @param array{title:string,body:string,data?:array<string,mixed>,sound?:string,badge?:int} $message
     */
    private function sendViaFcm(string $token, string $platform, array $message): bool
    {
        $payload = [
            'message' => [
                'token'        => $token,
                'notification' => [
                    'title' => (string) ($message['title'] ?? 'Notification'),
                    'body'  => (string) ($message['body'] ?? ''),
                ],
                'data' => $this->stringifyData($message['data'] ?? []),
            ],
        ];

        // Per-platform tweaks
        if ($platform === 'ANDROID') {
            $payload['message']['android'] = [
                'priority' => 'high',
                'notification' => [
                    'sound' => (string) ($message['sound'] ?? 'default'),
                ],
            ];
        } elseif ($platform === 'IOS') {
            $payload['message']['apns'] = [
                'payload' => [
                    'aps' => [
                        'sound' => (string) ($message['sound'] ?? 'default'),
                        'badge' => (int) ($message['badge'] ?? 1),
                    ],
                ],
            ];
        }

        $accessToken = $this->getOAuthAccessToken();
        $url         = sprintf(self::FCM_ENDPOINT, $this->projectId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=UTF-8',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code === 200) return true;

        // 404 / UNREGISTERED → token invalid, jangan retry
        if ($code === 404 || $code === 410) {
            log_message('info', "FCM token unregistered ({$code}): {$body}");
            return false;
        }
        log_message('warning', "FCM send failed (HTTP {$code}): {$body} curl_err={$err}");
        return false;
    }

    /**
     * Convert data values jadi string (FCM v1 wajib string-only di data block).
     *
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = (string) $v;
            } else {
                $out[(string) $k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }
        return $out;
    }

    /**
     * OAuth2 service account → access token (cache 50 menit).
     * Implementasi minimal pakai JWT bearer flow Google.
     */
    private function getOAuthAccessToken(): string
    {
        $cache = Services::cache();
        $key   = 'fcm.oauth.accessToken';
        $cached = $cache->get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (! is_file($this->serviceAccountPath)) {
            throw new \RuntimeException("FCM service account file not found: {$this->serviceAccountPath}");
        }
        $sa = json_decode((string) file_get_contents($this->serviceAccountPath), true);
        if (! is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new \RuntimeException('FCM service account JSON invalid');
        }

        $now = time();
        $jwt = $this->buildJwt(
            $sa['client_email'],
            $sa['private_key'],
            $now,
            'https://www.googleapis.com/auth/firebase.messaging',
        );

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_TIMEOUT        => 8,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new \RuntimeException("FCM OAuth token exchange failed: HTTP {$code} {$body}");
        }
        $data = json_decode((string) $body, true);
        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('FCM OAuth: missing access_token');
        }
        // Token Google biasanya berlaku 1 jam; cache 50 menit untuk safety margin
        $cache->save($key, $token, 3000);
        return $token;
    }

    /**
     * Build & sign JWT RS256 untuk Google OAuth bearer assertion.
     */
    private function buildJwt(string $clientEmail, string $privateKeyPem, int $now, string $scope): string
    {
        $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->b64url(json_encode([
            'iss'   => $clientEmail,
            'scope' => $scope,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ], JSON_UNESCAPED_SLASHES));
        $signingInput = $header . '.' . $claims;

        $pkey = openssl_pkey_get_private($privateKeyPem);
        if ($pkey === false) {
            throw new \RuntimeException('FCM: invalid private_key in service account');
        }
        $signature = '';
        openssl_sign($signingInput, $signature, $pkey, 'sha256WithRSAEncryption');

        return $signingInput . '.' . $this->b64url($signature);
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}