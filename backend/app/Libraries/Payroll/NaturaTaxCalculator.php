<?php

namespace App\Libraries\Payroll;

/**
 * H2.10 — Pure helpers untuk perhitungan Natura/Kenikmatan PMK 66/2023.
 *
 * Logika di-extract dari `PayrollService::calculateEmployee` supaya
 * bisa di-unit-test tanpa setup DB. Method semua `static`, deterministik,
 * tidak melakukan I/O.
 *
 * Konsep PMK 66/2023:
 *  - Natura = fasilitas/kenikmatan non-tunai (mobil dinas, perumahan, gym).
 *  - Untuk basis PPh21: Natura **ditambahkan** ke Penghasilan Bruto setelah
 *    dikurangi ambang batas tahunan yang dipecah proporsional /12 per bulan.
 *  - Untuk Take Home Pay tunai: Natura **dikecualikan** karena tidak dibayar
 *    cash; hanya akan muncul di payslip sebagai komponen non-tunai.
 *  - Sehingga Total Earnings (display & laporan SPT 1721) lebih besar dari
 *    Take Home Pay saat ada natura — itu *by design* sesuai regulasi.
 */
class NaturaTaxCalculator
{
    /**
     * Hitung porsi taxable dari satu komponen natura per periode payroll
     * bulanan, setelah memperhitungkan ambang batas tahunan (proporsi
     * /12 per bulan).
     *
     * Aturan:
     *  - threshold tahunan ≤ 0  → seluruh nominal taxable.
     *  - amount ≤ threshold/12 → 0 (di bawah ambang batas, tidak taxable).
     *  - amount > threshold/12 → (amount - threshold/12) menjadi tax base.
     *
     * @param float $amount          Nominal natura bulan ini.
     * @param float $thresholdYearly Ambang batas tahunan (Rp). 0 / null = no threshold.
     */
    public static function monthlyTaxBase(float $amount, float $thresholdYearly = 0.0): float
    {
        if ($amount <= 0) return 0.0;
        if ($thresholdYearly <= 0) return $amount;
        $thresholdMonthly = $thresholdYearly / 12.0;
        if ($amount <= $thresholdMonthly) return 0.0;
        return round($amount - $thresholdMonthly, 2);
    }

    /**
     * Bangun earning entry dengan tagging natura.
     *
     * Output entry punya field tambahan:
     *  - `is_natura` (1)
     *  - `natura_tax_base` (porsi yang masuk Penghasilan Bruto pajak)
     *  - `is_taxable` mungkin di-override ke 0 jika seluruh nominal di
     *    bawah ambang batas (tidak menambah pajak sama sekali).
     *
     * @param array  $entry            Entry awal (code, name, category, amount, is_taxable)
     * @param float  $thresholdYearly  Ambang batas tahunan
     */
    public static function buildEntry(array $entry, float $thresholdYearly = 0.0): array
    {
        $amount = (float) ($entry['amount'] ?? 0);
        $taxBase = self::monthlyTaxBase($amount, $thresholdYearly);

        $entry['is_natura'] = 1;
        $entry['natura_tax_base'] = $taxBase;

        if ($taxBase <= 0 && $thresholdYearly > 0) {
            // Seluruh nominal masih di bawah ambang batas → tidak menambah
            // Penghasilan Bruto. Tetap di-flag is_natura supaya logic
            // "tidak masuk cash THP" tetap berlaku.
            $entry['is_taxable'] = 0;
        }

        return $entry;
    }

    /**
     * Hitung total earnings split: gross_earning, cash_earning, gross_taxable,
     * natura_total dari list earning entries.
     *
     * Pure function — caller pass list entries (campuran natura & non-natura)
     * dan dapatkan agregat siap pakai untuk persistence payslip.
     *
     * @param list<array{
     *   amount:float|int,
     *   is_taxable:int,
     *   is_natura?:int,
     *   natura_tax_base?:float,
     * }> $earnings
     *
     * @return array{
     *   gross_earning:float,
     *   cash_earning:float,
     *   gross_taxable:float,
     *   natura_total:float,
     * }
     */
    public static function aggregate(array $earnings): array
    {
        $gross = 0.0;
        $cash = 0.0;
        $taxable = 0.0;
        $natura = 0.0;

        foreach ($earnings as $e) {
            $amt = (float) ($e['amount'] ?? 0);
            $gross += $amt;
            $isNatura = (int) ($e['is_natura'] ?? 0) === 1;
            $isTaxable = (int) ($e['is_taxable'] ?? 0) === 1;

            if ($isNatura) {
                $natura += $amt;
                if ($isTaxable) {
                    $taxable += (float) ($e['natura_tax_base'] ?? $amt);
                }
            } else {
                $cash += $amt;
                if ($isTaxable) $taxable += $amt;
            }
        }

        return [
            'gross_earning' => round($gross, 2),
            'cash_earning'  => round($cash, 2),
            'gross_taxable' => round($taxable, 2),
            'natura_total'  => round($natura, 2),
        ];
    }
}