<?php

namespace Tests\Unit;

use App\Libraries\InboundWebhookHandler;
use App\Libraries\WebhookSigner;
use PHPUnit\Framework\TestCase;

/**
 * Unit test untuk InboundWebhookHandler (Sprint 14+).
 *
 * Pure registry + IP allowlist + signature verify (delegate ke WebhookSigner).
 * Decrypt secret pakai Services::encryption — kita test path itu di
 * integration test terpisah; di sini fokus ke logic murni:
 *   - Registry: register/hasHandler/dispatch
 *   - IP allowlist: exact, CIDR, no filter, IPv4 boundaries
 *   - Verify signature: signature valid/invalid (raw secret tanpa encrypt)
 */
class InboundWebhookHandlerTest extends TestCase
{
    /* ──────────────────────────────────────────────────────────────────
     * Registry
     * ──────────────────────────────────────────────────────────────── */

    public function testHasHandlerReturnsFalseForUnknownEvent(): void
    {
        $h = new InboundWebhookHandler();
        $this->assertFalse($h->hasHandler('candidate.applied'));
    }

    public function testRegisterAndHasHandler(): void
    {
        $h = new InboundWebhookHandler();
        $h->register('candidate.applied', static fn (array $p, array $s) => null);
        $this->assertTrue($h->hasHandler('candidate.applied'));
        $this->assertFalse($h->hasHandler('candidate.rejected'));
    }

    public function testDispatchInvokesHandlerWithPayloadAndSource(): void
    {
        $h = new InboundWebhookHandler();
        $captured = null;
        $h->register('candidate.applied', static function (array $p, array $s) use (&$captured) {
            $captured = ['p' => $p, 's' => $s];
        });

        $result = $h->dispatch('candidate.applied',
            ['name' => 'Budi', 'email' => 'budi@example.com'],
            ['id' => 1, 'slug' => 'talenta', 'company_id' => 5]);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('Budi', $captured['p']['name']);
        $this->assertSame('talenta', $captured['s']['slug']);
    }

