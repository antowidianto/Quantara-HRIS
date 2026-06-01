<?php

namespace App\Libraries\System;

/**
 * IndonesianValidator
 *
 * Validator khusus konteks Indonesia: NIK KTP, NPWP, BPJS, nomor HP +62,
 * kode pos, format jam/PTKP, dan UMP/UMK lookup.
 *
 * Pakai sebagai static helper, atau register sebagai custom validation rules
 * via app/Config/Validation.php (lihat method ruleSet()).
 */
class IndonesianValidator
{
    /**
     * NIK KTP (Nomor Induk Kependudukan): 16 digit angka.
     * Struktur: PPKKCC DDMMYY NNNN
     *  - PP   : kode provinsi (2 digit)
     *  - KK   : kode kabupaten/kota (2 digit)
     *  - CC   : kode kecamatan (2 digit)
     *  - DDMMYY: tanggal lahir (perempuan +40 di hari)
     *  - NNNN : nomor urut
     */
    public static function isValidNik(?string $nik): bool
    {
        if ($nik === null) return false;
        $nik = preg_replace('/\s+/', '', $nik);
        if (! preg_match('/^\d{16}$/', $nik)) return false;

        // Validasi sederhana tanggal lahir
        $day   = (int) substr($nik, 6, 2);
        $month = (int) substr($nik, 8, 2);
        $year2 = (int) substr($nik, 10, 2);

        // Perempuan: hari + 40
        if ($day > 40) $day -= 40;
        if ($day < 1 || $day > 31) return false;
        if ($month < 1 || $month > 12) return false;

        // Tahun 2-digit: 00-25 dianggap 2000-2025, sisanya 1926-1999.
        // Cukup longgar untuk tujuan format check.
        $year = $year2 <= (int) date('y') ? 2000 + $year2 : 1900 + $year2;
        return checkdate($month, $day, $year);
    }

    /**
     * NPWP: format lama 15 digit (XX.XXX.XXX.X-XXX.XXX) atau format baru
     * 16 digit (mengikuti NIK untuk WP OP). Terima dengan/atau tanpa
     * pemisah titik & strip.
     */
    public static function isValidNpwp(?string $npwp): bool
    {
        if ($npwp === null) return false;
        $digits = preg_replace('/[^0-9]/', '', $npwp);
        if ($digits === null) return false;
        $len = strlen($digits);
        return $len === 15 || $len === 16;
    }

    /**
     * Nomor BPJS Kesehatan: 13 digit.
     */
    public static function isValidBpjsKesehatan(?string $no): bool
    {
        if ($no === null) return false;
        $digits = preg_replace('/[^0-9]/', '', $no);
        return $digits !== null && strlen($digits) === 13;
    }

    /**
     * Nomor BPJS Ketenagakerjaan (KPJ): 11 digit.
     */
    public static function isValidBpjsKetenagakerjaan(?string $no): bool
    {
        if ($no === null) return false;
        $digits = preg_replace('/[^0-9]/', '', $no);
        return $digits !== null && strlen($digits) === 11;
    }

    /**
     * Nomor telepon Indonesia: terima format +62, 62, 08, atau 8.
     * Total 9-13 digit setelah dinormalisasi ke 0…
     */
    public static function isValidPhoneId(?string $phone): bool
    {
        if ($phone === null) return false;
        $normalized = self::normalizePhoneId($phone);
        return $normalized !== null;
    }

    public static function normalizePhoneId(?string $phone): ?string
    {
        if ($phone === null) return null;
        $digits = preg_replace('/[^0-9+]/', '', $phone);
        if ($digits === null || $digits === '') return null;

        if (str_starts_with($digits, '+62')) {
            $digits = '0' . substr($digits, 3);
        } elseif (str_starts_with($digits, '62')) {
            $digits = '0' . substr($digits, 2);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '0' . $digits;
        }

        // Operator seluler Indonesia mulai dari 08, total 10–13 digit
        if (! preg_match('/^08\d{8,11}$/', $digits)) return null;
        return $digits;
    }

    /** Kode pos Indonesia: 5 digit. */
    public static function isValidPostalCode(?string $code): bool
    {
        if ($code === null) return false;
        return preg_match('/^\d{5}$/', $code) === 1;
    }

    /** Status PTKP: TK0..TK3, K0..K3, HB0..HB3 (hak istri-suami). */
    public static function isValidPtkp(?string $code): bool
    {
        if ($code === null) return false;
        return in_array(strtoupper($code), [
            'TK0','TK1','TK2','TK3',
            'K0','K1','K2','K3',
            'HB0','HB1','HB2','HB3',
        ], true);
    }

    /**
     * Cari UMP/UMK aktif untuk lokasi tertentu.
     * Kembalikan array {amount, level, reference} atau null bila tidak ada.
     */
    public static function findMinimumWage(string $province, ?string $city = null, ?int $year = null): ?array
    {
        $year = $year ?: (int) date('Y');
        $db = \Config\Database::connect();

        // Coba UMK kota dulu (jika ada), fallback ke UMP provinsi
        if ($city) {
            $umk = $db->table('regional_minimum_wages')
                ->where('province', $province)
                ->where('city', $city)
                ->where('level', 'UMK')
                ->where('effective_year <=', $year)
                ->orderBy('effective_year', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
            if ($umk) {
                return [
                    'amount'    => (float) $umk['amount'],
                    'level'     => 'UMK',
                    'reference' => $umk['reference'] ?? null,
                    'year'      => (int) $umk['effective_year'],
                ];
            }
        }

        $ump = $db->table('regional_minimum_wages')
            ->where('province', $province)
            ->where('level', 'UMP')
            ->where('effective_year <=', $year)
            ->orderBy('effective_year', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
        if ($ump) {
            return [
                'amount'    => (float) $ump['amount'],
                'level'     => 'UMP',
                'reference' => $ump['reference'] ?? null,
                'year'      => (int) $ump['effective_year'],
            ];
        }
        return null;
    }

    /**
     * Definisi rules untuk dipakai di Validation->setRules() atau di-array_merge
     * ke aturan controller.
     *
     * Contoh:
     *   $this->validateOrFail($data, IndonesianValidator::ruleSet([
     *       'nik' => ['rules' => 'permit_empty|valid_nik'],
     *   ]));
     */
    public static function ruleSet(array $rules): array
    {
        // Method ini hanya membungkus untuk readability — registrasi nyata
        // dilakukan di Validation config (lihat below).
        return $rules;
    }
}