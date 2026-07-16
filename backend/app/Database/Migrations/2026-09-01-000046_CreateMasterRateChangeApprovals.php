<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateMasterRateChangeApprovals extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('master_rate_change_requests')) return;
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'request_number' => ['type' => 'VARCHAR', 'constraint' => 64],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 32],
            'change_action' => ['type' => 'VARCHAR', 'constraint' => 16],
            'target_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'PENDING_APPROVAL'],
            'payload_json' => ['type' => 'TEXT'],
            'payload_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'target_snapshot_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'submitted_by' => ['type' => 'INT', 'unsigned' => true],
            'submitted_at' => ['type' => 'DATETIME'],
            'approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at' => ['type' => 'DATETIME', 'null' => true],
            'rejected_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'rejection_reason' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'], 'updated_at' => ['type' => 'DATETIME'],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'request_number']);
        $this->forge->addKey(['company_id', 'entity_type', 'target_id', 'status']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('master_rate_change_requests');
    }

    public function down(): void
    {
        // Keep rate governance evidence on rollback.
    }
}
