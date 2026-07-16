<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenAssetTransactionApproval extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('assets')) return;
        $fields = [];
        if (! $this->db->fieldExists('approval_action', 'assets')) {
            $fields['approval_action'] = ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true];
        }
        if (! $this->db->fieldExists('approval_employee_id', 'assets')) {
            $fields['approval_employee_id'] = ['type' => 'INT', 'unsigned' => true, 'null' => true];
        }
        if (! $this->db->fieldExists('approval_assignment_id', 'assets')) {
            $fields['approval_assignment_id'] = ['type' => 'INT', 'unsigned' => true, 'null' => true];
        }
        if (! $this->db->fieldExists('approval_payload', 'assets')) {
            $fields['approval_payload'] = ['type' => 'TEXT', 'null' => true];
        }
        if (! $this->db->fieldExists('approval_rejected_by', 'assets')) {
            $fields['approval_rejected_by'] = ['type' => 'INT', 'unsigned' => true, 'null' => true];
        }
        if (! $this->db->fieldExists('approval_rejected_at', 'assets')) {
            $fields['approval_rejected_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if (! $this->db->fieldExists('approval_rejection_reason', 'assets')) {
            $fields['approval_rejection_reason'] = ['type' => 'TEXT', 'null' => true];
        }
        if ($fields !== []) $this->forge->addColumn('assets', $fields);
    }

    public function down(): void
    {
        // Pending action and decision evidence are intentionally retained.
    }
}
