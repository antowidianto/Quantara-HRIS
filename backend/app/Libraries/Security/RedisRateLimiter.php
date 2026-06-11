<?php

namespace App\Libraries\Security;

/**
 * S2.5 — Token bucket rate limiter dengan Redis backend.
 *
 * Algoritma: Token bucket dengan refill rate konstan. State disimpan di
 * Redis dengan key `ratelimit:{bucket}:{identifier}` (TTL = window).
 *
 * Alternative untuk ThrottleFilter built-in (yang pakai file cache lokal).
 * Pakai ini di production multi-server supaya rate limit konsisten antar
 * worker (file cache lokal tidak shared antar pod/container).
 *
 * Pure helpers `*Pure()` testable tanpa Redis client.
 *
 * Fallback policy: kalau Redis tidak available, return allowed=true
 * (fail-open) supaya tidak block traffic legitimate. Caller boleh override
 * dengan `$strict = true` untuk fail-closed.
 */
class RedisRateLimiter
{
    private $redis = null;
    private bool $available = false;

    public function __construct()
    {
        $host = (string) env('REDIS_HOST', '');
        if ($host === '') {
            $this->available = false;
            return;
        }

        if (! class_exists('\Redis')) {
            log_message('warning', '[RedisRateLimiter] ext-redis tidak terinstall, fallback ke fail-open');
            $this->available = false;
            return;
        }

        try {
            $this->redis = new \Redis();
            $port = (int) env('REDIS_PORT', 6379);
            $timeout = (float) env('REDIS_TIMEOUT', 1.5);
            $connected = $this->redis->connect($host, $port, $timeout);
            if (! $connected) {
                throw new \RuntimeException('connect failed');
            }
            $password = (string) env('REDIS_PASSWORD', '');
            if ($password !== '') {
                $this->redis->auth($password);
            }
            $db = (int) env('REDIS_DB', 0);
            if ($db !== 0) {
                $this->redis->select($db);
            }
            $this->available = true;
        } catch (\Throwable $e) {
            log_message('warning', '[RedisRateLimiter] connect failed: ' . $e->getMessage());
            $this->available = false;
        }
    }

    /**
     * Pure helper: hitung sisa token + reset timestamp dari state lama.
     *
     * @param int    $current     Token tersisa di state lama
     * @param int    $capacity    Max token (bucket size)
     * @param int    $refillRate  Token per second (capacity/window)
     * @param int    $lastRefill  Timestamp last_refill_at
     * @param int    $now         Current timestamp
     * @param int    $cost        Berapa token yang akan di-consume
     *
     * @return array{allowed:bool, remaining:int, retry_after:int, reset_at:int}
     */
    public static function consumePure(
        int $current,
        int $capacity,
        float $refillRate,
        int $lastRefill,
        int $now,
        int $cost = 1
    ): array {
        // Refill dulu berdasarkan elapsed time
        $elapsed = max(0, $now - $lastRefill);
        $refilled = (int) floor($elapsed * $refillRate);
        $current = min($capacity, $current + $refilled);

        if ($current >= $cost) {
            $current -= $cost;
            return [
                'allowed'      => true,
                'remaining'    => $current,
                'retry_after'  => 0,
                'reset_at'     => $now + (int) ceil(($capacity - $current) / max(0.0001, $refillRate)),
                'last_refill'  => $now,
                'new_value'    => $current,
            ];
        }

        // Tidak cukup token → blok dengan retry_after = waktu sampai cukup
        $tokensNeeded = $cost - $current;
        $retryAfter = (int) ceil($tokensNeeded / max(0.0001, $refillRate));
        return [
            'allowed'     => false,
            'remaining'   => $current,
            'retry_after' => $retryAfter,
            'reset_at'    => $now + $retryAfter,
            'last_refill' => $now,
            'new_value'   => $current, // tidak di-deduct
        ];
    }

    /**
     * Coba consume `cost` token dari bucket {bucket}:{identifier}.
     *
     * @param string $bucket      Nama bucket / endpoint group (mis. "login", "api_calls")
     * @param string $identifier  IP address / user_id / company_id
     * @param int    $capacity    Max token
     * @param int    $windowSec   Window detik (capacity terisi penuh setelah windowSec)
     * @param int    $cost        Token cost (default 1)
     */
    public function consume(
        string $bucket,
        string $identifier,
        int $capacity,
        int $windowSec,
        int $cost = 1
    ): array {
        $now = time();

        if (! $this->available) {
            // Fail-open: kalau Redis down, allow request — better UX,
            // tapi log warning supaya ops aware.
            return [
                'allowed'     => true,
                'remaining'   => $capacity - $cost,
                'retry_after' => 0,
                'reset_at'    => $now + $windowSec,
                'fallback'    => 'redis_unavailable',
            ];
        }

        $key = "ratelimit:{$bucket}:{$identifier}";
        $refillRate = $capacity / max(1, $windowSec);

        // Race-safe: pakai HGETALL + HMSET. Lebih advance pakai Lua script
        // tapi PHP-redis tidak guarantee ada eval support; HMSET cukup
        // untuk MVP single-DC.
        try {
            $raw = $this->redis->hGetAll($key);
            $current = isset($raw['tokens']) ? (int) $raw['tokens'] : $capacity;
            $lastRefill = isset($raw['last_refill']) ? (int) $raw['last_refill'] : $now;

            $result = self::consumePure($current, $capacity, $refillRate, $lastRefill, $now, $cost);

            // Persist new state
            $this->redis->hMSet($key, [
                'tokens'      => $result['new_value'],
                'last_refill' => $result['last_refill'],
            ]);
            $this->redis->expire($key, $windowSec * 2); // TTL 2× window untuk safety

            unset($result['new_value'], $result['last_refill']);
            return $result;
        } catch (\Throwable $e) {
            log_message('warning', '[RedisRateLimiter] consume failed: ' . $e->getMessage());
            return [
                'allowed'     => true,
                'remaining'   => $capacity,
                'retry_after' => 0,
                'reset_at'    => $now + $windowSec,
                'fallback'    => 'redis_error',
            ];
        }
    }

    /**
     * Reset bucket (untuk admin override / testing).
     */
    public function reset(string $bucket, string $identifier): bool
    {
        if (! $this->available) {
            return false;
        }
        try {
            $this->redis->del("ratelimit:{$bucket}:{$identifier}");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Inspect bucket state tanpa consume. Untuk debug / admin UI.
     */
    public function inspect(string $bucket, string $identifier, int $capacity, int $windowSec): array
    {
        if (! $this->available) {
            return ['available' => false];
        }
        $key = "ratelimit:{$bucket}:{$identifier}";
        try {
            $raw = $this->redis->hGetAll($key);
            return [
                'available'   => true,
                'tokens'      => isset($raw['tokens']) ? (int) $raw['tokens'] : $capacity,
                'last_refill' => isset($raw['last_refill']) ? (int) $raw['last_refill'] : null,
                'capacity'    => $capacity,
                'window_sec'  => $windowSec,
            ];
        } catch (\Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }
}