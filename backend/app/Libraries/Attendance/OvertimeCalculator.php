<?php

namespace App\Libraries\Attendance;

/**
 * OvertimeCalculator - Sesuai PP 35/2021 Pasal 31.
 *
 * Hourly rate = (gaji_pokok + tunjangan tetap) / 173
 *
 * HARI KERJA (WEEKDAY):
 *  - Jam pertama        : 1.5 × hourly rate
 *  - Jam ke-2 dan seterusnya : 2 × hourly rate
 *
 * HARI ISTIRAHAT/LIBUR (5 hari kerja per minggu — Sabtu/Minggu off):
 *  - 8 jam pertama  : 2 × hourly rate per jam
 *  - Jam ke-9       : 3 × hourly rate
 *  - Jam ke-10 & 11 : 4 × hourly rate
 *
 * HARI ISTIRAHAT/LIBUR (6 hari kerja per minggu):
 *  - 7 jam pertama  : 2 × hourly rate
 *  - Jam ke-8       : 3 × hourly rate
 *  - Jam ke-9 & 10  : 4 × hourly rate
 *
 * HARI LIBUR NASIONAL: same as weekend formula.
 */
class OvertimeCalculator
{
    /** Default jam per bulan per PP 35/2021 (= 40 jam/minggu × 4.33 minggu). */
    public const DEFAULT_HOURS_PER_MONTH = 173;

    /**
     * Hitung total upah lembur.
     *
     * @param float  $monthlyBase   Upah bulanan dasar (basic + tunjangan tetap)
     * @param float  $hours         Total jam lembur
     * @param string $dayType       'WEEKDAY' | 'WEEKEND' | 'HOLIDAY'
     * @param int    $workDaysPerWeek 5 atau 6 (untuk schema rest-day)
     * @param int    $hoursPerMonth Override divisor jam (Sprint 5-6 — biasanya
     *                              dari `PayrollSettings::overtimeHoursPerMonth`).
     *                              Default 173 untuk backward-compat.
     */
    public function calculate(
        float $monthlyBase,
        float $hours,
        string $dayType = 'WEEKDAY',
        int $workDaysPerWeek = 5,
        int $hoursPerMonth = self::DEFAULT_HOURS_PER_MONTH,
    ): float {
        if ($hours <= 0) return 0.0;
        if ($hoursPerMonth <= 0) $hoursPerMonth = self::DEFAULT_HOURS_PER_MONTH;

        $hourly = $monthlyBase / $hoursPerMonth;

        if ($dayType === 'WEEKDAY') {
            return $this->calculateWeekday($hourly, $hours);
        }

        // WEEKEND or HOLIDAY
        return $this->calculateRestDay($hourly, $hours, $workDaysPerWeek);
    }

    private function calculateWeekday(float $hourly, float $hours): float
    {
        $first = min(1.0, $hours);
        $rest  = max(0.0, $hours - 1.0);
        return round($first * 1.5 * $hourly + $rest * 2.0 * $hourly);
    }

    private function calculateRestDay(float $hourly, float $hours, int $workDays): float
    {
        // 5 days/week scheme
        if ($workDays === 5) {
            $tier1 = min(8.0, $hours);                       // 2x for first 8h
            $tier2 = max(0.0, min(1.0, $hours - 8.0));       // 3x for hour 9
            $tier3 = max(0.0, $hours - 9.0);                 // 4x for hour 10+
            return round(
                $tier1 * 2.0 * $hourly +
                $tier2 * 3.0 * $hourly +
                $tier3 * 4.0 * $hourly
            );
        }

        // 6 days/week scheme
        $tier1 = min(7.0, $hours);
        $tier2 = max(0.0, min(1.0, $hours - 7.0));
        $tier3 = max(0.0, $hours - 8.0);
        return round(
            $tier1 * 2.0 * $hourly +
            $tier2 * 3.0 * $hourly +
            $tier3 * 4.0 * $hourly
        );
    }
}