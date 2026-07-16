<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenGeneratedContractApproval extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('generated_contracts')) return;

        $fields = [];
        if (! $this->db->fieldExists('approval_action', 'generated_contracts')) {
            $fields['approval_action'] = ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true];
        }
        if (! $this->db->fieldExists('approval_previous_status', 'generated_contracts')) {
            $fields['approval_previous_status'] = ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true];
        }
        if (! $this->db->fieldExists('approval_rejected_by', 'generated_contracts')) {
            $fields['approval_rejected_by'] = ['type' => 'INT', 'unsigned' => true, 'null' => true];
        }
        if (! $this->db->fieldExists('approval_rejected_at', 'generated_contracts')) {
            $fields['approval_rejected_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if (! $this->db->fieldExists('approval_rejection_reason', 'generated_contracts')) {
            $fields['approval_rejection_reason'] = ['type' => 'TEXT', 'null' => true];
        }
        if ($fields !== []) $this->forge->addColumn('generated_contracts', $fields);

        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query("ALTER TABLE generated_contracts MODIFY status ENUM('DRAFT','PENDING_APPROVAL','APPROVAL_REJECTED','SENT','SIGNED','REVOKED') NOT NULL DEFAULT 'DRAFT'");
            return;
        }
        $this->forge->modifyColumn('generated_contracts', [
            'status' => ['name' => 'status', 'type' => 'VARCHAR', 'constraint' => 32, 'default' => 'DRAFT'],
        ]);
    }

    public function down(): void
    {
        // Approval state and evidence are intentionally retained on rollback.
    }
}
