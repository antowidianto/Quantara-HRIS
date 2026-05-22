<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tambah kolom expected_approver_user_id & expected_approver_employee_id
 * ke `manpower_request_approvals`. Dipakai oleh ManpowerService untuk
 * resolve approver dari `organization_structures` saat MPP di-submit,
 * supaya UI inbox bisa menampilkan "menunggu approval dari [Nama]".
 *
 * Idempoten: cek kolom dulu sebelum add (untuk env yang sudah jalan
 * migrate sebelum migration ini ditambah).
 */
class AddExpectedApproverToMpp extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('manpower_request_approvals')) {
            return;
        }

        $fields = $db->getFieldNames('manpower_request_approvals');

        $newCols = [];
        if (! in_array('expected_approver_user_id', $fields, true)) {
            $newCols['expected_approver_user_id'] = [
                'type' => 'INT', 'unsigned' => true, 'null' => true,
                'after' => 'approver_role',
                'comment' => 'User yang seharusnya approve (resolved dari org_structures saat submit)',
            ];
        }
        if (! in_array('expected_approver_employee_id', $fields, true)) {
            $newCols['expected_approver_employee_id'] = [
                'type' => 'INT', 'unsigned' => true, 'null' => true,
                'after' => 'expected_approver_user_id',
                'comment' => 'Employee penjabat (display only)',
            ];
        }

        if (! empty($newCols)) {
            $forge->addColumn('manpower_request_approvals', $newCols);
        }
    }

    public function down(): void
    {
        $db = $this->db;
        if (! $db->tableExists('manpower_request_approvals')) return;
        $fields = $db->getFieldNames('manpower_request_approvals');
        foreach (['expected_approver_user_id', 'expected_approver_employee_id'] as $col) {
            if (in_array($col, $fields, true)) {
                $this->forge->dropColumn('manpower_request_approvals', $col);
            }
        }
    }
}