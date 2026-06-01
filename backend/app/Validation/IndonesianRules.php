<?php

namespace App\Validation;

use App\Libraries\System\IndonesianValidator;

/**
 * Custom validation rules untuk format khas Indonesia.
 *
 * Pemakaian di rules controller:
 *   'nik'   => 'permit_empty|valid_nik'
 *   'npwp'  => 'permit_empty|valid_npwp'
 *   'phone' => 'permit_empty|valid_phone_id'
 *   'bpjs_kesehatan_no' => 'permit_empty|valid_bpjs_kesehatan'
 *   'bpjs_tk_no'        => 'permit_empty|valid_bpjs_ketenagakerjaan'
 *   'postal_code'       => 'permit_empty|valid_postal_id'
 *   'ptkp_status'       => 'permit_empty|valid_ptkp'
 */
class IndonesianRules
{
    public function valid_nik(?string $str = null, ?string &$error = null): bool
    {
        if (! IndonesianValidator::isValidNik($str)) {
            $error = 'NIK harus 16 digit angka dan format tanggal lahir valid.';
            return false;
        }
        return true;
    }

    public function valid_npwp(?string $str = null, ?string &$error = null): bool
    {
        if (! IndonesianValidator::isValidNpwp($str)) {
            $error = 'NPWP harus 15 atau 16 digit angka.';
            return false;
        }
        return true;
    }

    public function valid_bpjs_kesehatan(?string $str = null, ?string &$error = null): bool
    {
        if (! IndonesianValidator::isValidBpjsKesehatan($str)) {
            $error = 'Nomor BPJS Kesehatan harus 13 digit angka.';
            return false;
        }
        return true;
    }

    public function valid_bpjs_ketenagakerjaan(?string $str = null, ?string &$error = null): bool
    {
        if (! IndonesianValidator::isValidBpjsKetenagakerjaan($str)) {
            $error = 'Nomor BPJS Ketenagakerjaan (KPJ) harus 11 digit angka.';
            return false;
        }
        return true;
    }

    public function valid_phone_id(?string $str = null, ?string &$error = null): bool
    {
        if (! IndonesianValidator::isValidPhoneId($str)) {
            $error = 'Nomor telepon harus format Indonesia (+62/08/8) dan 10–13 digit.';
            return false;
        }
        return true;
    }

    public function valid_postal_id(?string $str = null, ?string &$error = null): bool
    {
        if (! IndonesianValidator::isValidPostalCode($str)) {
            $error = 'Kode pos harus 5 digit angka.';
            return false;
        }
        return true;
    }

    public function valid_ptkp(?string $str = null, ?string &$error = null): bool
    {
        if (! IndonesianValidator::isValidPtkp($str)) {
            $error = 'Status PTKP tidak valid (TK0..TK3, K0..K3, HB0..HB3).';
            return false;
        }
        return true;
    }
}