<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seeder data master khas Indonesia:
 *  - regional_minimum_wages (UMP 2026 per provinsi)
 *  - holidays (libur nasional 2026 per Kepmenaker)
 *
 * Aman untuk dijalankan berulang (idempotent): cek existing dulu sebelum insert.
 *
 * Jalankan:
 *   php spark db:seed IndonesianMastersSeeder
 */
class IndonesianMastersSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedMinimumWages();
        $this->seedHolidays2026();
    }

    /**
     * UMP 2026 per provinsi (estimasi naik ~6.5% dari UMP 2025).
     * Angka ini DEFAULT awal — admin wajib memutakhirkan sesuai SK Gubernur
     * resmi tahun berjalan via menu Settings > UMP/UMK.
     *
     * Tidak menyertakan UMK kabupaten/kota. Tambahkan manual jika perlu
     * referensi kota tertentu (mis. Jakarta UMP = UMK).
     */
    private function seedMinimumWages(): void
    {
        $year = 2026;
        $rows = [
            // Sumatera
            ['Aceh', null, 'UMP', 3742000],
            ['Sumatera Utara', null, 'UMP', 3030000],
            ['Sumatera Barat', null, 'UMP', 3092000],
            ['Riau', null, 'UMP', 3508000],
            ['Kepulauan Riau', null, 'UMP', 3623000],
            ['Jambi', null, 'UMP', 3315000],
            ['Bengkulu', null, 'UMP', 2696000],
            ['Sumatera Selatan', null, 'UMP', 3681000],
            ['Bangka Belitung', null, 'UMP', 3876000],
            ['Lampung', null, 'UMP', 2880000],
            // Jawa
            ['DKI Jakarta', null, 'UMP', 5396760],
            ['Jawa Barat', null, 'UMP', 2275000],
            ['Banten', null, 'UMP', 2950000],
            ['Jawa Tengah', null, 'UMP', 2244000],
            ['DI Yogyakarta', null, 'UMP', 2350000],
            ['Jawa Timur', null, 'UMP', 2329000],
            // Bali & Nusa Tenggara
            ['Bali', null, 'UMP', 2996561],
            ['Nusa Tenggara Barat', null, 'UMP', 2602000],
            ['Nusa Tenggara Timur', null, 'UMP', 2330000],
            // Kalimantan
            ['Kalimantan Barat', null, 'UMP', 2880000],
            ['Kalimantan Tengah', null, 'UMP', 3486000],
            ['Kalimantan Selatan', null, 'UMP', 3496000],
            ['Kalimantan Timur', null, 'UMP', 3700000],
            ['Kalimantan Utara', null, 'UMP', 3580000],
            // Sulawesi
            ['Sulawesi Utara', null, 'UMP', 3776000],
            ['Sulawesi Tengah', null, 'UMP', 2914000],
            ['Sulawesi Selatan', null, 'UMP', 3700000],
            ['Sulawesi Tenggara', null, 'UMP', 3220000],
            ['Gorontalo', null, 'UMP', 3221000],
            ['Sulawesi Barat', null, 'UMP', 3142000],
            // Maluku & Papua
            ['Maluku', null, 'UMP', 3141700],
            ['Maluku Utara', null, 'UMP', 3408000],
            ['Papua', null, 'UMP', 4476000],
            ['Papua Barat', null, 'UMP', 3829000],
            ['Papua Tengah', null, 'UMP', 4290000],
            ['Papua Pegunungan', null, 'UMP', 4290000],
            ['Papua Selatan', null, 'UMP', 4290000],
            ['Papua Barat Daya', null, 'UMP', 3829000],
            // UMK contoh (bisa ditambah lebih banyak via UI)
            ['Jawa Barat', 'Bekasi', 'UMK', 5558444],
            ['Jawa Barat', 'Karawang', 'UMK', 5599593],
            ['Jawa Barat', 'Bandung', 'UMK', 4232000],
            ['Banten', 'Tangerang', 'UMK', 4900000],
            ['Banten', 'Tangerang Selatan', 'UMK', 4910000],
            ['Jawa Tengah', 'Semarang', 'UMK', 3454827],
            ['Jawa Timur', 'Surabaya', 'UMK', 4961753],
            ['Jawa Timur', 'Sidoarjo', 'UMK', 4869502],
        ];

        $now = date('Y-m-d H:i:s');
        $db  = $this->db;

        foreach ($rows as [$province, $city, $level, $amount]) {
            $exists = $db->table('regional_minimum_wages')
                ->where('province', $province)
                ->where('city', $city)
                ->where('level', $level)
                ->where('effective_year', $year)
                ->countAllResults();
            if ($exists > 0) continue;

            $db->table('regional_minimum_wages')->insert([
                'province'       => $province,
                'city'           => $city,
                'level'          => $level,
                'amount'         => $amount,
                'effective_year' => $year,
                'reference'      => $level === 'UMP'
                    ? "SK Gubernur {$province} tahun {$year} (referensi awal — verifikasi)"
                    : "SK Gubernur {$province} (UMK {$city}) tahun {$year}",
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }
    }

    /**
     * Hari libur nasional Indonesia tahun 2026.
     * Mengikuti SKB 3 Menteri (Kemenag, Kemenaker, KemenPAN-RB).
     * Cuti bersama tidak dimasukkan default — tambahkan manual sesuai
     * kebijakan perusahaan.
     */
    private function seedHolidays2026(): void
    {
        $holidays = [
            ['2026-01-01', 'Tahun Baru Masehi 2026', true,  false],
            ['2026-02-17', 'Tahun Baru Imlek 2577 Kongzili', true, true],
            ['2026-03-19', 'Hari Suci Nyepi (Tahun Baru Saka 1948)', true, true],
            ['2026-03-20', 'Wafat Isa Al Masih', true, true],
            ['2026-03-21', 'Idulfitri 1447 Hijriah (Hari Pertama)', true, true],
            ['2026-03-22', 'Idulfitri 1447 Hijriah (Hari Kedua)', true, true],
            ['2026-04-03', 'Wafat Yesus Kristus (Jumat Agung)', true, true],
            ['2026-05-01', 'Hari Buruh Internasional', true, false],
            ['2026-05-14', 'Kenaikan Isa Al Masih', true, true],
            ['2026-05-29', 'Hari Raya Waisak 2570 BE', true, true],
            ['2026-05-28', 'Iduladha 1447 Hijriah', true, true],
            ['2026-06-01', 'Hari Lahir Pancasila', true, false],
            ['2026-06-17', 'Tahun Baru Islam 1448 Hijriah', true, true],
            ['2026-08-17', 'Hari Kemerdekaan Republik Indonesia', true, false],
            ['2026-08-26', 'Maulid Nabi Muhammad SAW', true, true],
            ['2026-12-25', 'Hari Raya Natal', true, true],
        ];

        $now = date('Y-m-d H:i:s');
        $db  = $this->db;

        foreach ($holidays as [$date, $name, $isNational, $isReligious]) {
            // company_id = NULL = berlaku nasional untuk semua tenant
            $exists = $db->table('holidays')
                ->where('company_id IS NULL')
                ->where('date', $date)
                ->countAllResults();
            if ($exists > 0) continue;

            $db->table('holidays')->insert([
                'company_id'   => null,
                'date'         => $date,
                'name'         => $name,
                'is_national'  => $isNational ? 1 : 0,
                'is_religious' => $isReligious ? 1 : 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }
}