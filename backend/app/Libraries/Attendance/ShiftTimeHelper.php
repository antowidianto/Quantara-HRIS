<?php

namespace App\Libraries\Attendance;

/**
 * ShiftTimeHelper
 *
 * Utility untuk hitung waktu shift yang benar saat shift cross-midnight
 * (overnight shift), mis. shift malam 22:00 → 06:00.
 *
 * Sprint 5-6 fix:
 *  Bug lama: `AttendanceController::clockIn` pakai
 *    $shiftStart = strtotime($today . ' ' . $shift['start_time']);
 *  yang tidak tahu kalau shift cross-day. Akibatnya:
 *   - Karyawan yang clock-in tanggal 16 jam 23:30 untuk shift yang
 *     mulai jam 22:00 tanggal 15 → dianggap "telat ~91200 menit".
 *   - Work-date jadi salah (pakai today, bukan shift's effective date).
 *
 * Solusi: helper yang resolve `effectiveShiftStart()` dengan logika
 *   - Kalau shift normal (start < end): start = today + start_time
 *   - Kalau shift overnight (start >= end): mungkin start kemarin atau
 *     today, tergantung jam clock-in. Pilih candidate yang paling dekat
 *     dengan clock-in time tapi tidak lebih dari 6 jam ke depan.
 *
 * Public method:
 *  - `isOvernight($shift)`           — bool
 *  - `effectiveWorkDate($shift, $clockInTs)` — YYYY-MM-DD (tanggal mulai shift)
 *  - `effectiveShiftStart($shift, $clockInTs)` — Unix timestamp shift start
 *  - `effectiveShiftEnd($shift, $startTs)` — Unix timestamp shift end
 *  - `lateMinutes($shift, $clockInTs, $tolerance=0)` — int >= 0
 *  - `earlyLeaveMinutes($shift, $clockOutTs, $startTs, $tolerance=0)` — int >= 0
 */
class ShiftTimeHelper
{
    /** Toleransi window untuk match clock-in ke shift start (default 6 jam). */
    private const MATCH_WINDOW_HOURS = 6;

    /**
     * Apakah shift ini cross-midnight (mis. 22:00 → 06:00).
     */
    public function isOvernight(array $shift): bool
    {
        $start = $this->parseTime($shift['start_time'] ?? null);
        $end   = $this->parseTime($shift['end_time'] ?? null);
        if ($start === null || $end === null) return false;
        return $end <= $start;
    }

    /**
     * Tanggal kerja efektif untuk shift saat karyawan clock-in pada
     * timestamp tertentu. Untuk shift normal = tanggal clock-in.
     * Untuk shift overnight = tanggal mulai shift (bisa kemarin kalau
     * clock-out keesokan paginya).
     *
     * @param array $shift     Row dari `shifts` (butuh start_time, end_time)
     * @param int   $clockInTs Unix timestamp clock-in
     * @return string YYYY-MM-DD
     */
    public function effectiveWorkDate(array $shift, int $clockInTs): string
    {
        if (! $this->isOvernight($shift)) {
            return date('Y-m-d', $clockInTs);
        }
        $shiftStartTs = $this->effectiveShiftStart($shift, $clockInTs);
        return date('Y-m-d', $shiftStartTs);
    }

    /**
     * Unix timestamp shift start yang efektif untuk satu clock-in.
     *
     * Logika:
     *  - Shift normal: gunakan today @ start_time.
     *  - Shift overnight: pilih dari kandidat (kemarin start, today start)
     *    yang paling dekat ke clock-in tapi tidak melebihi clockIn +
     *    MATCH_WINDOW_HOURS (asumsi karyawan tidak clock-in lebih dari
     *    6 jam sebelum shift mulai).
     */
    public function effectiveShiftStart(array $shift, int $clockInTs): int
    {
        $startMin = $this->parseTime($shift['start_time'] ?? null) ?? 0;

        if (! $this->isOvernight($shift)) {
            return strtotime(date('Y-m-d', $clockInTs)) + ($startMin * 60);
        }

        // Overnight: kandidat = kemarin atau today
        $todayStart = strtotime(date('Y-m-d', $clockInTs)) + ($startMin * 60);
        $yesterdayStart = $todayStart - 86400;

        $window = self::MATCH_WINDOW_HOURS * 3600;
        // Prioritas: kemarin (kalau masih masuk window dari clockIn) → today
        // Kondisi kemarin valid: clockIn antara (kemarin_start - window) dan
        // (kemarin_start + 24jam + window).
        if ($clockInTs >= $yesterdayStart - $window
            && $clockInTs <= $yesterdayStart + 86400 + $window
        ) {
            // Pilih yg paling dekat ke clockIn
            $diffYesterday = abs($clockInTs - $yesterdayStart);
            $diffToday     = abs($clockInTs - $todayStart);
            if ($diffYesterday <= $diffToday) {
                return $yesterdayStart;
            }
        }
        return $todayStart;
    }

    /**
     * Unix timestamp shift end. Untuk overnight = next-day end_time.
     */
    public function effectiveShiftEnd(array $shift, int $shiftStartTs): int
    {
        $endMin = $this->parseTime($shift['end_time'] ?? null) ?? 0;
        $startDate = date('Y-m-d', $shiftStartTs);
        $endTs = strtotime($startDate) + ($endMin * 60);
        if ($this->isOvernight($shift)) {
            // End time keesokan harinya
            $endTs += 86400;
        }
        return $endTs;
    }

    /**
     * Hitung berapa menit terlambat. 0 kalau on-time / lebih awal.
     *
     * @param array $shift
     * @param int   $clockInTs
     * @param int   $tolerance Toleransi keterlambatan (menit), default 0
     * @return int 0..N
     */
    public function lateMinutes(array $shift, int $clockInTs, int $tolerance = 0): int
    {
        $shiftStart = $this->effectiveShiftStart($shift, $clockInTs);
        $diffSec = $clockInTs - $shiftStart - ($tolerance * 60);
        if ($diffSec <= 0) return 0;
        return (int) ceil($diffSec / 60);
    }

    /**
     * Hitung berapa menit pulang cepat. 0 kalau ≥ shift end.
     *
     * @param array $shift
     * @param int   $clockOutTs
     * @param int   $shiftStartTs Effective shift start (untuk anchor end)
     * @param int   $tolerance Toleransi pulang cepat (menit), default 0
     * @return int 0..N
     */
    public function earlyLeaveMinutes(
        array $shift, int $clockOutTs, int $shiftStartTs, int $tolerance = 0
    ): int {
        $shiftEnd = $this->effectiveShiftEnd($shift, $shiftStartTs);
        $diffSec = $shiftEnd - $clockOutTs - ($tolerance * 60);
        if ($diffSec <= 0) return 0;
        return (int) ceil($diffSec / 60);
    }

    /**
     * Parse "HH:MM" / "HH:MM:SS" → menit since midnight (0..1439).
     * Return null kalau invalid.
     */
    private function parseTime(?string $time): ?int
    {
        if ($time === null || $time === '') return null;
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)) return null;
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h < 0 || $h > 23 || $i < 0 || $i > 59) return null;
        return $h * 60 + $i;
    }
}