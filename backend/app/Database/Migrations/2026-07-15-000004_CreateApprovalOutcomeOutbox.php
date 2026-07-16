<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateApprovalOutcomeOutbox extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('approval_outbox_events')) return;
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'approval_request_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'module' => ['type' => 'VARCHAR', 'constraint' => 40],
            'entity_table' => ['type' => 'VARCHAR', 'constraint' => 100],
            'entity_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'decision' => ['type' => 'VARCHAR', 'constraint' => 20],
            'event_type' => ['type' => 'VARCHAR', 'constraint' => 100],
            'event_id' => ['type' => 'CHAR', 'constraint' => 36],
            'payload' => ['type' => 'JSON'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDING'],
            'attempts' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'max_attempts' => ['type' => 'INT', 'unsigned' => true, 'default' => 5],
            'available_at' => ['type' => 'DATETIME'],
            'processing_at' => ['type' => 'DATETIME', 'null' => true],
            'published_at' => ['type' => 'DATETIME', 'null' => true],
            'last_error' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('approval_request_id', 'uq_approval_outbox_request');
        $this->forge->addUniqueKey('event_id', 'uq_approval_outbox_event');
        $this->forge->addKey(['status', 'available_at', 'id'], false, false, 'idx_approval_outbox_poll');
        $this->forge->addKey(['company_id', 'status'], false, false, 'idx_approval_outbox_tenant');
        $this->forge->addForeignKey('approval_request_id', 'approval_requests', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('approval_outbox_events');
    }

    public function down(): void
    {
        $this->forge->dropTable('approval_outbox_events', true);
    }
}
