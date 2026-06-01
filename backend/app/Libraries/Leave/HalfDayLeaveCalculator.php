<?php

namespace App\Libraries\Leave;

/**
 * HalfDayLeaveCalculator — Sprint C+1 / C10.
 *
 * Pure-function helper untuk handle half-day leave (MORNING/AFTERNOON)
 * di sisi attendance + payroll deduction.
 *
 * Masalah yang di-solve:
 *   - Tabel `leaves` sudah punya `is_half_day=1` + `half_day_part='MORNING'|'AFTERNOON'`
 *     dengan `days=0.5`, tapi:
 *     - Attendance tidak split: hari leave half-day di-mark "leave penuh" → ngitung
 *       jam kerja salah
 *     - Payroll deduction tidak proporsional: 0.5 hari leave tapi attendance
 *       potong 1 hari atau 0 hari (tergantung order proses)
 *
 * Konvensi half-day part:
 *   - MORNING   = 08:00–12:00 (4 jam pertama shift). Karyawan masuk siang.
 *   - AFTERNOON = 13:00–17:00 (4 jam terakhir shift). Karyawan pulang siang.
 *
 * Untuk shift non-standar (mis. shift malam), caller harus pass shift hours
 * eksplisit; kalau tidak, default 8-jam kerja (0.5 = 4 jam).
 *
 * Tidak butuh DB — pure function. Tests: `tests/Unit/HalfDayLeaveCalculatorTest.php`.
 */
class HalfDayLeaveCalculator
{
    public const PART_MORNING   = 'MORNING';
    public const PART_AFTERNOON = 'AFTERNOON';

    /**
     * Default jam kerja per hari (untuk shift standar 8 jam).
     */
    public const DEFAULT_HOURS_PER_DAY = 8.0;

    /**
     * Resolve effective worked hours untuk hari yang ada half-day leave.
     *
     * Skenario:
     *   - is_half_day=0 (full leave) → worked = 0
     *   - is_half_day=1 MORNING      → worked = jam afternoon (default 4h)
     *   - is_half_day=1 AFTERNOON    → worked = jam morning (default 4h)
     *   - tidak ada leave            → worked = full shift hours
     *
     * @param array{
     *   is_half_day?:int|bool,
     *   half_day_part?:string|null,
     * }|null     $leave        Row dari tabel leaves untuk tanggal tsb (null = no leave)
     * @param float $shiftHours  Total jam shift hari itu (default 8)
     * @return float Jam kerja yang seharusnya hadir (range 0..shiftHours)
     */
    public function effectiveWorkHours(?array $leave, float $shiftHours = self::DEFAULT_HOURS_PER_DAY): float
    {
        $shiftHours = max(0.0, $shiftHours);

        if ($leave === null) {
            return $shiftHours;
        }

        $isHalfDay = $this->boolish($leave['is_half_day'] ?? 0);
        if (! $isHalfDay) {
            // Full-day leave → 0 jam kerja
            return 0.0;
        }

        $part = strtoupper((string) ($leave['half_day_part'] ?? ''));
        if ($part !== self::PART_MORNING && $part !== self::PART_AFTERNOON) {
            // Half-day flag set tapi part invalid — defensive: treat as full leave
            return 0.0;
        }

        // Half-day = setengah dari shift hours
        return $shiftHours / 2.0;
    }