    public function testDispatchRunsMultipleHandlersSequentially(): void
    {
        $h = new InboundWebhookHandler();
        $count = 0;
        $h->register('test.event', static function () use (&$count) { $count++; });
        $h->register('test.event', static function () use (&$count) { $count++; });
        $h->register('test.event', static function () use (&$count) { $count++; });

        $result = $h->dispatch('test.event', [], ['id' => 1, 'slug' => 's', 'company_id' => 1]);

        $this->assertSame(3, $count);
        $this->assertSame(3, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    public function testDispatchContinuesAfterHandlerFailure(): void
    {
        $h = new InboundWebhookHandler();
        $h->register('test.event', static function () {
            throw new \RuntimeException('boom-1');
        });
        $okCalled = false;
        $h->register('test.event', static function () use (&$okCalled) {
            $okCalled = true;
        });
        $h->register('test.event', static function () {
            throw new \RuntimeException('boom-2');
        });

        $result = $h->dispatch('test.event', [], ['id' => 1, 'slug' => 's', 'company_id' => 1]);

        $this->assertTrue($okCalled, 'Handler tengah harus tetap eksekusi meski yang lain throw');
        $this->assertSame(1, $result['processed']);
        $this->assertSame(2, $result['failed']);
        $this->assertCount(2, $result['errors']);
        $this->assertContains('boom-1', $result['errors']);
        $this->assertContains('boom-2', $result['errors']);
    }

    public function testDispatchWithoutHandlerReturnsZeroCounts(): void
    {
        $h = new InboundWebhookHandler();
        $result = $h->dispatch('unknown.event', ['x' => 1],
            ['id' => 1, 'slug' => 's', 'company_id' => 1]);
        $this->assertSame(['processed' => 0, 'failed' => 0, 'errors' => []], $result);
    }

    /* ──────────────────────────────────────────────────────────────────
     * IP allowlist
     * ──────────────────────────────────────────────────────────────── */

    public function testIpAllowedReturnsTrueWhenNoFilterSet(): void
    {
        $h = new InboundWebhookHandler();
        $this->assertTrue($h->ipAllowed(['allowed_ips' => null], '203.0.113.10'));
        $this->assertTrue($h->ipAllowed(['allowed_ips' => ''], '203.0.113.10'));
        $this->assertTrue($h->ipAllowed(['allowed_ips' => '[]'], '203.0.113.10'));
    }

    public function testIpAllowedExactMatch(): void
    {
        $h = new InboundWebhookHandler();
        $source = ['allowed_ips' => json_encode(['203.0.113.10', '198.51.100.5'])];
        $this->assertTrue($h->ipAllowed($source, '203.0.113.10'));
        $this->assertTrue($h->ipAllowed($source, '198.51.100.5'));
        $this->assertFalse($h->ipAllowed($source, '203.0.113.11'));
    }

    public function testIpAllowedCidrMatch(): void
    {
        $h = new InboundWebhookHandler();
        $source = ['allowed_ips' => json_encode(['10.0.0.0/8', '192.168.1.0/24'])];

        // /8 — first octet match
        $this->assertTrue($h->ipAllowed($source, '10.0.0.1'));
        $this->assertTrue($h->ipAllowed($source, '10.255.255.255'));
        $this->assertFalse($h->ipAllowed($source, '11.0.0.1'));

        // /24 — first 3 octets match
        $this->assertTrue($h->ipAllowed($source, '192.168.1.50'));
        $this->assertFalse($h->ipAllowed($source, '192.168.2.50'));
    }

    public function testIpAllowedMixedExactAndCidr(): void
    {
        $h = new InboundWebhookHandler();
        $source = ['allowed_ips' => json_encode(['203.0.113.10', '10.0.0.0/8'])];
        $this->assertTrue($h->ipAllowed($source, '203.0.113.10'));      // exact
        $this->assertTrue($h->ipAllowed($source, '10.10.10.10'));        // cidr
        $this->assertFalse($h->ipAllowed($source, '203.0.113.11'));     // neither
    }

    public function testIpAllowedRejectsInvalidEntries(): void
    {
        $h = new InboundWebhookHandler();
        // Mask out of range / non-IP — gracefully fail-closed
        $source = ['allowed_ips' => json_encode(['10.0.0.0/99', 'not-an-ip', ''])];
        $this->assertFalse($h->ipAllowed($source, '10.0.0.1'));
    }

    /* ──────────────────────────────────────────────────────────────────
     * Signature verify
     * ──────────────────────────────────────────────────────────────── */

    public function testVerifyRejectsMissingHeaders(): void
    {
        $h = new InboundWebhookHandler();
        $source = ['secret_enc' => 'whatever'];
        $this->assertSame(
            'Missing signature or timestamp header',
            $h->verifySignature($source, '{"a":1}', '', '12345'),
        );
        $this->assertSame(
            'Missing signature or timestamp header',
            $h->verifySignature($source, '{"a":1}', 'sha256=abc', null),
        );
        $this->assertSame(
            'Missing signature or timestamp header',
            $h->verifySignature($source, '{"a":1}', 'sha256=abc', ''),
        );
    }

    /**
     * Verifikasi via WebhookSigner langsung (raw secret), tanpa encrypt
     * layer, untuk fokus test logic murni signature.
     *
     * Override ke handler stub yang skip decrypt (re-test path utama via
     * static helper agar tidak depend on Services::encryption).
     */
    public function testVerifySignatureValidAndInvalid(): void
    {
        $secret = 'shared-secret-for-test';
        $rawBody = '{"event":"candidate.applied","name":"Budi"}';
        $eventType = 'candidate.applied';
        $ts = time();

        $signed = WebhookSigner::sign($rawBody, $secret, $eventType, $ts);

        // Sanity check pakai static verify directly
        $this->assertTrue(WebhookSigner::verify(
            $rawBody, $signed['signature'], $signed['timestamp'], $secret, $ts,
        ));

        // Tampered body → invalid
        $this->assertFalse(WebhookSigner::verify(
            $rawBody . 'X', $signed['signature'], $signed['timestamp'], $secret, $ts,
        ));

        // Wrong secret → invalid
        $this->assertFalse(WebhookSigner::verify(
            $rawBody, $signed['signature'], $signed['timestamp'], 'wrong-secret', $ts,
        ));

        // Expired timestamp (10 menit lalu, di luar 5 menit window)
        $oldTs = $ts - 600;
        $oldSigned = WebhookSigner::sign($rawBody, $secret, $eventType, $oldTs);
        $this->assertFalse(WebhookSigner::verify(
            $rawBody, $oldSigned['signature'], $oldSigned['timestamp'], $secret, $ts,
        ));
    }
}