<?php

namespace Tests\Unit;

use App\Libraries\System\EmailQueueBackoff;
use PHPUnit\Framework\TestCase;

/**
 * EmailQueueBackoffTest — verifikasi exponential backoff schedule.
 *
 * Run: vendor/bin/phpunit --filter EmailQueueBackoffTest
 */
class EmailQueueBackoffTest extends TestCase
{
    // ─── secondsForAttempt() ───────────────────────────────────────

    public function testAttemptZeroReturnsZero(): void
    {
        $this->assertSame(0, EmailQueueBackoff::secondsForAttempt(0));
    }

    public function testAttemptOneIsAroundOneMinute(): void
    {
        // 60s ± 20% jitter → 48..72
        $delay = EmailQueueBackoff::secondsForAttempt(1);
        $this->assertGreaterThanOrEqual(48, $delay);
        $this->assertLessThanOrEqual(72, $delay);
    }

    public function testAttemptTwoIsAroundFiveMinutes(): void
    {
        // 300s ± 20% → 240..360
        $delay = EmailQueueBackoff::secondsForAttempt(2);
        $this->assertGreaterThanOrEqual(240, $delay);
        $this->assertLessThanOrEqual(360, $delay);
    }

    public function testAttemptThreeIsAroundThirtyMinutes(): void
    {
        // 1800s ± 20% → 1440..2160
        $delay = EmailQueueBackoff::secondsForAttempt(3);
        $this->assertGreaterThanOrEqual(1_440, $delay);
        $this->assertLessThanOrEqual(2_160, $delay);
    }

    public function testAttemptBeyondMaxReturnsZero(): void
    {
        $this->assertSame(0, EmailQueueBackoff::secondsForAttempt(4));
        $this->assertSame(0, EmailQueueBackoff::secondsForAttempt(99));
    }

    public function testNegativeAttemptReturnsZero(): void
    {
        $this->assertSame(0, EmailQueueBackoff::secondsForAttempt(-1));
    }

    public function testJitterProducesDifferentValues(): void
    {
        // Jalankan 10x, harus menghasilkan setidaknya 2 nilai berbeda
        // (probabilitas semua sama dengan jitter ±20% sangat kecil).
        $values = [];
        for ($i = 0; $i < 10; $i++) {
            $values[] = EmailQueueBackoff::secondsForAttempt(2);
        }
        $unique = array_unique($values);
        $this->assertGreaterThanOrEqual(2, count($unique),
            'Jitter harus menghasilkan minimal 2 nilai berbeda dalam 10 panggilan');
    }

    // ─── nextRetryAt() ──────────────────────────────────────────────

    public function testNextRetryAtFormat(): void
    {
        $now = strtotime('2026-01-15 10:00:00');
        $next = EmailQueueBackoff::nextRetryAt(1, $now);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $next
        );
    }

    public function testNextRetryAtIsInFuture(): void
    {
        $now = strtotime('2026-01-15 10:00:00');
        $next = EmailQueueBackoff::nextRetryAt(2, $now);
        $this->assertGreaterThan($now, strtotime($next));
    }

    public function testNextRetryAtForExhaustedAttemptIsNow(): void
    {
        // attempt > MAX → delay 0 → nextRetryAt = now
        $now = strtotime('2026-01-15 10:00:00');
        $next = EmailQueueBackoff::nextRetryAt(99, $now);
        $this->assertSame($now, strtotime($next));
    }

    // ─── isReady() ──────────────────────────────────────────────────

    public function testIsReadyForNewRow(): void
    {
        $row = ['attempts' => 0];
        $this->assertTrue(EmailQueueBackoff::isReady($row));
    }

    public function testIsReadyWhenNextRetryPassed(): void
    {
        $row = [
            'attempts'      => 1,
            'next_retry_at' => date('Y-m-d H:i:s', time() - 60),
        ];
        $this->assertTrue(EmailQueueBackoff::isReady($row));
    }

    public function testIsReadyFalseWhenNextRetryInFuture(): void
    {
        $row = [
            'attempts'      => 1,
            'next_retry_at' => date('Y-m-d H:i:s', time() + 60),
        ];
        $this->assertFalse(EmailQueueBackoff::isReady($row));
    }

    public function testIsReadyFalseWhenAttemptsExhausted(): void
    {
        $row = [
            'attempts'      => EmailQueueBackoff::MAX_ATTEMPTS,
            'next_retry_at' => null,
        ];
        $this->assertFalse(EmailQueueBackoff::isReady($row));
    }

    public function testIsReadyHandlesLegacyRowWithoutNextRetryAt(): void
    {
        // Legacy data (sebelum migration #039) tidak punya kolom next_retry_at
        $row = ['attempts' => 1];
        $this->assertTrue(EmailQueueBackoff::isReady($row));
    }

    // ─── isExhausted() ──────────────────────────────────────────────

    public function testIsExhaustedAtMaxAttempts(): void
    {
        $this->assertTrue(EmailQueueBackoff::isExhausted([
            'attempts' => EmailQueueBackoff::MAX_ATTEMPTS,
        ]));
        $this->assertTrue(EmailQueueBackoff::isExhausted([
            'attempts' => EmailQueueBackoff::MAX_ATTEMPTS + 1,
        ]));
    }

    public function testIsNotExhaustedBelowMax(): void
    {
        $this->assertFalse(EmailQueueBackoff::isExhausted(['attempts' => 0]));
        $this->assertFalse(EmailQueueBackoff::isExhausted(['attempts' => 1]));
        $this->assertFalse(EmailQueueBackoff::isExhausted([
            'attempts' => EmailQueueBackoff::MAX_ATTEMPTS - 1,
        ]));
    }

    public function testMaxAttemptsConstantIsExposed(): void
    {
        $this->assertGreaterThan(0, EmailQueueBackoff::MAX_ATTEMPTS);
    }
}