<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * ThrottleFilter
 *
 * Simple in-memory (cache-backed) rate limiter.
 * Usage: $routes->post('login', '...', ['filter' => 'throttle:login']);
 *
 * Notes:
 *  - Skips OPTIONS preflight requests (CORS) so they are never throttled.
 *  - Wraps cache I/O in try/catch so a misconfigured cache backend never
 *    breaks the request — it just degrades to allow.
 */
class ThrottleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Never throttle CORS preflight
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return;
        }

        $bucket = $arguments[0] ?? 'api';
        [$max, $window] = $this->bucketLimits($bucket);

        try {
            $cache = Services::cache();
            // CodeIgniter cache validator menolak karakter reserved
            // (`{}()/\@:`). Pakai underscore sebagai separator + sanitasi
            // IP supaya IPv6 (yang punya `:`) tidak memicu warning
            // "Cache key contains reserved characters". Lihat
            // CodeIgniter\Cache\Handlers\BaseHandler::validateKey.
            $ip = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $request->getIPAddress());
            $key = sprintf(
                'throttle_%s_%s_%s',
                preg_replace('/[^A-Za-z0-9_-]/', '_', $bucket),
                $ip,
                md5($request->getUri()->getPath()),
            );

            $entry = $cache->get($key);
            $now   = time();

            if (! is_array($entry) || ! isset($entry['reset'], $entry['count']) || $entry['reset'] < $now) {
                $entry = ['count' => 0, 'reset' => $now + $window];
            }

            $entry['count']++;
            $cache->save($key, $entry, max(1, $entry['reset'] - $now));

            if ($entry['count'] > $max) {
                return Services::response()
                    ->setStatusCode(429)
                    ->setHeader('Retry-After', (string) max(1, $entry['reset'] - $now))
                    ->setJSON([
                        'status'  => 'error',
                        'code'    => 'RATE_LIMIT_EXCEEDED',
                        'message' => 'Too many requests. Please slow down.',
                    ]);
            }
        } catch (\Throwable $e) {
            // Fail open — never let a cache hiccup break the API
            log_message('warning', 'ThrottleFilter cache error: ' . $e->getMessage());
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No-op
    }

    private function bucketLimits(string $bucket): array
    {
        return match ($bucket) {
            'login' => [
                (int) env('ratelimit.loginAttempts', 5),
                (int) env('ratelimit.loginWindow', 900),
            ],
            // Sprint 3 — bucket khusus untuk endpoint sensitif
            // Forgot password: 3 request / 15 menit per IP+path
            // (cegah enumeration & email spam ke user)
            'forgot_password' => [
                (int) env('ratelimit.forgotPasswordAttempts', 3),
                (int) env('ratelimit.forgotPasswordWindow', 900),
            ],
            // 2FA verify: 5 request / 15 menit (cegah brute-force kode 6 digit)
            'twofa' => [
                (int) env('ratelimit.twofaAttempts', 5),
                (int) env('ratelimit.twofaWindow', 900),
            ],
            // Password reset: 5 request / jam (gabungan untuk request token
            // baru dan submit password baru)
            'password_reset' => [
                (int) env('ratelimit.passwordResetAttempts', 5),
                (int) env('ratelimit.passwordResetWindow', 3600),
            ],
            // Export CSV/PDF berat: 10 / jam per user
            // (mencegah disk thrashing & DoS dari export download loop)
            'export' => [
                (int) env('ratelimit.exportAttempts', 10),
                (int) env('ratelimit.exportWindow', 3600),
            ],
            // Sprint 14+ — Inbound webhook receiver. Ketat per IP+slug
            // supaya source nakal/bug tidak banjiri storage. Bucket
            // window 1 menit (sender legit retry sekitar 1m/5m/30m/...
            // jadi 60 dalam 1 menit lebih dari cukup untuk pulse normal).
            'inbound_webhook' => [
                (int) env('ratelimit.inboundWebhookAttempts', 60),
                (int) env('ratelimit.inboundWebhookWindow', 60),
            ],
            // S1.3 SaaS — Self-Service Tenant Signup. Sangat ketat karena
            // ini endpoint publik tanpa auth + memicu provisioning DB
            // (mahal). 3/jam/IP cukup untuk legit user yang mau coba
            // beberapa email, dan blokir bot/scraper.
            'signup' => [
                (int) env('ratelimit.signupAttempts', 3),
                (int) env('ratelimit.signupWindow', 3600),
            ],
            'api'   => [
                (int) env('ratelimit.apiPerMinute', 120),
                60,
            ],
            default => [60, 60],
        };
    }
}
