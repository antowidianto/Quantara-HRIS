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
            $key = sprintf(
                'throttle:%s:%s:%s',
                $bucket,
                $request->getIPAddress(),
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
            'api'   => [
                (int) env('ratelimit.apiPerMinute', 120),
                60,
            ],
            default => [60, 60],
        };
    }
}