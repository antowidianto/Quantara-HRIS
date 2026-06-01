<?php

namespace App\Libraries\Payroll;

/**
 * JkpPayslipIntegration — Sprint C+1 / C7.
 *
 * BPJS JKP (Jaminan Kehilangan Pekerjaan) wiring ke payslip + SiPP export.
 * Per PP 37/2021:
 *
 *   - Iuran total: 0.46% dari upah, di-bayar penuh oleh pemberi kerja
 *     (employee tidak ada potongan, tapi tetap di-record di payslip
 *     sebagai company contribution untuk audit & SiPP report).
 *   - Pemerintah tanggung 0.22% dari iuran ini → company netto bayar 0.24%.
 *   - Cap upah PP 35/2021: Rp 5.000.000/bulan (sama dengan JP).
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
     * Total kontribusi 0.46% dari upah (≤ cap).
     */
    public const RATE_TOTAL = 0.0046;

    /**
     * Subsidi pemerintah 0.22% — substract dari total untuk netto company.
     */
    public const RATE_GOV_SUBSIDY = 0.0022;

    /**
     * Kontribusi netto company yang sebenarnya keluar.
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
        // Hitung companyNet dari total - subsidy supaya pembulatan konsisten
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
            'company_net' => $jkp['company_net'],
            'cap_applied' => $monthlyWage > self::WAGE_CAP,
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