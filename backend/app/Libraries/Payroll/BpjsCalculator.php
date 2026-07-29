<?php

namespace App\Libraries\Payroll;

use App\Constants\EmploymentStatus;

use App\Libraries\System\CacheService;
/**
 * BpjsCalculator
 *
 * Hitung iuran BPJS Indonesia per komponen sesuai regulasi terbaru:
 *
 *  BPJS Kesehatan (PerPres 64/2020): 5% × upah, dibagi 4% perusahaan +
 *  1% karyawan. Plafon upah Rp 12 juta.
 *
 *  BPJS Ketenagakerjaan:
 *    - JHT (Jaminan Hari Tua): 5.7% total = 3.7% perusahaan + 2% karyawan.
 *      Tidak ada plafon (full salary).
 *    - JP (Jaminan Pensiun): 3% total = 2% perusahaan + 1% karyawan.
 *      Plafon upah Rp 10.547.400 (PMK terbaru).
 *    - JKK (Jaminan Kecelakaan Kerja): 0,10%–1,60% perusahaan, setelah
 *      rekomposisi 0,14% untuk pendanaan JKP (PP 6/2025).
 *    - JKM (Jaminan Kematian): 0,3% perusahaan only.
 *    - JKP (Jaminan Kehilangan Pekerjaan, PP 6/2025): 0,36% dengan plafon
 *      upah Rp5 juta; 0,22% bersumber dari Pemerintah Pusat dan 0,14%
 *      dari rekomposisi JKK. Bukan employer cost tambahan di atas JKK.
 *
 * Caller pattern:
 *
 *   $calc = Services::bpjs();
 *   $result = $calc->calculate($companyId, $basicSalary, $allowance);
 *   // → ['kes_employee' => …, 'jht_company' => …, 'jkp_company' => …, …]
 *
 * Kalau perusahaan belum set rates di DB, fallback ke nilai default
 * (lihat `defaultRates()`). Method `compute()` adalah versi pure
 * (tidak hit DB) yang menerima rates langsung — mudah di-unit-test.
 */
class BpjsCalculator
{
    /**
     * Default rate efektif sejak 7 Februari 2025 (PP 6/2025).
     *
     * `jkp_company_rate` dipertahankan sebagai nama kolom legacy, tetapi
     * nilainya adalah total program JKP 0,36%, bukan biaya tambahan perusahaan.
     * Breakdown sumber dana dikembalikan secara eksplisit oleh compute().
     *
     * @return array<string, float>
     */
    public static function defaultRates(): array
    {
        return [
            'kes_employee_rate' => 0.01,
            'kes_company_rate'  => 0.04,
            'kes_max_salary'    => 12000000.0,
            'jht_employee_rate' => 0.02,
            'jht_company_rate'  => 0.037,
            'jp_employee_rate'  => 0.01,
            'jp_company_rate'   => 0.02,
            'jp_max_salary'     => 10547400.0,
            'jkk_company_rate'        => 0.0010,
            'jkm_company_rate'        => 0.003,
            'jkp_company_rate'        => 0.0036,
            'jkp_government_rate'     => 0.0022,
            'jkp_jkk_reallocation_rate' => 0.0014,
            'jkp_max_salary'          => 5000000.0,
        ];
    }

    /**
     * Hitung iuran BPJS untuk satu karyawan (entry point dengan DB).
     *
     * Caller bisa pass `$employmentStatus` untuk mengaktifkan JKP secara
     * kondisional (default: aktif untuk PKWT/PKWTT, skip untuk MAGANG/
     * OUTSOURCING/HARIAN). Kalau null, JKP tetap dihitung — biarkan
     * payroll service yang putuskan apakah include atau tidak.
     *
     * @return array per-component amounts (employee + company portions)
     */
    public function calculate(
        int $companyId,
        float $basicSalary,
        ?float $allowance = 0,
        ?string $employmentStatus = null,
    ): array {
        $rates = $this->loadRates($companyId);
        $base  = $basicSalary + (float) ($allowance ?? 0);
        return $this->compute($base, $rates, $employmentStatus);
    }