    /**
     * Hitung deduction hari (untuk absent/late deduction) dari leave +
     * actual attendance.
     *
     * Logic:
     *   - Tidak ada leave    + present = 0 deduction
     *   - Tidak ada leave    + absent  = 1 day deduction
     *   - Full leave         + absent  = 0 deduction (sudah di-cover leave balance)
     *   - Full leave         + present = 0 deduction (jangan double-count)
     *   - Half-day MORNING   + masuk siang & full afternoon  = 0 deduction
     *   - Half-day MORNING   + tidak masuk sama sekali       = 0.5 deduction
     *     (afternoon tidak hadir tapi tidak ada leave untuk afternoon)
     *   - Half-day AFTERNOON + present full morning, pulang siang = 0 deduction
     *   - Half-day AFTERNOON + tidak masuk sama sekali       = 0.5 deduction
     *
     * Caller harus tentukan `presentHours` dari log attendance.
     *
     * @param array{
     *   is_half_day?:int|bool,
     *   half_day_part?:string|null,
     * }|null     $leave
     * @param float $presentHours Jam aktual hadir (dari clock-in/out)
     * @param float $shiftHours   Total jam shift (default 8)
     * @return float Hari deduction (range 0.0–1.0)
     */
    public function deductionDays(?array $leave, float $presentHours, float $shiftHours = self::DEFAULT_HOURS_PER_DAY): float
    {
        $shiftHours   = max(0.01, $shiftHours);  // anti div-by-zero
        $presentHours = max(0.0, min($presentHours, $shiftHours));

        $expected = $this->effectiveWorkHours($leave, $shiftHours);

        if ($expected <= 0) {
            // Full leave — tidak ada expected, tidak ada deduction
            return 0.0;
        }

        if ($presentHours >= $expected) {
            // Hadir cukup
            return 0.0;
        }

        // Pro-rata jam yang tidak hadir
        $missingHours = $expected - $presentHours;
        return round($missingHours / $shiftHours, 4);
    }

    /**
     * Validasi half-day request bisa di-grant. Cek konflik:
     *   - Tidak boleh dua half-day di hari sama (mis. MORNING dan AFTERNOON
     *     keduanya — itu = full leave, harus pakai is_half_day=0)
     *   - Tidak boleh half-day overlap dengan full-day di tanggal sama
     *
     * @param array{
     *   is_half_day?:int|bool,
     *   half_day_part?:string|null,
     * }       $newLeave
     * @param list<array{
     *   is_half_day?:int|bool,
     *   half_day_part?:string|null,
     * }>      $existingLeavesForDate
     * @return array{valid:bool, reason?:string}
     */
    public function validateHalfDayConflict(array $newLeave, array $existingLeavesForDate): array
    {
        $newIsHalf = $this->boolish($newLeave['is_half_day'] ?? 0);
        $newPart   = strtoupper((string) ($newLeave['half_day_part'] ?? ''));

        if ($newIsHalf && $newPart !== self::PART_MORNING && $newPart !== self::PART_AFTERNOON) {
            return ['valid' => false, 'reason' => 'half_day_part wajib MORNING atau AFTERNOON saat is_half_day=1'];
        }

        foreach ($existingLeavesForDate as $existing) {
            $exIsHalf = $this->boolish($existing['is_half_day'] ?? 0);
            $exPart   = strtoupper((string) ($existing['half_day_part'] ?? ''));

            // Kasus 1: existing full-day → new apa pun konflik
            if (! $exIsHalf) {
                return ['valid' => false, 'reason' => 'Sudah ada leave full-day di tanggal yang sama'];
            }

            // Kasus 2: existing half-day → new tidak boleh full
            if (! $newIsHalf) {
                return ['valid' => false, 'reason' => 'Sudah ada half-day leave; gabungan jadi full-day, ajukan ulang sebagai full-day'];
            }

            // Kasus 3: existing half-day + new half-day di part SAMA → duplikat
            if ($exPart === $newPart) {
                return ['valid' => false, 'reason' => "Sudah ada half-day {$exPart} di tanggal yang sama"];
            }
        }

        return ['valid' => true];
    }

    /**
     * Coerce mixed bool/int/string ke bool. PHP DB driver kadang return
     * "1"/"0" string, kadang int.
     */
    private function boolish(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v))  return $v === 1;
        if (is_string($v)) {
            $s = strtolower(trim($v));
            return $s === '1' || $s === 'true' || $s === 'yes';
        }
        return false;
    }
}