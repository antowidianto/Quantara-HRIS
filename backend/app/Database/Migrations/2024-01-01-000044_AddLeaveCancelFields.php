<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 17+ — Tambah field cancel di tabel `leaves`.
 *
 * Mendukung fitur self-cancel cuti (LeaveController::cancel) yang
 * menerima ENUM status baru `CANCELED` dan trail audit (`cancellation_reason`,
 * `cancelled_by`, `cancelled_at`).
 *
 * Status lama yang sudah ada di production (PENDING/APPROVED/REJECTED)
 * tidak terganggu — kolom baru semua nullable.
 *
 * Additive-only sesuai ADR #0004.
 */
class AddLeaveCancelFields extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('leaves')) {
            return;
        }

        $fields = [];
        if (! $this->db->fieldExists('cancellation_reason', 'leaves')) {
            $fields['cancellation_reason'] = [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
                'comment'    => 'Alasan pembatalan cuti (Sprint 17+)',
            ];
        }
        if (! $this->db->fieldExists('cancelled_by', 'leaves')) {
            $fields['cancelled_by'] = [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'User ID yang membatalkan (pemilik atau HR)',
            ];
        }
        if (! $this->db->fieldExists('cancelled_at', 'leaves')) {
            $fields['cancelled_at'] = [
                'type' => 'DATETIME',
                'null' => true,
            ];
        }

        if (! empty($fields)) {
            $this->forge->addColumn('leaves', $fields);
        }
    }

    public function down()
    {
        // Additive-only; no-op.
    }
}