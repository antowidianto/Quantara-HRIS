<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * H2.10 — Pajak atas Natura / Kenikmatan (PMK 66/2023)
 *
 * Menambahkan flag pada master `allowance_types` agar tunjangan dalam
 * bentuk natura/kenikmatan (mis. mobil dinas, perumahan, fasilitas
 * gym) bisa dibedakan perlakuan pajaknya:
 *  - `is_taxable_natura` — TRUE → komponen menambah Penghasilan Bruto
 *    (basis perhitungan PPh21) tetapi tidak menambah cash Take Home
 *    Pay karyawan.
 *  - `natura_tax_threshold` — ambang batas nilai natura yang masih
 *    bebas pajak per tahun (default referensi PMK 66/2023: Rp 3 juta
 *    untuk paket sembako, dst). NULL = tidak ada threshold (langsung
 *    taxable seluruhnya).
 *
 * Idempotent: cek tableExists & fieldExists sebelum addColumn.
 */
class PajakNaturaPMK66 extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('allowance_types')) {
            return;
        }

        $columns = [];

        if (! $db->fieldExists('is_taxable_natura', 'allowance_types')) {
            $columns['is_taxable_natura'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'comment'    => '1 jika allowance berupa fasilitas/natura (PMK 66/2023) yang menambah basis pajak tapi bukan cash THP',
            ];
        }

        if (! $db->fieldExists('natura_tax_threshold', 'allowance_types')) {
            $columns['natura_tax_threshold'] = [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => true,
                'comment'    => 'Ambang batas nilai natura yang bebas pajak tahunan (mis. 3 juta). NULL = full taxable',
            ];
        }

        if ($columns !== []) {
            $this->forge->addColumn('allowance_types', $columns);
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('allowance_types')) {
            return;
        }

        foreach (['is_taxable_natura', 'natura_tax_threshold'] as $col) {
            if ($db->fieldExists($col, 'allowance_types')) {
                $this->forge->dropColumn('allowance_types', $col);
            }
        }
    }
}