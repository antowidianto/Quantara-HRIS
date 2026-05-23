<?php

namespace Tests\Unit;

use App\Libraries\ShiftTimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ShiftTimeHelperTest — verifikasi resolve waktu shift termasuk untuk
 * shift cross-midnight (overnight). Sprint 5-6 fix.
 *
 * Run: vendor/bin/phpunit --filter ShiftTimeHelperTest
 */
class ShiftTimeHelperTest extends TestCase
{
    private ShiftTimeHelper $h;

    protected function setUp(): void
    {
        $this->h = new ShiftTimeHelper();
    }

    private function shift(string $start, string $end, ?int $tol = null): array
    {
        return [
            'start_time' => $start,
            'end_time'   => $end,
            'late_tolerance_minutes' => $tol ?? 0,
        ];
    }

    // ─── isOvernight ────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function overnightProvider(): array
    {
        return [
            'pagi 07-15 normal'    => ['07:00', '15:00', false],
            'siang 14-22 normal'   => ['14:00', '22:00', false],
            'malam 22-06 cross'    => ['22:00', '06:00', true],
            'malam 23-07 cross'    => ['23:00', '07:00', true],
            'shift 24h end=start'  => ['08:00', '08:00', true],
            'subuh 04-12 normal'   => ['04:00', '12:00', false],
        ];
    }

    #[DataProvider('overnightProvider')]
    public function testIsOvernight(string $start, string $end, bool $expected): void
    {
        $this->assertSame($expected, $this->h->isOvernight($this->shift($start, $end)));
    }

    public function testIsOvernightWithInvalidTimes(): void
    {
        $this->assertFalse($this->h->isOvernight(['start_time' => null, 'end_time' => '06:00']));
        $this->assertFalse($this->h->isOvernight(['start_time' => 'invalid', 'end_time' => '06:00']));
    }

    // ─── effectiveWorkDate ──────────────────────────────────────────

    public function testWorkDateForNormalShift(): void
    {
        $shift = $this->shift('08:00', '17:00');
        // Karyawan clock-in 9 Jan 08:30
        $clockIn = strtotime('2026-01-09 08:30:00');
        $this->assertSame('2026-01-09', $this->h->effectiveWorkDate($shift, $clockIn));
    }

    public function testWorkDateForOvernightClockInBeforeMidnight(): void
    {
        // Shift 22:00 → 06:00, karyawan clock-in 8 Jan 22:00
        $shift = $this->shift('22:00', '06:00');
        $clockIn = strtotime('2026-01-08 22:00:00');
        // Work date = tanggal mulai shift = 8 Jan
        $this->assertSame('2026-01-08', $this->h->effectiveWorkDate($shift, $clockIn));
    }

    public function testWorkDateForOvernightClockInAfterMidnight(): void
    {
        // Shift 22:00 → 06:00. Karyawan datang telat: 9 Jan jam 01:00
        // → shift mulai 8 Jan 22:00, work date 8 Jan
        $shift = $this->shift('22:00', '06:00');
        $clockIn = strtotime('2026-01-09 01:00:00');
        $this->assertSame('2026-01-08', $this->h->effectiveWorkDate($shift, $clockIn));
    }

    public function testWorkDateForOvernightClockInDayShift(): void
    {
        // Shift 22:00 → 06:00. Karyawan datang awal untuk shift hari ini:
        // 9 Jan 21:30 → shift mulai 9 Jan 22:00, work date 9 Jan
        $shift = $this->shift('22:00', '06:00');
        $clockIn = strtotime('2026-01-09 21:30:00');
        $this->assertSame('2026-01-09', $this->h->effectiveWorkDate($shift, $clockIn));
    }

    // ─── lateMinutes ────────────────────────────────────────────────

    public function testLateMinutesNormalShiftOnTime(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $clockIn = strtotime('2026-01-09 08:00:00');
        $this->assertSame(0, $this->h->lateMinutes($shift, $clockIn));
    }

    public function testLateMinutesNormalShiftLate(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $clockIn = strtotime('2026-01-09 08:15:00');
        $this->assertSame(15, $this->h->lateMinutes($shift, $clockIn));
    }

