<?php

namespace App\Libraries\Payroll;

/**
 * Pph21YearEndCorrection — Sprint C / C6.
 *
 * PMK 168/2023 mengatur PPh 21 dengan TER (Tarif Efektif Rata-rata) bulanan
 * + koreksi progresif Desember (atau bulan terakhir bekerja). TER bulanan
 * adalah estimasi; koreksi Desember menghitung pajak final tahunan dan
 * menyesuaikan dengan total TER yang sudah dibayar.
 *
 * Implementation existing (Pph21TerCalculator) menghitung sebagai kalau
 * karyawan bekerja penuh 12 bulan. Untuk kasus mid-year join atau
 * mid-year resign, perhitungan progresif harus mempertimbangkan:
 *
 *   1. Mid-year JOIN — bulan kerja efektif < 12. Total bruto setahun
 *      lebih kecil. PTKP setahun penuh tetap dipotong (UU PPh Pasal 7).
 *      → Pajak progresif lebih kecil dari estimasi 12-bulan.
 *      → Kemungkinan over-pay dari TER bulanan, perlu refund.
 *
 *   2. Mid-year RESIGN — bulan kerja efektif < 12. Total bruto = sum of
 *      bulan-bulan efektif. PTKP setahun penuh tetap dipotong.
 *      → Sama seperti case 1, ada potensi refund.
 *      → Bulan terakhir kerja jadi "Desember efektif" untuk koreksi.
 *
 *   3. Mid-year JOIN + RESIGN (kontrak singkat di tahun yang sama):
 *      Hitung dari hire date ke resign date. PTKP tetap setahun penuh.
 *
 * Calculator ini PURE FUNCTION — fokus pada **bulan efektif** dan
 * **periode koreksi** yang harus diketahui oleh Pph21TerCalculator.
 * Calculator ini TIDAK ganti algoritma TER existing — hanya menambah
 * helper untuk identifikasi tipe koreksi yang harus dijalankan.
 */
class Pph21YearEndCorrection
{
    public const SCENARIO_FULL_YEAR    = 'FULL_YEAR';      // Hire ≤ Jan 1, masih aktif Des 31
    public const SCENARIO_MID_JOIN     = 'MID_YEAR_JOIN';  // Hire setelah Jan 1, masih aktif
    public const SCENARIO_MID_RESIGN   = 'MID_YEAR_RESIGN';// Hire ≤ Jan 1, resign sebelum Des 31
    public const SCENARIO_PARTIAL_YEAR = 'PARTIAL_YEAR';   // Hire & resign dalam tahun sama

    /**
     * Identifikasi skenario koreksi tahunan untuk satu karyawan.
     *
     * @param string      $taxYear    Tahun pajak (mis. '2026')
     * @param string      $hireDate   Tanggal join karyawan (Y-m-d)
     * @param string|null $resignDate Tanggal resign (Y-m-d) atau null kalau masih aktif
     * @return array{
     *   scenario: string,
     *   effective_months: int,
     *   correction_period: array{start:string,end:string},
     *   needs_correction: bool,
     *   description: string
     * }
     */
    public static function classify(
        string $taxYear,
        string $hireDate,
        ?string $resignDate = null,
    ): array {
        $year = (int) $taxYear;
        if ($year < 2000 || $year > 2100) {
            return self::invalidInput('Tahun pajak tidak valid: ' . $taxYear);
        }

        try {
            $yearStart = new \DateTimeImmutable($year . '-01-01');
            $yearEnd   = new \DateTimeImmutable($year . '-12-31');
            $hire      = new \DateTimeImmutable($hireDate);
            $resign    = $resignDate ? new \DateTimeImmutable($resignDate) : null;
        } catch (\Throwable $e) {
            return self::invalidInput('Tanggal tidak parseable: ' . $e->getMessage());
        }

        // Window efektif kerja dalam tahun ini
        $effectiveStart = max($hire, $yearStart);
        $effectiveEnd   = $resign && $resign < $yearEnd ? $resign : $yearEnd;

        if ($effectiveStart > $effectiveEnd) {
            // Hire setelah resign tahun ini, atau hire setelah year end, atau
            // resign sebelum year start (sudah resign tahun lalu)
            return [
                'scenario'         => self::SCENARIO_FULL_YEAR,
                'effective_months' => 0,
                'correction_period'=> ['start' => $yearStart->format('Y-m-d'), 'end' => $yearEnd->format('Y-m-d')],
                'needs_correction' => false,
                'description'      => 'Karyawan tidak aktif dalam tahun pajak ini.',
            ];
        }

        $months = self::countMonths($effectiveStart, $effectiveEnd);

        // Tentukan scenario
        $hiredThisYear   = $hire >= $yearStart && $hire <= $yearEnd;
        $resignedThisYear = $resign && $resign >= $yearStart && $resign <= $yearEnd;

        if ($hiredThisYear && $resignedThisYear) {
            $scenario = self::SCENARIO_PARTIAL_YEAR;
        } elseif ($hiredThisYear) {
            $scenario = self::SCENARIO_MID_JOIN;
        } elseif ($resignedThisYear) {
            $scenario = self::SCENARIO_MID_RESIGN;
        } else {
            $scenario = self::SCENARIO_FULL_YEAR;
        }

        $needsCorrection = $scenario !== self::SCENARIO_FULL_YEAR || $months === 12;

        return [
            'scenario'         => $scenario,
            'effective_months' => $months,
            'correction_period'=> [
                'start' => $effectiveStart->format('Y-m-d'),
                'end'   => $effectiveEnd->format('Y-m-d'),
            ],
            'needs_correction' => $needsCorrection,
            'description'      => self::describe($scenario, $months, $effectiveStart, $effectiveEnd),
        ];
    }

