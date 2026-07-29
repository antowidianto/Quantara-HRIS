<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Menormalkan rate BPJS Ketenagakerjaan sesuai PP 6 Tahun 2025.
 *
 * Efektif 7 Februari 2025:
 * - total iuran JKP 0,36%;
 * - 0,22% berasal dari Pemerintah Pusat;
 * - 0,14% berasal dari rekomposisi iuran JKK;
 * - tarif JKK setelah rekomposisi: 0,10%; 0,40%; 0,75%; 1,13%; 1,60%.
 *
 * Migration hanya memetakan lima tarif JKK legacy yang resmi. Nilai custom
 * lainnya tidak diubah agar tidak merusak konfigurasi yang memerlukan review.
 */
final class ApplyBpjsPp6Of2025 extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('bpjs_rates')) {
            return;
        }

        $columns = $db->getFieldNames('bpjs_rates');
        if (! in_array('jkp_company_rate', $columns, true)) {
            return;
        }

        // Ubah rate JKP legacy PP 37/2021 menjadi total rate PP 6/2025.
        $db->table('bpjs_rates')
            ->where('jkp_company_rate', 0.0046)
            ->update(['jkp_company_rate' => 0.0036]);

        if (! in_array('jkk_company_rate', $columns, true)) {
            return;
        }

        // Mapping lima tingkat risiko: tarif legacy dikurangi rekomposisi 0,14%.
        $rateMap = [
            '0.0024' => 0.0010,
            '0.0054' => 0.0040,
            '0.0089' => 0.0075,
            '0.0127' => 0.0113,
            '0.0174' => 0.0160,
        ];

        foreach ($rateMap as $legacyRate => $newRate) {
            $db->table('bpjs_rates')
                ->where('jkk_company_rate', (float) $legacyRate)
                ->update(['jkk_company_rate' => $newRate]);
        }
    }

    public function down()
    {
        // Rate legal yang sudah efektif tidak di-rollback otomatis. Rollback
        // formula statutory berisiko mengubah payroll periode berjalan.
    }
}