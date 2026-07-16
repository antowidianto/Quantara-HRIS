<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddApprovalWorkflowSnapshot extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('approval_requests')) return;
        $existing = $this->db->getFieldNames('approval_requests');
        $fields = [];
        if (! in_array('workflow_snapshot', $existing, true)) {
            $fields['workflow_snapshot'] = ['type' => 'JSON', 'null' => true, 'after' => 'meta'];
        }
        if (! in_array('chain_version_hash', $existing, true)) {
            $fields['chain_version_hash'] = ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'after' => 'workflow_snapshot'];
        }
        if ($fields !== []) $this->forge->addColumn('approval_requests', $fields);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('approval_requests')) return;
        foreach (['chain_version_hash', 'workflow_snapshot'] as $column) {
            if ($this->db->fieldExists($column, 'approval_requests')) {
                $this->forge->dropColumn('approval_requests', $column);
            }
        }
    }
}
