<?php

namespace Tests\Unit;

use App\Libraries\Webhook\WebhookSigner;
use PHPUnit\Framework\TestCase;

/**
 * Unit test untuk WebhookSigner — pure HMAC, no external dependency.
 */
class WebhookSignerTest extends TestCase
{
    public function testSignReturnsExpectedHeaders(): void
    {
        $body   = '{"event":"leave.approved","leave_id":42}';
        $secret = 'a-shared-secret-min-32-chars-long-12345';
        $ts     = 1700000000;

        $headers = WebhookSigner::sign($body, $secret, 'leave.approved', $ts);

        $this->assertArrayHasKey('signature', $headers);
        $this->assertArrayHasKey('timestamp', $headers);
        $this->assertArrayHasKey('event', $headers);

        $this->assertSame($ts, $headers['timestamp']);
        $this->assertSame('leave.approved', $headers['event']);
        $this->assertStringStartsWith('sha256=', $headers['signature']);

        // Manual compute untuk pastikan algoritma cocok
        $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
        $this->assertSame($expected, $headers['signature']);
    }

    public function testSignDefaultsToCurrentTime(): void
    {
        $before = time();
        $headers = WebhookSigner::sign('{}', 'secret', 'employee.created');
        $after = time();

        $this->assertGreaterThanOrEqual($before, $headers['timestamp']);
        $this->assertLessThanOrEqual($after, $headers['timestamp']);
    }

    public function testVerifyReturnsTrueForValidSignature(): void
    {
        $body   = '{"foo":"bar"}';
        $secret = 'shared-secret';
        $ts     = time();
        $sig    = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);

        $this->assertTrue(
            WebhookSigner::verify($body, $sig, $ts, $secret)
        );
    }

    public function testVerifyRejectsInvalidSignature(): void
    {
        $body   = '{"foo":"bar"}';
        $secret = 'shared-secret';
        $ts     = time();
        $wrongSig = 'sha256=' . str_repeat('a', 64);

        $this->assertFalse(
            WebhookSigner::verify($body, $wrongSig, $ts, $secret)
        );
    }

    public function testVerifyRejectsTamperedBody(): void
    {
        $original = '{"amount":100}';
        $tampered = '{"amount":1000000}';  // attacker change body
        $secret   = 'secret';
        $ts       = time();
        $origSig  = 'sha256=' . hash_hmac('sha256', $ts . '.' . $original, $secret);

        $this->assertFalse(
            WebhookSigner::verify($tampered, $origSig, $ts, $secret),
            'Verify must fail when body is changed but signature is original'
        );
    }

    public function testVerifyRejectsExpiredTimestamp(): void
    {
        $body   = '{}';
        $secret = 'secret';
        $oldTs  = 1000000;  // jauh di masa lalu
        $sig    = 'sha256=' . hash_hmac('sha256', $oldTs . '.' . $body, $secret);

        // Even though signature is valid, timestamp out of window → reject
        $this->assertFalse(
            WebhookSigner::verify($body, $sig, $oldTs, $secret, time())
        );
    }

    public function testVerifyAcceptsTimestampWithinTolerance(): void
    {
        $body   = '{}';
        $secret = 'secret';
        $now    = 1700000000;
        $ts     = $now - 60;  // 1 menit lalu, masih dalam window 5 menit
        $sig    = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);

        $this->assertTrue(
            WebhookSigner::verify($body, $sig, $ts, $secret, $now)
        );
    }

    public function testVerifyRejectsFutureTimestampOutsideWindow(): void
    {
        $body   = '{}';
        $secret = 'secret';
        $now    = 1700000000;
        $ts     = $now + 1000;  // 1000 detik di masa depan (clock skew tidak normal)
        $sig    = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);

        $this->assertFalse(
            WebhookSigner::verify($body, $sig, $ts, $secret, $now),
            'Future timestamp outside tolerance must reject'
        );
    }

    public function testGenerateSecretIsHex64Chars(): void
    {
        $secret = WebhookSigner::generateSecret();
        $this->assertSame(64, strlen($secret));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function testGenerateSecretReturnsUnique(): void
    {
        $a = WebhookSigner::generateSecret();
        $b = WebhookSigner::generateSecret();
        $c = WebhookSigner::generateSecret();
        $this->assertNotSame($a, $b);
        $this->assertNotSame($b, $c);
        $this->assertNotSame($a, $c);
    }

    public function testReplayToleranceConstantIsReasonable(): void
    {
        // Pastikan window tidak terlalu lebar (security) atau sempit (false reject)
        $this->assertGreaterThanOrEqual(60, WebhookSigner::REPLAY_TOLERANCE_SECONDS);
        $this->assertLessThanOrEqual(900, WebhookSigner::REPLAY_TOLERANCE_SECONDS);
    }
}