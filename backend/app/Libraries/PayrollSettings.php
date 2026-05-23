<?php

namespace App\Libraries;

/**
 * PayrollSettings
 *
 * Menggantikan "magic numbers" di payroll/overtime/leave dengan konfigurasi
 * per-perusahaan via tabel `settings`. Caller dapat tetap pakai default
 * konstan; hanya perusahaan yang ingin override yang menambah baris di
 * `settings`. Semua nilai di-cache per-request untuk hindari N hit DB.
 *
 * Sprint 5-6 fix:
 *  - `working_days_per_month` (default 22) — dipakai untuk hitung pemotongan
 *    upah harian (mis. absent deduction di PayrollService).
 *  - `overtime_hours_per_month` (default 173) — divisor jam lembur per
 *    PP 35/2021 Pasal 31. Dipakai di `OvertimeCalculator::calculate()`.
 *  - `overtime_to_leave_hours_per_day` (default 8) — jam ↔ hari konversi
 *    saat overtime di-credit jadi cuti pengganti.
 *
 * Lihat docs/IMPROVEMENT_CHECKLIST.md item 4 (Payroll Engine).
 *
 * Pemakaian:
 *
 *   $cfg = Services::payrollSettings();
 *   $workDays = $cfg->workingDaysPerMonth($companyId);  // 22 default
 *   $otHours  = $cfg->overtimeHoursPerMonth($companyId); // 173 default
 *
 * Setting key (di tabel `settings`):
 *   - payroll.working_days_per_month
 *   - payroll.overtime_hours_per_month
 *   - payroll.overtime_to_leave_hours_per_day
 *   - payroll.absent_deduction_enabled
 */
class PayrollSettings
{
    /** Default magic numbers — dipakai kalau company belum override. */
    public const DEFAULT_WORKING_DAYS_PER_MONTH         = 22;
    public const DEFAULT_OVERTIME_HOURS_PER_MONTH       = 173;
    public const DEFAULT_OVERTIME_TO_LEAVE_HOURS_PER_DAY = 8;

    /** @var array<int, array<string, mixed>> Cache per-company per-request. */
    private array $cache = [];

    /**
     * Working days per bulan untuk perhitungan absent deduction.
     */
    public function workingDaysPerMonth(?int $companyId = null): int
    {
        $val = $this->get($companyId, 'payroll.working_days_per_month',
            self::DEFAULT_WORKING_DAYS_PER_MONTH);
        return max(1, min(31, (int) $val));
    }

    /**
     * Jam kerja standar per bulan untuk perhitungan upah lembur per jam.
     * PP 35/2021 menetapkan 173 = 40 jam/minggu × 4.33 minggu, atau
     * 7 jam × 6 hari × 4.33 ≈ 173.
     */
    public function overtimeHoursPerMonth(?int $companyId = null): int
    {
        $val = $this->get($companyId, 'payroll.overtime_hours_per_month',
            self::DEFAULT_OVERTIME_HOURS_PER_MONTH);
        return max(1, min(300, (int) $val));
    }

    /**
     * Jam kerja per hari untuk konversi jam lembur ke hari cuti pengganti.
     */
    public function overtimeToLeaveHoursPerDay(?int $companyId = null): int
    {
        $val = $this->get($companyId, 'payroll.overtime_to_leave_hours_per_day',
            self::DEFAULT_OVERTIME_TO_LEAVE_HOURS_PER_DAY);
        return max(1, min(12, (int) $val));
    }

    /**
     * Apakah pemotongan gaji untuk hari absen aktif. Default true (enable).
     */
    public function absentDeductionEnabled(?int $companyId = null): bool
    {
        $val = $this->get($companyId, 'payroll.absent_deduction_enabled', '1');
        return (string) $val !== '0' && (string) $val !== 'false';
    }

    /**
     * Bulk reader. Berguna untuk debug / endpoint settings UI yang ingin
     * tampilkan semua nilai aktif sekaligus.
     *
     * @return array<string, mixed>
     */
    public function all(?int $companyId = null): array
    {
        return [
            'working_days_per_month'          => $this->workingDaysPerMonth($companyId),
            'overtime_hours_per_month'        => $this->overtimeHoursPerMonth($companyId),
            'overtime_to_leave_hours_per_day' => $this->overtimeToLeaveHoursPerDay($companyId),
            'absent_deduction_enabled'        => $this->absentDeductionEnabled($companyId),
        ];
    }

    /**
     * Read setting value with fallback ke default. Result di-cache per
     * request untuk efisiensi (PayrollService bisa call ratusan kali per
     * generate periode).
     */
    private function get(?int $companyId, string $key, $default): mixed
    {
        $cacheKey = $companyId ?? 0;
        if (! isset($this->cache[$cacheKey])) {
            $this->cache[$cacheKey] = $this->loadAll($companyId);
        }
        return $this->cache[$cacheKey][$key] ?? $default;
    }

    /**
     * Load semua setting `payroll.*` untuk company sekali jalan.
     *
     * @return array<string, mixed>
     */
    private function loadAll(?int $companyId): array
    {
        if (! $companyId) return [];
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('settings')) return [];

            $rows = $db->table('settings')
                ->where('company_id', $companyId)
                ->like('key', 'payroll.', 'after')
                ->get()
                ->getResultArray();
            $out = [];
            foreach ($rows as $r) {
                $out[$r['key']] = $r['value'];
            }
            return $out;
        } catch (\Throwable $e) {
            // DB tidak tersedia — fallback ke default semua
            return [];
        }
    }
}