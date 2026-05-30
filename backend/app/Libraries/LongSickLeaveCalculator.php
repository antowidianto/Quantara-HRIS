<?php

namespace App\Libraries;

/**
 * LongSickLeaveCalculator — Sprint C / C3.
 *
 * UU 13/2003 Pasal 153 + PP 35/2021 mengatur pemotongan upah untuk
 * karyawan yang sakit berkepanjangan (continuous sick leave) yang
 * di-verifikasi dokter. Tarif pembayaran:
 *
 *   Bulan 1–4    : 100% upah
 *   Bulan 5–8    :  75% upah
 *   Bulan 9–12   :  50% upah
 *   Bulan 13+    :  25% upah (sampai PHK karena tidak mampu kerja
 *                  pasal 154A ayat 1 huruf l)
 *
 * Saat ini aplikasi tidak deteksi kondisi ini — payroll tetap bayar
 * 100% walaupun sakit > 4 bulan. Risk: company over-pay; HR violate
 * UU 13/2003 dengan tidak menerapkan tarif progresif (walau
 * tidak penalti pidana, audit Kemenaker bisa menemukan).
 *
 * Calculator ini PURE FUNCTION — input: tanggal mulai sakit, tanggal
 * payroll period, basic_salary; output: persentase pembayaran +
 * jumlah dipotong + flag eligible_phk.
 *
 * Caller (PayrollService) bisa pakai output-nya untuk:
 *   1. Hitung allowance/deduction baru
 *   2. Set flag di payslip "long_sick_phase"
 *   3. Trigger reminder HR kalau eligible_phk = true (Pasal 154A)
 */
class LongSickLeaveCalculator
{
    /**
     * Sick payment phase configuration.
     * Index: bulan ke-N, value: persentase pembayaran (0..100).
     *
     * Note: konvensi "bulan ke-1" adalah bulan pertama sakit (>=hari ke-1).
     * Bulan ke-12 = sudah 12 bulan continuous sick, masuk hari ke-1 bulan ke-13.
     */
    public const PHASES = [
        // [maxMonths, percent]
        [4,  100], // Bulan 1-4: 100%
        [8,   75], // Bulan 5-8: 75%
        [12,  50], // Bulan 9-12: 50%
        [PHP_INT_MAX, 25], // Bulan 13+: 25% (sambil menunggu PHK pasal 154A)
    ];

    /**
     * Threshold dimana karyawan eligible PHK karena sakit berkepanjangan
     * (UU Cipta Kerja Pasal 154A ayat 1 huruf l): 12 bulan terus-menerus.
     */
    public const PHK_ELIGIBLE_AFTER_MONTHS = 12;

    /**
     * Hitung persentase pembayaran untuk continuous sick leave.
     *
     * @param string $sickStartDate    Tanggal mulai sakit terus-menerus (Y-m-d)
     * @param string $payrollPeriodEnd Akhir periode payroll yang sedang dihitung (Y-m-d)
     * @return array{
     *   months_continuous: int,
     *   percentage: int,
     *   phase: string,
     *   eligible_phk: bool,
     *   description: string
     * }
     */
    public static function compute(string $sickStartDate, string $payrollPeriodEnd): array
    {
        $start = self::parseDate($sickStartDate);
        $end   = self::parseDate($payrollPeriodEnd);

        if ($start === false || $end === false || $end < $start) {
            // Input tidak valid atau periode payroll sebelum sakit dimulai
            return [
                'months_continuous' => 0,
                'percentage'        => 100,
                'phase'             => 'NORMAL',
                'eligible_phk'      => false,
                'description'       => 'Tidak dalam masa long sick leave (input invalid atau periode sebelum mulai sakit)',
            ];
        }

        $months = self::diffMonths($start, $end);
        $pct    = self::percentageForMonth($months);
        $phase  = self::phaseLabel($months);
        $phk    = $months >= self::PHK_ELIGIBLE_AFTER_MONTHS;

        return [
            'months_continuous' => $months,
            'percentage'        => $pct,
            'phase'             => $phase,
            'eligible_phk'      => $phk,
            'description'       => self::describe($months, $pct, $phk),
        ];
    }

    /**
     * Hitung jumlah pembayaran berdasarkan tarif progresif.
     */
    public static function paymentAmount(float $basicSalary, int $months): float
    {
        if ($basicSalary <= 0 || $months <= 0) {
            return $basicSalary;
        }
        $pct = self::percentageForMonth($months);
        return round($basicSalary * $pct / 100.0, 2);
    }

    /**
     * Hitung jumlah pemotongan dari upah penuh karena tarif progresif.
     */
    public static function deductionAmount(float $basicSalary, int $months): float
    {
        return round($basicSalary - self::paymentAmount($basicSalary, $months), 2);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * Selisih bulan antara dua tanggal (rounded down, minimum 1 kalau gap > 0).
     * Konvensi: tanggal sama → 0 bulan; selisih 30 hari → 1 bulan.
     */
    public static function diffMonths(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        if ($end < $start) return 0;
        // Pakai diff days / 30.4375 (rata2 hari per bulan)
        // tapi konsisten: bulan 1 = sudah masuk bulan ke-1 (start day 0..30).
        $days = (int) $start->diff($end)->format('%a');
        if ($days <= 0) return 0;

        // Bulan ke-1 = hari 1..30 (inklusif), bulan ke-2 = hari 31..60, dst.
        // Formula: ceil(days / 30) tapi clamp ke minimum 1
        return max(1, (int) ceil($days / 30));
    }

    public static function percentageForMonth(int $month): int
    {
        if ($month <= 0) return 100;
        foreach (self::PHASES as [$maxMonths, $pct]) {
            if ($month <= $maxMonths) {
                return $pct;
            }
        }
        return 25; // fallback (tidak tercapai karena PHP_INT_MAX di phase terakhir)
    }

    public static function phaseLabel(int $month): string
    {
        if ($month <= 0)  return 'NORMAL';
        if ($month <= 4)  return 'PHASE_1_FULL';      // 100%
        if ($month <= 8)  return 'PHASE_2_75';        // 75%
        if ($month <= 12) return 'PHASE_3_50';        // 50%
        return 'PHASE_4_25_OR_PHK';                   // 25% atau eligible PHK
    }

    private static function describe(int $months, int $pct, bool $phk): string
    {
        if ($months <= 0) {
            return 'Belum termasuk long sick leave.';
        }
        $base = sprintf('Sakit berkepanjangan bulan ke-%d → upah %d%%.', $months, $pct);
        if ($phk) {
            $base .= ' Eligible PHK Pasal 154A UU Cipta Kerja (sakit > 12 bulan).';
        }
        return $base;
    }

    private static function parseDate(string $date): \DateTimeImmutable|false
    {
        try {
            return new \DateTimeImmutable($date);
        } catch (\Throwable $e) {
            return false;
        }
    }
}