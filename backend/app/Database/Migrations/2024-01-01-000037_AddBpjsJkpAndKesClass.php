<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 4 — Compliance Indonesia.
 *
 * Tambah kolom untuk:
 *   1. JKP (Jaminan Kehilangan Pekerjaan, PP 37/2021).
 *      - Iuran: 0.46% dari upah, dibebankan ke pengusaha (employer-only).
 *      - Wajib untuk PKWT/PKWTT (penerima upah <= 5 jt/bulan capped).
 *      - Plafon upah JKP saat ini Rp 5.000.000. Besar iuran dibatasi.
 *   2. BPJS Kesehatan kelas (1/2/3) per karyawan.
 *      - Saat ini hanya plafon flat 12 jt; tidak track kelas.
 *      - Tambah `bpjs_kes_class` di `employees` (default 1).
 *
 * Migration ini ADDITIVE & idempotent — aman dijalankan ulang.
 */
class AddBpjsJkpAndKesClass extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        // ─── bpjs_rates: JKP ────────────────────────────────────────
        if ($db->tableExists('bpjs_rates')) {
            $cols = $db->getFieldNames('bpjs_rates');
            $additions = [];
            if (! in_array('jkp_company_rate', $cols, true)) {
                $additions['jkp_company_rate'] = [
                    'type'       => 'DECIMAL',
                    'constraint' => '6,5',
                    'default'    => 0.0046,
                    'after'      => 'jkm_company_rate',
                    'comment'    => 'JKP rate (PP 37/2021): 0.46% dibebankan employer',
                ];
            }
            if (! in_array('jkp_max_salary', $cols, true)) {
                $additions['jkp_max_salary'] = [
                    'type'       => 'DECIMAL',
                    'constraint' => '12,2',
                    'default'    => 5000000,
                    'after'      => 'jkp_company_rate',
                    'comment'    => 'Plafon upah JKP (default 5jt)',
                ];
            }
            if (! empty($additions)) {
                $forge->addColumn('bpjs_rates', $additions);
            }

            // Backfill nilai default untuk record existing yang masih
            // NULL/0 (kalau driver tidak honor default constraint saat alter).
            $db->query("UPDATE bpjs_rates SET jkp_company_rate = 0.0046 WHERE jkp_company_rate IS NULL OR jkp_company_rate = 0");
            $db->query("UPDATE bpjs_rates SET jkp_max_salary   = 5000000 WHERE jkp_max_salary IS NULL OR jkp_max_salary = 0");
        }

        // ─── employees.bpjs_kes_class ───────────────────────────────
        if ($db->tableExists('employees')) {
            $cols = $db->getFieldNames('employees');
            if (! in_array('bpjs_kes_class', $cols, true)) {
                $forge->addColumn('employees', [
                    'bpjs_kes_class' => [
                        'type'       => 'TINYINT',
                        'unsigned'   => true,
                        'default'    => 1,
                        'after'      => 'bpjs_kesehatan_no',
                        'comment'    => 'Kelas BPJS Kesehatan: 1 (utama), 2 (menengah), 3 (PBI). Default 1.',
                    ],
                ]);
            }
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('bpjs_rates')) {
            $cols = $db->getFieldNames('bpjs_rates');
            foreach (['jkp_max_salary', 'jkp_company_rate'] as $col) {
                if (in_array($col, $cols, true)) {
                    $forge->dropColumn('bpjs_rates', $col);
                }
            }
        }
        if ($db->tableExists('employees')) {
            $cols = $db->getFieldNames('employees');
            if (in_array('bpjs_kes_class', $cols, true)) {
                $forge->dropColumn('employees', 'bpjs_kes_class');
            }
        }
    }
}