    public function testLateMinutesNormalShiftEarly(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $clockIn = strtotime('2026-01-09 07:45:00');
        $this->assertSame(0, $this->h->lateMinutes($shift, $clockIn));
    }

    public function testLateMinutesWithTolerance(): void
    {
        $shift = $this->shift('08:00', '17:00');
        // Telat 10 menit, toleransi 15 → tidak dianggap telat
        $clockIn = strtotime('2026-01-09 08:10:00');
        $this->assertSame(0, $this->h->lateMinutes($shift, $clockIn, 15));
        // Telat 20 menit, toleransi 15 → telat 5 menit (di luar window)
        $clockIn = strtotime('2026-01-09 08:20:00');
        $this->assertSame(5, $this->h->lateMinutes($shift, $clockIn, 15));
    }

    public function testLateMinutesOvernightShiftOnTime(): void
    {
        // Shift 22:00 → 06:00, karyawan clock-in tepat 22:00 → 0 menit
        $shift = $this->shift('22:00', '06:00');
        $clockIn = strtotime('2026-01-08 22:00:00');
        $this->assertSame(0, $this->h->lateMinutes($shift, $clockIn));
    }

    public function testLateMinutesOvernightShiftLateNextDay(): void
    {
        // Shift 22:00 → 06:00, karyawan datang 9 Jan 01:00
        // → shift mulai 8 Jan 22:00 → telat 3 jam = 180 menit
        $shift = $this->shift('22:00', '06:00');
        $clockIn = strtotime('2026-01-09 01:00:00');
        $this->assertSame(180, $this->h->lateMinutes($shift, $clockIn));
    }

    public function testLateMinutesOvernightShiftEarlyNoNegative(): void
    {
        // Shift 22:00 → 06:00, karyawan datang 21:30 → tidak terlambat
        $shift = $this->shift('22:00', '06:00');
        $clockIn = strtotime('2026-01-08 21:30:00');
        $this->assertSame(0, $this->h->lateMinutes($shift, $clockIn));
    }

    // ─── effectiveShiftEnd & earlyLeaveMinutes ──────────────────────

    public function testShiftEndForNormalShift(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $start = strtotime('2026-01-09 08:00:00');
        $end = $this->h->effectiveShiftEnd($shift, $start);
        $this->assertSame('2026-01-09 17:00:00', date('Y-m-d H:i:s', $end));
    }

    public function testShiftEndForOvernightShift(): void
    {
        $shift = $this->shift('22:00', '06:00');
        $start = strtotime('2026-01-08 22:00:00');
        $end = $this->h->effectiveShiftEnd($shift, $start);
        // Berakhir keesokan paginya (9 Jan 06:00)
        $this->assertSame('2026-01-09 06:00:00', date('Y-m-d H:i:s', $end));
    }

    public function testEarlyLeaveMinutesNormalShiftOnTime(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $start = strtotime('2026-01-09 08:00:00');
        $clockOut = strtotime('2026-01-09 17:00:00');
        $this->assertSame(0, $this->h->earlyLeaveMinutes($shift, $clockOut, $start));
    }

    public function testEarlyLeaveMinutesNormalShiftEarly(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $start = strtotime('2026-01-09 08:00:00');
        $clockOut = strtotime('2026-01-09 16:30:00');
        $this->assertSame(30, $this->h->earlyLeaveMinutes($shift, $clockOut, $start));
    }

    public function testEarlyLeaveMinutesOvernightShift(): void
    {
        // Karyawan pulang 9 Jan jam 05:30 untuk shift 22-06
        // → shift end = 9 Jan 06:00, pulang 30 menit lebih awal
        $shift = $this->shift('22:00', '06:00');
        $start = strtotime('2026-01-08 22:00:00');
        $clockOut = strtotime('2026-01-09 05:30:00');
        $this->assertSame(30, $this->h->earlyLeaveMinutes($shift, $clockOut, $start));
    }

    public function testEarlyLeaveMinutesAfterShiftEndIsZero(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $start = strtotime('2026-01-09 08:00:00');
        $clockOut = strtotime('2026-01-09 18:30:00');
        $this->assertSame(0, $this->h->earlyLeaveMinutes($shift, $clockOut, $start));
    }
}