    /**
     * Hitung jumlah bulan efektif kerja antara dua tanggal (inclusive).
     * Konvensi: jika hire mid-bulan dan resign mid-bulan yang sama →
     * tetap dihitung 1 bulan (untuk PPh, partial month dianggap full month
     * mengikuti konvensi PMK 168/2023 yang pakai bulan kalender).
     */
    public static function countMonths(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        if ($end < $start) return 0;
        $diff = $start->diff($end);
        // Bulan + 1 karena kalau start = Jan 15 dan end = Jan 31 → 1 bulan (bukan 0)
        $months = ($diff->y * 12) + $diff->m + 1;
        return max(1, $months);
    }

    /**
     * Apakah PPh 21 perlu disesuaikan (refund/additional) berdasarkan
     * total bruto efektif vs total TER yang sudah dibayar?
     *
     * @param float $totalBrutoEffective    Total upah bruto bulan efektif
     * @param float $totalTerPaid           Total PPh dipotong via TER bulanan
     * @param float $progressiveTaxFinal    Pajak progresif final hasil hitung tahunan
     * @return array{
     *   adjustment: float,        // positif = under-pay, negatif = over-pay (refund)
     *   action: string,           // ADD|REFUND|NONE
     *   description: string
     * }
     */
    public static function computeAdjustment(
        float $totalBrutoEffective,
        float $totalTerPaid,
        float $progressiveTaxFinal,
    ): array {
        $adjustment = round($progressiveTaxFinal - $totalTerPaid, 2);
        $action = match (true) {
            abs($adjustment) < 0.01 => 'NONE',
            $adjustment > 0         => 'ADD',
            default                 => 'REFUND',
        };

        return [
            'adjustment'  => $adjustment,
            'action'      => $action,
            'description' => match ($action) {
                'NONE'   => 'Pajak terutang sudah sama dengan total TER yang dipotong.',
                'ADD'    => sprintf('Karyawan harus tambah bayar Rp %s di bulan terakhir kerja.', number_format($adjustment, 2, ',', '.')),
                'REFUND' => sprintf('Karyawan berhak refund Rp %s (TER over-paid).', number_format(abs($adjustment), 2, ',', '.')),
            },
        ];
    }

    private static function describe(
        string $scenario,
        int $months,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
    ): string {
        $period = sprintf('%s s/d %s (%d bulan efektif)',
            $start->format('Y-m-d'), $end->format('Y-m-d'), $months);
        return match ($scenario) {
            self::SCENARIO_FULL_YEAR    => 'Karyawan aktif penuh setahun. Koreksi Desember standar. ' . $period,
            self::SCENARIO_MID_JOIN     => 'Karyawan join mid-year. Koreksi Desember dengan PTKP setahun penuh. ' . $period,
            self::SCENARIO_MID_RESIGN   => 'Karyawan resign mid-year. Koreksi di bulan terakhir kerja, PTKP setahun penuh. ' . $period,
            self::SCENARIO_PARTIAL_YEAR => 'Karyawan hire & resign di tahun sama. Koreksi di bulan resign, PTKP setahun penuh. ' . $period,
            default                     => $period,
        };
    }

    private static function invalidInput(string $reason): array
    {
        return [
            'scenario'         => self::SCENARIO_FULL_YEAR,
            'effective_months' => 0,
            'correction_period'=> ['start' => '', 'end' => ''],
            'needs_correction' => false,
            'description'      => 'Input tidak valid: ' . $reason,
        ];
    }
}