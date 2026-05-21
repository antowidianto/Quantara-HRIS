<?php

namespace App\Libraries;

/**
 * BpjsCalculator
 *
 * BPJS Kesehatan: 1% karyawan + 4% perusahaan, plafon 12jt/bulan.
 * BPJS JHT: 2% karyawan + 3.7% perusahaan (no cap).
 * BPJS JP: 1% karyawan + 2% perusahaan, plafon (default 10.547.400 per PMK terbaru).
 * BPJS JKK: 0.24%–1.74% perusahaan only (default 0.24%).
 * BPJS JKM: 0.3% perusahaan only.
 */
class BpjsCalculator
{
    /**
     * @return array per-component amounts (employee + company portions)
     */
    public function calculate(int $companyId, float $basicSalary, ?float $allowance = 0): array
    {
        $db = \Config\Database::connect();
        $rates = $db->table('bpjs_rates')
            ->where('company_id', $companyId)
            ->limit(1)
            ->get()
            ->getRowArray();

        if (! $rates) {
            // sensible defaults
            $rates = [
                'kes_employee_rate' => 0.01,
                'kes_company_rate'  => 0.04,
                'kes_max_salary'    => 12000000,
                'jht_employee_rate' => 0.02,
                'jht_company_rate'  => 0.037,
                'jp_employee_rate'  => 0.01,
                'jp_company_rate'   => 0.02,
                'jp_max_salary'     => 10547400,
                'jkk_company_rate'  => 0.0024,
                'jkm_company_rate'  => 0.003,
            ];
        }

        $base = $basicSalary + (float) ($allowance ?? 0);

        $kesBase = min($base, (float) $rates['kes_max_salary']);
        $jpBase  = min($base, (float) $rates['jp_max_salary']);
        $jhtBase = $base; // JHT no cap
        $jkkBase = $base;
        $jkmBase = $base;

        return [
            'kes_employee'   => round($kesBase * (float) $rates['kes_employee_rate']),
            'kes_company'    => round($kesBase * (float) $rates['kes_company_rate']),
            'jht_employee'   => round($jhtBase * (float) $rates['jht_employee_rate']),
            'jht_company'    => round($jhtBase * (float) $rates['jht_company_rate']),
            'jp_employee'    => round($jpBase  * (float) $rates['jp_employee_rate']),
            'jp_company'     => round($jpBase  * (float) $rates['jp_company_rate']),
            'jkk_company'    => round($jkkBase * (float) $rates['jkk_company_rate']),
            'jkm_company'    => round($jkmBase * (float) $rates['jkm_company_rate']),

            'employee_total' => round(
                $kesBase * (float) $rates['kes_employee_rate']
                + $jhtBase * (float) $rates['jht_employee_rate']
                + $jpBase  * (float) $rates['jp_employee_rate']
            ),
            'company_total'  => round(
                $kesBase * (float) $rates['kes_company_rate']
                + $jhtBase * (float) $rates['jht_company_rate']
                + $jpBase  * (float) $rates['jp_company_rate']
                + $jkkBase * (float) $rates['jkk_company_rate']
                + $jkmBase * (float) $rates['jkm_company_rate']
            ),
        ];
    }
}