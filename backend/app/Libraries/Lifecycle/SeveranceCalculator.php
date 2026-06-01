<?php

namespace App\Libraries\Lifecycle;

/**
 * SeveranceCalculator
 *
 * Hitung pesangon, UPMK, dan UPH sesuai UU 6/2023 (Cipta Kerja) dan
 * PP 35/2021 Pasal 40-58.
 *
 * Tabel pesangon (Pasal 40 ayat 2) — bulan upah berdasarkan masa kerja:
 *   < 1 thn       : 1 bulan
 *   1 - < 2 thn   : 2 bulan
 *   2 - < 3 thn   : 3 bulan
 *   3 - < 4 thn   : 4 bulan
 *   4 - < 5 thn   : 5 bulan
 *   5 - < 6 thn   : 6 bulan
 *   6 - < 7 thn   : 7 bulan
 *   7 - < 8 thn   : 8 bulan
 *   ≥ 8 thn       : 9 bulan
 *
 * Tabel UPMK (Pasal 40 ayat 3) — hanya untuk masa kerja ≥ 3 tahun:
 *   3  - < 6 thn  : 2 bulan
 *   6  - < 9 thn  : 3 bulan
 *   9  - < 12 thn : 4 bulan
 *   12 - < 15 thn : 5 bulan
 *   15 - < 18 thn : 6 bulan
 *   18 - < 21 thn : 7 bulan
 *   21 - < 24 thn : 8 bulan
 *   ≥ 24 thn      : 10 bulan
 *
 * Multiplier pesangon/UPMK menurut alasan PHK (Pasal 41-57 PP 35/2021):
 *
 * | Slug                  | Pesangon | UPMK  | Hak | Catatan
 * |-----------------------|----------|-------|-----|------------------
 * | EFFICIENCY_LOSS       | 0.5×     | 1.0×  | UPH | Pasal 43 (efisiensi rugi)
 * | EFFICIENCY_PREVENT    | 1.0×     | 1.0×  | UPH | Pasal 43 (efisiensi cegah rugi)
 * | MERGER_NO_CONTINUE    | 1.0×     | 1.0×  | UPH | Pasal 41 (penggabungan, peleburan)
 * | TAKEOVER_NO_CONTINUE  | 1.0×     | 1.0×  | UPH | Pasal 41 (pengambilalihan)
 * | TAKEOVER_NEW_TERMS    | 0.5×     | 1.0×  | UPH | Pasal 42 (alihdaya, syarat baru)
 * | CLOSURE_LOSS_2YR      | 0.5×     | 1.0×  | UPH | Pasal 44 (tutup karena rugi 2 tahun)
 * | CLOSURE_FORCE_MAJEURE | 0.5×     | 1.0×  | UPH | Pasal 45 (force majeure tanpa tutup)
 * | CLOSURE_NO_REASON     | 1.0×     | 1.0×  | UPH | Pasal 44 (tutup tanpa alasan rugi)
 * | FORCE_MAJEURE         | 0.75×    | 1.0×  | UPH | Pasal 45 ayat 2 (force majeure tutup)
 * | PKPU_BANKRUPTCY       | 0.5×     | 1.0×  | UPH | Pasal 46 (PKPU/pailit)
 * | LONG_ABSENT_PROHIBIT  | -        | -     | UPH+SP | Pasal 50 (mangkir 5 hari, hanya UPH+uang pisah)
 * | VIOLATION_GROSS       | -        | -     | UPH+SP | Pasal 52 (pelanggaran berat, UPH+uang pisah)
 * | RESIGN                | -        | -     | UPH+SP | Pasal 50 (mengundurkan diri, UPH+uang pisah)
 * | PENSION_RETIRE        | 1.75×    | 1.0×  | UPH | Pasal 56 (pensiun)
 * | DEATH                 | 2.0×     | 1.0×  | UPH | Pasal 57 (meninggal dunia)
 * | DISABILITY_PROLONGED  | 2.0×     | 1.0×  | UPH | Pasal 55 (sakit berkepanjangan ≥ 12 bln)
 * | END_OF_PKWT           | -        | -     | KOMPENSASI | UU 6/2023 — kompensasi PKWT (proporsional)
 * | CONTRACT_EXPIRY       | -        | -     | -   | PKWTT habis kontrak biasa, tidak ada pesangon
 *
 * Catatan implementasi:
 *  - Untuk RESIGN/VIOLATION_GROSS/LONG_ABSENT_PROHIBIT, sistem hitung
 *    UPH + uang pisah (Pasal 50 ayat 4 — nominal sesuai PKB/PP perusahaan,
 *    minimum sesuai kebijakan internal). Default service ini set
 *    `separation_pay = 0` dan UPH penuh; HR isi `separation_pay` manual
 *    via field di payload kalau ada kesepakatan.
 *  - Output angka float; pembulatan dilakukan saat penyimpanan ke DB
 *    (DECIMAL 15,2).
 */