    /**
     * Pure compute() — tidak hit DB. Rates harus disuplai. Berguna untuk:
     *  - Unit test
     *  - Caller yang sudah punya rates di-cache
     *  - Simulator gaji frontend yang call sekali, hitung berkali-kali
     *
     * @param float       $base             Upah dasar untuk perhitungan (basic + tunjangan tetap)
     * @param array       $rates            Override rates (gabung ke default)
     * @param string|null $employmentStatus PKWT/PKWTT/MAGANG/dll. NULL = include semua
     * @return array<string, float>
     */
    public function compute(
        float $base,
        array $rates = [],
        ?string $employmentStatus = null,
    ): array {
        $rates = array_merge(self::defaultRates(), $rates);

        $kesBase = min($base, (float) $rates['kes_max_salary']);
        $jpBase  = min($base, (float) $rates['jp_max_salary']);
        $jhtBase = $base; // JHT no cap
        $jkkBase = $base;
        $jkmBase = $base;
        $jkpBase = min($base, (float) $rates['jkp_max_salary']);

        // Eligibility tetap ditentukan dari status hubungan kerja. Null
        // dipertahankan sebagai include untuk kompatibilitas caller lama.
        $jkpEligible = EmploymentStatus::isJkpEligible($employmentStatus);

        $kesEmployee = round($kesBase * (float) $rates['kes_employee_rate']);
        $kesCompany  = round($kesBase * (float) $rates['kes_company_rate']);
        $jhtEmployee = round($jhtBase * (float) $rates['jht_employee_rate']);
        $jhtCompany  = round($jhtBase * (float) $rates['jht_company_rate']);
        $jpEmployee  = round($jpBase  * (float) $rates['jp_employee_rate']);
        $jpCompany   = round($jpBase  * (float) $rates['jp_company_rate']);
        $jkkCompany  = round($jkkBase * (float) $rates['jkk_company_rate']);
        $jkmCompany  = round($jkmBase * (float) $rates['jkm_company_rate']);
        $jkpTotal = $jkpEligible
            ? round($jkpBase * (float) $rates['jkp_company_rate'])
            : 0.0;
        $jkpGovernment = $jkpEligible
            ? round($jkpBase * (float) $rates['jkp_government_rate'])
            : 0.0;
        $jkpJkkReallocation = $jkpEligible
            ? round($jkpBase * (float) $rates['jkp_jkk_reallocation_rate'])
            : 0.0;

        // PP 6/2025 tidak membuat kontribusi JKP menjadi employer cost
        // tambahan: bagian 0,14% sudah terkandung dalam tarif JKK baru.
        $jkpCompanyAdditional = 0.0;

        return [
            'kes_employee'   => $kesEmployee,
            'kes_company'    => $kesCompany,
            'jht_employee'   => $jhtEmployee,
            'jht_company'    => $jhtCompany,
            'jp_employee'    => $jpEmployee,
            'jp_company'     => $jpCompany,
            'jkk_company'    => $jkkCompany,
            'jkm_company'          => $jkmCompany,
            'jkp_total'            => $jkpTotal,
            'jkp_government'       => $jkpGovernment,
            'jkp_jkk_reallocation' => $jkpJkkReallocation,
            'jkp_company'          => $jkpCompanyAdditional,

            'employee_total' => round($kesEmployee + $jhtEmployee + $jpEmployee),
            'company_total'  => round($kesCompany + $jhtCompany + $jpCompany
                                 + $jkkCompany + $jkmCompany),
        ];
    }

    /**
     * Ambil rates dari DB; fallback ke default kalau belum ada.
     *
     * Sprint 11+ — di-cache via `CacheService` dengan TTL 1 jam karena
     * rates BPJS jarang berubah (regulasi tahunan). Invalidate manual
     * saat admin update via `cacheService->forget(keyBpjsRates($companyId))`
     * di controller setting.
     *
     * @return array<string, float>
     */
    private function loadRates(int $companyId): array
    {
        try {
            /** @var CacheService $cache */
            $cache = service('cacheService');
            return $cache->remember(
                $cache->keyBpjsRates($companyId),
                CacheService::TTL_HOUR,
                fn () => $this->fetchRatesFromDb($companyId),
                skipFalsy: false,  // cache walau result default (hit DB sekali, tidak setiap request)
            );
        } catch (\Throwable $e) {
            // CacheService tidak available (test env / boot issue) —
            // fallback langsung ke DB query
            return $this->fetchRatesFromDb($companyId);
        }
    }

    /**
     * Direct DB fetch (no cache). Dipakai sebagai callback di loadRates()
     * dan saat cache backend tidak available.
     *
     * @return array<string, float>
     */
    private function fetchRatesFromDb(int $companyId): array
    {
        try {
            $db = \Config\Database::connect();
            $row = $db->table('bpjs_rates')
                ->where('company_id', $companyId)
                ->limit(1)
                ->get()
                ->getRowArray();
            if ($row) return $row;
        } catch (\Throwable $e) {
            // DB tidak tersedia (test env / setup awal) — pakai default
        }
        return self::defaultRates();
    }
}
