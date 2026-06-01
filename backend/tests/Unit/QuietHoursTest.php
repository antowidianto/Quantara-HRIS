<?php

namespace Tests\Unit;

use App\Libraries\Notification\QuietHours;
use PHPUnit\Framework\TestCase;

/**
 * QuietHours unit test — Sprint Mobile M7.
 * Covers cross-midnight, normal window, edge case, dan invalid input.
 */
final class QuietHoursTest extends TestCase
{
    public function testNormalWindowSameDay(): void
    {
        $this->assertTrue(QuietHours::isQuiet('12:00', '13:00', '12:00'));
        $this->assertTrue(QuietHours::isQuiet('12:00', '13:00', '12:30'));
        $this->assertFalse(QuietHours::isQuiet('12:00', '13:00', '13:00'), 'end is exclusive');
        $this->assertFalse(QuietHours::isQuiet('12:00', '13:00', '14:00'));
        $this->assertFalse(QuietHours::isQuiet('12:00', '13:00', '11:59'));
    }

    public function testCrossMidnightWindow(): void
    {
        $this->assertTrue(QuietHours::isQuiet('22:00', '06:00', '22:00'));
        $this->assertTrue(QuietHours::isQuiet('22:00', '06:00', '23:30'));
        $this->assertTrue(QuietHours::isQuiet('22:00', '06:00', '00:00'));
        $this->assertTrue(QuietHours::isQuiet('22:00', '06:00', '03:00'));
        $this->assertTrue(QuietHours::isQuiet('22:00', '06:00', '05:59'));
        $this->assertFalse(QuietHours::isQuiet('22:00', '06:00', '06:00'), 'end exclusive even cross-midnight');
        $this->assertFalse(QuietHours::isQuiet('22:00', '06:00', '12:00'));
        $this->assertFalse(QuietHours::isQuiet('22:00', '06:00', '21:59'));
    }

    public function testEqualStartEndIsAlwaysQuiet(): void
    {
        // Documented behavior: start == end → 24-jam mute
        $this->assertTrue(QuietHours::isQuiet('08:00', '08:00', '08:00'));
        $this->assertTrue(QuietHours::isQuiet('08:00', '08:00', '15:30'));
        $this->assertTrue(QuietHours::isQuiet('08:00', '08:00', '00:00'));
    }

    public function testNullOrEmptyMeansNeverQuiet(): void
    {
        $this->assertFalse(QuietHours::isQuiet(null, '06:00', '03:00'));
        $this->assertFalse(QuietHours::isQuiet('22:00', null, '03:00'));
        $this->assertFalse(QuietHours::isQuiet('', '06:00', '03:00'));
        $this->assertFalse(QuietHours::isQuiet('22:00', '', '03:00'));
    }

    public function testInvalidFormatFailsOpen(): void
    {
        // Invalid format → false (notif tetap kirim, jangan mute by accident)
        $this->assertFalse(QuietHours::isQuiet('25:00', '06:00', '03:00'));
        $this->assertFalse(QuietHours::isQuiet('22-00', '06:00', '03:00'));
        $this->assertFalse(QuietHours::isQuiet('22:00', '6:60', '03:00'));
    }

    public function testWithSecondsFormat(): void
    {
        // MySQL TIME column biasanya kembalikan "HH:MM:SS"
        $this->assertTrue(QuietHours::isQuiet('22:00:00', '06:00:00', '23:30:45'));
    }

    public function testIsQuietAtUsesTimezone(): void
    {
        // Test bahwa wrapper isQuietAt menerapkan timezone ke timestamp UTC.
        // 22:30 di Asia/Jakarta == 15:30 UTC
        $atUtc = new \DateTimeImmutable('2026-05-28T15:30:00+00:00');
        $this->assertTrue(QuietHours::isQuietAt('22:00', '06:00', $atUtc, 'Asia/Jakarta'));

        $morningUtc = new \DateTimeImmutable('2026-05-28T03:00:00+00:00'); // 10:00 WIB
        $this->assertFalse(QuietHours::isQuietAt('22:00', '06:00', $morningUtc, 'Asia/Jakarta'));
    }
}