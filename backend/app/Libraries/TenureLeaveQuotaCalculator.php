<?php

namespace App\Libraries;

/**
 * TenureLeaveQuotaCalculator — Sprint C / C4 + C5.
 *
 * Mengelola quota cuti yang naik berdasarkan masa kerja (tenure):
 *
 * 1. **Cuti Besar (Long-Service Leave)** — UU 13/2003 Pasal 79 ayat 2(d):
 *    Karyawan dengan PKWTT > 6 tahun terus-menerus → berhak 1 bulan
 *    cuti besar di tahun ke-7. Berulang setiap kelipatan 6 tahun.
 *
 * 2. **Bonus Quota Cuti Tahunan** — convention HR Indonesia:
 *    Tenure 5 tahun  → +2 hari (jadi 14 hari/tahun)
 *    Tenure 10 tahun → +5 hari (jadi 17 hari/tahun)
 *    Tenure 15 tahun → +7 hari
 *    Tenure 20 tahun → +10 hari
 *
 *    Ini bukan UU eksplisit; banyak company adopt sebagai retention benefit.
 *    Default schedule bisa di-override per company via setting.
 *
 * Calculator ini PURE FUNCTION — input: tanggal join + tahun referensi;
 * output: bonus_days, eligible_long_service, jumlah hari cuti besar.
 *
 * Caller (LeaveBalanceService) bisa pakai untuk:
 *   1. Tambah ke `annual_quota` saat compute saldo
 *   2. Auto-grant cuti besar saat masuk tahun ke-7/13/19
 *   3. Tampilkan di leave balance screen sebagai "tenure bonus"
 */
class TenureLeaveQuotaCalculator
{
    /**
     * Default tenure bonus schedule.
     * Index: minimum_years, value: bonus_days (cumulative bonus).
     *
     * Format: list dari [minTenure, bonusDaysAdditional]. Caller pakai
     * `bonusDaysForTenure()` untuk lookup.
     *
     * @var array<int,array{0:int,1:int}>
     */
    public const DEFAULT_BONUS_SCHEDULE = [
        [5,  2],
        [10, 5],
        [15, 7],
        [20, 10],
        [25, 13],
        [30, 16],
    ];

    /**
     * UU 13/2003 Pasal 79 ayat 2(d): cuti besar setelah 6 tahun PKWTT terus-menerus.
     */
    public const LONG_SERVICE_THRESHOLD_YEARS = 6;

    /**
     * Default cuti besar = 1 bulan kalender. Dengan asumsi 30 hari kalender
     * minus 22 hari kerja → setara ≈ 30 hari kalender. Dipakai konvensi:
     * "30 hari kalender" yang tidak include weekend (mengikuti policy
     * UMK Pasal 79 yang tidak mengatur eksplisit).
     */
    public const LONG_SERVICE_DAYS = 30;

    /**
     * Hitung tenure dalam tahun penuh (rounded down).
     * Return 0 kalau join_date di masa depan atau invalid.
     */
    public static function tenureYears(string $joinDate, ?string $referenceDate = null): int
    {
        try {
            $start = new \DateTimeImmutable($joinDate);
            $ref   = new \DateTimeImmutable($referenceDate ?? 'now');
        } catch (\Throwable $e) {
            return 0;
        }
        if ($ref < $start) return 0;
        $diff = $start->diff($ref);
        return (int) $diff->y;
    }

    /**
     * Hitung bonus hari cuti tahunan berdasarkan tenure.
     * Return 0 kalau tenure < 5 tahun.
     *
     * @param array<int,array{0:int,1:int}>|null $schedule Custom schedule per company
     */
    public static function bonusDaysForTenure(int $tenureYears, ?array $schedule = null): int
    {
        if ($tenureYears <= 0) return 0;
        $schedule ??= self::DEFAULT_BONUS_SCHEDULE;

        $bonus = 0;
        foreach ($schedule as [$minYears, $bonusDays]) {
            if ($tenureYears >= $minYears) {
                $bonus = $bonusDays; // cumulative — schedule sudah inclusive
            }
        }
        return $bonus;
    }

    /**
     * Cek eligibility cuti besar di tahun N berdasarkan join_date.
     * Convention: cuti besar di-grant SEKALI saat masuk tahun ke-7 (= setelah
     * 6 tahun lengkap), lalu berulang tiap 6 tahun (year 13, 19, 25, ...).
     *
     * @return array{
     *   eligible: bool,
     *   tenure_years: int,
     *   next_cycle_year: int,
     *   days_granted: int,
     *   description: string
     * }
     */
    public static function longServiceLeaveEligibility(
        string $joinDate,
        ?string $referenceDate = null,
    ): array {
        $tenure = self::tenureYears($joinDate, $referenceDate);

        if ($tenure < self::LONG_SERVICE_THRESHOLD_YEARS) {
            return [
                'eligible'        => false,
                'tenure_years'    => $tenure,
                'next_cycle_year' => self::LONG_SERVICE_THRESHOLD_YEARS,
                'days_granted'    => 0,
                'description'     => sprintf(
                    'Belum mencapai 6 tahun PKWTT. Tenure saat ini: %d tahun. Eligible saat tenure tepat 6 tahun.',
                    $tenure,
                ),
            ];
        }

        // Hitung cycle ke-N. Cycle 1 = tahun 6, cycle 2 = tahun 12, dst.
        $cycle = (int) floor($tenure / self::LONG_SERVICE_THRESHOLD_YEARS);
        $nextCycleYear = ($cycle + 1) * self::LONG_SERVICE_THRESHOLD_YEARS;

        return [
            'eligible'        => true,
            'tenure_years'    => $tenure,
            'next_cycle_year' => $nextCycleYear,
            'days_granted'    => self::LONG_SERVICE_DAYS,
            'description'     => sprintf(
                'Eligible cuti besar (cycle ke-%d, %d hari). Cycle berikut di tahun ke-%d.',
                $cycle, self::LONG_SERVICE_DAYS, $nextCycleYear,
            ),
        ];
    }

    /**
     * Compute total quota cuti tahunan (basic + bonus tenure).
     * Convention basic = 12 hari/tahun (UU 13/2003 Pasal 79 ayat 2(c)).
     *
     * @param int $tenureYears
     * @param int $basicQuota Default 12 (UU 13/2003)
     * @param array<int,array{0:int,1:int}>|null $schedule Optional custom
     * @return array{basic:int,bonus:int,total:int,tenure_years:int}
     */
    public static function totalAnnualQuota(
        int $tenureYears,
        int $basicQuota = 12,
        ?array $schedule = null,
    ): array {
        $bonus = self::bonusDaysForTenure($tenureYears, $schedule);
        return [
            'basic'        => $basicQuota,
            'bonus'        => $bonus,
            'total'        => $basicQuota + $bonus,
            'tenure_years' => $tenureYears,
        ];
    }
}