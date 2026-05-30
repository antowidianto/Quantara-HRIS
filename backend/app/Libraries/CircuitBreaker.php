<?php

namespace App\Libraries;

/**
 * CircuitBreaker — Sprint D / D6.
 *
 * Pelindung untuk panggilan ke external service yang lambat atau tidak
 * stabil (WhatsApp provider, OCR, AI Copilot, webhook outbound). Tanpa
 * circuit breaker, kalau provider down, request HRIS akan menggantung
 * sampai timeout HTTP — user experience hancur dan thread pool habis.
 *
 * Pattern 3-state klasik:
 *
 *   CLOSED — normal operation. Setiap call diteruskan ke service. Kalau
 *            failure rate > threshold dalam window time, switch ke OPEN.
 *
 *   OPEN   — service dianggap down. Call langsung throw `CircuitOpenException`
 *            tanpa coba ke service (fail fast). Setelah cool-down window,
 *            switch ke HALF_OPEN.
 *
 *   HALF_OPEN — coba 1 kali. Sukses → CLOSED, gagal → OPEN lagi.
 *
 * State persisted di cache (Redis di prod, file di dev). Per service ada
 * key terpisah, jadi WhatsApp down tidak men-trip OCR dst.
 *
 * Usage:
 *
 *   $cb = new CircuitBreaker('whatsapp');
 *   try {
 *       $result = $cb->call(fn() => $whatsAppService->sendMessage($payload));
 *   } catch (CircuitOpenException $e) {
 *       // Service down, log & queue ulang nanti
 *       Services::audit()->logFailure('whatsapp.send', null, null, 'CIRCUIT_OPEN');
 *       return null;
 *   }
 *
 * Calculator murni dengan dependency cache yang di-injeksi (testable).
 * Tidak butuh DB.
 */
class CircuitBreaker
{
    public const STATE_CLOSED    = 'closed';
    public const STATE_OPEN      = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private string $service;
    private int $failureThreshold;
    private int $cooldownSeconds;
    private object $cache;

    /**
     * @param string      $service          Nama service (mis. 'whatsapp')
     * @param int         $failureThreshold Jumlah failure berturut sebelum trip ke OPEN
     * @param int         $cooldownSeconds  Berapa detik di-OPEN sebelum HALF_OPEN
     * @param object|null $cache            Optional cache backend (default: service('cache'))
     */
    public function __construct(
        string $service,
        int $failureThreshold = 5,
        int $cooldownSeconds = 60,
        ?object $cache = null,
    ) {
        $this->service          = $service;
        $this->failureThreshold = max(1, $failureThreshold);
        $this->cooldownSeconds  = max(1, $cooldownSeconds);
        $this->cache            = $cache ?? service('cache');
    }

    /**
     * Eksekusi callback dengan circuit breaker protection.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     * @throws CircuitOpenException Saat state = OPEN dan masih cooldown
     */
    public function call(callable $callback)
    {
        $state = $this->state();

        if ($state === self::STATE_OPEN) {
            throw new CircuitOpenException(
                "Circuit breaker for service '{$this->service}' is OPEN. Retry after cooldown."
            );
        }

        try {
            $result = $callback();
            $this->recordSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    /**
     * Cek state saat ini. Side-effect: kalau OPEN tapi cooldown sudah lewat,
     * transition ke HALF_OPEN.
     */
    public function state(): string
    {
        $stateData = $this->loadState();
        $now = time();

        if ($stateData['state'] === self::STATE_OPEN
            && $stateData['opened_at'] > 0
            && ($now - $stateData['opened_at']) >= $this->cooldownSeconds
        ) {
            // Transition ke HALF_OPEN — beri kesempatan retry sekali
            $stateData['state'] = self::STATE_HALF_OPEN;
            $this->saveState($stateData);
        }

        return $stateData['state'];
    }

    /**
     * Catat success — reset failure count + set state ke CLOSED.
     */
    public function recordSuccess(): void
    {
        $this->saveState([
            'state'        => self::STATE_CLOSED,
            'failures'     => 0,
            'opened_at'    => 0,
            'last_success' => time(),
        ]);
    }

    /**
     * Catat failure. Increment counter; kalau > threshold, trip ke OPEN.
     * Kalau state HALF_OPEN dan gagal lagi, langsung OPEN.
     */
    public function recordFailure(): void
    {
        $stateData = $this->loadState();
        $stateData['failures']++;
        $stateData['last_failure'] = time();

        if ($stateData['state'] === self::STATE_HALF_OPEN
            || $stateData['failures'] >= $this->failureThreshold
        ) {
            $stateData['state']     = self::STATE_OPEN;
            $stateData['opened_at'] = time();
        }

        $this->saveState($stateData);
    }

    /**
     * Reset paksa ke CLOSED — pakai untuk admin override / setelah maintenance.
     */
    public function reset(): void
    {
        $this->saveState([
            'state'     => self::STATE_CLOSED,
            'failures'  => 0,
            'opened_at' => 0,
        ]);
    }

    /**
     * Snapshot state untuk monitoring / dashboard ops.
     *
     * @return array{
     *   service:string,
     *   state:string,
     *   failures:int,
     *   opened_at:int,
     *   last_success:int,
     *   last_failure:int,
     *   threshold:int,
     *   cooldown_seconds:int
     * }
     */
    public function snapshot(): array
    {
        $stateData = $this->loadState();
        return [
            'service'          => $this->service,
            'state'            => $stateData['state'],
            'failures'         => $stateData['failures'],
            'opened_at'        => $stateData['opened_at'],
            'last_success'     => $stateData['last_success'] ?? 0,
            'last_failure'     => $stateData['last_failure'] ?? 0,
            'threshold'        => $this->failureThreshold,
            'cooldown_seconds' => $this->cooldownSeconds,
        ];
    }

    private function key(): string
    {
        return 'hris:cb:' . $this->service;
    }

    /**
     * @return array{
     *   state:string,
     *   failures:int,
     *   opened_at:int,
     *   last_success?:int,
     *   last_failure?:int
     * }
     */
    private function loadState(): array
    {
        try {
            $raw = $this->cache->get($this->key());
        } catch (\Throwable $e) {
            $raw = null;
        }
        if (is_array($raw) && isset($raw['state'])) {
            return $raw;
        }
        return [
            'state'     => self::STATE_CLOSED,
            'failures'  => 0,
            'opened_at' => 0,
        ];
    }

    private function saveState(array $data): void
    {
        try {
            // TTL = 24 jam — kalau service mati > 24 jam, biarkan reset alami.
            $this->cache->save($this->key(), $data, 86_400);
        } catch (\Throwable $e) {
            // Cache backend down — log & lewatkan; circuit breaker degrade
            // jadi pass-through (better than block all calls).
            log_message('warning', 'CircuitBreaker.saveState failed for ' . $this->service . ': ' . $e->getMessage());
        }
    }
}