<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * H1.12 — Kompensasi PKWT & Limit 5 Tahun (PP 35/2021)
 *
 * Menambahkan flagging pelacakan kompensasi PKWT pada tabel
 * `employee_contracts`. Kolom yang ditambahkan:
 *  - `pkwt_compensation_paid` — penanda apakah uang kompensasi PKWT
 *    sudah dibayarkan saat kontrak berakhir.
 *  - `pkwt_compensation_amount` — nominal akhir yang dihitung saat
 *    PayrollService meng-generate item earnings "Kompensasi PKWT" pada
 *    bulan berakhirnya kontrak.
 *  - `pkwt_compensation_paid_period_id` — payroll_period_id ketika
 *    kompensasi tersebut dibayarkan supaya idempotensi terjaga (re-
 *    calculate periode tidak menggandakan item kompensasi).
 *
 * Catatan implementasi:
 *  - Tabel referensi yang benar adalah `employee_contracts` (bukan
 *    `contracts`). Kolom `contract_type` sudah ENUM('PKWT','PKWTT',...)
 *    sehingga tidak perlu kolom is_pkwt redundan; deteksi PKWT cukup
 *    via `contract_type = 'PKWT'`.
 *  - Migration ini idempotent: cek tableExists & fieldExists supaya
 *    aman di-run ulang dan kompatibel dengan tenant existing.
 */
class KompensasiPkwt extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('employee_contracts')) {
            return;
        }

        $columns = [];

        if (! $db->fieldExists('pkwt_compensation_paid', 'employee_contracts')) {
            $columns['pkwt_compensation_paid'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'comment'    => '1 jika uang kompensasi PKWT sudah dibayarkan saat expired (PP 35/2021)',
            ];
        }

        if (! $db->fieldExists('pkwt_compensation_amount', 'employee_contracts')) {
            $columns['pkwt_compensation_amount'] = [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => true,
                'comment'    => 'Nominal kompensasi PKWT yang telah/akan dibayarkan',
            ];
        }

        if (! $db->fieldExists('pkwt_compensation_paid_period_id', 'employee_contracts')) {
            $columns['pkwt_compensation_paid_period_id'] = [
                'type'       => 'INT',
                'unsigned'   => true,
                'null'       => true,
                'comment'    => 'FK ke payroll_periods saat kompensasi dibayarkan (untuk idempotensi)',
            ];
        }

        if (! $db->fieldExists('pkwt_compensation_paid_at', 'employee_contracts')) {
            $columns['pkwt_compensation_paid_at'] = [
                'type'       => 'DATETIME',
                'null'       => true,
                'comment'    => 'Timestamp pembayaran kompensasi PKWT',
            ];
        }

        if ($columns !== []) {
            $this->forge->addColumn('employee_contracts', $columns);
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('employee_contracts')) {
            return;
        }

        foreach ([
            'pkwt_compensation_paid',
            'pkwt_compensation_amount',
            'pkwt_compensation_paid_period_id',
            'pkwt_compensation_paid_at',
        ] as $col) {
            if ($db->fieldExists($col, 'employee_contracts')) {
                $this->forge->dropColumn('employee_contracts', $col);
            }
        }
    }
}