class SeveranceCalculator
{
    public const REASONS = [
        'EFFICIENCY_LOSS' => [
            'label' => 'PHK Efisiensi (perusahaan rugi)',
            'pesangon_mul' => 0.5, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'EFFICIENCY_PREVENT' => [
            'label' => 'PHK Efisiensi (mencegah kerugian)',
            'pesangon_mul' => 1.0, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'MERGER_NO_CONTINUE' => [
            'label' => 'Penggabungan/Peleburan, karyawan tidak dilanjutkan',
            'pesangon_mul' => 1.0, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'TAKEOVER_NO_CONTINUE' => [
            'label' => 'Pengambilalihan, karyawan tidak dilanjutkan',
            'pesangon_mul' => 1.0, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'TAKEOVER_NEW_TERMS' => [
            'label' => 'Pengambilalihan dengan syarat kerja baru',
            'pesangon_mul' => 0.5, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'CLOSURE_LOSS_2YR' => [
            'label' => 'Perusahaan tutup karena rugi 2 tahun',
            'pesangon_mul' => 0.5, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'CLOSURE_NO_REASON' => [
            'label' => 'Perusahaan tutup tanpa alasan rugi',
            'pesangon_mul' => 1.0, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'FORCE_MAJEURE' => [
            'label' => 'Force majeure, perusahaan tutup',
            'pesangon_mul' => 0.75, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'PKPU_BANKRUPTCY' => [
            'label' => 'PKPU / pailit',
            'pesangon_mul' => 0.5, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'PENSION_RETIRE' => [
            'label' => 'Pensiun',
            'pesangon_mul' => 1.75, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'DEATH' => [
            'label' => 'Meninggal dunia',
            'pesangon_mul' => 2.0, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'DISABILITY_PROLONGED' => [
            'label' => 'Sakit berkepanjangan / cacat (≥ 12 bulan)',
            'pesangon_mul' => 2.0, 'upmk_mul' => 1.0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
        'RESIGN' => [
            'label' => 'Mengundurkan diri',
            'pesangon_mul' => 0, 'upmk_mul' => 0, 'eligible_uph' => true,
            'requires_separation' => true,
        ],
        'VIOLATION_GROSS' => [
            'label' => 'Pelanggaran berat (Pasal 52 PP 35/2021)',
            'pesangon_mul' => 0, 'upmk_mul' => 0, 'eligible_uph' => true,
            'requires_separation' => true,
        ],
        'LONG_ABSENT_PROHIBIT' => [
            'label' => 'Mangkir 5 hari kerja berturut-turut',
            'pesangon_mul' => 0, 'upmk_mul' => 0, 'eligible_uph' => true,
            'requires_separation' => true,
        ],
        'END_OF_PKWT' => [
            'label' => 'Akhir PKWT (kompensasi proporsional)',
            'pesangon_mul' => 0, 'upmk_mul' => 0, 'eligible_uph' => true,
            'requires_separation' => false,
            'is_pkwt' => true,
        ],
        'CONTRACT_EXPIRY' => [
            'label' => 'Habis kontrak (tidak ada pesangon)',
            'pesangon_mul' => 0, 'upmk_mul' => 0, 'eligible_uph' => true,
            'requires_separation' => false,
        ],
    ];

    /**
     * Hitung dan kembalikan breakdown lengkap.
     *
     * @param array $params {
     *   employee_id, last_monthly_salary, service_years (decimal),
     *   termination_reason, termination_date, unused_leave_days,
     *   uph_homecoming_amount?, uph_other_amount?, separation_pay?,
     *   work_days_per_month? (default 22)
     * }
     * @return array
     */
    public function calculate(array $params): array
    {
        $reason = (string) ($params['termination_reason'] ?? 'EFFICIENCY_LOSS');
        if (! isset(self::REASONS[$reason])) {
            throw new \InvalidArgumentException("Alasan PHK '{$reason}' tidak valid. Pilihan: " . implode(', ', array_keys(self::REASONS)));
        }
        $reasonCfg = self::REASONS[$reason];

        $salary       = max(0.0, (float) ($params['last_monthly_salary'] ?? 0));
        $serviceYears = max(0.0, (float) ($params['service_years'] ?? 0));
        $serviceMonths= (int) round($serviceYears * 12);
        $unusedLeave  = max(0.0, (float) ($params['unused_leave_days'] ?? 0));
        $workDaysPerMonth = max(20, (int) ($params['work_days_per_month'] ?? 22));

        // Special case: PKWT compensation. Pasal 17 PP 35/2021:
        // "1 bulan upah untuk setiap 12 bulan masa kerja" (proporsional).
        if (! empty($reasonCfg['is_pkwt'])) {
            $pkwtCompensation = ($serviceMonths / 12) * $salary;
            $upMessage = "PKWT: 1 bulan upah per 12 bulan masa kerja proporsional";

            $uphLeave  = $this->calculateUnusedLeavePay($salary, $unusedLeave, $workDaysPerMonth);
            $uphHome   = (float) ($params['uph_homecoming_amount'] ?? 0);
            $uphOther  = (float) ($params['uph_other_amount'] ?? 0);
            $uphTotal  = $uphLeave + $uphHome + $uphOther;

            return $this->buildResult($reason, $reasonCfg['label'], $salary, $serviceYears, $serviceMonths, [
                'pesangon_multiplier' => 0,
                'pesangon_months' => 0,
                'pesangon_amount' => $pkwtCompensation, // diparkir di pesangon untuk simplicity
                'pesangon_note' => $upMessage,
                'upmk_multiplier' => 0,
                'upmk_months' => 0,
                'upmk_amount' => 0,
                'uph_unused_leave_amount' => $uphLeave,
                'uph_homecoming_amount' => $uphHome,
                'uph_other_amount' => $uphOther,
                'uph_total' => $uphTotal,
                'unused_leave_days' => $unusedLeave,
                'separation_pay' => 0,
            ]);
        }

        // Pesangon
        $pesangonMonths = $this->pesangonMonths($serviceYears);
        $pesangonAmount = $reasonCfg['pesangon_mul'] * $pesangonMonths * $salary;

        // UPMK (hanya untuk masa kerja ≥ 3 tahun)
        $upmkMonths = $this->upmkMonths($serviceYears);
        $upmkAmount = $reasonCfg['upmk_mul'] * $upmkMonths * $salary;

        // UPH
        $uphLeave  = $reasonCfg['eligible_uph']
            ? $this->calculateUnusedLeavePay($salary, $unusedLeave, $workDaysPerMonth)
            : 0;
        $uphHome   = $reasonCfg['eligible_uph'] ? (float) ($params['uph_homecoming_amount'] ?? 0) : 0;
        $uphOther  = $reasonCfg['eligible_uph'] ? (float) ($params['uph_other_amount'] ?? 0) : 0;
        $uphTotal  = $uphLeave + $uphHome + $uphOther;

        // Uang pisah (resign / pelanggaran berat / mangkir)
        $separationPay = $reasonCfg['requires_separation']
            ? max(0.0, (float) ($params['separation_pay'] ?? 0))
            : 0;

        return $this->buildResult($reason, $reasonCfg['label'], $salary, $serviceYears, $serviceMonths, [
            'pesangon_multiplier' => $reasonCfg['pesangon_mul'],
            'pesangon_months' => $pesangonMonths,
            'pesangon_amount' => $pesangonAmount,
            'upmk_multiplier' => $reasonCfg['upmk_mul'],
            'upmk_months' => $upmkMonths,
            'upmk_amount' => $upmkAmount,
            'uph_unused_leave_amount' => $uphLeave,
            'uph_homecoming_amount' => $uphHome,
            'uph_other_amount' => $uphOther,
            'uph_total' => $uphTotal,
            'unused_leave_days' => $unusedLeave,
            'separation_pay' => $separationPay,
        ]);
    }

    /**
     * Render HTML breakdown untuk dokumen / preview.
     */
    public function renderBreakdown(array $result, array $employee = [], array $company = []): string
    {
        $fmt = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                   'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $dateLong = function ($d) use ($months) {
            if (! $d) return '-';
            $ts = strtotime($d);
            return date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . date('Y', $ts);
        };

        $h = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
           . 'body{font-family:Arial,sans-serif;font-size:11px;color:#222;padding:20px}'
           . 'h1{font-size:16px;text-align:center;margin:0 0 4px}'
           . 'h2{font-size:13px;margin:18px 0 6px;border-bottom:1px solid #aaa;padding-bottom:2px}'
           . 'table{border-collapse:collapse;width:100%;margin-top:6px}'
           . 'td,th{padding:5px 8px;vertical-align:top}'
           . 'th{background:#f3f4f6;text-align:left}'
           . '.right{text-align:right}.bold{font-weight:bold}'
           . '.total-row{background:#fef9c3}.kv td{border:1px solid #ddd}'
           . '</style></head><body>';
        $h .= '<h1>RINCIAN PERHITUNGAN HAK KARYAWAN — PHK</h1>';
        $h .= '<div style="text-align:center;font-size:11px;margin-bottom:14px">';
        $h .= 'Sesuai UU No 6 Tahun 2023 (Cipta Kerja) & PP 35 Tahun 2021';
        $h .= '</div>';

        $h .= '<h2>A. Data Karyawan</h2>';
        $h .= '<table class="kv"><tbody>';
        $h .= '<tr><td style="width:35%">Nama</td><td>' . htmlspecialchars($employee['full_name'] ?? '-') . '</td></tr>';
        $h .= '<tr><td>NIK / NIP</td><td>' . htmlspecialchars($employee['employee_number'] ?? '-') . '</td></tr>';
        $h .= '<tr><td>Jabatan</td><td>' . htmlspecialchars($employee['position_name'] ?? '-') . '</td></tr>';
        $h .= '<tr><td>Departemen</td><td>' . htmlspecialchars($employee['department_name'] ?? '-') . '</td></tr>';
        $h .= '<tr><td>Tanggal Bergabung</td><td>' . $dateLong($employee['join_date'] ?? null) . '</td></tr>';
        $h .= '<tr><td>Tanggal Berakhir</td><td>' . $dateLong($result['termination_date'] ?? null) . '</td></tr>';
        $h .= '<tr><td>Masa Kerja</td><td>' . number_format((float) $result['service_years'], 2, ',', '.') . ' tahun ('
            . $result['service_months'] . ' bulan)</td></tr>';
        $h .= '<tr><td>Alasan PHK</td><td>' . htmlspecialchars($result['reason_label']) . '</td></tr>';
        $h .= '<tr><td>Upah Bulanan Dasar</td><td>' . $fmt($result['last_monthly_salary']) . '</td></tr>';
        $h .= '</tbody></table>';

        $h .= '<h2>B. Komponen Pesangon (Pasal 40 ayat 2 PP 35/2021)</h2>';
        $h .= '<table class="kv"><tbody>';
        $h .= '<tr><td style="width:35%">Bulan Upah Pesangon</td><td>' . number_format($result['pesangon_months'], 2, ',', '.') . ' bulan</td></tr>';
        $h .= '<tr><td>Multiplier (sesuai alasan)</td><td>' . number_format($result['pesangon_multiplier'], 2, ',', '.') . '× </td></tr>';
        $h .= '<tr class="bold"><td>Total Pesangon</td><td class="right">' . $fmt($result['pesangon_amount']) . '</td></tr>';
        $h .= '</tbody></table>';

        $h .= '<h2>C. UPMK (Pasal 40 ayat 3 PP 35/2021)</h2>';
        if ($result['upmk_months'] > 0) {
            $h .= '<table class="kv"><tbody>';
            $h .= '<tr><td style="width:35%">Bulan Upah UPMK</td><td>' . number_format($result['upmk_months'], 2, ',', '.') . ' bulan</td></tr>';
            $h .= '<tr><td>Multiplier (sesuai alasan)</td><td>' . number_format($result['upmk_multiplier'], 2, ',', '.') . '×</td></tr>';
            $h .= '<tr class="bold"><td>Total UPMK</td><td class="right">' . $fmt($result['upmk_amount']) . '</td></tr>';
            $h .= '</tbody></table>';
        } else {
            $h .= '<p style="font-size:11px;color:#666">Tidak ada UPMK karena masa kerja kurang dari 3 tahun atau alasan PHK tidak memenuhi syarat.</p>';
        }

        $h .= '<h2>D. UPH — Uang Penggantian Hak (Pasal 40 ayat 4)</h2>';
        $h .= '<table class="kv"><tbody>';
        $h .= '<tr><td style="width:55%">Cuti tahunan belum diambil (' . number_format($result['unused_leave_days'], 2, ',', '.') . ' hari)</td>'
            . '<td class="right">' . $fmt($result['uph_unused_leave_amount']) . '</td></tr>';
        $h .= '<tr><td>Biaya pulang ke tempat penerimaan kerja</td><td class="right">' . $fmt($result['uph_homecoming_amount']) . '</td></tr>';
        $h .= '<tr><td>Hak lain sesuai PKB/PP/PKWT</td><td class="right">' . $fmt($result['uph_other_amount']) . '</td></tr>';
        $h .= '<tr class="bold"><td>Total UPH</td><td class="right">' . $fmt($result['uph_total']) . '</td></tr>';
        $h .= '</tbody></table>';

        if ($result['separation_pay'] > 0) {
            $h .= '<h2>E. Uang Pisah (Pasal 50 PP 35/2021)</h2>';
            $h .= '<table class="kv"><tbody><tr class="bold"><td style="width:55%">Uang Pisah (sesuai PKB/PP)</td>'
                . '<td class="right">' . $fmt($result['separation_pay']) . '</td></tr></tbody></table>';
        }

        $h .= '<h2>Grand Total</h2>';
        $h .= '<table style="border:2px solid #222"><tbody>';
        $h .= '<tr class="total-row bold"><td style="width:55%">TOTAL HAK YANG DITERIMA KARYAWAN</td>'
            . '<td class="right" style="font-size:14px">' . $fmt($result['total_amount']) . '</td></tr>';
        $h .= '</tbody></table>';

        $h .= '<p style="margin-top:24px;font-size:10px;color:#555">Catatan: Perhitungan ini dibuat berdasarkan data dan ketentuan saat dokumen ini diterbitkan. '
            . 'Pajak (PPh21) atas pesangon dipotong terpisah sesuai PMK 16/2010 dengan tarif final '
            . 'progresif (0% untuk ≤ 50jt, 5% untuk 50-100jt, 15% untuk 100-500jt, 25% > 500jt).</p>';

        $h .= '<table style="width:100%;margin-top:36px;border:none"><tr style="border:none">'
            . '<td style="border:none;width:50%;text-align:center">'
            . '<div>' . htmlspecialchars($company['city'] ?? 'Jakarta') . ', ' . $dateLong(date('Y-m-d')) . '</div>'
            . '<div>HR / Manajemen</div>'
            . '<div style="height:60px"></div><div style="text-decoration:underline"><strong>______________________</strong></div></td>'
            . '<td style="border:none;width:50%;text-align:center">'
            . '<div>Karyawan</div><div style="height:90px"></div>'
            . '<div style="text-decoration:underline"><strong>' . htmlspecialchars($employee['full_name'] ?? '___________') . '</strong></div></td>'
            . '</tr></table>';

        $h .= '</body></html>';
        return $h;
    }

    /**
     * Hitung masa kerja dalam tahun (decimal) antara dua tanggal.
     */
    public function computeServiceYears(string $joinDate, string $endDate): float
    {
        $start = strtotime($joinDate);
        $end   = strtotime($endDate);
        if (! $start || ! $end || $end <= $start) return 0.0;
        $days = ($end - $start) / 86400;
        return round($days / 365.25, 2);
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    /**
     * Bulan upah pesangon berdasarkan masa kerja (Pasal 40 ayat 2).
     */
    public function pesangonMonths(float $years): int
    {
        if ($years < 1) return 1;
        if ($years < 2) return 2;
        if ($years < 3) return 3;
        if ($years < 4) return 4;
        if ($years < 5) return 5;
        if ($years < 6) return 6;
        if ($years < 7) return 7;
        if ($years < 8) return 8;
        return 9;
    }

    /**
     * Bulan upah UPMK berdasarkan masa kerja (Pasal 40 ayat 3).
     * 0 untuk masa kerja < 3 tahun.
     */
    public function upmkMonths(float $years): int
    {
        if ($years < 3)  return 0;
        if ($years < 6)  return 2;
        if ($years < 9)  return 3;
        if ($years < 12) return 4;
        if ($years < 15) return 5;
        if ($years < 18) return 6;
        if ($years < 21) return 7;
        if ($years < 24) return 8;
        return 10;
    }

    /**
     * Hitung UPH komponen cuti tahunan belum diambil.
     * Formula: hari cuti × (upah bulanan / hari kerja per bulan).
     */
    private function calculateUnusedLeavePay(float $salary, float $unusedDays, int $workDaysPerMonth): float
    {
        if ($unusedDays <= 0) return 0.0;
        $dailyRate = $salary / max(1, $workDaysPerMonth);
        return $unusedDays * $dailyRate;
    }

    /**
     * Bangun result array final dengan total.
     */
    private function buildResult(
        string $reason, string $reasonLabel, float $salary,
        float $years, int $months, array $components
    ): array {
        $total = (float) ($components['pesangon_amount'] ?? 0)
               + (float) ($components['upmk_amount'] ?? 0)
               + (float) ($components['uph_total'] ?? 0)
               + (float) ($components['separation_pay'] ?? 0);

        return array_merge([
            'termination_reason'  => $reason,
            'reason_label'        => $reasonLabel,
            'last_monthly_salary' => $salary,
            'service_years'       => $years,
            'service_months'      => $months,
            'total_amount'        => $total,
        ], $components);
    }

    /**
     * Daftar reason yang available untuk dropdown UI.
     */
    public static function reasonOptions(): array
    {
        $out = [];
        foreach (self::REASONS as $slug => $cfg) {
            $out[] = [
                'value' => $slug,
                'label' => $cfg['label'],
                'pesangon_mul' => $cfg['pesangon_mul'],
                'upmk_mul' => $cfg['upmk_mul'],
                'requires_separation' => ! empty($cfg['requires_separation']),
                'is_pkwt' => ! empty($cfg['is_pkwt']),
            ];
        }
        return $out;
    }
}