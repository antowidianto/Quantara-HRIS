<?php

namespace App\Libraries\Payroll;

/**
 * JkpPayslipIntegration — Sprint C+1 / C7.
 *
 * BPJS JKP (Jaminan Kehilangan Pekerjaan) wiring ke payslip + SiPP export.
 * Per PP 6/2025 (efektif 7 Februari 2025):
 *
 *   - Iuran total: 0,36% dari upah (employee tidak dipotong).
 *   - Pemerintah Pusat mendanai 0,22%.
 *   - Sisanya 0,14% berasal dari rekomposisi iuran JKK, sehingga bukan
 *     employer cost tambahan di atas tarif JKK yang telah direkomposisi.
 *   - Plafon upah JKP Rp5.000.000 per bulan.
 *
 * `BpjsCalculator` sudah hitung breakdown ini, tapi `PayrollService`
 * belum mantap masukin ke payslip kolom + breakdown JSON. Library ini
 * pure function transformasi: kasih breakdown BPJS → kasih balik update
 * yang siap di-merge ke payslip row.
 *
 * Tidak butuh DB. Test pure function di `tests/Unit/JkpPayslipIntegrationTest.php`.
 */
class JkpPayslipIntegration
{
    /**
     * Cap upah JKP per PP 35/2021. Sama dengan JP cap.
     */
    public const WAGE_CAP = 5_000_000.0;

    /**
     * Total iuran program JKP 0,36% dari upah (≤ cap).
     */
    public const RATE_TOTAL = 0.0036;

    /**
     * Pendanaan Pemerintah Pusat 0,22%.
     */
    public const RATE_GOV_SUBSIDY = 0.0022;

    /**
     * Pendanaan melalui rekomposisi JKK 0,14%.
     *
     * Nama konstanta legacy dipertahankan untuk kompatibilitas API. Nilai ini
     * bukan biaya tambahan perusahaan; sudah merupakan bagian tarif JKK baru.
     */
    public const RATE_COMPANY_NET = self::RATE_TOTAL - self::RATE_GOV_SUBSIDY;

    /**
     * Hitung breakdown JKP untuk satu employee dalam 1 period.
     *
     * @param float $monthlyWage Upah bulanan (gross_earning sebelum BPJS/PPh)
     * @return array{
     *   wage_base:float,
     *   total:float,
     *   gov_subsidy:float,
     *   company_net:float,
     *   employee:float,
     * }
     */
    public function compute(float $monthlyWage): array
    {
        if ($monthlyWage <= 0) {
            return [
                'wage_base'    => 0.0,
                'total'        => 0.0,
                'gov_subsidy'  => 0.0,
                'company_net'  => 0.0,
                'employee'     => 0.0,
            ];
        }

        $wageBase   = min($monthlyWage, self::WAGE_CAP);
        $total      = round($wageBase * self::RATE_TOTAL, 2);
        $govSubsidy = round($wageBase * self::RATE_GOV_SUBSIDY, 2);
        // Legacy key `company_net` berarti sumber rekomposisi JKK, bukan
        // tambahan employer cost. Hitung dari selisih agar rounding konsisten.
        $companyNet = round($total - $govSubsidy, 2);

        return [
            'wage_base'    => $wageBase,
            'total'        => $total,
            'gov_subsidy'  => $govSubsidy,
            'company_net'  => $companyNet,
            'employee'     => 0.0, // JKP: employee tidak potong
        ];
    }

    /**
     * Apply hasil compute() ke array payslip row. Tidak mengubah array
     * input — return array baru dengan field JKP di-set.
     *
     * Konvensi nama kolom mengikuti migration #037 `AddBpjsJkpAndKesClass`:
     *   - `bpjs_jkp_company` (DECIMAL 15,2)
     *
     * Plus tambahan key di `breakdown` JSON untuk transparansi audit.
     *
     * @param array<string,mixed> $payslip Row payslip yang sedang di-compute
     * @param float               $monthlyWage
     * @return array<string,mixed> Payslip dengan JKP fields populated
     */
    public function applyToPayslip(array $payslip, float $monthlyWage): array
    {
        $jkp = $this->compute($monthlyWage);

        // Kolom legacy menyimpan nominal sumber rekomposisi JKK untuk audit.
        // Caller tidak boleh menambahkannya lagi ke employer total.
        $payslip['bpjs_jkp_company'] = $jkp['company_net'];

        // Append ke breakdown JSON. Caller bertanggung jawab serialize
        // payslip['breakdown'] saat persist (PayrollService biasanya
        // melakukan ini di tahap akhir).
        $existing = $payslip['breakdown'] ?? [];
        if (is_string($existing)) {
            $decoded = json_decode($existing, true);
            $existing = is_array($decoded) ? $decoded : [];
        }
        $existing['jkp'] = [
            'wage_base'   => $jkp['wage_base'],
            'rate_total'  => self::RATE_TOTAL,
            'gov_subsidy' => $jkp['gov_subsidy'],
            'jkk_reallocation' => $jkp['company_net'],
            'company_additional_cost' => 0.0,
            'rule'         => 'PP_6_2025',
            'effective_from' => '2025-02-07',
            'cap_applied'  => $monthlyWage > self::WAGE_CAP,
        ];
        $payslip['breakdown'] = $existing;

        return $payslip;
    }

    /**
     * Format row untuk SiPP (Sistem Pelaporan Pelayanan) BPJS export
     * bulanan. Output struktur ini di-flatten ke CSV/XML oleh exporter.
     *
     * Berbeda dengan compute() yang fokus ke payslip: SiPP butuh
     * employee-level data plus contribution breakdown.
     *
     * @param array{
     *   employee_id:int,
     *   bpjs_tk_no:string,
     *   nik:string,
     *   full_name:string,
     *   monthly_wage:float,
     * } $employeeContext
     * @return array{
     *   bpjs_tk_no:string,
     *   nik:string,
     *   name:string,
     *   wage_base:float,
     *   total_iuran:float,
     *   gov_subsidy:float,
     *   company_paid:float,
     * }
     */
    public function buildSippRow(array $employeeContext): array
    {
        $monthlyWage = (float) ($employeeContext['monthly_wage'] ?? 0);
        $jkp = $this->compute($monthlyWage);

        return [
            'bpjs_tk_no'   => (string) ($employeeContext['bpjs_tk_no'] ?? ''),
            'nik'          => (string) ($employeeContext['nik'] ?? ''),
            'name'         => (string) ($employeeContext['full_name'] ?? ''),
            'wage_base'    => $jkp['wage_base'],
            'total_iuran'  => $jkp['total'],
            'gov_subsidy'  => $jkp['gov_subsidy'],
            'company_paid' => $jkp['company_net'],
        ];
    }
}