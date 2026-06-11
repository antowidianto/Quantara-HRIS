<?php

namespace App\Libraries\Payroll;

class OvertimeCalculator
{
    /**
     * Menghitung nilai jam payable (terkonversi) berdasarkan PP 35/2021 Pasal 31.
     *
     * @param float $hours Durasi lembur riil dalam jam
     * @param bool $isHoliday Apakah lembur dilakukan di hari istirahat mingguan/libur nasional?
     * @param bool $isFiveDayWorkweek Apakah perusahaan menerapkan 5 hari kerja (asumsi standar)
     * @return float Payable hours yang sudah dikali multiplier
     */
    public static function calculatePayableHours(float $hours, bool $isHoliday = false, bool $isFiveDayWorkweek = true): float
    {
        $payable = 0.0;

        if (!$isHoliday) {
            // Hari kerja biasa:
            // Jam pertama = 1.5x
            // Jam kedua dan seterusnya = 2x
            $firstHour = min(1.0, $hours);
            $restHours = max(0.0, $hours - 1.0);
            $payable = ($firstHour * 1.5) + ($restHours * 2.0);
        } else {
            // Hari libur (asumsi 5 hari kerja seminggu):
            // 8 jam pertama = 2x
            // Jam ke-9 = 3x
            // Jam ke-10, ke-11, ke-12 = 4x
            if ($isFiveDayWorkweek) {
                $first8 = min(8.0, $hours);
                $ninth = min(1.0, max(0.0, $hours - 8.0));
                $tenthOnwards = max(0.0, $hours - 9.0);
                $payable = ($first8 * 2.0) + ($ninth * 3.0) + ($tenthOnwards * 4.0);
            }
            // Catatan: Jika 6 hari kerja, hitungannya sedikit berbeda (7 jam pertama 2x), 
            // logic ini dapat diperluas jika diperlukan.
        }

        return $payable;
    }
}