<?php

namespace Tests\Unit;

use App\Libraries\System\CircuitBreaker;
use App\Libraries\System\CircuitOpenException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit test CircuitBreaker — Sprint D / D6.
 *
 * Verifikasi state machine 3-state (CLOSED → OPEN → HALF_OPEN → CLOSED) dan
 * boundary perilaku: increment failure, threshold trip, cooldown transition,
 * reset paksa, snapshot.
 *
 * Cache backend di-stub dengan in-memory array supaya test tidak menyentuh
 * Redis/file. Stub di-konstruksi di setUp() supaya tiap test isolasi.
 *
 * Konvensi: pakai `PHPUnit\Framework\TestCase` polos (bukan CIUnitTestCase)
 * supaya tidak butuh bootstrap `TESTPATH` constant CodeIgniter.
 */
final class CircuitBreakerTest extends TestCase
{
    private object $cache;

    protected function setUp(): void
    {
        parent::setUp();
        // Stub cache: in-memory key/value, abaikan TTL.
        $this->cache = new class () {
            private array $store = [];

            public function get(string $key)
            {
                return $this->store[$key] ?? null;
            }

            public function save(string $key, $value, int $ttl = 60): bool
            {
                $this->store[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);
                return true;
            }

            public function deleteMatching(string $pattern): bool
            {
                return true;
            }
        };
    }

    public function testInitialStateIsClosed(): void
    {
        $cb = new CircuitBreaker('test', 3, 30, $this->cache);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->state());
    }

    public function testSuccessfulCallReturnsResultAndKeepsClosed(): void
    {
        $cb = new CircuitBreaker('test', 3, 30, $this->cache);
        $result = $cb->call(fn() => 'ok');
        $this->assertSame('ok', $result);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->state());
    }

    public function testFailureBelowThresholdKeepsClosed(): void
    {
        $cb = new CircuitBreaker('test', 3, 30, $this->cache);

        // 2 kegagalan, threshold 3 — masih CLOSED
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->call(fn() => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                // expected
            }
        }

        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->state());
        $snap = $cb->snapshot();
        $this->assertSame(2, $snap['failures']);
    }

    public function testReachingThresholdTripsToOpen(): void
    {
        $cb = new CircuitBreaker('test', 3, 30, $this->cache);

        for ($i = 0; $i < 3; $i++) {
            try {
                $cb->call(fn() => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                // expected
            }
        }

        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->state());
    }

    public function testOpenStateBlocksCallsWithCircuitOpenException(): void
    {
        $cb = new CircuitBreaker('test', 2, 30, $this->cache);

        // Trip dulu
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->call(fn() => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                // expected
            }
        }
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->state());

        // Call berikutnya harus throw CircuitOpenException tanpa eksekusi callback
        $callbackInvoked = false;
        $this->expectException(CircuitOpenException::class);
        try {
            $cb->call(function () use (&$callbackInvoked) {
                $callbackInvoked = true;
                return 'should-not-reach';
            });
        } finally {
            $this->assertFalse($callbackInvoked, 'Callback should not be invoked while OPEN');
        }
    }

    public function testCooldownTransitionsToHalfOpen(): void
    {
        // Cooldown 1 detik supaya test cepat
        $cb = new CircuitBreaker('test', 2, 1, $this->cache);

        // Trip
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->call(fn() => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                // expected
            }
        }
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->state());

        // Manipulasi state agar opened_at di masa lalu (simulasi cooldown lewat)
        // tanpa harus sleep() di test
        $key = 'hris:cb:test';
        $stateData = $this->cache->get($key);
        $stateData['opened_at'] = time() - 10; // 10 detik lalu, > cooldown 1 detik
        $this->cache->save($key, $stateData);

        // Cek state — harus transition ke HALF_OPEN otomatis
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->state());
    }

    public function testHalfOpenSuccessClosesCircuit(): void
    {
        $cb = new CircuitBreaker('test', 2, 1, $this->cache);

        // Trip
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->call(fn() => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                // expected
            }
        }

        // Force ke HALF_OPEN
        $key = 'hris:cb:test';
        $stateData = $this->cache->get($key);
        $stateData['opened_at'] = time() - 10;
        $this->cache->save($key, $stateData);
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->state());

        // Successful call → CLOSED
        $result = $cb->call(fn() => 'recovered');
        $this->assertSame('recovered', $result);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->state());
        $this->assertSame(0, $cb->snapshot()['failures']);
    }

    public function testHalfOpenFailureReopensImmediately(): void
    {
        $cb = new CircuitBreaker('test', 5, 1, $this->cache);

        // Trip dengan 5 failure
        for ($i = 0; $i < 5; $i++) {
            try {
                $cb->call(fn() => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                // expected
            }
        }
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->state());

        // Force ke HALF_OPEN
        $key = 'hris:cb:test';
        $stateData = $this->cache->get($key);
        $stateData['opened_at'] = time() - 10;
        $this->cache->save($key, $stateData);
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->state());

        // Failure di HALF_OPEN harus langsung re-OPEN (tanpa nunggu threshold lagi)
        try {
            $cb->call(fn() => throw new RuntimeException('still broken'));
        } catch (RuntimeException $e) {
            // expected
        }
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->state());
    }

    public function testResetForcesClosed(): void
    {
        $cb = new CircuitBreaker('test', 2, 30, $this->cache);

        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->call(fn() => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                // expected
            }
        }
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->state());

        $cb->reset();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->state());
        $this->assertSame(0, $cb->snapshot()['failures']);
    }

    public function testSnapshotContainsExpectedKeys(): void
    {
        $cb = new CircuitBreaker('whatsapp', 7, 90, $this->cache);
        $snap = $cb->snapshot();

        $this->assertSame('whatsapp', $snap['service']);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $snap['state']);
        $this->assertSame(7, $snap['threshold']);
        $this->assertSame(90, $snap['cooldown_seconds']);
        $this->assertArrayHasKey('failures', $snap);
        $this->assertArrayHasKey('opened_at', $snap);
        $this->assertArrayHasKey('last_success', $snap);
        $this->assertArrayHasKey('last_failure', $snap);
    }

    public function testDifferentServicesHaveIndependentStates(): void
    {
        $cbWhatsApp = new CircuitBreaker('whatsapp', 2, 30, $this->cache);
        $cbOcr      = new CircuitBreaker('ocr', 2, 30, $this->cache);

        // Trip whatsapp
        for ($i = 0; $i < 2; $i++) {
            try {
                $cbWhatsApp->call(fn() => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                // expected
            }
        }

        $this->assertSame(CircuitBreaker::STATE_OPEN, $cbWhatsApp->state());
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cbOcr->state(), 'OCR breaker should be unaffected');
    }

    public function testFailureThresholdBelowOneIsClampedToOne(): void
    {
        // Defensive: threshold 0 atau negatif → clamp ke 1
        $cb = new CircuitBreaker('test', 0, 30, $this->cache);

        try {
            $cb->call(fn() => throw new RuntimeException('boom'));
        } catch (RuntimeException $e) {
            // expected
        }

        // Dengan threshold efektif 1, satu kegagalan langsung trip
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->state());
    }

    public function testCallbackExceptionIsRethrown(): void
    {
        $cb = new CircuitBreaker('test', 5, 30, $this->cache);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('original error');
        $cb->call(fn() => throw new RuntimeException('original error'));
    }
}