<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * H2.10 (lanjutan) — Persist flag `is_natura` di payslip_details.
 *
 * PayrollService.calculateEmployee sebelumnya hanya men-set is_natura via
 * defensive `fieldExists` check. Migration ini menambahkan kolom resmi
 * supaya frontend (PayslipDetail.vue) bisa render badge "Natura" tanpa
 * harus parse JSON breakdown, dan reporting SPT 1721 bisa filter natura
 * langsung dari payslip_details.
 *
 * Idempotent: cek tableExists & fieldExists supaya aman di-run ulang.
 */
class PayslipDetailIsNatura extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('payslip_details')) {
            return;
        }
        if ($db->fieldExists('is_natura', 'payslip_details')) {
            return;
        }
        $this->forge->addColumn('payslip_details', [
            'is_natura' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'comment'    => 'PMK 66/2023 — 1 jika item ini natura/kenikmatan non-tunai',
            ],
        ]);
    }

    public function down()
    {
        $db = \Config\Database::connect();
        if ($db->tableExists('payslip_details') && $db->fieldExists('is_natura', 'payslip_details')) {
            $this->forge->dropColumn('payslip_details', 'is_natura');
        }
    }
}