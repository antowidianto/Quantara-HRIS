<?php

namespace Tests\Unit;

use App\Models\System\IdempotencyKeyModel;
use PHPUnit\Framework\TestCase;

/**
 * Unit test untuk hash helper IdempotencyKeyModel — Mobile Prep / MOB-3.
 *
 * Hanya cover bagian pure/stateless (hashing). Operasi DB di-test
 * lewat integration test terpisah supaya unit test ini fast.
 */
final class IdempotencyKeyModelTest extends TestCase
{
    public function testHashKeyDeterministic(): void
    {
        $h1 = IdempotencyKeyModel::hashKey('clock-in-2026-05-28-08-30-uuid-abc');
        $h2 = IdempotencyKeyModel::hashKey('clock-in-2026-05-28-08-30-uuid-abc');
        $this->assertSame($h1, $h2, 'Same input must yield same hash');
        $this->assertSame(64, strlen($h1), 'SHA-256 hex must be 64 chars');
    }

    public function testHashKeyDifferentInputs(): void
    {
        $h1 = IdempotencyKeyModel::hashKey('key-1');
        $h2 = IdempotencyKeyModel::hashKey('key-2');
        $this->assertNotSame($h1, $h2, 'Different inputs must produce different hashes');
    }

    public function testHashBodyDeterministic(): void
    {
        $body = '{"latitude":-6.2,"longitude":106.8,"client_at":"2026-05-28T08:30:00+07:00"}';
        $h1 = IdempotencyKeyModel::hashBody($body);
        $h2 = IdempotencyKeyModel::hashBody($body);
        $this->assertSame($h1, $h2);
        $this->assertSame(64, strlen($h1));
    }

    public function testHashBodyDifferentBodies(): void
    {
        $h1 = IdempotencyKeyModel::hashBody('{"a":1}');
        $h2 = IdempotencyKeyModel::hashBody('{"a":2}');
        $this->assertNotSame($h1, $h2);
    }

    public function testStateConstantsCovered(): void
    {
        // Smoke test: konstanta state harus expose semua nilai ENUM
        // yang dipakai di filter & migration. Kalau ada yang missed,
        // filter akan compile ulang tapi state IN_FLIGHT tidak akan
        // ditemukan di replay.
        $this->assertSame('IN_FLIGHT', IdempotencyKeyModel::STATE_IN_FLIGHT);
        $this->assertSame('COMPLETED', IdempotencyKeyModel::STATE_COMPLETED);
        $this->assertSame('FAILED',    IdempotencyKeyModel::STATE_FAILED);
    }

    public function testDefaultTtlIsSane(): void
    {
        // 24 jam default — terlalu pendek = client retry hilang record
        // di sela offline. Terlalu panjang = storage growth ledak.
        // 24h adalah convention industri (Stripe pakai 24h juga).
        $this->assertSame(24, IdempotencyKeyModel::DEFAULT_TTL_HOURS);
    }
}