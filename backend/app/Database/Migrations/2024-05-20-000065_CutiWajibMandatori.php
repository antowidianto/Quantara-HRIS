<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * H1.11 — Cuti Wajib Terbayar (Mandatory Paid Leaves)
 *
 * Menambahkan flag pada master `leave_types` untuk membedakan cuti
 * wajib terbayar (UU 13/2003 & UU KIA) yang TIDAK boleh memotong
 * saldo cuti tahunan reguler:
 *  - `is_system_mandatory` — TRUE = cuti wajib (haid, melahirkan,
 *    keguguran, ayah/istri melahirkan, alasan penting). Saat
 *    diajukan, saldo annual_leave karyawan tidak berkurang dan
 *    tidak masuk perhitungan carry-over cap.
 *  - `gender_restriction` — `M` (laki-laki) / `F` (perempuan) /
 *    NULL (universal). Frontend memakai flag ini untuk menyaring
 *    pilihan tipe cuti agar form tidak menampilkan opsi yang
 *    tidak sesuai gender karyawan.
 *
 * Idempotent: cek tableExists & fieldExists supaya aman di-run
 * ulang dan kompatibel dengan tenant existing.
 */
class CutiWajibMandatori extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('leave_types')) {
            return;
        }

        $columns = [];

        if (! $db->fieldExists('is_system_mandatory', 'leave_types')) {
            $columns['is_system_mandatory'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'comment'    => '1 jika ini adalah cuti wajib (Haid, Melahirkan) yang tidak memotong saldo cuti tahunan (UU 13/2003 & UU KIA)',
            ];
        }

        if (! $db->fieldExists('gender_restriction', 'leave_types')) {
            // Pakai VARCHAR agar portable lintas DB driver (SQLite tidak
            // punya ENUM). Validasi nilai dilakukan di application layer.
            $columns['gender_restriction'] = [
                'type'       => 'VARCHAR',
                'constraint' => 1,
                'null'       => true,
                'comment'    => 'M=Laki-laki only, F=Perempuan only, NULL=universal',
            ];
        }

        if ($columns !== []) {
            $this->forge->addColumn('leave_types', $columns);
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('leave_types')) {
            return;
        }

        foreach (['is_system_mandatory', 'gender_restriction'] as $col) {
            if ($db->fieldExists($col, 'leave_types')) {
                $this->forge->dropColumn('leave_types', $col);
            }
        }
    }
}