<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 17+ — Tambah kolom `bonus_amount` di tabel `payslips`.
 *
 * Mendukung fitur auto-merge bonus run → payslip (PayrollService
 * Sprint 17+). Kolom ini di-populate oleh PayrollService::calculatePeriod
 * saat scope-nya termasuk bonus_runs dengan status APPROVED/PAID dan
 * `payroll_period_id` matching.
 *
 * Default 0 untuk row existing supaya tidak break re-calculate flow.
 *
 * Additive-only sesuai ADR #0004.
 */
class AddPayslipBonusAmount extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('payslips')) {
            return;
        }
        if ($this->db->fieldExists('bonus_amount', 'payslips')) {
            return;
        }

        $this->forge->addColumn('payslips', [
            'bonus_amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
                'comment'    => 'Total bonus dari Bonus Engine yang merged ke slip ini (Sprint 17+)',
                'after'      => 'reimbursement_amount',
            ],
        ]);
    }

    public function down()
    {
        // Additive-only; no-op.
    }
}